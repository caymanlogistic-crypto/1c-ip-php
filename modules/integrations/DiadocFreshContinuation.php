<?php
declare(strict_types=1);
namespace Modules\Integrations;
use PDO;use RuntimeException;

/** One normal WEB flow after the source-equivalent existing challenge returned404. */
final class DiadocFreshContinuation
{
    public const REQUEST='e1699e07-ec9c-4450-a6ab-ef9e0daf62c4';
    public const OWNER_RESTART_REQUEST='6ab5eb1e-e24a-4b91-bf7a-1f488ad43b13';
    public static function restartRequest(string $scope):string
    {
        if($scope!=='owner-requested-diadoc-restart-20261116-v2')throw new RuntimeException('DIADOC_FRESH_NOT_AUTHORIZED');
        return self::OWNER_RESTART_REQUEST;
    }
    public static function gate(array $run,array $metadata,array $source):void
    {
        $requests=$metadata['auth_diagnostic']['requests']??[];
        if(($run['provider']??null)!=='diadoc'||($run['operation']??null)!=='auth-metadata'||(int)($run['revision']??0)!==3
            ||($run['status']??null)!=='failed'||($run['result_code']??null)!=='CONFIRMATION_CHECK_FAILED_HTTP_404'
            ||($metadata['auth_request_mode']??null)!=='late_owner_existing_push_once'||($metadata['owner_attested_late_continuation']??null)!==true
            ||($metadata['source_receipt_sha256']??null)!==($source['source_receipt_sha256']??null)
            ||count($requests)!==2||($requests[0]['stage']??null)!=='registry-page'||($requests[0]['http_status']??null)!==401
            ||($requests[1]['stage']??null)!=='auth-confirm-status'||($requests[1]['http_status']??null)!==404)throw new RuntimeException('DIADOC_FRESH_GATE_NOT_PROVEN');
    }
    public function __construct(private PDO $db,private PrivateStore $store){}
    public function ownerRestart():array
    {
        $request=self::restartRequest((string)getenv('IPPHP_DIADOC_OWNER_RESTART'));
        if(PHP_SAPI!=='cli'||!Config::liveAllowed())throw new RuntimeException('DIADOC_FRESH_NOT_AUTHORIZED');
        $lock='ipphp-provider:diadoc';$q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('PROVIDER_BUSY');
        try{
            $connection=Config::get($this->db,'diadoc');$source=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='diadoc'")->fetch(PDO::FETCH_ASSOC);
            DiadocMountedProfile::assertCurrent($connection,$source,3,DiadocMountedProfile::REFERENCE);
            $q=$this->db->prepare("SELECT id,status,result_code FROM integration_runs WHERE provider='diadoc' AND actor_id=0 AND request_key=?");$q->execute([$request]);
            if($old=$q->fetch(PDO::FETCH_ASSOC))return $old+['reused'=>true];
            $path=Config::root().'/private/integrations/state/diadoc/'.DiadocMountedProfile::REFERENCE.'/pending.json';
            if(!is_file($path)||is_link($path)||filesize($path)>65536)throw new RuntimeException('DIADOC_OLD_PENDING_REQUIRED');
            $pending=Support::decode(file_get_contents($path));
            if(($pending['state']??null)!=='awaiting_confirmation'||!is_int($pending['created_at']??null)||!is_string($pending['details']['PushId']??null))throw new RuntimeException('DIADOC_OLD_PENDING_REQUIRED');
            $oldPush=$pending['details']['PushId'];$oldCreated=$pending['created_at'];
            $profile=Config::profile('diadoc',DiadocMountedProfile::REFERENCE);
            if(($profile['_source_receipt_sha256']??null)!==$source['source_receipt_sha256'])throw new RuntimeException('DIADOC_PROFILE_CAS_CONFLICT');
            if(isset($profile['_pending_history'][$request]))throw new RuntimeException('DIADOC_RESTART_ALREADY_PREPARED');
            $profile['_pending_history'][$request]=['pending'=>$pending,'closure'=>'OWNER_REQUESTED_RESTART','closed_at'=>time(),'provider_validity'=>'unknown'];
            Config::saveProfile('diadoc',DiadocMountedProfile::REFERENCE,$profile);unset($profile,$pending);
            $closure=$this->store->spool(['provider'=>'diadoc','operation'=>'owner-requested-auth-restart','request_key'=>$request],
                Support::json(['code'=>'OWNER_REQUESTED_RESTART','revision'=>3,'history_preserved_encrypted'=>true,'provider_validity'=>'unknown']));
            // Explicit owner restart closes this local pending only; never labels it expired.
            if(!unlink($path))throw new RuntimeException('DIADOC_PENDING_CLOSE_FAILED');
            $result=(new ReadOnlyCheck($this->db,$this->store))->run('diadoc',$request,false,3);
            $result+=['revision'=>3,'old_pending_preserved_encrypted'=>true,'closure_receipt'=>$closure];
            if(($result['reason_code']??null)==='PUSH_REQUIRED'){
                $new=Support::decode(file_get_contents($path));$changed=$new['details']['PushId']!==$oldPush;
                $result['challenge_changed']=$changed;$result['push_requests']=1;
                if($changed){$result['new_push_created_at']=date(DATE_ATOM,$new['created_at']);$result['new_push_local_deadline']=date(DATE_ATOM,$new['created_at']+600);}
                else{$new['created_at']=$oldCreated;file_put_contents($path,Support::json($new));$result['reason_code']='SOURCE_REUSED_CHALLENGE';$result['new_pushes']=0;$result['original_local_deadline']=date(DATE_ATOM,$oldCreated+600);}
            }else $result['push_requests']=0;
            return $result;
        }finally{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
    public function run():array
    {
        if(PHP_SAPI!=='cli'||getenv('IPPHP_DIADOC_FRESH_ADMISSION')!=='root-one-fresh-after-bound-404-20261116'||!Config::liveAllowed())throw new RuntimeException('DIADOC_FRESH_NOT_AUTHORIZED');
        $lock='ipphp-provider:diadoc';$q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('PROVIDER_BUSY');
        try{
            $connection=Config::get($this->db,'diadoc');$source=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='diadoc'")->fetch(PDO::FETCH_ASSOC);
            DiadocMountedProfile::assertCurrent($connection,$source,3,DiadocMountedProfile::REFERENCE);
            $q=$this->db->prepare("SELECT id,status,result_code FROM integration_runs WHERE provider='diadoc' AND actor_id=0 AND request_key=?");$q->execute([self::REQUEST]);
            if($old=$q->fetch(PDO::FETCH_ASSOC))return $old+['reused'=>true];
            $q=$this->db->prepare("SELECT * FROM integration_runs WHERE provider='diadoc' AND actor_id=0 AND request_key=?");$q->execute([DiadocPendingConfirmation::REQUEST]);
            $previous=$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('DIADOC_FRESH_GATE_NOT_PROVEN');
            $metadata=Support::decode($previous['coverage_json']);$receipt=$this->store->verifySpool($metadata['receipt_path']??'');
            $body=Support::decode(base64_decode($receipt['raw_base64'],true));
            if(($receipt['context']['run_id']??null)!==$previous['id']||($body['code']??null)!==$previous['result_code'])throw new RuntimeException('DIADOC_FRESH_GATE_NOT_PROVEN');
            self::gate($previous,$metadata,$source);
            $path=Config::root().'/private/integrations/state/diadoc/'.DiadocMountedProfile::REFERENCE.'/pending.json';
            if(!is_file($path)||is_link($path)||filesize($path)>65536)throw new RuntimeException('DIADOC_OLD_PENDING_REQUIRED');
            $pending=Support::decode(file_get_contents($path));
            if(($pending['state']??null)!=='awaiting_confirmation'||!is_int($pending['created_at']??null)||time()-$pending['created_at']<=600)throw new RuntimeException('DIADOC_OLD_PENDING_REQUIRED');
            $profile=Config::profile('diadoc',DiadocMountedProfile::REFERENCE);
            if(($profile['_source_receipt_sha256']??null)!==$source['source_receipt_sha256'])throw new RuntimeException('DIADOC_PROFILE_CAS_CONFLICT');
            $history=$profile['_expired_pending_history'][$previous['id']]??null;
            if($history!==null&&$history['pending']!==$pending)throw new RuntimeException('DIADOC_OLD_PENDING_CHANGED');
            if($history===null){$profile['_expired_pending_history'][$previous['id']]=['pending'=>$pending,'saved_at'=>time(),'cause'=>'source-equivalent-existing-status404'];Config::saveProfile('diadoc',DiadocMountedProfile::REFERENCE,$profile);}
            unset($profile,$pending);
            // Existing ReadOnlyCheck reserves the fixed request before any request.
            // The native normal WEB path retains cookie-first behavior, never an API-key path.
            $result=(new ReadOnlyCheck($this->db,$this->store))->run('diadoc',self::REQUEST,false,3);
            $result+=['revision'=>3,'old_pending_preserved_encrypted'=>true,'normal_web_flows'=>1];
            if(($result['reason_code']??null)==='PUSH_REQUIRED'){
                $new=Support::decode(file_get_contents($path));
                $result['new_push_created_at']=date(DATE_ATOM,$new['created_at']);$result['new_push_local_deadline']=date(DATE_ATOM,$new['created_at']+600);$result['new_pushes']=1;
            }else $result['new_pushes']=0;
            return $result;
        }finally{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
}
