<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try {
    if (($argv[2] ?? '') !== '--auth-metadata-only' || count($argv) > 4
        || (isset($argv[3]) && $argv[3] !== '--fresh-login-once')) {
        throw new RuntimeException('READONLY_COMMAND_REQUIRED');
    }
    $provider = \Modules\Integrations\Support::provider($argv[1] ?? '');
    $result = (new \Modules\Integrations\ReadOnlyCheck(\App\DB::pdo(), \Modules\Integrations\Config::store()))
        ->run($provider, \Modules\Integrations\Support::uuid(), isset($argv[3]));
    echo \Modules\Integrations\Support::json($result)."\n";
} catch (Throwable $error) {
    $allowed = ['EXTERNAL_ACCESS_NOT_AUTHORIZED', 'SELECTED_CONFIG_REQUIRED', 'PROVIDER_BUSY',
        'FRESH_LOGIN_NOT_AUTHORIZED', 'FRESH_LOGIN_ALREADY_ATTEMPTED'];
    $code = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'READONLY_CHECK_NOT_COMPLETED';
    fwrite(STDERR, $code."; no credential values disclosed.\n");
    exit(1);
}
