<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
    if(count($argv)!==2||$argv[1]!=='--once')throw new RuntimeException('DIADOC_COMMAND_REQUIRED');
    $result=(new Modules\Integrations\DiadocMountedProfile(App\DB::pdo(),Modules\Integrations\Config::store()))->activateAndCheck();
    echo Modules\Integrations\Support::json($result).PHP_EOL;
}catch(Throwable $error){
    $safe=['DIADOC_MOUNTED_NOT_AUTHORIZED','DIADOC_PROFILE_CAS_CONFLICT','DIADOC_MOUNTED_PROFILE_INVALID','DIADOC_MOUNTED_HANDOFF_REQUIRED','DIADOC_PROFILE_NOT_CHANGED','DIADOC_CANDIDATE_ALREADY_EXISTS','PROVIDER_BUSY'];
    fwrite(STDERR,(in_array($error->getMessage(),$safe,true)?$error->getMessage():'DIADOC_ONCE_NOT_COMPLETED').PHP_EOL);exit(1);
}
