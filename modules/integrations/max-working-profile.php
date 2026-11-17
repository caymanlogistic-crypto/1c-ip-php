<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/app/bootstrap.php';
try {
    if ($argc !== 2 || $argv[1] !== '--once') throw new RuntimeException('WORKING_MAX_COMMAND_REQUIRED');
    $service = new Modules\Integrations\MaxWorkingProfile(App\DB::pdo(), Modules\Integrations\Config::store());
    echo Modules\Integrations\Support::json($service->run()).PHP_EOL;
} catch (Throwable $error) {
    $allowed = ['WORKING_MAX_NOT_AUTHORIZED','WORKING_MAX_BUSY','WORKING_MAX_PROFILE_INVALID','WORKING_MAX_GROUP_INVALID',
        'WORKING_MAX_REQUEST_CONTRACT_CHANGED','WORKING_MAX_HANDOFF_REQUIRED','WORKING_MAX_CURRENT_REVISION_CHANGED',
        'WORKING_MAX_GROUP_CHANGED','WORKING_MAX_SOURCE_MISSING','WORKING_MAX_PROFILE_NOT_DIFFERENT','WORKING_MAX_COMMAND_REQUIRED'];
    fwrite(STDERR, (in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'WORKING_MAX_UNCONFIRMED_NO_AUTORETRY').PHP_EOL);
    exit(1);
}
