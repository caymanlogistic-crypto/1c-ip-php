<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\PagePolicy;
use Modules\Rental\{Service,WorkflowEvents,WorkflowNotices};
use PDO;
use RuntimeException;

/** One explicitly selected, committed rental event; no worker scan or client message input. */
final class WorkflowMaxEvent
{
    public const ADMISSION='owner-authorized-new-rental-events-text-only';
    public const POSTCOMMIT_ADMISSION='owner-authorized-new-test-workflow-events';
    private ?array $armed=null;
    private ?array $armedSecret=null;
    private ?array $postcommit=null;

    public function __construct(private PDO $db) {}

    public static function postcommitModeAllowed(string $sapi,string|false $gate): bool
    {
        return $sapi==='cli-server'&&$gate===self::POSTCOMMIT_ADMISSION;
    }

    private static function path(string $path):string
    {
        $path=rtrim(str_replace('\\','/',$path),'/');
        return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;
    }

    public static function validatePostcommitMarker(array $marker,string $root): array
    {
        $keys=['schema','mode','enabled','project_id','app_root','epoch','event_floor','entities','max_revision','group_id','catalog_versions','db_config_file','db_config_sha256'];
        if(array_diff(array_keys($marker),[...$keys,'future_contracts'])||array_diff($keys,array_keys($marker))
            ||$marker['schema']!==1||$marker['mode']!==self::POSTCOMMIT_ADMISSION||$marker['enabled']!==true
            ||$marker['project_id']!==WorkflowEvents::PROJECT||!is_string($marker['app_root'])||self::path($marker['app_root'])!==self::path($root)
            ||$marker['epoch']!==WorkflowNotices::EPOCH||!is_int($marker['event_floor'])||$marker['event_floor']<WorkflowNotices::BASELINE
            ||$marker['max_revision']!==6||!is_string($marker['group_id'])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$marker['group_id'])
            ||!is_string($marker['db_config_file'])||dirname(self::path($marker['db_config_file']))!==self::path($root).'/.local'
            ||!is_string($marker['db_config_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$marker['db_config_sha256']))
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        if(!is_array($marker['entities'])||!array_is_list($marker['entities'])||!$marker['entities']||count($marker['entities'])>32)
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        $seen=[];
        foreach($marker['entities'] as $entity){
            if(!is_array($entity)||array_diff(array_keys($entity),['kind','id'])||!in_array($entity['kind']??null,['contract','document','party'],true)
                ||!is_int($entity['id']??null)||$entity['id']<1||isset($seen[$entity['kind'].':'.$entity['id']]))
                throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
            if($entity['kind']==='party'&&$entity['id']!==9000001)throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
            $seen[$entity['kind'].':'.$entity['id']]=true;
        }
        $catalog=$marker['catalog_versions'];
        if(!is_array($catalog)||!$catalog||array_is_list($catalog)||array_diff(array_keys($catalog),['submit','addendum','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','activated','block','unblock','cancelled']))
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        foreach($catalog as $version)if(!is_int($version)||$version<1)throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        if(array_key_exists('future_contracts',$marker)){
            $future=$marker['future_contracts'];
            if(!is_array($future)||count($future)!==2||array_diff(array_keys($future),['enabled','after_contract_id'])
                ||!is_bool($future['enabled']??null)||!is_int($future['after_contract_id']??null)||$future['after_contract_id']<1)
                throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        }
        return $marker;
    }

    private function postcommitMarker():array
    {
        $root=realpath(Config::root());$path=Config::root().'/.local/rental-max-postcommit.json';
        $sha=getenv('IPPHP_RENTAL_MAX_POSTCOMMIT_MARKER_SHA256');
        if(!$root||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||is_link($path)||!is_file($path)||filesize($path)>16384
            ||self::path((string)realpath($path))!==self::path($root.'/.local/rental-max-postcommit.json'))
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_REQUIRED');
        for($parent=dirname($path);strlen($parent)>3;$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_INVALID');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||!hash_equals($sha,hash('sha256',$bytes)))throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_MARKER_CHANGED');
        $marker=self::validatePostcommitMarker(Support::decode($bytes),$root);
        $config=$marker['db_config_file'];
        if(getenv('APP_DB_CONFIG_FILE')!==$config||is_link($config)||!is_file($config)
            ||self::path((string)realpath($config))!==self::path($config)||!hash_equals($marker['db_config_sha256'],hash_file('sha256',$config)))
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_CONFIG_CHANGED');
        return $marker;
    }

    public static function postcommitEventAllowed(array $marker,array $event,?int $actualParentContractId=null): bool
    {
        if(($event['project_id']??null)!==$marker['project_id']||($event['epoch']??null)!==$marker['epoch']
            ||!is_int($event['event_id']??null)||$event['event_id']<=$marker['event_floor'])return false;
        if(($event['entity_kind']??null)==='party'&&($event['entity_id']??null)!==9000001)return false;
        foreach($marker['entities'] as $entity)if($entity['kind']===($event['entity_kind']??null)&&$entity['id']===($event['entity_id']??null))return true;
        $future=$marker['future_contracts']??null;
        if(($future['enabled']??false)!==true||$actualParentContractId===null||$actualParentContractId<=$future['after_contract_id']
            ||($event['contract_id']??null)!==$actualParentContractId||!is_int($event['entity_id']??null)||$event['entity_id']<1)return false;
        $kind=$event['entity_kind']??null;
        return ($kind==='contract'&&$event['entity_id']===$actualParentContractId)||$kind==='document';
    }

    private function postcommitParent(array $event):?int
    {
        $sql=match($event['entity_kind']??null){
            'contract'=>'SELECT id FROM contracts WHERE id=?',
            'document'=>'SELECT c.id FROM rental_documents d JOIN contracts c ON c.id=d.contract_id WHERE d.id=?',default=>null};
        if($sql===null)return null;
        $q=$this->db->prepare($sql);$q->execute([$event['entity_id']]);$id=$q->fetchColumn();
        return $id===false?null:(int)$id;
    }

    private function assertAdmission():void
    {
        if($this->postcommit===null){
            if(PHP_SAPI!=='cli'||getenv('IPPHP_RENTAL_MAX_EVENTS')!==self::ADMISSION)throw new RuntimeException('RENTAL_MAX_SCOPE_NOT_AUTHORIZED');
            return;
        }
        if(!self::postcommitModeAllowed(PHP_SAPI,getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'))||$this->postcommitMarker()!==$this->postcommit['marker'])
            throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_CONTEXT_CHANGED');
        $event=$this->postcommit['event'];
        if(!self::postcommitEventAllowed($this->postcommit['marker'],$event,$this->postcommitParent($event)))throw new RuntimeException('RENTAL_MAX_POSTCOMMIT_CONTEXT_CHANGED');
        $actor=\App\Auth::user();$original=$this->postcommit['actor'];
        if(!$actor||(int)$actor['id']!==(int)$original['id']||(int)$actor['auth_version']!==(int)$original['auth_version']
            ||($actor['role_id']??$actor['role'])!==($original['role_id']??$original['role']))throw new RuntimeException('RENTAL_MAX_ACTOR_CAPABILITY_CHANGED');
    }

    /** Called only with Service's freshly committed out-marker, never a request-selected event. */
    public function afterCommittedCommand(?int $freshEventId,string $commandKey,array $actor): array
    {
        if($freshEventId===null||!self::postcommitModeAllowed(PHP_SAPI,getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')))return ['status'=>'disabled'];
        if($this->db->inTransaction())throw new RuntimeException('RENTAL_MAX_AFTER_COMMIT_ONLY');
        $marker=$this->postcommitMarker();
        $q=$this->db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');$q->execute([$freshEventId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $event=$row?WorkflowEvents::decode($row):null;
        if(!$event||!self::postcommitEventAllowed($marker,$event,$this->postcommitParent($event)))return ['status'=>'skipped'];
        if($event['command_key']!==$commandKey||(int)$event['actor_id']!==(int)$actor['id']||(int)$event['auth_version']!==(int)$actor['auth_version'])
            throw new RuntimeException('RENTAL_MAX_COMMAND_PROOF_CHANGED');
        if(!in_array($event['event_kind'],['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','activated','block','unblock','cancelled'],true))return ['status'=>'skipped'];
        $key=self::select($event);
        if(!isset($marker['catalog_versions'][$key]))return ['status'=>'skipped'];
        $plan=$this->plan($freshEventId,$marker['group_id'],$marker['max_revision'],$marker['catalog_versions'][$key]);
        $this->postcommit=['marker'=>$marker,'actor'=>$actor,'event'=>$event];
        try{
            $attempts=$this->dispatch($plan,$plan['sha256']);$attempt=$attempts[0]??null;
            return ['status'=>$attempt['status']??'uncertain','code'=>$attempt['result_code']??'TRANSPORT_RESULT_UNKNOWN','attempt_id'=>$attempt['id']??null];
        }finally{$this->postcommit=null;}
    }

    public static function select(array $event): string
    {
        if(($event['project_id']??null)!==WorkflowEvents::PROJECT
            ||($event['epoch']??null)!==WorkflowNotices::EPOCH
            ||!is_int($event['event_id']??null)||$event['event_id']<=WorkflowNotices::BASELINE)
            throw new RuntimeException('RENTAL_MAX_EVENT_OUTSIDE_ROLLOUT');
        $kind=$event['event_kind']??null;
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

    public static function assertActor(array $event,array $actor,bool $read,bool $command,int $pageLevel): void
    {
        $roles=match($event['action']??''){
            'party.block'=>['manager','lawyer','accountant','director','admin'],
            'party.unblock'=>['director','admin'],
            'contract.cancel'=>['manager','director','admin'],default=>['manager','lawyer','accountant','admin']};
        if(empty($actor['active'])||(int)$actor['id']!==(int)$event['actor_id']
            ||(string)$actor['role_id']!==(string)$event['role_id']
            ||$actor['role']!==$event['actor_role']||(int)$actor['auth_version']!==(int)$event['auth_version']
            ||!in_array($actor['role'],$roles,true)
            ||!$read||!$command||(!empty($actor['grants_configured'])&&$pageLevel<2))
            throw new RuntimeException('RENTAL_MAX_ACTOR_CAPABILITY_CHANGED');
    }

    public static function requestKey(array $event): string
    {
        return 'rental-max-'.hash('sha256',$event['epoch'].':'.$event['event_id']);
    }

    public function plan(int $eventId,string $groupId,int $revision,int $catalogVersion): array
    {
        if($this->db->inTransaction())throw new RuntimeException('RENTAL_MAX_AFTER_COMMIT_ONLY');
        if($eventId<=WorkflowNotices::BASELINE||$revision!==6||$catalogVersion<1
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
        $q=$this->db->prepare("SELECT u.id,u.active,u.role role_id,u.auth_version,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?");
        $q->execute([$event['actor_id']]);$actor=$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('RENTAL_MAX_ACTOR_MISSING');
        $permission=function(string $grant)use($actor):bool{
            $q=$this->db->prepare('SELECT 1 FROM role_permissions WHERE role_id=? AND (permission_key=? OR permission_key=?) LIMIT 1');
            $q->execute([$actor['role_id'],$grant,'*']);return (bool)$q->fetchColumn();
        };
        $level=PagePolicy::levelForRole((string)$actor['role_id'],(string)$actor['role'],!empty($actor['grants_configured']),PagePolicy::commandPage($event['action']));
        self::assertActor($event,$actor,$permission('rental.read'),$permission(Service::commandGrant($event['action'],$actor)),$level);
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
        $render=TemplateCatalog::renderEvent($this->db,'max',$key,$values);
        if($render['binding']['version']!==$catalogVersion)throw new RuntimeException('RENTAL_MAX_CATALOG_REVISION_CHANGED');
        // Word/manual mode cannot silently tell a user to run the deferred automatic PDF step.
        if(preg_match('/Сформируйте\s+PDF/ui',$render['body']))throw new RuntimeException('RENTAL_MAX_WORD_MODE_CATALOG_UPDATE_REQUIRED');
        $plan=['schema'=>1,'event'=>$event,'group_id'=>$groupId,'revision'=>$revision,'catalog'=>$render,
            'group_binding'=>array_diff_key($groups[0],['chat_id'=>true]),'source'=>$source,
            'request_key'=>self::requestKey($event),'attachments'=>[],'external_scope'=>'max-one-selected-rental-event'];
        $plan['sha256']=hash('sha256',Support::canonical($plan));
        return $plan;
    }

    /** Called only by the dedicated transport instance while dispatching this exact claim. */
    public function assertRequest(string $provider,string $operation,string $target,string $raw): void
    {
        $this->assertAdmission();
        if(!$this->armed||$provider!=='max'||$operation!=='send'||$this->db->inTransaction())throw new RuntimeException('RENTAL_MAX_SCOPE_NOT_AUTHORIZED');
        $p=$this->armed;
        $current=$this->plan($p['event']['event_id'],$p['group_id'],$p['revision'],$p['catalog']['binding']['version']);
        if(!hash_equals($p['sha256'],$current['sha256'])||$raw!==Support::json(['text'=>$p['catalog']['body']]))throw new RuntimeException('RENTAL_MAX_CONTEXT_CHANGED');
        $q=$this->db->prepare("SELECT chat_id FROM messaging_groups WHERE id=? AND enabled=1 AND provider='max'");$q->execute([$p['group_id']]);
        if($target!==(string)$q->fetchColumn())throw new RuntimeException('RENTAL_MAX_TARGET_CHANGED');
        $secret=Config::secrets('max');Config::assertSecretRevision($secret,$p['revision']);
        if($secret!==$this->armedSecret||($secret['_owner_bound_revision']??null)!==$p['revision']||($secret['approved_chat_ids']??null)!==[$target])
            throw new RuntimeException('RENTAL_MAX_PRIVATE_BINDING_CHANGED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? AND operation='send' AND target=? AND revision=? AND body_text=? AND status='pending'");
        $q->execute([$p['event']['actor_id'],$p['request_key'],$target,$p['revision'],$p['catalog']['body']]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('RENTAL_MAX_COMMITTED_CLAIM_REQUIRED');
    }

    /** The lower HTTP guard admits no other host, route, method or request body. */
    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if($method!=='POST'||!is_string($body)
            ||!preg_match('~^https://platform-api2\.max\.ru/messages\?chat_id=(-?[0-9]{1,24})$~D',$url,$match))
            throw new RuntimeException('RENTAL_MAX_HTTP_SCOPE_REJECTED');
        $this->assertRequest('max','send',$match[1],$body);
    }

    public function dispatch(array $plan,string $expectedSha): array
    {
        $this->assertAdmission();
        $fresh=$this->plan($plan['event']['event_id'],$plan['group_id'],$plan['revision'],$plan['catalog']['binding']['version']);
        if(!hash_equals($expectedSha,$fresh['sha256'])||$plan!==$fresh)throw new RuntimeException('RENTAL_MAX_PLAN_CHANGED');
        if((int)$this->db->query('SELECT @@port')->fetchColumn()!==43061
            ||$this->db->query('SELECT DATABASE()')->fetchColumn()!=='ip_ya_php_scratch_core_f6e21e3c_d6dc6bc7_r2')
            throw new RuntimeException('RENTAL_MAX_LOCAL_R2_ONLY');
        $lock=(int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn();
        if($lock!==1)throw new RuntimeException('RENTAL_MAX_BUSY');
        try{
            foreach(['key','max.enc'] as $file){
                $path=Config::root().'/private/integrations/'.$file;
                if(is_link($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('RENTAL_MAX_EXISTING_VAULT_REQUIRED');
            }
            if(filesize(Config::root().'/private/integrations/key')!==32)throw new RuntimeException('RENTAL_MAX_EXISTING_VAULT_REQUIRED');
            $secret=Config::secrets('max');
            $q=$this->db->prepare("SELECT chat_id FROM messaging_groups WHERE id=? AND enabled=1 AND provider='max'");$q->execute([$fresh['group_id']]);$chat=$q->fetchColumn();
            if(($secret['_owner_bound_revision']??null)!==$fresh['revision']||($secret['approved_chat_ids']??null)!==[$chat])
                throw new RuntimeException('RENTAL_MAX_PRIVATE_BINDING_CHANGED');
            $this->armed=$fresh;
            $this->armedSecret=$secret;
            $transport=function($provider,$operation,$config,$secret,$target,$raw,$remoteId,$groups):array{
                if($secret!==$this->armedSecret)throw new RuntimeException('RENTAL_MAX_PRIVATE_BINDING_CHANGED');
                $guard=function()use($provider,$operation,$target,$raw):void{$this->assertRequest($provider,$operation,$target,$raw);};
                return (new MessageTransport(null,$guard,$this))->execute($provider,$operation,$config,$secret,$target,$raw,$remoteId,$groups);
            };
            return (new Messaging($this->db,Config::store(),$transport))->dispatch('max',(int)$fresh['event']['actor_id'],$fresh['request_key'],'send',[$fresh['group_id']],$fresh['catalog']['body'],'',[]);
        }finally{$this->armed=null;$this->armedSecret=null;$this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    }
}
