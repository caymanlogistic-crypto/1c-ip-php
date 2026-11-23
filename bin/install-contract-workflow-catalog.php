<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config, ServerWorkflowMax, Support, TemplateCatalog};

$options=getopt('',['database:','apply','reservation-notices']);
$database=DB::pdo()->query('SELECT DATABASE()')->fetchColumn();
$server=PHP_OS_FAMILY==='Linux' && getenv('APP_STAND')==='ui' && $database==='ipphp_ui';
$fixture=!getenv('APP_STAND') && preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$database);
if (PHP_SAPI!=='cli' || ($options['database'] ?? '')!==$database || (!$server && !$fixture)) {
    throw new RuntimeException('EXPLICIT_WORKFLOW_CATALOG_DATABASE_REQUIRED');
}
$pdo=DB::pdo();
if ((int)$pdo->query("SELECT GET_LOCK('ipphp-provider:max',5)")->fetchColumn()!==1) {
    throw new RuntimeException('MAX_BUSY');
}
$apply=array_key_exists('apply',$options);
$reservation=array_key_exists('reservation-notices',$options);
$temp=null;
try {
    $path=APP_ROOT.'/.local/'.($reservation?'reservation-max-admission.json':'workflow-max-admission.json');
    $scope=$server ? ($reservation ? Support::decode(file_get_contents($path)) : ServerWorkflowMax::settings()) : null;
    if ($server && !$scope) throw new RuntimeException('EXISTING_WORKFLOW_ADMISSION_REQUIRED');
    if ($server && $reservation && (is_link($path) || ($scope['scope'] ?? null)!=='reservation-notices'
        || ($scope['enabled'] ?? null)!==true || ($scope['database'] ?? null)!==$database
        || !is_array($scope['catalog_versions'] ?? null)
        || array_diff(array_column(\Modules\Rental\BookingNotices::TEMPLATES,0),array_keys($scope['catalog_versions'])))) {
        throw new RuntimeException('EXISTING_RESERVATION_ADMISSION_REQUIRED');
    }
    if ($scope) ServerWorkflowMax::assertProfile($pdo,$scope);
    $catalog=new TemplateCatalog($pdo,Config::store());
    $pinned=$scope['catalog_versions'] ?? [];
    $install=$reservation ? $catalog->installReservationWorkflowLayout(...) : $catalog->installContractWorkflowLayout(...);
    $result=$install(false,$pinned);
    $versions=array_column($result,'version');
    $versions=array_combine(array_keys($result),$versions);
    $needsBinding=$scope && $pinned!=$versions;
    if ($apply && $needsBinding) {
        $scope['catalog_versions']=$versions;
        if (!$reservation) ServerWorkflowMax::validate($scope);
        $temp=dirname($path).'/.workflow-max-admission-'.bin2hex(random_bytes(12));
        $bytes=Support::json($scope)."\n";
        if (file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)
            || !chmod($temp,fileperms($path)&0777)
            || (fileowner($temp)!==fileowner($path) && !chown($temp,fileowner($path)))
            || (filegroup($temp)!==filegroup($path) && !chgrp($temp,filegroup($path)))) {
            throw new RuntimeException('WORKFLOW_ADMISSION_STAGING_FAILED');
        }
    }
    if ($apply) {
        $install(true,$pinned,$result);
        if ($temp!==null && !rename($temp,$path)) {
            throw new RuntimeException('WORKFLOW_ADMISSION_REBIND_FAILED');
        }
    }
    echo Support::json(['applied'=>$apply,'templates'=>$result,'admission_rebound'=>$apply && $needsBinding]),"\n";
} finally {
    if ($temp!==null && is_file($temp)) unlink($temp);
    $pdo->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
}
