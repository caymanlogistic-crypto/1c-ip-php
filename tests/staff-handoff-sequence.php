<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config,CurrentContractMaxProof,Messaging,ServerWorkflowMax,Support,TemplateCatalog};
use Modules\Rental\{Controller,FinalReview,Service,SourceCards,WorkflowEvents};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function verify(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}

$now=time();
$scope=['schema'=>1,'enabled'=>true,'project_id'=>WorkflowEvents::PROJECT,'scope'=>ServerWorkflowMax::STAFF_SEQUENCE,
    'stand'=>'ui','database'=>'ipphp_ui','revision'=>37,'group_id'=>Support::uuid(),'group_name'=>'ТЕСТОВАЯ','chat_id'=>'-370000001',
    'vault_sha256'=>str_repeat('a',64),'catalog_version'=>1,'epoch'=>Support::uuid(),'event_floor'=>42,'valid_from'=>$now,'expires_at'=>$now+600,
    'contracts'=>[['id'=>900006,'number'=>'ММ-37']],'actions'=>[FinalReview::RETURN_FINANCE,FinalReview::RESUBMIT]];
ServerWorkflowMax::validateStaff($scope);
$sample=['event_id'=>43,'contract_id'=>900006,'contract_number'=>'ММ-37','action'=>FinalReview::RETURN_FINANCE,
    'epoch'=>$scope['epoch'],'staff_handoff'=>['task'=>'fixture']];
verify(ServerWorkflowMax::staffAdmits($scope,$sample,$now),'series opens at its start time');
verify(!ServerWorkflowMax::staffAdmits($scope,$sample,$now-1),'series does not open early');
verify(!ServerWorkflowMax::staffAdmits($scope,$sample,$scope['expires_at'])
    && ServerWorkflowMax::staffSelects($scope,$sample),'expiry stops the selected route without switching to legacy');
foreach ([
    ['contracts'=>[]],['contracts'=>[...$scope['contracts'],...$scope['contracts']]],['actions'=>['contract.*']],
    ['actions'=>[FinalReview::RESUBMIT,FinalReview::RESUBMIT]],['epoch'=>'not-an-epoch'],['expires_at'=>$now],
    ['group_name'=>'Другая группа'],['group_id'=>[$scope['group_id']]],['catalog_version'=>0],
] as $invalid) {
    try {
        ServerWorkflowMax::validateStaff(array_replace($scope,$invalid));
        throw new LogicException('Invalid series scope accepted');
    } catch (RuntimeException $e) {
        verify(in_array($e->getMessage(),['STAFF_HANDOFF_ADMISSION_INVALID','OBJECT_MAX_ADMISSION_INVALID'],true),
            'reject invalid '.array_key_first($invalid));
    }
}
verify(!ServerWorkflowMax::staffSelects($scope,array_replace($sample,['staff_handoff'=>null])),'legacy status event remains independent');
verify(!ServerWorkflowMax::staffSelects($scope,array_replace($sample,['contract_number'=>'НОВЫЙ-НОМЕР'])),'renumbered contract is outside this approval');

if (($argv[1]??null)!=='--private-rental37-fixture') return;
$db=DB::pdo();
if (PHP_SAPI!=='cli' || $db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_rent37' || Config::liveAllowed()
    || ServerWorkflowMax::settings()!==null || ServerWorkflowMax::staffSettings()!==null) throw new RuntimeException('Private rental 37 fixture required');

$login=static function(string $role) use ($db):void {
    $q=$db->prepare('SELECT id,auth_version FROM users WHERE username=?');
    $q->execute(['handoff37-'.$role.'@example.test']);
    $user=$q->fetch()?:throw new RuntimeException('Fixture user missing');
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
};
$command=static function(int $id,string $role,string $action,array $fields=[]) use($db,$login): array {
    $login($role);
    $q=$db->prepare('SELECT version FROM contracts WHERE id=? AND party_id=37001');
    $q->execute([$id]);
    $version=$q->fetchColumn()?:throw new RuntimeException('Fixture contract missing');
    $input=['id'=>$id,'version'=>(int)$version,'command_key'=>bin2hex(random_bytes(16))]+$fields;
    Service::command($action,$input,$fresh);
    if ($fresh===null || $db->inTransaction()) throw new RuntimeException('Fresh committed event required');
    $q=$db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');
    $q->execute([$fresh]);
    return [WorkflowEvents::decode($q->fetch()),$input];
};
$resubmit=['review_kind'=>'finance','confirmed'=>'1'];
foreach ([900006,900008] as $id) {
    if ((FinalReview::marker($db,$id,'finance')['state']??null)==='returned') $command($id,'manager',FinalReview::RESUBMIT,$resubmit);
}
if ((FinalReview::marker($db,900006,'legal')['state']??null)==='returned')
    $command(900006,'manager',FinalReview::RESUBMIT,['review_kind'=>'legal','confirmed'=>'1']);
$scope['event_floor']=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();
$scope['epoch']=$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
ServerWorkflowMax::validateStaff($scope);
verify(ServerWorkflowMax::staffSettings()===null,'ordinary runtime has no series enabled');

$old=$db->query("SELECT enabled,revision FROM integration_connections WHERE provider='max'")->fetch();
$source=$db->query("SELECT source_revision FROM integration_configuration_sources WHERE provider='max'")->fetch();
$secretPath=APP_ROOT.'/private/integrations/max.enc';
if (file_exists($secretPath)) throw new RuntimeException('Fixture must not have a MAX vault');
$group=$scope['group_id'];
$calls=[];
$transportStatus='accepted';
$clock=$now;
try {
    // Shadow only the receipt tables on this connection. Prior synthetic
    // uncertainty stays intact in the copy and cannot contaminate the clean run.
    foreach (['messaging_attempts','messaging_receipts'] as $table) {
        $ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
        $ddl=preg_replace('/,\R +CONSTRAINT [^\r\n]+/','',$ddl); // MySQL does not allow temporary-table foreign keys.
        $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
    }
    $db->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json) VALUES('max',1,37,'Local series simulation','{}')
        ON DUPLICATE KEY UPDATE enabled=1,revision=37");
    $db->exec("INSERT INTO integration_configuration_sources(provider,variant,source_receipt_sha256,source_revision,metadata_json)
        VALUES('max','local-simulation',REPEAT('a',64),37,'{}') ON DUPLICATE KEY UPDATE source_revision=37");
    $q=$db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,'ТЕСТОВАЯ')");
    $q->execute([$group,$scope['chat_id']]);
    $q=$db->prepare("INSERT INTO integration_group_bindings(source_variant,source_group_id,local_group_id) VALUES('local-simulation',?,?)");
    $q->execute([$group,$group]);
    Config::saveSecrets('max',['_owner_bound_revision'=>37,'secret'=>bin2hex(random_bytes(24))]);
    (new TemplateCatalog($db,Config::store()))->installStaffHandoff();

    $proof=new CurrentContractMaxProof($db);
    $server=new ServerWorkflowMax($db,true);
    $assertSlot=new ReflectionMethod($server,'assertSlot');
    $waitForTarget=new ReflectionMethod($server,'waitForTarget');
    $transport=static function(string $provider,string $operation,array $config,array $secret,string $chat,string $raw) use(&$calls,&$transportStatus,$scope):array {
        if ($provider!=='max' || $operation!=='send' || $chat!==$scope['chat_id']) throw new LogicException('Unapproved fixture operation');
        $text=Support::decode($raw)['text'];
        ServerWorkflowMax::assertPath(0,'POST','https://platform-api2.max.ru/messages?chat_id='.rawurlencode($chat),$raw,$chat,$text);
        $calls[]=$text;
        return ['status'=>$transportStatus,'code'=>'LOCAL_SIMULATION_ONLY'];
    };
    $messaging=new Messaging($db,Config::store(),$transport);
    $deliver=static function(array $scope,array $event) use($db,&$clock,$proof,$server,$assertSlot,$waitForTarget,$messaging):?array {
        if (!ServerWorkflowMax::staffAdmits($scope,$event,$clock)) return null;
        ServerWorkflowMax::assertLane($db,$event,true);
        $plan=$proof->plan($event['event_id'],$scope['group_id'],$scope['revision'],$scope['catalog_version'],true);
        $actual=$event+['request_key'=>$plan['request_key']];
        if ((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) throw new RuntimeException('Fixture MAX lock busy');
        try {
            $assertSlot->invoke($server,$actual);
            $waitForTarget->invoke($server,$scope['chat_id'],$plan['request_key']);
            return $messaging->dispatch('max',$event['actor_id'],$plan['request_key'],'send',[$scope['group_id']],$plan['catalog']['body'])[0];
        } finally {$db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    };

    [$first,$firstInput]=$command(900006,'accountant',FinalReview::RETURN_FINANCE);
    $a=$deliver($scope,$first);
    Service::command(FinalReview::RETURN_FINANCE,$firstInput,$fresh);
    verify($a['status']==='accepted' && $deliver($scope,$first)['id']===$a['id'] && $fresh===null && count($calls)===1,
        'return by accountant: confirmed acceptance and one call despite replay');

    [$second,$secondInput]=$command(900006,'manager',FinalReview::RESUBMIT,$resubmit);
    $b=$deliver($scope,$second);
    Service::command(FinalReview::RESUBMIT,$secondInput,$fresh);
    verify($b['status']==='accepted' && $deliver($scope,$second)['id']===$b['id'] && $fresh===null && count($calls)===2,
        'reassignment to the specific accountant: second simulated attempt, once');
    verify(str_contains($calls[0],'ММ-37') && str_contains($calls[1],'ММ-37') && $calls[0]!==$calls[1],'both messages name the same contract and different tasks');

    [$outside]=$command(900008,'accountant',FinalReview::RETURN_FINANCE);
    verify($outside['staff_handoff'] && $deliver($scope,$outside)===null,'actual handoff for ЦИКЛ-37 is outside the contract list');
    [$otherAction]=$command(900006,'lawyer',FinalReview::RETURN_LEGAL);
    verify($otherAction['staff_handoff'] && $deliver($scope,$otherAction)===null,'actual legal return is outside the action list');
    [$new]=$command(900006,'accountant',FinalReview::RETURN_FINANCE);
    verify($new['staff_handoff'] && $deliver(array_replace($scope,['epoch'=>Support::uuid()]),$new)===null,'old epoch refused for a committed handoff');
    verify($deliver(array_replace($scope,['event_floor'=>$new['event_id']]),$new)===null,'event at floor refused for a committed handoff');
    $clock=$scope['expires_at'];
    verify($deliver($scope,$new)===null && count($calls)===2,'expired series records no new simulated transport call');
    $q=$db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND request_key IN (?,?)");
    $q->execute([ServerWorkflowMax::requestKey($first,true),ServerWorkflowMax::requestKey($second,true)]);
    verify((int)$q->fetchColumn()===2,'two separate durable attempts and one target');

    $clock=$now;
    $transportStatus='uncertain';
    [$uncertain]=$command(900006,'manager',FinalReview::RESUBMIT,$resubmit);
    $attempt=$deliver($scope,$uncertain);
    verify($attempt['status']==='uncertain' && $deliver($scope,$uncertain)['id']===$attempt['id'] && count($calls)===3,
        'unknown result remains a single attempt');
    try {
        ServerWorkflowMax::assertLane($db,$uncertain,false);
        throw new LogicException('Legacy fallback unexpectedly permitted');
    } catch (RuntimeException $error) {
        verify($error->getMessage()==='STAFF_HANDOFF_ROUTE_ALREADY_CLAIMED','uncertain result fences legacy fallback for the same event');
    }

    [$stopped,$stoppedInput]=$command(900006,'accountant',FinalReview::RETURN_FINANCE);
    $blocked=static function(callable $send): RuntimeException {
        try {$send();}
        catch (RuntimeException $error) {
            verify($error->getMessage()===ServerWorkflowMax::RECONCILIATION_REQUIRED,'new transport blocked pending reconciliation');
            return $error;
        }
        throw new LogicException('Unreconciled attempt did not block transport');
    };
    $error=$blocked(fn()=>$deliver($scope,$stopped));
    verify(ServerWorkflowMax::staffSelects($scope,$stopped) && $stopped['staff_handoff']
        && (FinalReview::marker($db,900006,'finance')['state']??null)==='returned' && count($calls)===3,
        'next task was committed and selected once, with no transport');
    $failure=new ReflectionMethod(Controller::class,'workflowMaxFailure');
    $_SESSION['rental_notice']=trim($failure->invoke(null,$error));
    $_GET=['id'=>900006];
    $card=SourceCards::show();
    verify(str_contains($card,'Уведомление MAX не отправлено') && str_contains($card,'Сверьте результат предыдущей попытки'),
        'the saved contract card says to reconcile the earlier attempt');

    $legacy=new ServerWorkflowMax($db);
    $legacySlot=new ReflectionMethod($legacy,'assertSlot');
    $legacyProbe=['actor_id'=>$stopped['actor_id'],'request_key'=>'contract-workflow-'.str_repeat('a',64)];
    $blocked(fn()=>$legacySlot->invoke($legacy,$legacyProbe));

    // Model an independently established outcome only in the temporary fixture.
    $db->prepare("UPDATE messaging_attempts SET status='accepted' WHERE id=? AND status='uncertain'")->execute([$attempt['id']]);
    $legacySlot->invoke($legacy,$legacyProbe);
    Service::command(FinalReview::RETURN_FINANCE,$stoppedInput,$fresh);
    $q=$db->prepare('SELECT COUNT(*) FROM messaging_attempts WHERE request_key=?');
    $q->execute([ServerWorkflowMax::requestKey($stopped,true)]);
    verify($fresh===null && (int)$q->fetchColumn()===0 && count($calls)===3,
        'clearing the fence and replaying the command creates neither a fresh event nor an attempt');

    // A receipt in the original workflow namespace retains the same fence in both routes.
    $db->prepare("UPDATE messaging_attempts SET status='uncertain',request_key=? WHERE id=?")->execute([
        'contract-workflow-'.str_repeat('b',64),$attempt['id'],
    ]);
    $blocked(fn()=>$legacySlot->invoke($legacy,$legacyProbe));
    $blocked(fn()=>$assertSlot->invoke($server,$stopped+['request_key'=>ServerWorkflowMax::requestKey($stopped,true)]));
    $db->prepare("UPDATE messaging_attempts SET status='pending' WHERE id=?")->execute([$attempt['id']]);
    $blocked(fn()=>$legacySlot->invoke($legacy,$legacyProbe));
    $db->prepare("UPDATE messaging_attempts SET status='accepted' WHERE id=?")->execute([$attempt['id']]);
    $transportStatus='accepted';
    [$later]=$command(900006,'manager',FinalReview::RESUBMIT,$resubmit);
    verify($deliver($scope,$later)['status']==='accepted' && count($calls)===4,
        'a later genuine handoff sends after reconciliation');
} finally {
    if (is_file($secretPath)) unlink($secretPath);
    if ($old) $db->prepare("UPDATE integration_connections SET enabled=?,revision=? WHERE provider='max'")->execute([$old['enabled'],$old['revision']]);
    else $db->exec("DELETE FROM integration_connections WHERE provider='max'");
    if ($source) $db->prepare("UPDATE integration_configuration_sources SET source_revision=? WHERE provider='max'")->execute([$source['source_revision']]);
    else $db->exec("DELETE FROM integration_configuration_sources WHERE provider='max'");
    $db->prepare('DELETE FROM integration_group_bindings WHERE local_group_id=?')->execute([$group]);
    $db->prepare('DELETE FROM messaging_groups WHERE id=?')->execute([$group]);
    $db->exec('DROP TEMPORARY TABLE IF EXISTS messaging_receipts,messaging_attempts');
}
