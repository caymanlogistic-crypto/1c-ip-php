<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\Auth;
use Modules\Rental\WorkflowEvents;
use PDO;
use RuntimeException;

/** Explicit worktree-only capability for new contract workflow events. Never scans a queue. */
final class LocalWorkflowMax
{
    public function __construct(private PDO $db) {}

    public static function configured():bool
    {
        return is_file(Config::root().'/.local/max-workflow-access.json');
    }

    public static function eventAllowed(array $scope,array $event):bool
    {
        return ($event['entity_kind']??null)==='contract'
            && is_int($event['event_id']??null) && $event['event_id']>$scope['event_floor']
            && (in_array($event['contract_id']??null,$scope['contract_ids'],true)
                || is_int($event['contract_id']??null)&&$event['contract_id']>$scope['future_contracts_after'])
            && in_array($event['event_kind']??null,['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'],true);
    }

    private function scope():array
    {
        $root=Config::root();$path=$root.'/.local/max-workflow-access.json';
        if(PHP_OS_FAMILY!=='Windows'||!in_array(PHP_SAPI,['cli','cli-server'],true)
            ||!LocalMaxReadCheck::configured()||Config::liveAllowed()||getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')
            ||!is_file($path)||is_link($path)||filesize($path)>16384)throw new RuntimeException('LOCAL_WORKFLOW_MAX_NOT_AUTHORIZED');
        $scope=Support::decode(file_get_contents($path));
        $transport=Support::decode(file_get_contents($root.'/.local/max-read-access.json'));
        $normalize=static fn(string $p):string=>strtolower(str_replace('\\','/',$p));
        if(($scope['kind']??null)!=='local-new-contract-workflow'
            ||($scope['enabled']??null)!==true||$normalize((string)($scope['app_root']??''))!==$normalize($root)
            ||($scope['database']??null)!==$this->db->query('SELECT DATABASE()')->fetchColumn()
            ||!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',(string)$scope['database'])
            ||!is_int($scope['event_floor']??null)||!is_int($scope['future_contracts_after']??null)
            ||!is_array($scope['contract_ids']??null)||!array_is_list($scope['contract_ids'])
            ||!is_string($transport['workflow_scope_sha256']??null)
            ||!hash_equals($transport['workflow_scope_sha256'],hash_file('sha256',$path)))
            throw new RuntimeException('LOCAL_WORKFLOW_MAX_CONTEXT_CHANGED');
        return $scope;
    }

    /** Reuses the existing event/command, role, current version and catalog proof validator. */
    public function plan(int $eventId,string $commandKey,array $actor):?array
    {
        $scope=$this->scope();
        if($this->db->inTransaction()||($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('LOCAL_WORKFLOW_MAX_AFTER_COMMIT_POST_ONLY');
        $q=$this->db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');$q->execute([$eventId]);$row=$q->fetch();
        $event=$row?WorkflowEvents::decode($row):null;
        if(!$event||!self::eventAllowed($scope,$event))return null;
        $current=Auth::user();
        if(!$current||(int)$current['id']!==(int)($actor['id']??0)
            ||$event['actor_id']!==(int)$current['id']||$event['auth_version']!==(int)$current['auth_version']
            ||$event['command_key']!==$commandKey)throw new RuntimeException('LOCAL_WORKFLOW_MAX_ACTOR_CHANGED');
        $key=WorkflowMaxEvent::select($event);
        $version=$scope['catalog_versions'][$key]??null;
        if(!is_int($version))throw new RuntimeException('LOCAL_WORKFLOW_MAX_CATALOG_CHANGED');
        $proof=(new WorkflowMaxEvent($this->db))->plan($eventId,$scope['group_id'],$scope['revision'],$version);
        $q=$this->db->prepare("SELECT chat_id FROM messaging_groups WHERE id=? AND provider='max' AND enabled=1 AND name='ТЕСТОВАЯ'");$q->execute([$scope['group_id']]);$chat=$q->fetchColumn();
        if(!is_string($chat))throw new RuntimeException('LOCAL_WORKFLOW_MAX_TEST_GROUP_REQUIRED');
        return ['event_id'=>$eventId,'command_key'=>$commandKey,'actor_id'=>$event['actor_id'],
            'group_id'=>$scope['group_id'],'chat_id'=>$chat,'text'=>$proof['catalog']['body'],'proof_sha256'=>$proof['sha256']];
    }

    public function afterCommittedCommand(int $eventId,string $commandKey,array $actor):array
    {
        $plan=$this->plan($eventId,$commandKey,$actor);
        if($plan===null)return ['status'=>'skipped'];
        $attempts=(new LocalMaxReadCheck($this->db))->dispatchWorkflow($plan);
        $attempt=$attempts[0]??[];
        return ['status'=>$attempt['status']??'uncertain','code'=>$attempt['result_code']??'NO_RECEIPT','attempt_id'=>$attempt['id']??null];
    }

    public static function requestKey(array $plan):string
    {
        // Separate from inherited attempts; the local database is part of the approved scope.
        return 'local-workflow-'.hash('sha256',Config::root().':'.$plan['event_id'].':'.$plan['command_key']);
    }
}
