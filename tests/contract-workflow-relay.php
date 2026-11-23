<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth, DB};
use Modules\Integrations\{Config, ContractWorkflowReceiver, ContractWorkflowRelay, ServerWorkflowMax, Support};
use Modules\Rental\{Availability, Service};

function check(bool $condition,string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

function denied(callable $operation,string $expected): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        check($error->getMessage()===$expected,$expected.': '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Unexpected acceptance: '.$expected);
}

function actor(PDO $db,string $role): array
{
    $q=$db->prepare('SELECT u.id,u.auth_version FROM users u JOIN role_profiles p ON p.role_id=u.role
        WHERE p.kind=? AND u.active=1 ORDER BY u.id LIMIT 1');
    $q->execute([$role]);
    return $q->fetch() ?: throw new RuntimeException('Missing fixture role: '.$role);
}

function login(array $actor): void
{
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
}

function command(string $action,array $data,?int &$event): array
{
    return Service::command($action,$data+['command_key'=>bin2hex(random_bytes(16))],$event);
}

$sourceMark=APP_ROOT.'/.local/contract-workflow-relay-source-test.json';
$targetMark=APP_ROOT.'/.local/contract-workflow-relay-test.json';
$vault=APP_ROOT.'/private/integrations/max.enc';
$written=[];
try {
    check(PHP_SAPI==='cli' && getenv('APP_CONTRACT_RELAY_TEST')==='1' && !getenv('APP_STAND')
        && !Config::liveAllowed() && !ContractWorkflowRelay::configured(),'Private fixture required');
    foreach ([$sourceMark,$targetMark,$vault] as $path) {
        check(!file_exists($path) && !is_link($path),'Fixture file already exists');
    }
    $source=DB::pdo();
    check($source->query('SELECT DATABASE()')->fetchColumn()==='ipphp_task_relay_source','Private source database required');
    $config=require APP_ROOT.'/.local/receiver-db.php';
    check($config['host']==='127.0.0.1' && $config['name']==='ipphp_task_relay_target','Private target database required');
    $target=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['name'].';charset=utf8mb4',
        $config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $target->exec("SET time_zone='+03:00'");
    check($target->query('SELECT DATABASE()')->fetchColumn()===$config['name'],'Wrong target database');
    foreach ([$source,$target] as $db) {
        $db->exec('INSERT IGNORE INTO rental_runtime_context(id,epoch,revision) VALUES(1,UUID(),0)');
    }
    $sourceManager=actor($source,'manager');
    $sourceLawyer=actor($source,'lawyer');
    $targetManager=actor($target,'manager');
    $targetLawyer=actor($target,'lawyer');
    $floor=(int)$source->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();
    $afterNumber=(int)$source->query('SELECT MAX(id) FROM contracts')->fetchColumn();
    $group=Support::uuid();
    $chat='-'.random_int(400000000,500000000);
    $target->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json)
        VALUES('max',1,6,'Relay fixture','{}') ON DUPLICATE KEY UPDATE enabled=1,revision=6");
    $target->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,'ТЕСТОВАЯ')")->execute([$group,$chat]);
    $written[]=$vault;
    Config::saveSecrets('max',['secret'=>'fake-max-contract-relay','approved_chat_ids'=>[$chat],'_owner_bound_revision'=>6]);
    $now=time();
    $shared=[
        'enabled'=>true,'key'=>bin2hex(random_bytes(32)),'source'=>Support::uuid(),
        'source_epoch'=>$source->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn(),
        'valid_from'=>$now,'expires_at'=>$now+600,'event_floor'=>$floor,'future_after'=>$afterNumber,
        'contract_ids'=>[],'actor_map'=>[$sourceManager['id']=>$targetManager['id'],$sourceLawyer['id']=>$targetLawyer['id']],
        'catalog_versions'=>['submit'=>4,'lawyerReturn'=>3,'resubmit'=>3],
    ];
    $written[]=$sourceMark;
    file_put_contents($sourceMark,Support::json($shared+['app_root'=>APP_ROOT,'database'=>'ipphp_task_relay_source']));
    $targetScope=$shared+[
        'database'=>'ipphp_task_relay_target','group_name'=>'ТЕСТОВАЯ','group_id'=>$group,'chat_id'=>$chat,
        'revision'=>6,'vault_sha256'=>hash_file('sha256',$vault),
        'destination_epoch'=>$target->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn(),
    ];
    $written[]=$targetMark;
    file_put_contents($targetMark,Support::json($targetScope));

    $sent=[];
    $uncertain=false;
    $receiver=new ContractWorkflowReceiver($target,static function(string $method,string $path,array $headers,string $raw) use($chat,&$sent,&$uncertain): array {
        check($method==='POST' && $path==='/messages?chat_id='.$chat,'Wrong provider path or recipient');
        $message=Support::decode($raw);
        check(($message['format'] ?? null)==='html','HTML flag missing');
        $sent[]=$message['text'];
        if ($uncertain) throw new RuntimeException('Fixture timeout');
        return ['status'=>200,'raw'=>Support::json([
            'message'=>['body'=>['mid'=>'fixture-'.count($sent)],'recipient'=>['chat_id'=>$chat]],
        ])];
    });
    $received=[];
    $relay=new ContractWorkflowRelay($source,static function(string $method,string $url,array $headers,string $raw) use($receiver,&$received): array {
        check($method==='POST' && str_ends_with($url,ContractWorkflowRelay::PATH),'Wrong relay path');
        $time=substr($headers[1],strlen('X-IpPhp-Relay-Time: '));
        $signature=substr($headers[2],strlen('X-IpPhp-Relay-Signature: '));
        $received[]=[$raw,$time,$signature];
        return ['status'=>200,'raw'=>Support::json($receiver->accept($raw,$time,$signature))];
    });
    $_SERVER['REQUEST_METHOD']='POST';
    login($sourceManager);
    $party=command('party.save',['name'=>'Учебный контрагент MAX','party_type'=>'person'],$partyEvent)['id'];
    $start=date('Y-m-d',strtotime('+1 day'));
    $end=date('Y-m-d',strtotime('+10 days'));
    $rooms=array_values(array_filter(Availability::items($start,$end),static fn(array $row): bool=>$row['available']));
    $ids=array_column(array_slice($rooms,0,2),'id');
    check(count($ids)===2,'Two available fixture objects required');
    $input=['number'=>'RELAY-'.bin2hex(random_bytes(4)),'party_id'=>$party,'start_date'=>$start,'end_date'=>$end,
        'objects'=>$ids,'term_amount'=>array_fill_keys($ids,'10000.00'),'amount'=>'20000.00','intent'=>'submit',
        'command_key'=>bin2hex(random_bytes(16))];
    $created=Service::command('contract.create',$input,$createdEvent);
    $id=(int)$created['id'];
    check(Service::get($source,'contracts',$id)['status']==='legal','Create did not land at legal');
    check($relay->afterCommittedCommand($createdEvent,$input['command_key'],Auth::user())['status']==='accepted','Create handoff');
    check($receiver->accept(...$received[0])['status']==='accepted' && count($sent)===1,'Duplicate receipt sent again');
    Service::command('contract.create',$input,$repeat);
    check($repeat===null,'Command retry returned a fresh event');
    denied(fn()=>$receiver->accept($received[0][0].' ',$received[0][1],$received[0][2]),'CONTRACT_RELAY_SIGNATURE');
    $expired=(string)($now-91);
    denied(fn()=>$receiver->accept($received[0][0],$expired,ContractWorkflowRelay::signature($received[0][0],(int)$expired,$shared['key'])),
        'CONTRACT_RELAY_SIGNATURE');
    $old=Support::decode($received[0][0]);
    $old['event_id']=$floor;
    $send=static function(array $plan) use($receiver,$shared): array {
        $raw=Support::json($plan);$time=time();
        return $receiver->accept($raw,(string)$time,ContractWorkflowRelay::signature($raw,$time,$shared['key']));
    };
    denied(fn()=>$send($old),'CONTRACT_RELAY_EVENT_SCOPE');
    denied(fn()=>$send(Support::decode($received[0][0])+['chat_id'=>'-1']),'CONTRACT_RELAY_PAYLOAD');

    login($sourceLawyer);
    $return=['id'=>$id,'version'=>1,'reason'=>'Уточнить условия <тест>','command_key'=>bin2hex(random_bytes(16))];
    Service::command('contract.return',$return,$returnEvent);
    check($relay->afterCommittedCommand($returnEvent,$return['command_key'],Auth::user())['status']==='accepted','Lawyer return handoff');
    check(Service::get($source,'contracts',$id)['return_to']==='manager','Contract not returned to manager');
    login($sourceManager);
    $resubmit=array_replace($input,['id'=>$id,'version'=>2,'command_key'=>bin2hex(random_bytes(16))]);
    Service::command('contract.edit',$resubmit,$resubmitEvent);
    check($relay->afterCommittedCommand($resubmitEvent,$resubmit['command_key'],Auth::user())['status']==='accepted','Resubmission handoff');
    check(count($sent)===3,'Three provider requests expected');
    foreach ($sent as $text) {
        check(substr_count($text,'Объект: <b>')===2 && str_contains($text,'с <b>') && str_contains($text,'по <b>'),'Objects or period missing');
    }
    check(str_contains($sent[0],'ЗАРЕГИСТРИРОВАН НОВЫЙ КОНТРАГЕНТ И ДОГОВОР'),'Wrong submission title');
    check(str_contains($sent[1],'ВОЗВРАЩЁН МЕНЕДЖЕРУ') && str_contains($sent[1],'&lt;тест&gt;'),'Wrong lawyer return');
    check(str_contains($sent[2],'ПОВТОРНО НАПРАВЛЕН ЮРИСТУ'),'Wrong resubmission title');

    $uncertain=true;
    login($sourceLawyer);
    $return['version']=3;
    $return['command_key']=bin2hex(random_bytes(16));
    Service::command('contract.return',$return,$retryReturnEvent);
    check($relay->afterCommittedCommand($retryReturnEvent,$return['command_key'],Auth::user())['status']==='uncertain','Timeout not held');
    check($receiver->accept(...$received[3])['status']==='uncertain' && count($sent)===4,'Unknown receipt replayed');
    login($sourceManager);
    $resubmit['version']=4;
    $resubmit['command_key']=bin2hex(random_bytes(16));
    Service::command('contract.edit',$resubmit,$heldEvent);
    denied(fn()=>$relay->afterCommittedCommand($heldEvent,$resubmit['command_key'],Auth::user()),ServerWorkflowMax::RECONCILIATION_REQUIRED);
    check(count($sent)===4,'Unknown send did not fence next delivery');
    check((int)$source->query('SELECT COUNT(*) FROM messaging_attempts')->fetchColumn()===0,'Source attempted direct MAX send');
    echo "PASS create to legal, lawyer return, resubmission, approved HTML, signed relay, no repeat, unknown-result fence, source attempts 0.\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().' at '.$error->getLine().PHP_EOL);
    $failure=true;
} finally {
    foreach ($written as $path) {
        if (is_file($path)) unlink($path);
    }
}
if (!empty($failure)) exit(1);
