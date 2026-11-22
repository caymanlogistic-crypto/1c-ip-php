<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config, Support};
use Modules\Rental\BookingNotices;

try {
    $db=DB::pdo();
    $database=$db->query('SELECT DATABASE()')->fetchColumn();
    if (Config::liveAllowed() || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) throw new RuntimeException('RESERVATION_DATE_RUNTIME_INVALID');
    $local=!getenv('APP_STAND') && preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',$database);
    $production=getenv('APP_STAND')==='ui' && $database==='ipphp_ui';
    if (!$local && !$production) throw new RuntimeException('RESERVATION_DATE_RUNTIME_INVALID');
    $args=array_slice($argv,1);
    $apply=in_array('--apply',$args,true);
    $date=date('Y-m-d');
    foreach ($args as $arg) {
        if ($arg==='--apply') continue;
        if ($local && preg_match('/\A--date=(\d{4}-\d{2}-\d{2})\z/D',$arg,$m)) $date=$m[1];
        else throw new RuntimeException('RESERVATION_DATE_ARGUMENT_INVALID');
    }
    if ($production && $apply) {
        $path=APP_ROOT.'/.local/reservation-deadlines.json';
        if (is_link($path) || !is_readable($path) || filesize($path)>1024)
            throw new RuntimeException('RESERVATION_DEADLINE_ADMISSION_REQUIRED');
        $marker=Support::decode(file_get_contents($path));
        if (($marker['scope']??null)!=='reservation-deadlines-only' || ($marker['database']??null)!==$database)
            throw new RuntimeException('RESERVATION_DEADLINE_ADMISSION_REQUIRED');
    }
    $result=BookingNotices::dated($date,$apply);
    echo Support::json(['date'=>$date,'mode'=>$apply?'apply':'plan','external_sends'=>0]+$result),PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);
}
