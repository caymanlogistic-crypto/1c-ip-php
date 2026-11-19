<?php
declare(strict_types=1);

$action = $argv[1]??'';
if (PHP_SAPI !== 'cli' || getenv('APP_STAND') !== 'ui' || $argc !== ($action==='check'?2:3)) exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{MesCabinetAuthorization,Support};

$key = $argv[2]??'';
$code = null;
if ($action === 'confirm') {
    $input = stream_get_contents(STDIN, 9);
    if (!is_string($input) || !preg_match('/^([0-9]{5})(?:\r?\n)?$/D',$input,$matched)) {
        fwrite(STDERR,"MES_CONFIRM_REQUIRES_CODE_ON_STDIN\n");
        exit(2);
    }
    $code = $matched[1];
    unset($input,$matched);
}
try {
    $service = new MesCabinetAuthorization(App\DB::pdo());
    $result = $action==='check'?$service->check():$service->execute($action,$key,$code);
    echo Support::json($result),PHP_EOL;
    exit(match($result['status']) {
        'success','awaiting_code' => 0,
        'authentication_required' => 3,
        default => 1,
    });
} catch (Throwable $error) {
    if ($action==='check') {
        echo Support::json(['status'=>'failed','code'=>'MES_CHECK_NOT_CONFIRMED']),PHP_EOL;
        exit(1);
    }
    $safe = ['MES_MANUAL_ARGUMENTS_REJECTED','PROVIDER_BUSY','MES_PROFILE_CREDENTIALS_MISSING',
        'MES_CURRENT_PROFILE_CHANGED','MES_AUTH_NOT_ADMITTED','MES_LOGIN_REQUEST_ALREADY_USED',
        'MES_RECENT_AUTH_ATTEMPT_EXISTS','MES_AUTH_PHASE_NOT_READY','CREDENTIAL_REVISION_CONFLICT'];
    fwrite(STDERR,(in_array($error->getMessage(),$safe,true)?$error->getMessage():'MES_MANUAL_AUTH_FAILED').PHP_EOL);
    exit(1);
} finally {
    unset($code);
}
