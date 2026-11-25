<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{Config, ContractWorkflowRelay, MaxSettings, MaxWorkingDestination, Messaging, MessageTransport, ReservationMaxRelay, ServerManualMax, Support};
use Modules\Rental\WorkflowEvents;

require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): never {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function check(bool $condition,string $error): void
{
    if (!$condition) throw new RuntimeException($error);
}

function denied(callable $operation,string $message): void
{
    try { $operation(); }
    catch (RuntimeException $error) {
        check(str_contains($error->getMessage(),$message),'Unexpected refusal: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}

$db=DB::pdo();
$database=$db->query('SELECT DATABASE()')->fetchColumn();
check(PHP_SAPI==='cli' && !getenv('APP_STAND') && !Config::liveAllowed()
    && getenv('APP_MAX_WORKING_DESTINATION_FIXTURE')==='max-destination-fixture'
    && $database==='ipphp_task_max_destination','A private destination fixture is required');
$marker=APP_ROOT.'/.local/max-working-destination.json';
$baseMarker=APP_ROOT.'/.local/workflow-max-destination-fixture.json';
$local=APP_ROOT.'/.local/max-working-destination-local.json';
$contract=APP_ROOT.'/.local/contract-workflow-relay-local.json';
$reservation=APP_ROOT.'/.local/reservation-max-relay-local.json';
$vault=APP_ROOT.'/private/integrations/max.enc';
$files=[$marker,$baseMarker,$local,$contract,$reservation,$vault];
foreach ($files as $file) check(!file_exists($file) && !is_link($file),'Private fixture file already exists');
check(!$db->query("SELECT 1 FROM integration_connections WHERE provider='max'")->fetchColumn(),'A fresh MAX fixture is required');

try {
    $admin=(int)$db->query("SELECT id FROM users WHERE active=1 AND role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    check($admin>0,'Admin fixture required');
    $testGroup=Support::uuid();
    $testChat='-14230303030001';
    $workChat='-14230303030002';
    $testName='ТЕСТОВАЯ';
    $workName='Рабочая учебная';
    $db->exec("INSERT IGNORE INTO rental_number_guard(id) VALUES(1)");
    $db->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json) VALUES('max',1,6,'MAX','{}')");
    $db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name,enabled) VALUES(?,'max',?,?,1)")
        ->execute([$testGroup,$testChat,$testName]);
    $secret=['secret'=>'fake-bot-not-for-transport','approved_chat_ids'=>[$testChat],'_owner_bound_revision'=>6];
    Config::saveSecrets('max',$secret);
    $vaultHash=hash_file('sha256',$vault);
    $groups=[$testChat=>['name'=>$testName,'enabled'=>true],$workChat=>['name'=>$workName,'enabled'=>false]];
    check(MaxSettings::stageDisabled($db,6,$secret,$groups,true),'Disabled group could not be staged');
    $work=$db->prepare("SELECT id,enabled FROM messaging_groups WHERE provider='max' AND chat_id=?");
    $work->execute([$workChat]);$staged=$work->fetch(PDO::FETCH_ASSOC);
    check(!$staged['enabled'],'Staging enabled the group');
    $changed=$groups;$changed[$workChat]['enabled']=true;
    check(!MaxSettings::stageDisabled($db,6,$secret,$changed,true),'Active group edit bypassed the existing profile path');
    $base=['database'=>$database,'revision'=>6,'group_id'=>$testGroup,'chat_id'=>$testChat,
        'group_name'=>$testName,'vault_sha256'=>$vaultHash];
    denied(fn()=>MaxWorkingDestination::change($db,$base,'work',0,$admin),'подтвердите допуск');

    $sources=['contract'=>Support::uuid(),'reservation'=>Support::uuid()];
    $binding=['schema'=>1,'scope'=>'native-max-working-destination','project_id'=>WorkflowEvents::PROJECT,
        'test_group_id'=>$testGroup,'test_chat_id'=>$testChat,'group_id'=>$staged['id'],'chat_id'=>$workChat,
        'group_name'=>$workName,'membership_verified_at'=>time(),'relay_sources'=>$sources]+$base;
    file_put_contents($marker,Support::json($binding));
    check(!MaxWorkingDestination::prepareGroup($db,$base,false)['available'],'A dry run enabled the group');
    check(MaxWorkingDestination::prepareGroup($db,$base,true)['destination']==='test','Preparation changed the selected destination');
    MaxWorkingDestination::change($db,$base,'work',0,$admin);
    $choice=MaxWorkingDestination::selection($db);
    check(MaxWorkingDestination::route($db,$base,$choice['event_floor'])['chat_id']===$testChat,'Existing event changed destination');
    check(MaxWorkingDestination::route($db,$base,$choice['event_floor']+1)['chat_id']===$workChat,'New native event missed working group');
    check(MaxWorkingDestination::route($db,$base,$choice['fact_floor']+1,true)['chat_id']===$workChat,'New native reservation missed working group');
    check(MaxWorkingDestination::relayed($db,$base,'contract',$sources['contract'],false)['chat_id']===$testChat,'Legacy relay left TEST');
    check(MaxWorkingDestination::relayed($db,$base,'contract',$sources['contract'],true)['chat_id']===$workChat,'Scoped contract relay did not reach the authorized destination');
    check(MaxWorkingDestination::relayed($db,$base,'reservation',$sources['reservation'],true)['chat_id']===$workChat,'Scoped booking relay did not reach the authorized destination');
    denied(fn()=>MaxWorkingDestination::relayed($db,$base,'reservation',$sources['contract'],true),'RELAY_NOT_ADMITTED');
    denied(fn()=>MaxWorkingDestination::recipientSecret($secret,$workChat,MaxWorkingDestination::original($base),[$testChat,$workChat]),'GROUP_CHANGED');
    denied(fn()=>MaxWorkingDestination::change($db,$base,'test',0,$admin),'Выбор группы изменился');
    $db->exec("INSERT INTO rental_events(actor_role,action,description) VALUES('admin','fixture.route','Fixture routing boundary')");
    $eventId=(int)$db->lastInsertId();
    $db->exec("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('fixture_route','before-return','{}',0)");
    $factId=(int)$db->lastInsertId();
    MaxWorkingDestination::change($db,$base,'test',1,$admin);
    check(MaxWorkingDestination::route($db,$base,$eventId)['chat_id']===$workChat
        && MaxWorkingDestination::route($db,$base,$factId,true)['chat_id']===$workChat,'Switch moved previously committed work events');
    check(MaxWorkingDestination::route($db,$base,$eventId+1)['chat_id']===$testChat
        && MaxWorkingDestination::route($db,$base,$factId+1,true)['chat_id']===$testChat,'New events did not return to TEST');

    if (PHP_OS_FAMILY==='Windows') {
        file_put_contents($contract,Support::json(['source'=>$sources['contract']]));
        file_put_contents($reservation,Support::json(['source'=>$sources['reservation']]));
        file_put_contents($local,Support::json(['schema'=>1,'scope'=>'local-relay-max-working-destination','root'=>APP_ROOT,
            'database'=>$database,'group_name'=>$workName,'chat_id'=>$workChat,'relay_sources'=>$sources]));
        MaxWorkingDestination::change($db,$base,'work',2,$admin);
        check(MaxWorkingDestination::localChoice($db,'contract',$sources['contract'],$eventId+1)
            && MaxWorkingDestination::localChoice($db,'reservation',$sources['reservation'],$factId+1),'Local switch did not select both admitted relay sources');
        check(!MaxWorkingDestination::localChoice($db,'contract',Support::uuid(),$eventId+1)
            && !MaxWorkingDestination::localChoice($db,'reservation',$sources['reservation'],$factId),'Changed local choice routed an earlier/cloned event');
        unlink($local);
        denied(fn()=>MaxWorkingDestination::localChoice($db,'contract',$sources['contract'],$eventId+1),'ADMISSION');
    }
    $now=time();$epoch=Support::uuid();$hmac=str_repeat('a',64);
    $scope=['source'=>$sources['contract'],'source_epoch'=>$epoch,'key'=>$hmac,'actor_map'=>[$admin=>$admin],
        'valid_from'=>$now-1,'expires_at'=>$now+100,'event_floor'=>0,'contract_ids'=>[42],'future_after'=>0,
        'catalog_versions'=>['legalApprove'=>4]];
    $payload=['source'=>$sources['contract'],'epoch'=>$epoch,'event_id'=>1,'recorded_at'=>$now,
        'contract_id'=>42,'actor_id'=>$admin,'action'=>'contract.legal','kind'=>'legalApprove','version'=>4,
        'values'=>['number'=>'42','party'=>'Учебный клиент','contractStart'=>'25.11.2026','contractEnd'=>'30.11.2026','contractObjectLines'=>'[]']];
    $verify=static function(array $event) use($scope,$now,$hmac):array {
        $raw=Support::json($event);
        return ContractWorkflowRelay::verify($raw,(string)$now,ContractWorkflowRelay::signature($raw,$now,$hmac),$scope,$now);
    };
    check($verify($payload)===$payload && $verify($payload+['destination'=>'work'])===$payload+['destination'=>'work'],
        'Approved contract wire shapes changed');
    denied(fn()=> $verify($payload+['destination'=>$workChat]),'CONTRACT_RELAY_PAYLOAD');
    $booking=['key'=>str_repeat('a',64),'action'=>'reservation.cancel','recorded_at'=>$now,'actor_id'=>$admin,'version'=>1,
        'values'=>['number'=>'42','context'=>'Учебный объект','party'=>'Учебный клиент','manager'=>'Менеджер',
            'effective'=>'30.11.2026','reason'=>'Учебная причина','actor'=>'Менеджер']];
    $verifyBooking=static function(array $event) use($now,$hmac):array {
        $raw=Support::json($event);
        return ReservationMaxRelay::verifyRequest($raw,(string)$now,ReservationMaxRelay::signature($raw,$now,$hmac),$hmac,$now);
    };
    check($verifyBooking($booking)===$booking && $verifyBooking($booking+['destination'=>'work'])===$booking+['destination'=>'work'],
        'Approved reservation wire shapes changed');
    denied(fn()=> $verifyBooking($booking+['destination'=>$workChat]),'RESERVATION_RELAY_PAYLOAD');
    check((int)Config::get($db,'max')['revision']===6 && hash_file('sha256',$vault)===$vaultHash
        && Config::secrets('max')===$secret,'Group operations changed the bot credentials');
    check((int)$db->query('SELECT COUNT(*) FROM messaging_attempts')->fetchColumn()===0,'Configuration attempted to send');
    $adminVersion=(int)$db->query("SELECT auth_version FROM users WHERE id=".$admin)->fetchColumn();
    $_SESSION=['user_id'=>$admin,'auth_version'=>$adminVersion];
    file_put_contents($baseMarker,Support::json($base));
    $beforeChoice=MaxWorkingDestination::selection($db);
    $calls=[];
    $body='Проверка рабочей группы';
    $key=Support::uuid();
    $manual=null;
    $manual=new ServerManualMax($db,static function(string $method,string $path,array $headers,?string $raw) use(
        &$manual,&$calls,$db,$workChat,$body,$key
    ):array {
        check(!$db->inTransaction(),'Network started before committed claim');
        $lock=$db->query("SELECT IS_USED_LOCK('ipphp-provider:max') = CONNECTION_ID()")->fetchColumn();
        check((int)$lock===1,'Provider lock not held');
        $pending=$db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE request_key=? AND target=? AND status='pending'");
        $pending->execute([$key,$workChat]);
        check((int)$pending->fetchColumn()===1,'Missing committed manual claim');
        $url='https://platform-api2.max.ru'.$path;
        denied(fn()=> $manual->assertHttpRequest('GET',$url,$raw),'OBJECT_MAX_HTTP_REJECTED');
        denied(fn()=> $manual->assertHttpRequest($method,$url,MessageTransport::maxPayload('другой текст')),'OBJECT_MAX_HTTP_REJECTED');
        denied(fn()=> $manual->assertHttpRequest($method,'https://example.invalid'.$path,$raw),'OBJECT_MAX_HTTP_REJECTED');
        $manual->assertHttpRequest($method,$url,$raw);
        denied(fn()=> $manual->assertHttpRequest($method,$url,$raw),'MAX_MANUAL_REQUEST_REJECTED');
        $calls[]=[$method,$path,$raw];
        check($raw===MessageTransport::maxPayload($body),'Manual body changed');
        return ['status'=>200,'raw'=>Support::json(['message'=>['body'=>['mid'=>'fixture-mid'],'recipient'=>['chat_id'=>$workChat]]])];
    });

    denied(fn()=> (new ServerManualMax($db))->dispatch($admin,$key,[$staged['id']],$body),'MAX_MANUAL_ADMISSION_REQUIRED');
    denied(fn()=> $manual->dispatch($admin,$key,[$testGroup],$body),'MAX_MANUAL_WORK_ONLY');
    denied(fn()=> $manual->dispatch($admin,$key,[$testGroup,$staged['id']],$body),'MAX_MANUAL_WORK_ONLY');
    denied(fn()=> $manual->dispatch($admin,$key,[$workChat],$body),'MAX_MANUAL_WORK_ONLY');
    denied(fn()=> $manual->assertHttpRequest('POST','https://platform-api2.max.ru/messages?chat_id='.$workChat,
        MessageTransport::maxPayload($body)),'MAX_MANUAL_REQUEST_REJECTED');

    $db->prepare("INSERT INTO users(username,password_hash,name,role) VALUES(?,'fixture','MAX fixture reader','manager')")
        ->execute(['max-fixture-reader-'.bin2hex(random_bytes(6))]);
    $readerId=(int)$db->lastInsertId();
    $db->exec("INSERT IGNORE INTO role_permissions(role_id,permission_key) VALUES ('manager','messaging.read'),('manager','messaging.send')");
    $db->exec("UPDATE roles SET grants_configured=1 WHERE id='manager'");
    $db->exec("INSERT INTO role_page_grants(role_id,page_key,level) VALUES ('manager','connections.max',1)
        ON DUPLICATE KEY UPDATE level=1");
    $_SESSION=['user_id'=>$readerId,'auth_version'=>1];
    denied(fn()=> $manual->dispatch($readerId,$key,[$staged['id']],$body),'MAX_MANUAL_FORBIDDEN');
    denied(fn()=> $manual->dispatch($admin,$key,[$staged['id']],$body),'MAX_MANUAL_FORBIDDEN');
    $_SESSION=['user_id'=>$admin,'auth_version'=>$adminVersion];

    $beforeMarker=file_get_contents($marker);
    unlink($marker);
    denied(fn()=> $manual->dispatch($admin,$key,[$staged['id']],$body),'MAX_MANUAL_ADMISSION_REQUIRED');
    file_put_contents($marker,$beforeMarker);
    $db->prepare("UPDATE messaging_groups SET enabled=0 WHERE id=?")->execute([$staged['id']]);
    denied(fn()=> $manual->dispatch($admin,$key,[$staged['id']],$body),'MAX_MANUAL_ADMISSION_REQUIRED');
    $db->prepare("UPDATE messaging_groups SET enabled=1 WHERE id=?")->execute([$staged['id']]);

    $oldKey=Support::uuid();
    $old=(new Messaging($db,Config::store()))->dispatch('max',$admin,$oldKey,'send',[$staged['id']],$body);
    check($old[0]['result_code']==='EXTERNAL_ACCESS_NOT_AUTHORIZED','Global gate unexpectedly admitted generic MAX');
    check($manual->dispatch($admin,$oldKey,[$staged['id']],$body)[0]['id']===$old[0]['id'] && !$calls,
        'A failed generic claim was replayed under the new manual scope');
    $db->prepare("UPDATE messaging_attempts SET created_at=NOW(6)-INTERVAL 2 SECOND WHERE id=?")->execute([$old[0]['id']]);

    $sent=$manual->dispatch($admin,$key,[$staged['id']],$body);
    check($sent[0]['status']==='accepted' && $sent[0]['remote_id']==='fixture-mid'
        && $calls===[['POST','/messages?chat_id='.$workChat,MessageTransport::maxPayload($body)]],'Manual delivery mismatch');
    check($manual->dispatch($admin,$key,[$staged['id']],$body)[0]['id']===$sent[0]['id'],'Duplicate claim ID changed');
    denied(fn()=> $manual->dispatch($admin,$key,[$staged['id']],'Изменённый текст'),'IDEMPOTENCY_CONFLICT');

    $db->prepare("UPDATE messaging_attempts SET status='uncertain' WHERE id=?")->execute([$sent[0]['id']]);
    denied(fn()=> $manual->dispatch($admin,Support::uuid(),[$staged['id']],$body),'MAX_MANUAL_RECONCILIATION_REQUIRED');
    check(count($calls)===1 && (int)$db->query('SELECT COUNT(*) FROM messaging_attempts')->fetchColumn()===2,
        'Denied sends appended claims or made requests');
    check(MaxWorkingDestination::selection($db)===$beforeChoice && !Config::liveAllowed()
        && hash_file('sha256',$vault)===$vaultHash && Config::secrets('max')===$secret,
        'Manual send changed automatic destination or provider access');
    echo "PASS staged group, exact destination, independent relay scopes, stable bounds, revision and vault.\n";
    echo "PASS manual working group, rights, committed claim, single fake POST, guarded retry and unchanged automatic route.\n";
} finally {
    foreach ($files as $file) if (is_file($file)) unlink($file);
}
