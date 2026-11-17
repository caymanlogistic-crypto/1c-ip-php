<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/app/bootstrap.php';
try {
    if (count($argv) !== 2 || !in_array($argv[1], ['--activate-owner-pair','--probe-selected-once','--activate-mounted','--probe-mounted-once'], true)) throw new RuntimeException('OWNER_COMMAND_REQUIRED');
    $service = new Modules\Integrations\OnecOwnerRevision(App\DB::pdo(), Modules\Integrations\Config::store());
    $result = match($argv[1]) {
        '--activate-owner-pair'=>$service->activate(), '--activate-mounted'=>$service->activateMounted(),
        '--probe-mounted-once'=>$service->probeOnce(true), default=>$service->probeOnce(),
    };
    echo Modules\Integrations\Support::json($result).PHP_EOL;
} catch (Throwable $error) {
    $allowed = ['OWNER_ONEC_REVISION_NOT_AUTHORIZED','OWNER_CONFIG_CAS_CONFLICT','OWNER_CONFIG_BUSY',
        'OWNER_HANDOFF_REQUIRED','OWNER_PAIR_SHAPE_REJECTED','OWNER_PAIR_NOT_CHANGED','OWNER_CANDIDATE_ALREADY_EXISTS',
        'OWNER_ONEC_PROBE_ALREADY_CONSUMED','EXTERNAL_ACCESS_NOT_AUTHORIZED','MOUNTED_ONEC_PROFILE_INVALID','MOUNTED_ONEC_ENDPOINT_PIN_CHANGED','SELECTED_CONFIG_CHANGED'];
    fwrite(STDERR, (in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'OWNER_REVISION_NOT_COMPLETED').PHP_EOL);
    exit(1);
}
