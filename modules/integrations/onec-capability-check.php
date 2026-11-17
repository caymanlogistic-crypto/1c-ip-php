<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
    if(count($argv)!==3||$argv[1]!=='--fixed-entity-metadata-only'||!preg_match('/^--revision=([1-9][0-9]{0,8})$/D',$argv[2],$match))throw new RuntimeException('CAPABILITY_DIAGNOSTIC_NOT_AUTHORIZED');
    $result=Modules\Integrations\OnecCapabilityDiagnostic::run(App\DB::pdo(),Modules\Integrations\Config::store(),(int)$match[1]);
    echo Modules\Integrations\Support::json($result),PHP_EOL;
}catch(Throwable$error){
    $allowed=['CAPABILITY_DIAGNOSTIC_NOT_AUTHORIZED','CAPABILITY_DIAGNOSTIC_ALREADY_ATTEMPTED','PROVIDER_BUSY','SELECTED_CONFIG_CHANGED'];
    fwrite(STDERR,(in_array($error->getMessage(),$allowed,true)?$error->getMessage():'CAPABILITY_DIAGNOSTIC_NOT_COMPLETED')."; no private response disclosed.\n");exit(1);
}
