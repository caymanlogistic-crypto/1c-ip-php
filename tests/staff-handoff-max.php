<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth,DB};
use Modules\Integrations\{Config,CurrentContractMaxProof,Messaging,ServerWorkflowMax,Support,TemplateCatalog};
use Modules\Rental\{FinalReview,HandoverQuestion,Service,WorkflowEvents};

function check(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function deny(callable $operation,string $error): void {
    try {$operation();}
    catch (RuntimeException $e) {
        check($e->getMessage()===$error,'reject '.$error);
        return;
    }
    throw new RuntimeException('Unexpected success for '.$error);
}

$scope=['schema'=>1,'enabled'=>true,'project_id'=>WorkflowEvents::PROJECT,'scope'=>'staff-handoff','stand'=>'ui',
    'database'=>'ipphp_ui','revision'=>6,'group_id'=>Support::uuid(),'group_name'=>'ТЕСТОВАЯ','chat_id'=>'-117',
    'vault_sha256'=>str_repeat('a',64),'event_floor'=>42,'contract_id'=>157,'actor_id'=>37,
    'action'=>'document.return','command_key'=>str_repeat('b',32),'catalog_version'=>1];
ServerWorkflowMax::validateStaff($scope);
$event=['event_id'=>43,'contract_id'=>157,'actor_id'=>37,'action'=>'document.return','command_key'=>$scope['command_key']];
check(ServerWorkflowMax::staffAdmits($scope,$event),'one selected command matches');
foreach (['event_id'=>42,'contract_id'=>158,'actor_id'=>38,'command_key'=>str_repeat('c',32),'action'=>'document.forward'] as $field=>$value) {
    check(!ServerWorkflowMax::staffAdmits($scope,array_replace($event,[$field=>$value])),'selection rejects changed '.$field);
}
deny(fn()=>ServerWorkflowMax::validateStaff(array_replace($scope,['group_name'=>'Чужая группа'])),'OBJECT_MAX_ADMISSION_INVALID');
deny(fn()=>ServerWorkflowMax::validateStaff($scope+['extra_group'=>Support::uuid()]),'OBJECT_MAX_ADMISSION_INVALID');
deny(fn()=>ServerWorkflowMax::assertPath(0,'POST','https://platform-api2.max.ru/messages?chat_id=-118',Support::json(['text'=>'fixture']),'-117','fixture'),'WORKFLOW_MAX_HTTP_REJECTED');
$legacy=['event_kind'=>HandoverQuestion::EVENT,'epoch'=>'fixture','event_id'=>43,'command_key'=>$scope['command_key']];
check(ServerWorkflowMax::requestKey($legacy)===ServerWorkflowMax::requestKey($legacy+['staff_handoff'=>['task'=>'fixture']])
    && ServerWorkflowMax::requestKey($legacy)!==ServerWorkflowMax::requestKey($legacy,true),'legacy key stable with new metadata');

if (($argv[1]??null)!=='--private-rental37-fixture') return;
$db=DB::pdo();
if (PHP_SAPI!=='cli' || $db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_rent37' || Config::liveAllowed()
    || ServerWorkflowMax::settings()!==null || ServerWorkflowMax::staffSettings()!==null) throw new RuntimeException('Private rental 37 fixture required');

$login=static function(string $role) use ($db):array {
    $q=$db->prepare('SELECT id,auth_version FROM users WHERE username=?');
    $q->execute(['handoff37-'.$role.'@example.test']);
    $user=$q->fetch()?:throw new RuntimeException('Fixture user missing');
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
    return Auth::user();
};
$contract=static fn(string $num)=>$db->query("SELECT id,number,status,version FROM contracts WHERE party_id=37001 AND number=".$db->quote($num))->fetch();
$parking=$contract('ММ-37')?:throw new RuntimeException('Fixture contract missing');
$review=FinalReview::marker($db,(int)$parking['id'],'finance');
if (!isset($argv[2]) && ($review['state']??null)==='returned') {
    $login('manager');
    Service::command(FinalReview::RESUBMIT,['id'=>(int)$parking['id'],'version'=>(int)$parking['version'],
        'command_key'=>bin2hex(random_bytes(16)),'review_kind'=>'finance','confirmed'=>'1']);
    $parking=$contract('ММ-37');
}
$login('accountant');
$action=FinalReview::RETURN_FINANCE;
$input=['id'=>(int)$parking['id'],'version'=>(int)$parking['version'],'command_key'=>bin2hex(random_bytes(16)),'comment'=>'fixture@example.test'];
$eventRow=static function(string $key) use($db):?array {
    $q=$db->prepare("SELECT id,source_json,created_at FROM rental_events WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.command_key'))=?");
    $q->execute([$key]);
    $row=$q->fetch();
    return $row?WorkflowEvents::decode($row):null;
};
if (isset($argv[2])) {
    $input=Support::decode(base64_decode($argv[2],true));
    try {Service::command($action,$input,$fresh);}
    catch (RuntimeException $e) {
        if (($argv[3]??null)!=='--competing' || $e->getCode()!==409) throw $e;
        echo 'FRESH stale';
        return;
    }
    echo 'FRESH '.($fresh??'none');
    return;
}
$db->beginTransaction();
try {
    Service::command($action,$input,$fresh);
    check($fresh===null && $eventRow($input['command_key'])!==null,'nested command never publishes an uncommitted event');
} finally {$db->rollBack();}
check($eventRow($input['command_key'])===null && $contract('ММ-37')['version']===$parking['version'],'rollback removes event and command effect');
$login('director');
try {
    Service::command($action,$input,$fresh);
    throw new LogicException('Director completed accountant command');
} catch (RuntimeException $e) {check($e->getCode()===403 && $eventRow($input['command_key'])===null,'wrong role emits nothing');}

$parallel=static function(array $requests,bool $competing=false):array {
    $children=[];
    foreach ($requests as $request) {
        $command=[PHP_BINARY,__FILE__,'--private-rental37-fixture',base64_encode(Support::json($request))];
        if ($competing) $command[]='--competing';
        $child=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);
        fclose($pipes[0]);
        $children[]=[$child,$pipes[1],$pipes[2]];
    }
    $results=[];
    foreach ($children as [$child,$stdout,$stderr]) {
        $output=stream_get_contents($stdout);$errors=stream_get_contents($stderr);
        fclose($stdout);fclose($stderr);
        if (proc_close($child)!==0) throw new RuntimeException($errors);
        preg_match('/FRESH (\d+|none|stale)/',$output,$match);
        $results[]=$match[1]??'invalid';
    }
    sort($results);
    return $results;
};
$freshResults=$parallel([$input,$input]);
$receipt=$eventRow($input['command_key']);
check($receipt && $freshResults===[(string)$receipt['event_id'],'none'],'two PHP requests commit one fresh event');
$actor=$login('accountant');
Service::command($action,$input,$fresh);
check($fresh===null,'exact replay returns the saved receipt');

$template=(new TemplateCatalog($db,Config::store()));
$template->installStaffHandoff();
$template->installHandoverQuestion();
$values=CurrentContractMaxProof::staffValues($receipt);
$render=TemplateCatalog::renderEvent($db,'max','staffHandoff',$values);
check(str_contains($render['body'],'ММ-37') && !str_contains($render['body'],'fixture@example.test'),'catalog message excludes private command text');

$old=$db->query("SELECT * FROM integration_connections WHERE provider='max'")->fetch();
$source=$db->query("SELECT * FROM integration_configuration_sources WHERE provider='max'")->fetch();
$secretPath=APP_ROOT.'/private/integrations/max.enc';
if (file_exists($secretPath)) throw new RuntimeException('Fixture must not have a MAX vault');
$group=$scope['group_id'];
$other=Support::uuid();
try {
    $db->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json) VALUES('max',1,37,'Local simulation','{}')
        ON DUPLICATE KEY UPDATE enabled=1,revision=37");
    $db->exec("INSERT INTO integration_configuration_sources(provider,variant,source_receipt_sha256,source_revision,metadata_json)
        VALUES('max','local-simulation',REPEAT('a',64),37,'{}') ON DUPLICATE KEY UPDATE source_revision=37");
    $q=$db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,?)");
    $q->execute([$group,'-370000001','ТЕСТОВАЯ']);
    $q->execute([$other,'-370000002','Другая группа']);
    $q=$db->prepare("INSERT INTO integration_group_bindings(source_variant,source_group_id,local_group_id) VALUES('local-simulation',?,?)");
    foreach ([$group,$other] as $id) $q->execute([$id,$id]);
    $proof=new CurrentContractMaxProof($db);
    $eid=$receipt['event_id'];
    deny(fn()=>$proof->plan($eid,$group,36,1,true),'RENTAL_MAX_REVISION_CHANGED');
    deny(fn()=>$proof->plan($eid,$group,37,2,true),'RENTAL_MAX_CATALOG_REVISION_CHANGED');
    deny(fn()=>$proof->plan($eid,$other,37,1,true),'RENTAL_MAX_APPROVED_GROUP_REQUIRED');
    $p=$proof->plan($eid,$group,37,1,true);
    Config::saveSecrets('max',['_owner_bound_revision'=>37,'secret'=>bin2hex(random_bytes(24))]);
    $calls=[];
    $fake=static function(string $provider,string $operation,array $config,array $secret,string $chat,string $raw) use (&$calls):array {
        $calls[]=['provider'=>$provider,'operation'=>$operation,'chat'=>$chat,'raw'=>$raw];
        return ['status'=>match(count($calls)){1=>'uncertain',2=>'failed',default=>'accepted'},'code'=>'LOCAL_SIMULATION_ONLY'];
    };
    $messaging=new Messaging($db,Config::store(),$fake);
    $send=static fn()=>$messaging->dispatch('max',$actor['id'],$p['request_key'],'send',[$group],$p['catalog']['body']);
    $first=$send()[0];$again=$send()[0];
    check($first['status']==='uncertain' && $first['id']===$again['id'] && count($calls)===1
        && $calls[0]['chat']==='-370000001' && $calls[0]['raw']===Support::json(['text'=>$p['catalog']['body']]),'unknown simulation recorded once with one destination and no replay');
    deny(fn()=>ServerWorkflowMax::assertLane($db,$receipt,false),'STAFF_HANDOFF_ROUTE_ALREADY_CLAIMED');
    $db->exec("UPDATE integration_connections SET revision=38 WHERE provider='max'");
    deny(fn()=>$proof->plan($eid,$group,37,1,true),'RENTAL_MAX_REVISION_CHANGED');

    // The legacy capability still uses its previous key, applicable selector and explicit retry.
    $office=$contract('ОФ-37');
    $base=['id'=>(int)$office['id'],'version'=>(int)$office['version']];
    $key=static fn()=>['command_key'=>bin2hex(random_bytes(16))];
    $oldQuestion=HandoverQuestion::read($db,$base['id']);
    if (!$oldQuestion || empty($oldQuestion['resolution'])) throw new RuntimeException('Resolved browser fixture required');
    $actual=(new DateTimeImmutable($oldQuestion['actual']))->modify('+1 day')->format('Y-m-d');
    $login('manager');
    Service::command(HandoverQuestion::OPEN,$base+$key()+['handover_date'=>$actual],$legacyId);
    $db->exec("UPDATE integration_connections SET revision=6 WHERE provider='max'");
    $db->exec("UPDATE integration_configuration_sources SET source_revision=6 WHERE provider='max'");
    Config::saveSecrets('max',['_owner_bound_revision'=>6,'secret'=>bin2hex(random_bytes(24))]);
    $legacyPlan=$proof->plan($legacyId,$group,6,1);
    check($legacyPlan['event']['staff_handoff'] && $legacyPlan['request_key']===ServerWorkflowMax::requestKey($legacyPlan['event']),
        'legacy handover planner accepts compatible committed event and keeps its key');
    $simulateLegacy=static function(array $plan) use ($messaging,$group):array {
        usleep(1100000);
        return $messaging->dispatch('max',$plan['event']['actor_id'],$plan['request_key'],'send',[$group],$plan['catalog']['body'])[0];
    };
    check($simulateLegacy($legacyPlan)['status']==='failed','legacy rejection simulated');
    deny(fn()=>ServerWorkflowMax::assertLane($db,$legacyPlan['event'],true),'STAFF_HANDOFF_ROUTE_ALREADY_CLAIMED');
    $login('accountant');
    $case=HandoverQuestion::read($db,$base['id']);
    $delivery=HandoverQuestion::delivery($db,$base['id'],$case);
    check($delivery['status']==='failed' && HandoverQuestion::canRetry($delivery),'confirmed legacy failure still allows explicit retry');
    Service::command(HandoverQuestion::RETRY,$base+$key()+['question_key'=>$case['key'],'attempt_id'=>$delivery['attempt_id']],$retryId);
    check($simulateLegacy($proof->plan($retryId,$group,6,1))['status']==='accepted','legacy retry gets its own proven receipt');
    $case=HandoverQuestion::read($db,$base['id']);
    check(HandoverQuestion::delivery($db,$base['id'],$case)['status']==='accepted','legacy retry status visible on contract');
    Service::command(HandoverQuestion::RESOLVE,$base+$key()+['question_key'=>$case['key'],'resolution'=>'Учебная сверка','billing_ready'=>'1']);
    $self=$base+$key()+['handover_date'=>(new DateTimeImmutable($actual))->modify('+1 day')->format('Y-m-d')];
    Service::command(HandoverQuestion::OPEN,$self,$fresh);
    check(is_int($fresh) && $eventRow($self['command_key'])['staff_handoff']===null,'accountant does not hand a question to themselves');
    $case=HandoverQuestion::read($db,$base['id']);
    Service::command(HandoverQuestion::RESOLVE,$base+$key()+['question_key'=>$case['key'],'resolution'=>'Учебный вывод о себе','billing_ready'=>'1']);
} finally {
    if (is_file($secretPath)) unlink($secretPath);
    if ($old) $db->prepare("UPDATE integration_connections SET enabled=?,revision=? WHERE provider='max'")->execute([$old['enabled'],$old['revision']]);
    else $db->exec("DELETE FROM integration_connections WHERE provider='max'");
    if ($source) $db->prepare("UPDATE integration_configuration_sources SET source_revision=? WHERE provider='max'")->execute([$source['source_revision']]);
    else $db->exec("DELETE FROM integration_configuration_sources WHERE provider='max'");
    $q=$db->prepare('DELETE FROM integration_group_bindings WHERE local_group_id IN (?,?)');$q->execute([$group,$other]);
    $q=$db->prepare('DELETE FROM messaging_groups WHERE id IN (?,?)');$q->execute([$group,$other]);
}

$login('manager');
$parking=$contract('ММ-37');
Service::command(FinalReview::RESUBMIT,['id'=>(int)$parking['id'],'version'=>(int)$parking['version'],
    'command_key'=>bin2hex(random_bytes(16)),'review_kind'=>'finance','confirmed'=>'1']);
$parking=$contract('ММ-37');
$input['version']=(int)$parking['version'];
$a=array_replace($input,['command_key'=>bin2hex(random_bytes(16))]);
$b=array_replace($input,['command_key'=>bin2hex(random_bytes(16))]);
$results=$parallel([$a,$b],true);
$count=(int)($eventRow($a['command_key'])!==null)+(int)($eventRow($b['command_key'])!==null);
check(ctype_digit($results[0]) && $results[1]==='stale' && $count===1,'concurrent separate commands on the same version produce one handoff');
