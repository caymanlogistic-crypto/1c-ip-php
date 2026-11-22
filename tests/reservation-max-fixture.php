<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{BookingMax, Config, Support, TemplateCatalog};
use Modules\Rental\{Availability, BookingNotices, Service};

function check(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);}
$db=DB::pdo();
check(PHP_SAPI==='cli' && getenv('APP_RESERVATION_MAX_TEST')==='1' && !getenv('APP_STAND') && !Config::liveAllowed()
    && preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())===1,'Isolated disposable database required');
try {
    $actor=$db->query("SELECT u.id,u.auth_version FROM users u JOIN role_profiles r ON r.role_id=u.role
        WHERE r.kind='manager' AND u.active=1 ORDER BY u.id LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $day=new DateTimeImmutable('today');
    $date=static fn(int $n): string=>$day->modify("+$n days")->format('Y-m-d');
    $room=array_values(array_filter(Availability::items($date(0),$date(0),null,null,true),static fn($r)=>!$r['disabled']))[0]['id'];
    $command=static fn(string $action,array $input):array=>Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);
    $create=static fn(string $name,int $deadline):int=>$command('reservation.create',['client_name'=>$name,'object_id'=>$room,
        'start_date'=>$date(0),'until_date'=>$date($deadline)])['id'];
    $a=$create('Учебный клиент MAX 15',2);
    $b=$create('Учебный отказ MAX 15',2);
    $c=$create('Учебное истечение MAX 15',3);
    $read=static function(int $id,string $action)use($db):array {
        $q=$db->prepare("SELECT source_key FROM rental_facts WHERE namespace=?
          AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.action'))=? AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.reservation_id'))=?
          ORDER BY id");
        $q->execute([BookingNotices::NS,$action,(string)$id]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    $command('reservation.cancel',['id'=>$b,'version'=>1,'client_declined'=>'1','reason'=>'Учебный отказ']);
    BookingNotices::dated($date(1),true);
    check($read($b,'reservation.reminder')===[],'Manually withdrawn request got a reminder');
    $old=$read($a,'reservation.reminder')[0];
    $command('reservation.extend',['id'=>$a,'version'=>1,'until_date'=>$date(6)]);
    BookingNotices::dated($date(5),true);
    $run=BookingNotices::dated($date(5),true);
    check(!$run['applied'] && count($read($c,'reservation.expire'))===1 && count($read($a,'reservation.reminder'))===2,
        'Skipped date catchup or dated idempotency failed');
    $messages=new BookingMax($db,Config::store());
    (new TemplateCatalog($db,Config::store()))->installReservationNotices();
    check($messages->preview($old,$date(1))===null,'Old reminder remained eligible after extension');
    $connection=Config::get($db,'max');
    Config::save($db,'max',Support::decode($connection['config_json']),(int)$connection['revision'],true);
    $revision=(int)$connection['revision']+1;
    $group=Support::uuid();$db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name) VALUES(?,'max',?,'Учебная MAX 15')")
        ->execute([$group,'-'.random_int(700000000,999999999)]);
    $sent=[];
    $fixture=static function(string $raw)use(&$sent):array {
        $sent[]=Support::decode($raw)['text'];return ['status'=>'accepted','remote_id'=>'fixture-'.count($sent)];
    };
    $cases=[
        [$a,'reservation.create',0],[$a,'reservation.extend',1],[$b,'reservation.cancel',0],
        [$a,'reservation.reminder',5],[$c,'reservation.expire',5],
    ];
    foreach($cases as $i=>[$id,$action,$on]) {
        $keys=$read($id,$action);$key=end($keys);
        if($i) usleep(1100000); // The real message path retains its target rate guard.
        $existing=$messages->dispatchLocal($key,$date($on),(int)$actor['id'],$group,$revision,1,$fixture);
        check(count($existing)===1 && $existing[0]['status']==='accepted','Expected one accepted fixture attempt '.$action);
        $same=$messages->dispatchLocal($key,$date($on),(int)$actor['id'],$group,$revision,1,$fixture);
        check($same[0]['id']===$existing[0]['id'] && count($sent)===$i+1,'Journal repeated the simulated send '.$action);
        check(str_contains($sent[$i],'Заявка №'.$id) && str_contains($sent[$i],'Срок:') && str_contains($sent[$i],'Ответственный:'),'Rendered context incomplete');
    }
    $uncertain=$create('Учебный неизвестный MAX 15',9);
    $key=$read($uncertain,'reservation.create')[0];usleep(1100000);
    $attempt=$messages->dispatchLocal($key,$date(0),(int)$actor['id'],$group,$revision,1,
        static fn()=>['status'=>'uncertain','code'=>'FIXTURE_UNKNOWN']);
    check($attempt[0]['status']==='uncertain','Unknown transport result was not retained');
    $extra=$create('Учебный стоп MAX 15',9);
    try {
        $messages->dispatchLocal($read($extra,'reservation.create')[0],$date(0),(int)$actor['id'],$group,$revision,1,$fixture);
        throw new LogicException('Unknown outcome fence bypassed');
    } catch (RuntimeException $error) {check($error->getMessage()==='MAX_PENDING_OR_UNCERTAIN_REQUIRES_RECONCILIATION','Expected unknown fence');}
    echo "PASS: 5 fixture messages, exact replay, stale reminder cancellation, expiration catchup, uncertainty fence; network calls 0\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);exit(1);
}
