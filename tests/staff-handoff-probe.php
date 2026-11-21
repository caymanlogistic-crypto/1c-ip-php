<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config,CurrentContractMaxProof,Messaging,ServerWorkflowMax,StaffHandoffObservation,StaffHandoffProbe,Support,TemplateCatalog};
use Modules\Rental\{Importer,Service,WorkflowEvents};

set_exception_handler(static function(Throwable $e): void {
    fwrite(STDERR,$e->getMessage().' (line '.$e->getLine().")\n");
    exit(1);
});

function check(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}

function deny(callable $work,string $reason): void {
    try {
        $work();
    } catch (RuntimeException $e) {
        check($e->getMessage()===$reason,'refused '.$reason.' actual '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Expected '.$reason);
}

$now=time();
$scope=['schema'=>1,'enabled'=>true,'project_id'=>WorkflowEvents::PROJECT,'scope'=>'staff-handoff',
    'stand'=>'ui','database'=>'ipphp_ui','revision'=>6,'group_id'=>Support::uuid(),'group_name'=>'ТЕСТОВАЯ',
    'chat_id'=>'-37','vault_sha256'=>str_repeat('a',64),'event_floor'=>42,'catalog_version'=>1,
    'contract_id'=>101,'actor_id'=>2,'action'=>'contract.submit','command_key'=>bin2hex(random_bytes(16)),
    'isolated_probe'=>['attempt_id'=>Support::uuid(),'receipt_sha256'=>str_repeat('b',64),'decision_ref'=>'owner-review-37',
        'owner_id'=>1,'recipient_id'=>3,'contract_number'=>'УЧ-MAX-37-TEST','epoch'=>Support::uuid(),
        'valid_from'=>$now,'expires_at'=>$now+600]];
$event=['event_id'=>43,'contract_id'=>101,'actor_id'=>2,'contract_number'=>'УЧ-MAX-37-TEST','action'=>'contract.submit',
    'command_key'=>$scope['command_key'],'epoch'=>$scope['isolated_probe']['epoch'],'entity_kind'=>'contract',
    'from_status'=>'draft','to_status'=>'legal','event_kind'=>'submit','actor_role'=>'manager',
    'staff_handoff'=>['targets'=>[['role'=>'lawyer','id'=>3]],'task'=>'Проверить договорные документы']];
ServerWorkflowMax::validateStaff($scope);
check(ServerWorkflowMax::staffAdmits($scope,$event,$now),'exact new submission admits the named lawyer');
foreach (['contract_id'=>102,'event_id'=>42,'actor_id'=>99,'epoch'=>Support::uuid(),'from_status'=>'returned',
    'contract_number'=>'УЧ-MAX-37-OTHER','command_key'=>str_repeat('a',16),
    'staff_handoff'=>['targets'=>[['role'=>'lawyer','id'=>null]]]] as $key=>$value) {
    check(!ServerWorkflowMax::staffAdmits($scope,array_replace($event,[$key=>$value]),$now),'wrong '.$key.' rejected');
}
check(!ServerWorkflowMax::staffAdmits($scope,$event,$now+601)
    && ServerWorkflowMax::staffSelects($scope,$event),'expiry prevents fall back on the selected event');
deny(fn()=>ServerWorkflowMax::validateStaff(array_replace($scope,['group_name'=>'OTHER'])),'OBJECT_MAX_ADMISSION_INVALID');
deny(fn()=>ServerWorkflowMax::validateStaff(array_replace($scope,['action'=>'document.submit'])),'STAFF_HANDOFF_PROBE_ADMISSION_INVALID');
$wide=$scope;
$wide['isolated_probe']['expires_at']=$now+901;
deny(fn()=>ServerWorkflowMax::validateStaff($wide),'STAFF_HANDOFF_PROBE_ADMISSION_INVALID');
$observed=($argv[1] ?? null)==='--observed-db';
if (!$observed && ($argv[1] ?? null)!=='--isolated-db') return;

$db=DB::pdo();
if (PHP_SAPI!=='cli' || $db->query('SELECT DATABASE()')->fetchColumn()!=='ip_ya_php_scratch_max_probe'
    || Config::liveAllowed() || ServerWorkflowMax::staffSettings()!==null || ServerWorkflowMax::settings()!==null) {
    throw new RuntimeException('Explicit isolated test database required.');
}
foreach (['messaging_attempts','messaging_receipts','audit_log'] as $table) {
    $ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/,\R +CONSTRAINT [^\r\n]+/','',$ddl);
    $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
}
foreach ([1=>['admin','Учебный владелец'],2=>['manager','Раиса Учебная'],3=>['lawyer','Наиль Учебный']] as $id=>[$role,$name]) {
    $db->prepare("INSERT INTO users(id,username,name,password_hash,role,active) VALUES(?,?,?,'fixture',?,1)
        ON DUPLICATE KEY UPDATE active=1")->execute([$id,'probe-'.$role.'@example.test',$name,$role]);
}
$_SESSION=['user_id'=>2,'auth_version'=>(int)$db->query('SELECT auth_version FROM users WHERE id=2')->fetchColumn()];
$db->exec("INSERT IGNORE INTO parties(id,name,source_json) VALUES(37,'Учебный контрагент','{}')");
$db->exec("INSERT IGNORE INTO cadastre(id,number,type,source_json) VALUES(37,'Учебное здание','Здание','{}')");
$db->exec("INSERT IGNORE INTO rental_objects(id,name,type,cadastre_id,source_json) VALUES(37,'Учебное место','Машиноместо',37,'{}')");
$db->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json)
    VALUES('max',1,6,'Synthetic MAX','{}') ON DUPLICATE KEY UPDATE enabled=1,revision=6");
$db->exec("INSERT INTO integration_configuration_sources(provider,variant,source_receipt_sha256,source_revision,metadata_json)
    VALUES('max','synthetic',REPEAT('a',64),6,'{}') ON DUPLICATE KEY UPDATE source_revision=6");
$scope['group_id']='00000000-0000-4000-8000-000000000037';
$db->prepare("INSERT IGNORE INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,'ТЕСТОВАЯ')")
    ->execute([$scope['group_id'],$scope['chat_id']]);
$db->prepare("INSERT IGNORE INTO integration_group_bindings(source_variant,source_group_id,local_group_id) VALUES('synthetic',?,?)")
    ->execute([$scope['group_id'],$scope['group_id']]);
Config::saveSecrets('max',['_owner_bound_revision'=>6,'secret'=>'synthetic no network']);
$store=Config::store();
(new TemplateCatalog($db,$store))->installStaffHandoff();
$scope['catalog_version']=(int)$db->query("SELECT version FROM integration_template_catalog WHERE channel='max' AND source_key='staffHandoff'")->fetchColumn();

$old=$scope['isolated_probe']['attempt_id'];
$oldText="Документ возвращён юристу\nУчебный документ";
$historicalEvent=static function(bool $cli) use ($db): array {
    $key=bin2hex(random_bytes(16));
    $result=Support::json(['id'=>$cli?901:902]);
    $contract=$cli?901:902;
    $action=$cli?'contract.legal':'contract.return';
    $fingerprint=hash('sha256',$key);
    $db->prepare('INSERT INTO rental_commands(command_key,action,fingerprint,result_json) VALUES(?,?,?,?)')
        ->execute([$key,$action,$fingerprint,$result]);
    $q=$db->prepare('SELECT result_json FROM rental_commands WHERE command_key=?');
    $q->execute([$key]);
    $result=$q->fetchColumn();
    $db->prepare('INSERT INTO rental_events(contract_id,actor_id,action,created_at) VALUES(?,2,?,NOW()-INTERVAL 3 DAY)')
        ->execute([$contract,$action]);
    $event=['schema'=>1,'kind'=>'committed-rental-workflow-event','project_id'=>WorkflowEvents::PROJECT,
        'event_id'=>(int)$db->lastInsertId(),'epoch'=>Support::uuid(),'command_key'=>$key,
        'command_fingerprint'=>$fingerprint,'command_result_sha256'=>hash('sha256',$result),'actor_id'=>2,
        'action'=>$action,'event_kind'=>$cli?'legalApprove':'accountantReturn','contract_id'=>$contract,
        'entity_kind'=>'contract','from_status'=>$cli?'legal':'accounting','to_status'=>$cli?'accounting':'returned',
        'return_to'=>$cli?null:'lawyer'];
    $event['sha256']=hash('sha256',Importer::canonicalJson($event));
    $db->prepare('UPDATE rental_events SET source_json=? WHERE id=?')->execute([Support::json($event),$event['event_id']]);
    return $event;
};
$oldKey=$observed?ServerWorkflowMax::requestKey($historicalEvent(false)):'contract-workflow-'.hash('sha256',$old);
$oldRaw=Support::json(['text'=>$oldText]);
$fp=hash('sha256','fixture-'.$old);
$oldSpool=$store->spool(['attempt_id'=>$old,'provider'=>'max','operation'=>'send','request_key'=>$oldKey,
    'fingerprint'=>$fp,'revision'=>6,'target'=>$scope['chat_id']],$oldRaw);
$oldReceipt=$store->spool(['attempt_id'=>$old,'kind'=>'transport_receipt','request_key'=>$oldKey],
    Support::json(['status'=>'uncertain','code'=>'MAX_ACCEPTANCE_UNKNOWN','curl_errno'=>28]));
$store->linkReceipt($old,$oldReceipt);
$insert=$db->prepare("INSERT INTO messaging_attempts(id,provider,actor_id,request_key,operation,target,fingerprint,body_text,
    status,result_code,spool_path,revision,created_at,finished_at) VALUES(?,'max',2,?,'send',?,?,? ,?,?,?,6,
        NOW()-INTERVAL 2 DAY,NOW()-INTERVAL 2 DAY)");
$insert->execute([$old,$oldKey,$scope['chat_id'],$fp,$oldText,'uncertain','MAX_ACCEPTANCE_UNKNOWN',$oldSpool]);
$db->prepare("INSERT INTO messaging_receipts(attempt_id,receipt_path) VALUES(?,?)")->execute([$old,$oldReceipt]);
$scope['isolated_probe']['receipt_sha256']=basename($oldReceipt);
$scope['isolated_probe']['epoch']=$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
$probe=new StaffHandoffProbe($db,$store);
$probe->verifyReady($scope);
$badReceipt=$scope;
$badReceipt['isolated_probe']['receipt_sha256']=str_repeat('e',64);
deny(fn()=>$probe->verifyReady($badReceipt),'STAFF_HANDOFF_PROBE_RECEIPT_CHANGED');
$badProfile=$scope;
$badProfile['revision']=7;
deny(fn()=>$probe->verifyReady($badProfile),'STAFF_HANDOFF_PROBE_RECEIPT_CHANGED');
$badOwner=$scope;
$badOwner['isolated_probe']['owner_id']=3;
deny(fn()=>$probe->verifyReady($badOwner),'STAFF_HANDOFF_PROBE_OWNER_CHANGED');
$badGroup=$scope;
$badGroup['group_id']=Support::uuid();
deny(fn()=>$probe->verifyReady($badGroup),'STAFF_HANDOFF_PROBE_GROUP_CHANGED');
foreach (['pending','uncertain'] as $status) {
    $db->beginTransaction();
    try {
        $insert->execute([Support::uuid(),bin2hex(random_bytes(16)),$scope['chat_id'],$fp,'another attempt',$status,null,null]);
        deny(fn()=>$probe->verifyReady($scope),ServerWorkflowMax::RECONCILIATION_REQUIRED);
    } finally {
        $db->rollBack();
    }
}

if ($observed) {
    $cli=Support::uuid();
    $cliEvent=$historicalEvent(true);
    $cliKey=Modules\Integrations\WorkflowMaxEvent::requestKey($cliEvent);
    $missingSpool='spool/'.str_repeat('d',64);
    $insert->execute([$cli,$cliKey,$scope['chat_id'],$fp,'Учебное согласование','uncertain','MAX_ACCEPTANCE_UNKNOWN',$missingSpool]);
    $db->prepare('INSERT INTO messaging_receipts(attempt_id,receipt_path) VALUES(?,?)')
        ->execute([$cli,'spool/'.str_repeat('f',64)]);
    $db->prepare('UPDATE messaging_attempts SET created_at=created_at-INTERVAL 1 HOUR,finished_at=finished_at-INTERVAL 1 HOUR WHERE id=?')
        ->execute([$cli]);
    $scope['event_floor']=(int)$db->query('SELECT MAX(id) FROM rental_events')->fetchColumn();
    deny(fn()=>$probe->verifyReady($scope),ServerWorkflowMax::RECONCILIATION_REQUIRED);
    $observer=new StaffHandoffObservation($db,$store);
    $getCalls=[];
    $controlStamp=time()*1000;
    $message=['timestamp'=>$controlStamp,'body'=>['mid'=>'CONTROL-ID-ONLY','text'=>'NO_CONTROL_TEXT_IN_SEAL'],
        'recipient'=>['chat_id'=>$scope['chat_id']]];
    $get=static function(string $path) use (&$getCalls,$scope,$message,$controlStamp): array {
        $getCalls[]=$path;
        if ($path==='/chats/'.rawurlencode($scope['chat_id']).'/members/me') {
            return ['http'=>200,'data'=>['is_bot'=>true,'is_admin'=>true,'permissions'=>['read_all_messages']]];
        }
        if ($path==='/messages?chat_id='.rawurlencode($scope['chat_id']).'&count=1') {
            return ['http'=>200,'data'=>['messages'=>[$message]]];
        }
        check(str_starts_with($path,'/messages?chat_id='.rawurlencode($scope['chat_id']).'&count=30&from='),
            'observation GET only uses exact group');
        parse_str((string)parse_url($path,PHP_URL_QUERY),$params);
        if ((int)$params['from']===$controlStamp+5000 && (int)$params['to']===$controlStamp-5000) {
            return ['http'=>200,'data'=>['messages'=>[$message]]];
        }
        check((int)$params['from']-(int)$params['to']===55000,'historical window uses upper from and lower to');
        return ['http'=>200,'data'=>['messages'=>[]]];
    };
    deny(fn()=>$observer->observe($scope,$old,$cli,$get),'STAFF_HANDOFF_OBSERVATION_LOCK_REQUIRED');
    check((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()===1,'observation acquires existing provider lock');
    try {
        $deniedMembership=static fn(string $path): array=>['http'=>200,'data'=>['is_bot'=>true,'is_admin'=>false]];
        deny(fn()=>$observer->observe($scope,$old,$cli,$deniedMembership),'STAFF_HANDOFF_OBSERVATION_MEMBERSHIP_REQUIRED');
        $brokenControl=static fn(string $path): array=>str_contains($path,'&from=')
            ?['http'=>200,'data'=>['messages'=>[]]]:$get($path);
        deny(fn()=>$observer->observe($scope,$old,$cli,$brokenControl),'STAFF_HANDOFF_OBSERVATION_CONTROL_REQUIRED');
        $nonEmpty=static fn(string $path): array=>str_contains($path,'&from=') && !str_contains($path,'&from='.($controlStamp+5000).'&')
            ?['http'=>200,'data'=>['messages'=>[$message]]]:$get($path);
        deny(fn()=>$observer->observe($scope,$old,$cli,$nonEmpty),'STAFF_HANDOFF_OBSERVATION_EMPTY_HISTORY_REQUIRED');
        $getCalls=[];
        $sha=$observer->observe($scope,$old,$cli,$get);
        check(count($getCalls)===5 && $getCalls[3]!==$getCalls[4],'membership, positive control and two distinct bounded observations');
        $bytes=base64_decode($store->verifySpool('spool/'.$sha)['raw_base64'],true);
        check(!str_contains($bytes,'NO_CONTROL_TEXT_IN_SEAL') && !str_contains($bytes,'CONTROL-ID-ONLY'),
            'observation omits control message text and raw ID');
    } finally {
        $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
    }
    $scope['isolated_probe']['observation_sha256']=$sha;
    ServerWorkflowMax::validateStaff($scope);
    check($probe->verifyReady($scope)===[$old,$cli],'sealed observation binds exactly two historical unknown attempts');
    deny(fn()=>$observer->verify($scope,$sha,time()+1801),'STAFF_HANDOFF_OBSERVATION_INVALID');
    deny(fn()=>$observer->verify(array_replace($scope,['vault_sha256'=>str_repeat('9',64)]),$sha,time()),
        'STAFF_HANDOFF_OBSERVATION_SCOPE_CHANGED');
    deny(fn()=>$observer->verify(array_replace($scope,['chat_id'=>'-999']),$sha,time()),
        'STAFF_HANDOFF_OBSERVATION_SCOPE_CHANGED');
    deny(fn()=>$observer->verify(array_replace($scope,['contract_id'=>901]),$sha,time()),
        'STAFF_HANDOFF_OBSERVATION_HISTORY_CHANGED');
    foreach (['body','action','command','other-uncertain','other-pending'] as $change) {
        $db->beginTransaction();
        try {
            if ($change==='body') {
                $db->prepare("UPDATE messaging_attempts SET body_text='changed' WHERE id=?")->execute([$cli]);
                deny(fn()=>$probe->verifyReady($scope),'STAFF_HANDOFF_OBSERVATION_HISTORY_CHANGED');
            } elseif ($change==='action') {
                $db->prepare("UPDATE rental_events SET action='contract.return' WHERE id=?")->execute([$cliEvent['event_id']]);
                deny(fn()=>$probe->verifyReady($scope),'STAFF_HANDOFF_OBSERVATION_EVENT_CHANGED');
            } elseif ($change==='command') {
                $db->prepare("UPDATE rental_commands SET result_json='{}' WHERE command_key=?")->execute([$cliEvent['command_key']]);
                deny(fn()=>$probe->verifyReady($scope),'STAFF_HANDOFF_OBSERVATION_COMMAND_CHANGED');
            } else {
                $id=Support::uuid();
                $insert->execute([$id,bin2hex(random_bytes(16)),'-99',$fp,'another MAX attempt',substr($change,6),null,null]);
                $db->prepare("UPDATE messaging_attempts SET operation='check' WHERE id=?")->execute([$id]);
                deny(fn()=>$probe->verifyReady($scope),ServerWorkflowMax::RECONCILIATION_REQUIRED);
            }
        } finally {
            $db->rollBack();
        }
    }
}

$number='УЧ-MAX-37-'.strtoupper(bin2hex(random_bytes(5)));
$created=Service::command('contract.create',['number'=>$number,'command_key'=>bin2hex(random_bytes(16)),
    'party_id'=>37,'document_date'=>date('Y-m-d'),'start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+30 days')),
    'objects'=>[37],'term_amount'=>[37=>'1000'],'amount'=>'1000','intent'=>'draft']);
$scope['contract_id']=$created['id'];
$scope['isolated_probe']['contract_number']=$number;
$scope['event_floor']=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();
$input=['id'=>$created['id'],'version'=>1,'command_key'=>$scope['command_key']];
Service::command('contract.submit',$input,$fresh);
check($fresh!==null,'actual draft submission committed a new workflow event');
$q=$db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');
$q->execute([$fresh]);
$event=WorkflowEvents::decode($q->fetch());
check(ServerWorkflowMax::staffAdmits($scope,$event),'committed handoff pins the exact recipient');
$plan=(new CurrentContractMaxProof($db))->plan($fresh,$scope['group_id'],6,$scope['catalog_version'],true);
$event['request_key']=$plan['request_key'];
$legacy=new ReflectionMethod(ServerWorkflowMax::class,'assertSlot');
deny(fn()=>$legacy->invoke(new ServerWorkflowMax($db),$event),ServerWorkflowMax::RECONCILIATION_REQUIRED);
deny(fn()=>$legacy->invoke(new ServerWorkflowMax($db,true),$event),ServerWorkflowMax::RECONCILIATION_REQUIRED);
deny(fn()=>$probe->claim($scope,$event),'STAFF_HANDOFF_PROBE_LOCK_REQUIRED');

$calls=0;
$transport=static function(string $provider,string $operation,array $config,array $secret,string $chat,string $raw)use(&$calls,$scope,$plan):array {
    check($provider==='max' && $operation==='send' && $chat===$scope['chat_id'],'simulated transport only uses the exact group');
    ServerWorkflowMax::assertPath(0,'POST','https://platform-api2.max.ru/messages?chat_id='.rawurlencode($chat),$raw,$chat,$plan['catalog']['body']);
    $calls++;
    return ['status'=>'accepted','code'=>'SIMULATION_ONLY','remote_id'=>'fixture'];
};
$send=new Messaging($db,$store,$transport);
$otherConfig=require APP_ROOT.'/.local/database.php';
$other=new PDO($otherConfig['dsn'],$otherConfig['user'],$otherConfig['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
check((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()===1,'provider lock acquired');
try {
    check((int)$other->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()===0,'second connection cannot claim while the provider is locked');
    $probe->claim($scope,$event);
    // Assert the claim is already in the journal before handing off to the mock transport.
    $q=$db->prepare("SELECT before_json,after_json FROM audit_log WHERE action='max.staff-handoff-probe' AND entity_id=?");
    $q->execute([$old]);
    $audit=$q->fetch();
    check(Support::decode($audit['before_json'])['receipt_sha256']===basename($oldReceipt)
        && Support::decode($audit['after_json'])['event_id']===$fresh,'journal binds sealed old receipt and exact committed event');
    if ($observed) {
        $q->execute([$cli]);
        $audit=$q->fetch();
        $evidence=Support::decode($audit['before_json']);
        $decision=Support::decode($audit['after_json']);
        check($evidence['observation_sha256']===$sha && !isset($evidence['receipt_sha256'])
            && $decision['historical_attempts']===[$old,$cli] && $decision['primary_attempt_id']===$old,
            'both rows link to the observation, and only the primary row has a receipt hash');
    }
    $attempt=$send->dispatch('max',2,$event['request_key'],'send',[$scope['group_id']],$plan['catalog']['body']);
    check(count($attempt)===1 && $attempt[0]['status']==='accepted' && $calls===1,'one receipt from the substitute transport');
    deny(fn()=>$probe->claim($scope,$event),'STAFF_HANDOFF_PROBE_ALREADY_CLAIMED');
    $differentApproval=$scope;
    $differentApproval['isolated_probe']['decision_ref']='other-decision-37';
    deny(fn()=>$probe->verifyReady($differentApproval),'STAFF_HANDOFF_PROBE_ALREADY_CLAIMED');
} finally {
    $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
}
Service::command('contract.submit',$input,$repeat);
check($repeat===null && $calls===1,'same business request produces no second committed event or send');
$q=$db->prepare('SELECT status,request_key,remote_id FROM messaging_attempts WHERE id=?');
$q->execute([$old]);
check($q->fetch()===['status'=>'uncertain','request_key'=>$oldKey,'remote_id'=>null]
    && $store->receiptFor($old)['receipt_path']===$oldReceipt,'original unknown result and sealed receipt are untouched');
if ($observed) {
    $q->execute([$cli]);
    check($q->fetch()===['status'=>'uncertain','request_key'=>$cliKey,'remote_id'=>null]
        && $store->receiptFor($cli)===null,'CLI unknown retained with no fabricated receipt');
}
deny(fn()=>$legacy->invoke(new ServerWorkflowMax($db),array_replace($event,['request_key'=>str_repeat('f',32)])),
    ServerWorkflowMax::RECONCILIATION_REQUIRED);
