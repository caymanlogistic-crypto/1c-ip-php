<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/app/bootstrap.php';
try {
    if (($argv[1] ?? '') !== '--single-owner-fresh-otp' || ($argv[2] ?? '') !== '2' || count($argv) !== 3) throw new RuntimeException('MES_FRESH_COMMAND_REQUIRED');
    $result = (new Modules\Integrations\MesFreshFlow(App\DB::pdo(), Modules\Integrations\Config::store()))->run(2);
    echo Modules\Integrations\Support::json($result).PHP_EOL;
} catch (Throwable $error) {
    $safe = ['MES_FRESH_FLOW_NOT_AUTHORIZED','MES_CURRENT_PROFILE_CHANGED','MES_ASSIGNED_MAIL_PROFILE_REQUIRED',
        'MES_PROFILE_CREDENTIALS_MISSING','MES_SESSION_REQUIRES_READONLY_REFRESH','MES_FRESH_FLOW_ALREADY_CONSUMED','PROVIDER_BUSY'];
    fwrite(STDERR, (in_array($error->getMessage(), $safe, true) ? $error->getMessage() : 'MES_FRESH_FLOW_NOT_STARTED').PHP_EOL);
    exit(1);
}
