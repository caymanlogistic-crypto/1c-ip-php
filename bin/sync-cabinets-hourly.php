<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || getenv('APP_STAND')!=='ui' || $argc!==1) exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{CabinetSyncAdmission,Config,ProviderService,Support};

$profiles = CabinetSyncAdmission::configured();
if (!$profiles) {
    fwrite(STDERR,"CABINET_READ_PROFILES_NOT_ADMITTED\n");
    exit(2);
}
$db = App\DB::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') exit(2);
$service = new ProviderService($db,Config::store());
$today = (new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
$failed = false;
foreach (array_keys($profiles) as $provider) {
    $result = ['provider'=>$provider,'from'=>CabinetSyncAdmission::FROM,'to'=>$today];
    try {
        $run = $service->execute($provider,'sync',0,Support::uuid(),[
            'trigger'=>'hourly','from'=>CabinetSyncAdmission::FROM,'to'=>$today,
        ]);
        $result += ['run'=>$run['id'],'status'=>$run['status'],'code'=>$run['result_code'],
            'received'=>$run['coverage']['records_imported']??null,
            'coverage_confirmed'=>$run['coverage']['complete']??false];
    } catch (Throwable $error) {
        $result += ['status'=>'failed','code'=>match($error->getMessage()) {
            'EXTERNAL_ACCESS_NOT_AUTHORIZED','PROVIDER_BUSY','PROVIDER_BUSY_RECONCILE_PENDING' => $error->getMessage(),
            default => 'CABINET_SYNC_FAILED',
        }];
    }
    $failed = $failed||$result['status']!=='success';
    echo Support::json($result),PHP_EOL;
}
exit($failed?1:0);
