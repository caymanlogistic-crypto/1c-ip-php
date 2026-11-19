<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_STAND') !== 'ui' || $argc !== 1) {
    exit(2);
}

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{Config,Support,YakovlevImport};

$db = App\DB::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ipphp_ui') {
    fwrite(STDERR,"UNEXPECTED_DATABASE\n");
    exit(2);
}
$service = new YakovlevImport($db,Config::store());
$cycle = Support::uuid();
$failed = false;
$run = static function(string $tab,string $from,string $to) use ($service,$cycle,&$failed): void {
    $result = ['cycle'=>$cycle,'section'=>$tab,'from'=>$from,'to'=>$to];
    try {
        $import = $service->import($tab,$from,$to,0,$cycle.':'.$tab.':'.$from,true);
        $result += ['status'=>$import['status'],'run'=>$import['id'],
            'received'=>$import['coverage']['evidence']['received_by_kind']??[],
            'changes'=>$import['coverage']['changes']??[]];
        $failed = $failed||$import['status']!=='success';
    } catch (Throwable $error) {
        $result += ['status'=>'failed','code'=>YakovlevImport::safeCode($error)];
        $failed = true;
    }
    echo Support::json($result),PHP_EOL;
};

$timezone = new DateTimeZone('Europe/Moscow');
$today = new DateTimeImmutable('today',$timezone);
$run('parties','','');
$start = new DateTimeImmutable(YakovlevImport::DEFAULT_FROM,$timezone);
for ($from=$start; $from<=$today; $from=$until->modify('+1 day')) {
    $until = min($today,$from->modify('+89 days'));
    $run('bank',$from->format('Y-m-d'),$until->format('Y-m-d'));
    $run('invoices',$from->format('Y-m-d'),$until->format('Y-m-d'));
}
exit($failed?1:0);
