<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\Auth;
use Modules\Rental\{HandoverQuestion,StaffHandoff,WorkflowEvents};
use PDO;
use RuntimeException;

/** Operator opt-in: one committed contract workflow transition, one TEST destination, one POST. */
final class ServerWorkflowMax
{
    private ?array $event=null;
    private ?array $scope=null;
    private ?string $pending=null;
    private int $sent=0;
    public function __construct(private PDO $db,private bool $staff=false) {}

    public function caFile(): string
    {
        $path=Config::root().'/.local/certs/windows-trusted-roots.pem';
        if (is_link($path)||!is_file($path)||!is_readable($path)
            ||!hash_equals('b846c5f49517b6de22fcfaa239a9c0561185f22cb9ffc7e2b39ef8fb806c59e0',hash_file('sha256',$path)))
            throw new RuntimeException('WORKFLOW_MAX_CA_REJECTED');
        return $path;
    }

    public static function validate(mixed $m): void
    {
        if(!is_array($m)||($m['scope']??null)!=='contract-workflow'||!is_array($m['catalog_versions']??null)
            ||array_diff(array_keys($m['catalog_versions']),self::KINDS))throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        foreach(self::KINDS as $key)if(!is_int($m['catalog_versions'][$key]??null)||$m['catalog_versions'][$key]<1)throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        if (array_key_exists('handover_question', $m)) {
            $question = $m['handover_question'];
            if (!is_array($question) || count($question)!==2
                || !is_int($question['event_floor'] ?? null) || $question['event_floor'] < ($m['event_floor'] ?? PHP_INT_MAX)
                || !is_int($question['catalog_version'] ?? null) || $question['catalog_version'] < 1) {
                throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
            }
        }
        $base=$m;unset($base['catalog_versions'],$base['handover_question']);$base['scope']='object-activity';ServerObjectMax::validate($base);
    }

    private const KINDS=['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'];
    public const STAFF_SEQUENCE='staff-handoff-sequence';
    public const RECONCILIATION_REQUIRED='WORKFLOW_MAX_RECONCILIATION_REQUIRED';

    public static function requestKey(array $event,bool $staff=false):string
    {
        if ($staff) {
            return 'contract-workflow-staff-'.hash('sha256',$event['epoch'].':'.$event['command_key'].':'.$event['event_id']);
        }
        if (($event['event_kind'] ?? null) === HandoverQuestion::EVENT) {
            return 'handover-question-'.hash('sha256',$event['epoch'].':'.$event['command_key'].':'.$event['event_id']);
        }
        return 'contract-workflow-'.hash('sha256',$event['command_key'].':'.$event['event_id']);
    }

    public static function validateStaff(mixed $scope): void
    {
        if (!is_array($scope) || !in_array($scope['scope']??null,['staff-handoff',self::STAFF_SEQUENCE],true)
            || !is_int($scope['catalog_version']??null) || $scope['catalog_version']<1)
            throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
        if ($scope['scope']===self::STAFF_SEQUENCE) {
            self::validateStaffSequence($scope);
            unset($scope['contracts'],$scope['actions'],$scope['epoch'],$scope['valid_from'],$scope['expires_at']);
        } else {
            if (!is_int($scope['contract_id']??null) || $scope['contract_id']<1
                || !is_int($scope['actor_id']??null) || $scope['actor_id']<1
                || !is_string($scope['action']??null) || !StaffHandoff::managed($scope['action'])
                || !is_string($scope['command_key']??null) || !preg_match('/\A[a-zA-Z0-9-]{16,100}\z/',$scope['command_key']))
                throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
            if (array_key_exists('isolated_probe',$scope)) {
                StaffHandoffProbe::validate($scope['isolated_probe']);
                if ($scope['action']!=='contract.submit' || $scope['actor_id']===$scope['isolated_probe']['recipient_id']) {
                    throw new RuntimeException('STAFF_HANDOFF_PROBE_ADMISSION_INVALID');
                }
                unset($scope['isolated_probe']);
            }
            unset($scope['contract_id'],$scope['actor_id'],$scope['action'],$scope['command_key']);
        }
        unset($scope['catalog_version']);
        $scope['scope']='object-activity';
        ServerObjectMax::validate($scope);
    }

    private static function validateStaffSequence(array $scope): void
    {
        if (!is_array($scope['contracts']??null) || !array_is_list($scope['contracts']) || !$scope['contracts']
            || !is_array($scope['actions']??null) || !array_is_list($scope['actions']) || !$scope['actions']
            || !is_string($scope['epoch']??null) || !preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/',$scope['epoch'])
            || !is_int($scope['valid_from']??null) || $scope['valid_from']<1
            || !is_int($scope['expires_at']??null) || $scope['expires_at']<=$scope['valid_from'])
            throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
        $ids=[];
        foreach ($scope['contracts'] as $contract) {
            if (!is_array($contract) || count($contract)!==2 || !is_int($contract['id']??null) || $contract['id']<1
                || isset($ids[$contract['id']]) || !is_string($contract['number']??null)
                || trim($contract['number'])!==$contract['number'] || $contract['number']===''
                || mb_strlen($contract['number'])>100 || preg_match('/\p{C}/u',$contract['number'])!==0)
                throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
            $ids[$contract['id']]=true;
        }
        $actions=[];
        foreach ($scope['actions'] as $action) {
            if (!is_string($action) || !StaffHandoff::managed($action) || isset($actions[$action]))
                throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
            $actions[$action]=true;
        }
    }

    /** Select the route before checking whether a selected delivery is still allowed. */
    public static function staffSelects(array $scope,array $event): bool
    {
        if (($event['event_id']??0)<=$scope['event_floor']) return false;
        if (($scope['scope']??null)!==self::STAFF_SEQUENCE) {
            return $event['contract_id']===$scope['contract_id'] && $event['actor_id']===$scope['actor_id']
                && $event['action']===$scope['action'] && $event['command_key']===$scope['command_key'];
        }
        if (empty($event['staff_handoff']) || !in_array($event['action'],$scope['actions'],true)) return false;
        foreach ($scope['contracts'] as $contract) {
            if ($event['contract_id']===$contract['id'] && $event['contract_number']===$contract['number']) return true;
        }
        return false;
    }

    public static function staffAdmits(array $scope,array $event,?int $now=null): bool
    {
        if (!self::staffSelects($scope,$event)) return false;
        if (isset($scope['isolated_probe'])) return StaffHandoffProbe::admits($scope['isolated_probe'],$event,$now??time());
        if (($scope['scope']??null)!==self::STAFF_SEQUENCE) return true;
        $now??=time();
        return $event['epoch']===$scope['epoch'] && $now>=$scope['valid_from'] && $now<$scope['expires_at'];
    }

    public static function selectedStaffEvent(PDO $db,int $eventId): bool
    {
        $scope=self::staffSettings();
        if ($scope===null || $eventId<=$scope['event_floor']) return false;
        $event=self::readEvent($db,$eventId);
        return $event!==null && self::staffSelects($scope,$event);
    }

    private static function readEvent(PDO $db,int $eventId): ?array
    {
        $q=$db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');
        $q->execute([$eventId]);
        $row=$q->fetch();
        return $row?WorkflowEvents::decode($row):null;
    }

    public static function assertLane(PDO $db,array $event,bool $staff): void
    {
        if (!$staff && self::selectedStaffEvent($db,$event['event_id']))
            throw new RuntimeException('STAFF_HANDOFF_LEGACY_ROUTE_FORBIDDEN');
        $keys=$staff ? [self::requestKey($event),WorkflowMaxEvent::requestKey($event)] : [self::requestKey($event,true),self::requestKey($event,true)];
        $q=$db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key IN (?,?) LIMIT 1");
        $q->execute([$event['actor_id'],...$keys]);
        if ($q->fetchColumn()) throw new RuntimeException('STAFF_HANDOFF_ROUTE_ALREADY_CLAIMED');
    }

    public function plan(int $eventId,string $commandKey,array $actor):?array
    {
        $scope=$this->context();
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('WORKFLOW_MAX_POST_REQUIRED');
        $event=self::readEvent($this->db,$eventId);
        if (!$event || $eventId<=$scope['event_floor']) return null;
        if ($this->staff) {
            if (!self::staffAdmits($scope,$event) || empty($event['staff_handoff'])) return null;
            if (isset($scope['isolated_probe'])) {
                StaffHandoffProbe::assertReviewer($this->db,$scope['isolated_probe']['recipient_id'],$event['staff_handoff']['targets'][0]);
            }
            $version=$scope['catalog_version'];
        } elseif ($event['entity_kind']!=='contract') {
            return null;
        } elseif ($event['event_kind'] === HandoverQuestion::EVENT) {
            $question = $scope['handover_question'] ?? null;
            if ($question === null || $eventId <= $question['event_floor']) return null;
            $version = $question['catalog_version'];
        } else {
            $version = $scope['catalog_versions'][$event['event_kind']] ?? null;
            if ($version === null) return null;
        }
        $current=Auth::user();
        if(!$current||(int)$current['id']!==(int)($actor['id']??0)||$event['actor_id']!==(int)$current['id']
            ||$event['auth_version']!==(int)$current['auth_version']||$event['command_key']!==$commandKey)throw new RuntimeException('WORKFLOW_MAX_ACTOR_CHANGED');
        self::assertLane($this->db,$event,$this->staff);
        $proof=(new CurrentContractMaxProof($this->db))->plan($eventId,$scope['group_id'],$scope['revision'],$version,$this->staff);
        return ['event_id'=>$eventId,'command_key'=>$commandKey,'actor_id'=>$event['actor_id'],'text'=>$proof['catalog']['body'],
            'proof_sha256'=>$proof['sha256'],'request_key'=>$proof['request_key']];
    }

    public function afterCommittedCommand(int $eventId,string $commandKey,array $actor):array
    {
        $event=$this->plan($eventId,$commandKey,$actor);
        if($event===null)return ['status'=>'skipped'];
        $attempts=$this->dispatch($event);$a=$attempts[0]??[];
        return ['status'=>$a['status']??'uncertain','code'=>$a['result_code']??'NO_RECEIPT','attempt_id'=>$a['id']??null];
    }

    public static function settings(): ?array
    {
        if (PHP_OS_FAMILY!=='Linux'||getenv('APP_STAND')!=='ui') return null;
        $path=Config::root().'/.local/workflow-max-admission.json';
        if (!file_exists($path)) return null;
        if (is_link($path)||!is_readable($path)||filesize($path)>4096) throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        $m=Support::decode(file_get_contents($path));self::validate($m);return $m;
    }

    public static function staffSettings(): ?array
    {
        if (PHP_OS_FAMILY!=='Linux'||getenv('APP_STAND')!=='ui') return null;
        $path=Config::root().'/.local/staff-handoff-max-admission.json';
        if (!file_exists($path)) return null;
        if (is_link($path)||!is_readable($path)||filesize($path)>4096) throw new RuntimeException('STAFF_HANDOFF_ADMISSION_INVALID');
        $scope=Support::decode(file_get_contents($path));self::validateStaff($scope);return $scope;
    }

    private function context(): array
    {
        $m=$this->staff?self::staffSettings():self::settings();
        if ($m===null||$this->db->inTransaction()||$this->db->query('SELECT DATABASE()')->fetchColumn()!==$m['database']) throw new RuntimeException('WORKFLOW_MAX_SCOPE_REJECTED');
        self::assertProfile($this->db,$m);
        return $m;
    }

    public static function assertProfile(PDO $db,array $m): void
    {
        $c=Config::get($db,'max');$secret=Config::secrets('max');Config::assertSecretRevision($secret,$m['revision']);
        $path=Config::root().'/private/integrations/max.enc';
        if (!$c['enabled']||(int)$c['revision']!==$m['revision']||($secret['_owner_bound_revision'] ?? null)!==$m['revision']
            ||($secret['approved_chat_ids'] ?? null)!==[$m['chat_id']]||empty($secret['secret'])||is_link($path)
            ||!hash_equals($m['vault_sha256'],hash_file('sha256',$path))) throw new RuntimeException('WORKFLOW_MAX_PROFILE_CHANGED');
        $q=$db->prepare("SELECT name,chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 AND id=?");$q->execute([$m['group_id']]);$group=$q->fetch();
        if (!$group||$group['name']!==$m['group_name']||(string)$group['chat_id']!==$m['chat_id']) throw new RuntimeException('WORKFLOW_MAX_GROUP_CHANGED');
    }

    public function dispatch(array $event): array
    {
        $scope=$this->context();
        $proof=$this->plan($event['event_id'],$event['command_key'],Auth::user() ?? []);
        if ($proof!==$event||$event['event_id']<=$scope['event_floor']) throw new RuntimeException('WORKFLOW_MAX_EVENT_REJECTED');
        $sequence=$this->staff && $scope['scope']===self::STAFF_SEQUENCE;
        $probe=$this->staff && isset($scope['isolated_probe']);
        $lock=$this->db->prepare("SELECT GET_LOCK('ipphp-provider:max',?)");
        $lock->execute([$sequence?5:0]);
        if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('WORKFLOW_MAX_BUSY');
        try {
            if (!$probe) $this->assertSlot($event);
            if ($sequence) $this->waitForTarget($scope['chat_id'],$event['request_key']);
            if ($this->context()!==$scope || $this->plan($event['event_id'],$event['command_key'],Auth::user() ?? [])!==$event)
                throw new RuntimeException('WORKFLOW_MAX_EVENT_REJECTED');
            if ($probe) $this->claimProbe($scope,$event);
            $this->scope=$scope;$this->event=$event;$this->sent=0;$this->pending=null;
            $transport=fn($p,$o,$c,$s,$t,$r,$id,$g)=>(new MessageTransport(null,null,null,$this))->execute($p,$o,$c,$s,$t,$r,$id,$g);
            return (new Messaging($this->db,Config::store(),$transport))->dispatch('max',$event['actor_id'],$event['request_key'],'send',[$scope['group_id']],$event['text']);
        } finally {$this->event=null;$this->scope=null;$this->pending=null;$this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    }

    private function claimProbe(array $scope,array $event): void
    {
        $committed=self::readEvent($this->db,$event['event_id']);
        if (!$committed) throw new RuntimeException('STAFF_HANDOFF_PROBE_EVENT_CHANGED');
        (new StaffHandoffProbe($this->db,Config::store()))->claim($scope,$committed+['request_key'=>$event['request_key']]);
    }

    private function assertSlot(array $event): void
    {
        // Keep the original workflow fence. The staff key shares its prefix,
        // so uncertainty in either route stops new sends in both routes.
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max'
            AND (status='pending' OR (status='uncertain' AND request_key LIKE 'contract-workflow-%'))
            AND NOT(actor_id=? AND request_key=?) LIMIT 1");
        $q->execute([$event['actor_id'],$event['request_key']]);
        if ($q->fetchColumn()) throw new RuntimeException(self::RECONCILIATION_REQUIRED);
    }

    private function waitForTarget(string $chat,string $key): void
    {
        // Respect Messaging's existing one-second target interval without a job or replay.
        $q=$this->db->prepare("SELECT TIMESTAMPDIFF(MICROSECOND,NOW(6),MAX(created_at)+INTERVAL 1 SECOND)
            FROM messaging_attempts WHERE provider='max' AND target=? AND operation='send' AND request_key<>?
            AND created_at>DATE_SUB(NOW(6),INTERVAL 1 SECOND)");
        $q->execute([$chat,$key]);
        $remaining=(int)$q->fetchColumn();
        if ($remaining>0) usleep($remaining);
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        if (!$this->event||!$this->scope||$this->context()!==$this->scope||$provider!=='max'||$operation!=='send'
            ||$target!==$this->scope['chat_id']||$groups!==[$target]||$raw!==Support::json(['text'=>$this->event['text']])) throw new RuntimeException('WORKFLOW_MAX_REQUEST_REJECTED');
        $proof=$this->plan($this->event['event_id'],$this->event['command_key'],Auth::user() ?? []);
        if ($proof!==$this->event) throw new RuntimeException('WORKFLOW_MAX_EVENT_REJECTED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? AND target=? AND revision=? AND status='pending' AND body_text=?");
        $q->execute([$this->event['actor_id'],$this->event['request_key'],$target,$this->scope['revision'],$this->event['text']]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('WORKFLOW_MAX_CLAIM_REQUIRED');
    }

    public static function assertPath(int $sent,string $method,string $url,?string $body,string $chat,string $text): void
    {
        if ($sent!==0||$method!=='POST'||$url!=='https://platform-api2.max.ru/messages?chat_id='.rawurlencode($chat)
            ||$body!==Support::json(['text'=>$text])) throw new RuntimeException('WORKFLOW_MAX_HTTP_REJECTED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertHttpScope($method,'https://platform-api2.max.ru'.$path,$body);$this->pending=$body;
    }
    private function assertHttpScope(string $method,string $url,?string $body): void
    {
        if (!$this->event||!$this->scope) throw new RuntimeException('WORKFLOW_MAX_SCOPE_REJECTED');
        self::assertPath($this->sent,$method,$url,$body,$this->scope['chat_id'],$this->event['text']);
        $this->assertOperation('max','send',$this->scope['chat_id'],(string)$body,[$this->scope['chat_id']]);
    }
    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        $this->assertHttpScope($method,$url,$body);
        if ($body!==$this->pending) throw new RuntimeException('WORKFLOW_MAX_HTTP_REJECTED');
        $this->sent++;$this->pending=null;
    }
}
