<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\PagePolicy;
use Modules\Rental\{HandoverQuestion,Service,WorkflowEvents};
use PDO;
use RuntimeException;

/** Current-DB contract proof. Legacy R2 rollout selection and dispatch stay unchanged. */
final class CurrentContractMaxProof
{
    public function __construct(private PDO $db) {}
    public static function select(array $event): string
    {
        if(($event['project_id']??null)!==WorkflowEvents::PROJECT||($event['entity_kind']??null)!=='contract'
            ||!is_int($event['event_id']??null)||$event['event_id']<1)
            throw new RuntimeException('CURRENT_CONTRACT_MAX_EVENT_INVALID');
        $kind=$event['event_kind']??null;
        if ($kind === HandoverQuestion::EVENT) {
            if (!in_array($event['action'] ?? null, [HandoverQuestion::OPEN, HandoverQuestion::RETRY], true)
                || ($event['contract_id'] ?? null) !== ($event['entity_id'] ?? null)
                || ($event['target_role'] ?? null) !== 'accountant'
                || ($event['from_status'] ?? null) !== ($event['to_status'] ?? null)) {
                throw new RuntimeException('RENTAL_MAX_TRANSITION_PROOF_INVALID');
            }
            return $kind;
        }
        if(!in_array($kind,['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','activated','block','unblock','cancelled'],true))
            throw new RuntimeException('RENTAL_MAX_EVENT_NOT_PLANNED');
        if(!in_array($event['entity_kind']??null,['contract','document','party'],true)
            ||!preg_match('/^(?:(?:contract|document)\.(?:create|edit|submit|legal|return|forward|packet|sign|apply)|contract\.cancel|party\.(?:block|unblock))$/D',$event['action']??''))
            throw new RuntimeException('RENTAL_MAX_ACTION_NOT_PLANNED');
        [$entity,$operation]=explode('.',$event['action']);
        $valid=match($kind){
            'submit','resubmit'=>in_array($operation,['create','edit','submit'],true)&&($event['to_status']??null)==='legal',
            'legalApprove'=>$operation==='legal'&&($event['to_status']??null)==='accounting',
            'lawyerReturn','accountantReturn'=>in_array($operation,['return','forward'],true)&&($event['to_status']??null)==='returned',
            'package'=>$operation==='packet'&&($event['to_status']??null)==='approved',
            'signed'=>$operation==='sign'&&in_array($event['to_status']??null,['signed','active'],true),
            'activated'=>$operation==='apply',
            'block','unblock'=>$entity==='party'&&$operation===$kind&&($event['party_id']??null)===($event['entity_id']??null)
                &&($event['entity_id']??null)===9000001&&array_key_exists('contract_id',$event)&&$event['contract_id']===null
                &&($event['from_status']??null)===($kind==='block'?'unblocked':'blocked')&&($event['to_status']??null)===($kind==='block'?'blocked':'unblocked'),
            'cancelled'=>$entity==='contract'&&$operation==='cancel'&&($event['from_status']??null)!=='cancelled'&&($event['to_status']??null)==='cancelled',default=>false};
        if($entity!==$event['entity_kind']||!$valid)throw new RuntimeException('RENTAL_MAX_TRANSITION_PROOF_INVALID');
        return $kind==='submit'&&$event['entity_kind']==='document'?'addendum':$kind;
    }

    public function plan(int $eventId,string $groupId,int $revision,int $catalogVersion): array
    {
        if($this->db->inTransaction())throw new RuntimeException('RENTAL_MAX_AFTER_COMMIT_ONLY');
        if($eventId<1||$revision!==6||$catalogVersion<1
            ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$groupId))
            throw new RuntimeException('RENTAL_MAX_SELECTOR_INVALID');
        $q=$this->db->prepare('SELECT * FROM rental_events WHERE id=?');$q->execute([$eventId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $event=$row?WorkflowEvents::decode($row):null;
        if(!$event)throw new RuntimeException('RENTAL_MAX_COMMITTED_EVENT_REQUIRED');
        $key=self::select($event);
        $epoch=(string)$this->db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        if($epoch!==$event['epoch']||(int)$row['actor_id']!==(int)$event['actor_id']
            ||$row['action']!==$event['action']||($row['contract_id']===null)!==($event['contract_id']===null)
            ||($row['contract_id']!==null&&(int)$row['contract_id']!==(int)$event['contract_id']))
            throw new RuntimeException('RENTAL_MAX_EVENT_BINDING_CHANGED');
        $q=$this->db->prepare('SELECT * FROM rental_commands WHERE command_key=?');$q->execute([$event['command_key']]);$command=$q->fetch(PDO::FETCH_ASSOC);
        if(!$command||$command['action']!==$event['action']||!hash_equals($event['command_fingerprint'],$command['fingerprint'])
            ||!hash_equals($event['command_result_sha256'],hash('sha256',$command['result_json'])))
            throw new RuntimeException('RENTAL_MAX_COMMAND_PROOF_CHANGED');
        $party=$event['entity_kind']==='party';$table=$party?'parties':($event['entity_kind']==='contract'?'contracts':'rental_documents');
        $q=$this->db->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$event['entity_id']]);$owner=$q->fetch(PDO::FETCH_ASSOC);
        if(!$owner||(int)$owner['version']!==(int)$event['entity_version']||($party?(!empty($owner['blocked'])?'blocked':'unblocked'):$owner['status'])!==$event['to_status'])
            throw new RuntimeException('RENTAL_MAX_EVENT_OWNER_CHANGED');
        if ($key === HandoverQuestion::EVENT) {
            $question = HandoverQuestion::read($this->db, $event['entity_id']);
            if (!$question || !empty($question['resolution']) || $question['notification_command'] !== $event['command_key']) {
                throw new RuntimeException('RENTAL_MAX_EVENT_OWNER_CHANGED');
            }
        }
        $q=$this->db->prepare("SELECT u.id,u.active,u.role role_id,u.auth_version,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?");
        $q->execute([$event['actor_id']]);$actor=$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('RENTAL_MAX_ACTOR_MISSING');
        $permission=function(string $grant)use($actor):bool{
            $q=$this->db->prepare('SELECT 1 FROM role_permissions WHERE role_id=? AND (permission_key=? OR permission_key=?) LIMIT 1');
            $q->execute([$actor['role_id'],$grant,'*']);return (bool)$q->fetchColumn();
        };
        $level=PagePolicy::levelForRole((string)$actor['role_id'],(string)$actor['role'],!empty($actor['grants_configured']),PagePolicy::commandPage($event['action']));
        WorkflowMaxEvent::assertActor($event,$actor,$permission('rental.read'),$permission(Service::commandGrant($event['action'],$actor)),$level);
        $connection=Config::get($this->db,'max');
        if(!$connection['enabled']||(int)$connection['revision']!==$revision)throw new RuntimeException('RENTAL_MAX_REVISION_CHANGED');
        $q=$this->db->prepare("SELECT g.id,g.name,g.chat_id,b.source_variant,b.source_group_id FROM messaging_groups g JOIN integration_group_bindings b ON b.provider='max' AND b.local_group_id=g.id WHERE g.id=? AND g.provider='max' AND g.enabled=1");
        $q->execute([$groupId]);$groups=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($groups)!==1||$groups[0]['name']!=='ТЕСТОВАЯ')throw new RuntimeException('RENTAL_MAX_APPROVED_GROUP_REQUIRED');
        $q=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='max'");$source=$q->fetch(PDO::FETCH_ASSOC);
        if(!$source||(int)$source['source_revision']!==$revision)
            throw new RuntimeException('RENTAL_MAX_SOURCE_BINDING_CHANGED');
        $people=array_fill_keys(['manager','lawyer','accountant','director'],'');
        foreach($this->db->query("SELECT u.name,rp.kind FROM users u JOIN role_profiles rp ON rp.role_id=u.role WHERE u.active=1 ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC) as $person){
            if(array_key_exists($person['kind'],$people))$people[$person['kind']].=($people[$person['kind']]!==''?', ':'').$person['name'];
        }
        if($event['target_role']!==null&&($people[$event['target_role']]??'')==='')throw new RuntimeException('RENTAL_MAX_RESPONSIBLE_UNRESOLVED');
        $values=array_fill_keys(TemplateCatalog::MESSAGE_VARS,'');
        $values=array_replace($values,$people,['text'=>$event['text'],'number'=>$event['contract_number'],'party'=>$event['party_name'],
            'actor'=>$event['actor_name'],'version'=>(string)$event['entity_version'],'reason'=>$event['reason'],
            'documentNumber'=>$event['record_number'],'documentLabel'=>$party?'Контрагент '.$event['party_name']:($event['entity_kind']==='document'?'Документ':'Договор').' №'.$event['record_number'],
            'context'=>$party?'Контрагент '.$event['party_name']:'Договор №'.$event['contract_number'].' · '.$event['party_name'],'recipientNames'=>$event['target_role']===null?'':$people[$event['target_role']]]);
        if ($key === HandoverQuestion::EVENT) {
            $values['recordUrl']='https://ui.89.208.213.14.sslip.io/rental/contract?id='.$event['entity_id'];
        }
        $render=TemplateCatalog::renderEvent($this->db,'max',$key,$values);
        if($render['binding']['version']!==$catalogVersion)throw new RuntimeException('RENTAL_MAX_CATALOG_REVISION_CHANGED');
        // Word/manual mode cannot silently tell a user to run the deferred automatic PDF step.
        if(preg_match('/Сформируйте\s+PDF/ui',$render['body']))throw new RuntimeException('RENTAL_MAX_WORD_MODE_CATALOG_UPDATE_REQUIRED');
        $plan=['schema'=>1,'event'=>$event,'group_id'=>$groupId,'revision'=>$revision,'catalog'=>$render,
            'group_binding'=>array_diff_key($groups[0],['chat_id'=>true]),'source'=>$source,
            'request_key'=>ServerWorkflowMax::requestKey($event),'attachments'=>[],'external_scope'=>'max-one-selected-rental-event'];
        $plan['sha256']=hash('sha256',Support::canonical($plan));
        return $plan;
    }

}
