<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{BookingMax, Config, ReservationMaxRelay, Support, TemplateCatalog};
use Modules\Rental\{Availability, BookingNotices, Service, WorkflowEvents};

function check(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);}
$db=DB::pdo();
$database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
check(PHP_SAPI==='cli' && getenv('APP_RESERVATION_MAX_TEST')==='1' && !getenv('APP_STAND')
    && !Config::liveAllowed() && preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',$database)===1,'Isolated disposable database required');
$marker=APP_ROOT.'/.local/reservation-max-local.json';
$vault=APP_ROOT.'/private/integrations/max.enc';
check(!file_exists($marker) && !is_link($marker) && !is_link($vault),'Fixture would overwrite an admission or symlink');
$before=is_file($vault)?file_get_contents($vault):null;
try {
    $actor=$db->query("SELECT u.id,u.auth_version,u.name FROM users u JOIN role_profiles r ON r.role_id=u.role
        WHERE r.kind='manager' AND u.active=1 ORDER BY u.id LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $day=new DateTimeImmutable('today');
    $date=static fn(int $n): string=>$day->modify("+$n days")->format('Y-m-d');
    $room=array_values(array_filter(Availability::items($date(0),$date(0),null,null,true),static fn($r)=>!$r['disabled']))[0]['id'];
    $object=$db->prepare('SELECT name FROM rental_objects WHERE id=?');$object->execute([$room]);$objectName=$object->fetchColumn();
    $ru=static fn(int $n):string=>$day->modify("+$n days")->format('d.m.Y');
    $command=static fn(string $action,array $input):array=>Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);
    $create=static fn(string $name,int $deadline,int $start=0,string $comment=''):int=>$command('reservation.create',
        ['client_name'=>$name,'object_id'=>$room,'start_date'=>$date($start),'until_date'=>$date($deadline),'comment'=>$comment])['id'];
    $b=$create('Учебный отказ MAX 15',2);
    $c=$create('Учебное истечение MAX 15',3);
    $old=$create('Учебная запись до допуска MAX 15',10);
    $legacy=$create('Учебный старый срок MAX 15',0);
    $db->prepare('UPDATE reservations SET until_date=DATE_SUB(CURRENT_DATE,INTERVAL 3 DAY) WHERE id=?')->execute([$legacy]);
    $sent=[];$unknown=false;
    $target='-'.random_int(700000000,999999999);
    $fixture=static function(string $method,string $path,array $headers,string $raw)use(&$sent,&$unknown,$target):array {
        check($method==='POST' && $path==='/messages?chat_id='.rawurlencode($target),'Outside the admitted address');
        $sent[]=Support::decode($raw)['text'];
        return ['status'=>200,'raw'=>Support::json($unknown?['ok'=>true]:[
            'message'=>['body'=>['mid'=>'fixture-'.count($sent)],'recipient'=>['chat_id'=>$target]]])];
    };
    $runner=new BookingMax($db,Config::store(),$fixture);
    check($runner->run($date(0))['state']==='disabled' && !$sent,'A disabled lane reached transport');

    $catalog=new TemplateCatalog($db,Config::store());
    $oldTemplate=$db->prepare("INSERT INTO integration_template_catalog(channel,source_key,source_id,name,trigger_text,format,body_text,
        version,source_sha256,source_path,source_version) VALUES('max',?,?,?,'','plain',?,1,?,?, 'reservation-notices-v1')");
    foreach (['reservation.create','reservation.extend'] as $action) {
        [$key,$title]=BookingNotices::TEMPLATES[$action];
        $body=$title.". Заявка №{number}. Помещение: {context}. Клиент: {party}. Срок: {effective}. Ответственный: {manager}. {reason}";
        $definition=['id'=>$key,'name'=>$title,'format'=>'plain','body'=>$body,'version'=>1];
        $oldTemplate->execute([$key,$key,$title,$body,hash('sha256',Support::canonical($definition)),
            Config::store()->put('catalog-records',Support::json($definition))]);
    }
    $catalog->save('max','reservationExtended',1,['format'=>'plain','body'=>'Пользовательская редакция {number}'],'Редактор теста');
    $versionQuery=$db->query("SELECT version,body_text FROM integration_template_catalog WHERE channel='max' AND source_key='staffHandoff'");
    $neighbor=$versionQuery->fetch();
    $preview=$catalog->installReservationNotices(false);
    check($preview['reservationCreated']['status']==='can_upgrade' && $preview['reservationCancelled']['status']==='missing','Catalog dry run');
    $installed=$catalog->installReservationNotices();
    check($installed['reservationCreated']===['status'=>'upgraded','version'=>2]
        && $installed['reservationExtended']===['status'=>'preserved','version'=>2]
        && $installed['reservationCancelled']===['status'=>'installed','version'=>1],'Unexpected catalog migration result');
    check($db->query("SELECT body_text FROM integration_template_catalog WHERE source_key='reservationExtended'")->fetchColumn()==='Пользовательская редакция {number}',
        'Installer overwrote a user edit');
    check((int)$db->query("SELECT COUNT(*) FROM integration_template_history WHERE source_key='reservationCreated' AND version=1")->fetchColumn()===1,
        'Previous factory version missing from history');
    $versionQuery->execute();check($neighbor===$versionQuery->fetch(),'Unrelated template changed');
    $current=Support::decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/reservation-max-templates.json'));
    $catalog->save('max','reservationExtended',2,['format'=>'plain','body'=>$current['reservationExtended']['body']],'Редактор теста');
    $versions=$db->query("SELECT source_key,version FROM integration_template_catalog WHERE channel='max' AND source_key LIKE 'reservation%'")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $again=$catalog->installReservationNotices();
    foreach ($again as $key=>$item) check($item['status']==='preserved' && $item['version']===(int)$versions[$key],'Repeated install changed a revision');

    $connection=Config::get($db,'max');
    Config::save($db,'max',Support::decode($connection['config_json']),(int)$connection['revision'],true);
    $revision=(int)$connection['revision']+1;
    $group=Support::uuid();
    $db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,'Учебная MAX 15')")->execute([$group,$target]);
    Config::saveSecrets('max',['secret'=>'fixture-no-external-use','approved_chat_ids'=>[$target],'_owner_bound_revision'=>$revision]);
    $floor=(int)$db->query('SELECT MAX(id) FROM rental_facts')->fetchColumn();
    $scope=[
        'schema'=>1,'enabled'=>true,'project_id'=>WorkflowEvents::PROJECT,'scope'=>'reservation-notices','database'=>$database,
        'epoch'=>$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn(),
        'event_floor'=>$floor,'valid_from'=>time(),'expires_at'=>time()+900,
        'group_id'=>$group,'group_name'=>'Учебная MAX 15','chat_id'=>$target,'revision'=>$revision,
        'vault_sha256'=>hash_file('sha256',$vault),'catalog_versions'=>array_map('intval',$versions),
    ];
    $a=$create('Учебный клиент MAX 15',2,1,'Позвонить после 14:30.');
    $command('reservation.cancel',['id'=>$b,'version'=>1,'client_declined'=>'1','reason'=>'Учебный отказ']);
    BookingNotices::dated($date(1),true);
    $command('reservation.extend',['id'=>$a,'version'=>1,'until_date'=>$date(6)]);
    BookingNotices::dated($date(5),true);
    check(!BookingNotices::dated($date(5),true)['applied'],'Dated replay changed business state');
    check(Service::get($db,'reservations',$legacy)['status']==='expired','Pre-admission deadline was not closed in ERP');

    file_put_contents($marker,Support::json(array_replace($scope,['group_name'=>'Другая группа'])));
    try { $runner->run($date(5));throw new LogicException('Wrong group was admitted'); }
    catch (RuntimeException $e) {check($e->getMessage()==='WORKFLOW_MAX_GROUP_CHANGED' && !$sent,'Wrong group reached transport');}
    file_put_contents($marker,Support::json(array_replace($scope,['enabled'=>false])));
    check($runner->run($date(5))['state']==='disabled' && !$sent,'Disabled marker reached transport');
    file_put_contents($marker,Support::json($scope));
    $run=$runner->run($date(5));
    check($run['accepted']===5 && $run['skipped']===2 && count($sent)===5,'Expected five notices and one stale reminder: '.Support::json([$run,count($sent)]));
    $name=$actor['name'];
    $expected=[
        "Резервирование №$a создано\nПомещение $objectName · Учебный клиент MAX 15\nПланируемое начало аренды · ".$ru(1)
            ."\nРезервирование действует по ".$ru(2)." включительно\nОтветственный · $name\nКомментарий · Позвонить после 14:30.",
        "Резервирование №$a продлено\nПомещение $objectName · Учебный клиент MAX 15\nПрежний срок · по ".$ru(2)
            ." включительно\nНовый срок · по ".$ru(6)." включительно\nОтветственный · $name",
        "Резервирование №$b снято\nПомещение $objectName · Учебный отказ MAX 15\nПричина · Клиент отказался. Учебный отказ\nСнял · $name",
        "Резервирование №$a заканчивается завтра\nПомещение $objectName · Учебный клиент MAX 15\nПоследний день · ".$ru(6)
            ."\nОтветственный · $name\nЕсли клиенту нужно больше времени, продлите резервирование.",
        "Резервирование №$c снято по сроку\nПомещение $objectName · Учебное истечение MAX 15\nПоследний день резервирования · ".$ru(3)
            ."\nОтветственный · $name\nЗаявка сохранена в истории.",
    ];
    foreach ($expected as $message) check(count(array_keys($sent,$message,true))===1,'HTTP text differs: '.$message);
    check(count(array_filter($sent,static fn($s)=>str_contains($s,'Резервирование №'.$old.' ')))===0,'Pre-admission event was replayed');
    check($runner->run($date(5))['accepted']===0 && count($sent)===5,'Replay reached transport');


    $exports=$runner->exports($floor,$scope['valid_from'],$date(5));
    $mine=array_values(array_filter($exports,static fn($e)=>$e['values']['number']===(string)$a));
    check(array_column($mine,'action')===['reservation.create','reservation.extend','reservation.reminder'],'Local export did not preserve exact eligible booking events');
    check(!in_array((string)$old,array_column(array_column($exports,'values'),'number'),true),'Local export replayed a pre-admission event');
    check(!$runner->exports($floor,$scope['valid_from'],$date(5),[PHP_INT_MAX]),'Unmapped local actor was exported');
    check($runner->exports($floor,$scope['valid_from'],$date(5),[(int)$actor['id']])===$exports,'Mapped actor export changed the event');
    $payload=Support::json($mine[0]);
    $now=time();
    $relayKey=bin2hex(random_bytes(32));
    $signed=ReservationMaxRelay::signature($payload,$now,$relayKey);
    $event=ReservationMaxRelay::verifyRequest($payload,(string)$now,$signed,$relayKey,$now);
    check($event===$mine[0],'Relay did not preserve the signed event');
    try { ReservationMaxRelay::verifyRequest($payload.' ',(string)$now,$signed,$relayKey,$now);throw new LogicException('Changed payload was accepted'); }
    catch (RuntimeException $e) { check($e->getCode()===403,'Tampered payload check'); }
    try { ReservationMaxRelay::verifyRequest($payload,(string)$now,$signed,$relayKey,$now+91);throw new LogicException('Stale signature was accepted'); }
    catch (RuntimeException $e) { check($e->getCode()===403,'Stale signature check'); }
    $relay=new ReservationMaxRelay($db);
    $receive=new ReflectionMethod($relay,'enqueue');
    $binding=['source'=>Support::uuid(),'group_id'=>$group,'started_at'=>$scope['valid_from'],'key'=>$relayKey];
    check($receive->invoke($relay,$event,$binding)===$event['key'],'Signed event was not queued');
    check($receive->invoke($relay,$event,$binding)===$event['key'],'Request retry was not acknowledged');
    $rowCount=$db->prepare('SELECT COUNT(*) FROM rental_facts WHERE namespace=?');
    $rowCount->execute([ReservationMaxRelay::INBOX]);
    check((int)$rowCount->fetchColumn()===1,'Request retry produced a second event');
    $event['values']['party']='Changed';
    try { $receive->invoke($relay,$event,$binding);throw new LogicException('Retry changed queued content'); }
    catch (RuntimeException $e) { check($e->getCode()===409,'A key conflict was not rejected'); }
    $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
        ->execute([ReservationMaxRelay::HANDED_OFF,$mine[0]['key'],'{}']);
    check(!in_array($mine[0]['key'],array_column($runner->exports($floor,$scope['valid_from'],$date(5)),'key'),true),
        'Confirmed local event was offered again');

    $blank=$create('Учебная заявка без комментария',9,2,'   ');
    check($runner->run($date(5))['accepted']===1 && count($sent)===6
        && $sent[5]==="Резервирование №$blank создано\nПомещение $objectName · Учебная заявка без комментария\nПланируемое начало аренды · "
            .$ru(2)."\nРезервирование действует по ".$ru(9)." включительно\nОтветственный · $name",'Blank comment left an extra line');
    $revoked=$create('Учебный отзыв роли MAX 15',9);
    $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$actor['id']]);
    check($runner->run($date(5))['accepted']===0 && count($sent)===6,'Revoked actor reached transport');
    $_SESSION['auth_version']++;
    $unknown=true;
    $create('Учебный неизвестный MAX 15',9);
    $out=$runner->run($date(5));
    check($out['state']==='reconciliation' && $out['uncertain']===1 && count($sent)===7,'Unknown acknowledgement not held');
    $create('Учебный стоп MAX 15',9);
    $out=$runner->run($date(5));
    check($out['state']==='reconciliation' && count($sent)===7,'Unknown acknowledgement was replayed or bypassed');

    echo "PASS: same scoped runner and MAX response parser; exact five texts and empty comment, safe catalog upgrade, disabled and wrong target zero requests, stale and historical rejected including pre-admission overdue closure, role revoked, no replay, uncertainty fence; network 0\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);$failed=true;
} finally {
    if (file_exists($marker)) unlink($marker);
    if ($before!==null) file_put_contents($vault,$before);
    elseif (file_exists($vault)) unlink($vault);
}

if (!empty($failed)) exit(1);
