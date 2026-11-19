<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;
use Throwable;

/** Interactive operator entry point. Scheduled reads cannot create or finish a PUSH. */
final class DiadocSessionRestore
{
    private const OPERATION = 'diadoc-session-refresh';
    private const BOX_DENIED = 'SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN';

    public function __construct(private PDO $db) {}

    public static function safeCode(Throwable $error): string
    {
        $code = $error->getMessage();
        if (preg_match('/^CONFIRMATION_(?:CHECK_FAILED|FAILED)_HTTP_[1-5][0-9]{2}$/D',$code)) return $code;
        return in_array($code,[
            'INVALID_REQUEST','DIADOC_RESTORE_NOT_ALLOWED','PROFILE_NOT_CONFIGURED','PROVIDER_BUSY',
            'DIADOC_OTHER_CHALLENGE_PENDING','DIADOC_ATTEMPT_REQUIRES_RECONCILIATION','DIADOC_RESTORE_BINDING_CHANGED',
            'DIADOC_PENDING_MISMATCH','DIADOC_PENDING_EXPIRED_OR_INVALID','PRIVATE_OWNER_MODE_REQUIRED',
            'FRESH_PRIVATE_STATE_REQUIRED','STATE_DIRECTORY_FAILED','LOGIN_REJECTED','AUTH_BOOTSTRAP_FAILED',
            'AUTH_SESSION_MISSING','CONFIRMATION_REFUSED','CONFIRMATION_EXPIRED_RUN_LOGIN','ONLINE_HTTPS_FAILED',
            'ONLINE_TIME_LIMIT','ONLINE_RESPONSE_LIMIT','DOCUMENT_LIST_SCHEMA_CHANGED',self::BOX_DENIED,
        ],true)?$code:'DIADOC_RESTORE_FAILED';
    }

    public function execute(string $action,string $key): array
    {
        if (!in_array($action,['start','confirm'],true)
            || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)) {
            throw new RuntimeException('INVALID_REQUEST');
        }
        if (PHP_SAPI!=='cli' || getenv('APP_STAND')!=='ui' || $this->db->inTransaction()) {
            throw new RuntimeException('DIADOC_RESTORE_NOT_ALLOWED');
        }
        \App\NativePlatform::requireOwnerChecks();
        $lock = $this->db->query("SELECT GET_LOCK('ipphp-provider:diadoc',0)");
        if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('PROVIDER_BUSY');
        $lock->closeCursor();
        $stream = null;
        $umask = umask(0077);
        try {
            $connection = Config::get($this->db,'diadoc');
            $reference = Config::reference(Support::decode($connection['config_json'])['access_reference']??'');
            $revision = (int)$connection['revision'];
            if (!$connection['enabled'] || !CabinetSyncAdmission::allows($this->db,'diadoc','authenticate',$reference)) {
                throw new RuntimeException('DIADOC_RESTORE_NOT_ALLOWED');
            }
            $profile = Config::profile('diadoc',$reference);
            foreach (['username'=>320,'password'=>1024] as $field=>$limit) {
                $value = $profile[$field]??null;
                if (!is_string($value) || $value==='' || strlen($value)>$limit || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                    throw new RuntimeException('PROFILE_NOT_CONFIGURED');
                }
            }
            $box = $profile['box_id']??null;
            if (!is_string($box) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$box)) {
                throw new RuntimeException('PROFILE_NOT_CONFIGURED');
            }
            $run = $this->lookup($key);
            $binding = self::binding($reference,$revision,$profile);
            if ($run && ($run['operation']!==self::OPERATION || !hash_equals($run['fingerprint'],$binding))) {
                throw new RuntimeException('DIADOC_RESTORE_BINDING_CHANGED');
            }
            if ($run && ($action==='start' || $run['status']!=='awaiting_confirmation')) {
                return self::result($run['id'],$run['status'],$run['result_code'],true);
            }
            if (!$run && $action==='confirm') throw new RuntimeException('DIADOC_PENDING_MISMATCH');
            if (!$run) {
                $count = $this->db->query("SELECT COUNT(*) FROM integration_runs WHERE provider='diadoc'
                    AND operation='diadoc-session-refresh' AND status IN ('pending','awaiting_confirmation','uncertain')");
                if ((int)$count->fetchColumn()>0) throw new RuntimeException('DIADOC_ATTEMPT_REQUIRES_RECONCILIATION');
            }
            $path = Config::root().'/private/integrations/state/diadoc/'.$reference;
            if (!is_dir($path) && !mkdir($path,0700,true)) throw new RuntimeException('STATE_DIRECTORY_FAILED');
            require_once __DIR__.'/native/php/Portal.php';
            $state = \NativeClients\Portal::state($path);
            foreach (['run.lock','cookies.txt','pending.json'] as $name) self::privateFile($state.'/'.$name);
            $stream = fopen($state.'/run.lock','c');
            if (!$stream || !flock($stream,LOCK_EX|LOCK_NB)) throw new RuntimeException('PROVIDER_BUSY');
            if (is_file($state.'/pending.json')) throw new RuntimeException('DIADOC_OTHER_CHALLENGE_PENDING');
            $id = $run['id']??Support::uuid();
            $pendingPath = $state.'/restore-'.$id.'.json';
            $pending = null;
            if ($run) {
                self::privateFile($pendingPath);
                $coverage = Support::decode($run['coverage_json']??'{}');
                $bytes = is_file($pendingPath)&&filesize($pendingPath)<=65536?file_get_contents($pendingPath):false;
                if (!is_string($bytes) || !hash_equals($coverage['pending_sha256']??'',hash('sha256',$bytes))) {
                    throw new RuntimeException('DIADOC_PENDING_MISMATCH');
                }
                $pending = Support::decode($bytes);
                try { DiadocPendingConfirmation::pending($pending,time()); }
                catch (RuntimeException $error) {
                    $this->finish($id,'failed',self::safeCode($error),[]);
                    return self::result($id,'failed',self::safeCode($error));
                }
                $reserve = $this->db->prepare("UPDATE integration_runs SET status='pending',result_code=NULL,finished_at=NULL
                    WHERE id=? AND status='awaiting_confirmation'");
                $reserve->execute([$id]);
                if ($reserve->rowCount()!==1) throw new RuntimeException('DIADOC_PENDING_MISMATCH');
            } else {
                $reserve = $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status)
                    VALUES(?,'diadoc',?,?,0,?,?,'pending')");
                $reserve->execute([$id,self::OPERATION,$key,$revision,$binding]);
            }
            return $this->transport($id,$reference,$revision,$binding,$state,$pendingPath,$profile,$pending);
        } finally {
            if (is_resource($stream)) fclose($stream);
            umask($umask);
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:diadoc')")->closeCursor();
        }
    }

    private static function privateFile(string $path): void
    {
        if (is_link($path) || file_exists($path) && (!is_file($path)
            || PHP_OS_FAMILY!=='Windows' && (fileowner($path)!==posix_geteuid() || (fileperms($path)&0077)!==0))) {
            throw new RuntimeException('PRIVATE_OWNER_MODE_REQUIRED');
        }
    }

    private function lookup(string $key): ?array
    {
        $query=$this->db->prepare("SELECT * FROM integration_runs WHERE provider='diadoc' AND actor_id=0 AND request_key=?");
        $query->execute([$key]);
        return $query->fetch(PDO::FETCH_ASSOC)?:null;
    }

    private static function binding(string $reference,int $revision,array $profile): string
    {
        return hash_hmac('sha256',Support::canonical([
            $reference,$revision,$profile['box_id']??null,$profile['username']??null,$profile['password']??null,
        ]),Config::key());
    }

    /** Runs exactly one specified phase using the existing provider client. */
    public static function advance(object $client,array $profile,?array $pending): array
    {
        if ($pending) {
            $auth = $client->confirm($pending);
            if (($auth['state']??'')!=='authenticated') return ['status'=>'failed','code'=>'PUSH_NOT_CONFIRMED'];
        } else {
            try {
                $client->page($profile['box_id'],'Any',1);
                return ['status'=>'success','code'=>'AUTH_METADATA_CHECKED'];
            } catch (RuntimeException $error) {
                if ($error->getMessage()!==self::BOX_DENIED) throw $error;
            }
            $auth = $client->login($profile['username'],$profile['password']);
            if (($auth['state']??'')==='awaiting_confirmation') {
                return ['status'=>'awaiting_confirmation','code'=>'PUSH_REQUIRED','pending'=>$auth];
            }
        }
        $client->page($profile['box_id'],'Any',1);
        return ['status'=>'success','code'=>'AUTH_METADATA_CHECKED'];
    }

    private function transport(string $id,string $reference,int $revision,string $binding,string $state,string $pendingPath,array $profile,?array $pending): array
    {
        $http = null;
        $requests = [];
        $authPostStarted = false;
        $prior = $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']??null;
        $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = true;
        $metadata = [];
        try {
            $http = new \Diadoc\Http($state,Support::caFile(),function(array $event) use ($reference,$revision,$binding,&$requests,&$authPostStarted): void {
                if (($event['status']??null)==='started') {
                    $current = Config::get($this->db,'diadoc');
                    if (!$current['enabled'] || (int)$current['revision']!==$revision
                        || !CabinetSyncAdmission::allows($this->db,'diadoc','authenticate',$reference)
                        || !hash_equals($binding,self::binding($reference,$revision,Config::profile('diadoc',$reference)))) {
                        throw new RuntimeException('DIADOC_RESTORE_BINDING_CHANGED');
                    }
                    if ($event['method']==='POST') $authPostStarted=true;
                } else {
                    $requests[] = ['stage'=>$event['stage'],'http'=>(int)$event['status'],'curl_errno'=>(int)$event['curl_errno']];
                }
            },hrtime(true)/1e9+95);
            $client = new \Diadoc\WebClient($http);
            $result=self::advance($client,$profile,$pending);
            $status=$result['status'];
            $code=$result['code'];
            if (isset($result['pending'])) {
                \Diadoc\Export::writeJson($pendingPath,$result['pending']);
                $metadata['pending_sha256']=hash_file('sha256',$pendingPath);
            }
        } catch (Throwable $error) {
            $code=self::safeCode($error);
            $status=$authPostStarted && !in_array($code,['LOGIN_REJECTED','CONFIRMATION_REFUSED'],true)?'uncertain':'failed';
        } finally {
            $http?->close();
            if ($prior===null) unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);
            else $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=$prior;
        }
        $this->finish($id,$status,$code,$metadata+['requests'=>$requests,'auth_post_started'=>$authPostStarted]);
        // The journal is durable before the now-consumed challenge is removed.
        if ($status==='success' && $pending && is_file($pendingPath)) unlink($pendingPath);
        return self::result($id,$status,$code);
    }

    private function finish(string $id,string $status,string $code,array $coverage): void
    {
        $update=$this->db->prepare('UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=?');
        $update->execute([$status,$code,Support::json($coverage),$id]);
    }

    private static function result(string $id,string $status,?string $code,bool $reused=false): array
    {
        return ['id'=>$id,'status'=>$status,'code'=>$code,'reused'=>$reused];
    }
}
