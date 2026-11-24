<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{Config, ContractWorkflowRelay, MaxSettings, MaxWorkingDestination, ReservationMaxRelay, Support};
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
$local=APP_ROOT.'/.local/max-working-destination-local.json';
$contract=APP_ROOT.'/.local/contract-workflow-relay-local.json';
$reservation=APP_ROOT.'/.local/reservation-max-relay-local.json';
$vault=APP_ROOT.'/private/integrations/max.enc';
$files=[$marker,$local,$contract,$reservation,$vault];
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
    echo "PASS staged group, exact destination, independent relay scopes, stable bounds, revision and vault.\n";
} finally {
    foreach ($files as $file) if (is_file($file)) unlink($file);
}
