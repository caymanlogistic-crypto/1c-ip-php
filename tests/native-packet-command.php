<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth, DB};
use Modules\Rental\{Importer, NativePacket, Service};

// Usage: php tests/native-packet-command.php <accounting-contract-id>
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: '.$error->getMessage().PHP_EOL);
    exit(1);
});

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function effects(PDO $db, int $id): array
{
    $queries = [
        'SELECT COUNT(*) FROM invoices WHERE contract_id=?',
        "SELECT COUNT(*) FROM rental_packet_confirmations WHERE record_kind='contract' AND record_id=?",
        "SELECT COUNT(*) FROM rental_events WHERE contract_id=? AND action='contract.packet'",
    ];
    return array_map(static function (string $sql) use ($db, $id): int {
        $query = $db->prepare($sql);
        $query->execute([$id]);
        return (int)$query->fetchColumn();
    }, $queries);
}

$id = (int)($argv[1] ?? 0);
check($id > 0, 'Supply a test contract at the accounting stage.');
$receipt = json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'), true, 512, JSON_THROW_ON_ERROR);
$db = DB::pdo();
$database = $db->query('SELECT DATABASE()')->fetchColumn();
check(preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', $database) === 1
    && $db->query('SELECT CURRENT_USER()')->fetchColumn() === $database.'@127.0.0.1'
    && $receipt['database'] === $database && $receipt['isolated_test_data'] === true
    && str_replace('\\', '/', $receipt['app_root']) === str_replace('\\', '/', APP_ROOT)
    && !getenv('IPPHP_ALLOW_EXTERNAL') && !getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'),
    'Verified isolated local database required.');

$db->beginTransaction();
try {
    $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
    $contract = Service::get($db, 'contracts', $id);
    check($contract['status'] === 'accounting', 'Contract must be ready for accounting.');
    $actor = $db->query("SELECT id,auth_version FROM users WHERE role='accountant' AND active=1 ORDER BY id LIMIT 1")->fetch();
    check((bool)$actor, 'Accountant fixture required.');
    $_SESSION = ['user_id' => $actor['id'], 'auth_version' => $actor['auth_version']];
    $epoch = $db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
    $db->prepare("INSERT IGNORE INTO rental_facts(namespace,source_key,fact_json,operative)
        VALUES('zero_point_runtime','active',?,0)")->execute([Importer::json(['epoch' => $epoch, 'event_floor' => 0])]);

    $preview = NativePacket::preview($db, 'contract', $id, Auth::user());
    $action = 'contract.packet';
    $input = ['id' => $id, 'version' => $preview['record']['version'], 'command_key' => bin2hex(random_bytes(16)),
        'expected_packet_hash' => $preview['expected_packet_hash'], 'packet_confirmed' => '1',
        'counterparty_confirmed' => '1', 'financial_confirmed' => '1', 'document_confirmed' => '1'];
    $before = effects($db, $id);
    $result = Service::command($action, $input);
    $expectedNewInvoices = count(array_filter($preview['view']['invoices'], static fn(array $invoice) => !$invoice['existing']));
    $after = effects($db, $id);
    check($after === [$before[0]+$expectedNewInvoices, $before[1]+1, $before[2]+1], 'Wrong initial billing or history.');
    check(Service::get($db, 'contracts', $id)['version'] === $preview['record']['version']+1, 'Contract version did not advance.');

    $query = $db->prepare('SELECT fingerprint FROM rental_commands WHERE command_key=?');
    $query->execute([$input['command_key']]);
    check($query->fetchColumn() === hash('sha256', Importer::json([Auth::user()['id'], $action, $input, $epoch])),
        'Stored receipt did not retain the epoch.');
    check(NativePacket::current($db, 'contract', $id, Auth::user(), 'prepare')['state'] === 'confirmed',
        'Saved packet proof is unreadable.');
    check(Service::command($action, $input) === $result && effects($db, $id) === $after,
        'Retry wrote effects or returned a different receipt.');
    echo "PASS: epoch-bound command receipt, initial invoices, history and retry\n";
} finally {
    $db->rollBack();
}
