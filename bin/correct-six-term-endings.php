<?php
declare(strict_types=1);

use App\DB;
use Modules\Rental\{Importer, Service, TermEndCorrection};

if (PHP_SAPI !== 'cli' || !getenv('APP_DB_CONFIG_FILE')) exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

try {
    [, $mode, $actorId] = array_pad($argv, 3, '');
    $query = DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");
    $query->execute([(int)$actorId]);
    $actor = $query->fetch() ?: throw new RuntimeException('Укажите действующего администратора.');
    $_SESSION = ['user_id' => $actor['id'], 'auth_version' => $actor['auth_version']];
    TermEndCorrection::requireOperator();

    if (($mode === 'plan' && $argc === 3) || ($mode === 'undo-plan' && $argc === 4)) {
        $result = TermEndCorrection::plan(bin2hex(random_bytes(20)), $argv[3] ?? '');
    } elseif ($mode === 'apply' && $argc === 5) {
        $bytes = file_get_contents($argv[3]);
        if ($bytes === false || !hash_equals($argv[4], hash('sha256', $bytes))) {
            throw new RuntimeException('SHA-256 файла не совпадает с проверенным планом.');
        }
        $result = Service::command(TermEndCorrection::ACTION, Importer::decodeExact($bytes));
    } else {
        throw new RuntimeException('Использование: plan <admin-id> | undo-plan <admin-id> <original-key> | apply <admin-id> <plan.json> <sha256>');
    }
    echo Importer::json($result), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
