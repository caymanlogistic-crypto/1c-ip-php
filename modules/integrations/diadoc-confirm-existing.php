<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
    if(count($argv)!==2||!in_array($argv[1],['--once','--late-owner-once','--new-challenge-once','--owner-restart-once'],true))throw new RuntimeException('DIADOC_COMMAND_REQUIRED');
    if($argv[1]==='--late-owner-once'&&getenv('IPPHP_DIADOC_CONFIRM_ADMISSION')!=='confirm-same-mounted-diadoc-late-owner-20261116')throw new RuntimeException('DIADOC_CONFIRM_NOT_AUTHORIZED');
    if($argv[1]==='--new-challenge-once'&&getenv('IPPHP_DIADOC_CONFIRM_ADMISSION')!=='confirm-new-mounted-push-20261116')throw new RuntimeException('DIADOC_CONFIRM_NOT_AUTHORIZED');
    if($argv[1]==='--owner-restart-once'&&getenv('IPPHP_DIADOC_CONFIRM_ADMISSION')!=='confirm-owner-restart-push-20261116')throw new RuntimeException('DIADOC_CONFIRM_NOT_AUTHORIZED');
    echo Modules\Integrations\Support::json((new Modules\Integrations\DiadocPendingConfirmation(App\DB::pdo(),Modules\Integrations\Config::store()))->run()).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,in_array($error->getMessage(),['DIADOC_CONFIRM_NOT_AUTHORIZED','DIADOC_PENDING_EXPIRED_OR_INVALID','DIADOC_ORIGINAL_PENDING_REQUIRED','DIADOC_PROFILE_CAS_CONFLICT','PROVIDER_BUSY'],true)?$error->getMessage().PHP_EOL:"DIADOC_CONFIRM_NOT_COMPLETED\n");exit(1);}
