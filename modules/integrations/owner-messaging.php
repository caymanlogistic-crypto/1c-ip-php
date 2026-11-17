<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/app/bootstrap.php';
try {
    if (count($argv)!==2||!in_array($argv[1],['--publish-whole-differences','--send-email-once','--send-max-once'],true)) throw new RuntimeException('OWNER_MESSAGE_COMMAND_REQUIRED');
    $service=new Modules\Integrations\OwnerMessaging(App\DB::pdo(),Modules\Integrations\Config::store());
    $result=$argv[1]==='--publish-whole-differences'?$service->activate():$service->sendOnce($argv[1]==='--send-email-once'?'email':'max');
    echo Modules\Integrations\Support::json($result).PHP_EOL;
} catch (Throwable $error) {
    $allowed=['OWNER_MESSAGE_NOT_AUTHORIZED','OWNER_MESSAGE_HANDOFF_REQUIRED','OWNER_MESSAGE_SHAPE_REJECTED','OWNER_MAX_GROUP_BINDING_CONFLICT',
        'OWNER_CONNECTION_DISABLED','OWNER_MESSAGE_CAS_CONFLICT','OWNER_HISTORY_ALREADY_EXISTS','OWNER_MESSAGE_BUSY','OWNER_MESSAGE_PROFILE_NOT_SELECTED','CREDENTIAL_REVISION_CONFLICT'];
    fwrite(STDERR,(in_array($error->getMessage(),$allowed,true)?$error->getMessage():'OWNER_MESSAGE_NOT_CONFIRMED_NO_AUTORETRY').PHP_EOL); exit(1);
}
