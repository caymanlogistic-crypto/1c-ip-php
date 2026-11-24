<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth, DB};
use Modules\Rental\{AccountantPacketSummary, Evidence, Importer, NativePacket, Service, WorkflowEvents};

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
        "SELECT COUNT(*) FROM rental_delivery_intents WHERE entity_type='contract' AND entity_id=?",
    ];
    return array_map(static function (string $sql) use ($db, $id): int {
        $query = $db->prepare($sql);
        $query->execute([$id]);
        return (int)$query->fetchColumn();
    }, $queries);
}

function rejected(callable $operation, string $message, ?int $code = null): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $message) && ($code === null || $error->getCode() === $code),
            'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

function packetInput(PDO $db, int $id): array
{
    $preview = NativePacket::preview($db, 'contract', $id, Auth::user());
    return ['id' => $id, 'version' => $preview['record']['version'], 'command_key' => bin2hex(random_bytes(16)),
        'expected_packet_hash' => $preview['expected_packet_hash'], 'packet_confirmed' => '1',
        'counterparty_confirmed' => '1', 'financial_confirmed' => '1', 'document_confirmed' => '1'];
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

    $action = 'contract.packet';
    $input = packetInput($db, $id);
    $before = effects($db, $id);
    $summary = NativePacket::preview($db, 'contract', $id, Auth::user())['view']['accountant_confirmation_summary'];
    $accountantSession = $_SESSION;
    $manager = $db->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();
    check((bool)$manager, 'Manager fixture required.');
    $_SESSION = ['user_id' => $manager['id'], 'auth_version' => $manager['auth_version']];
    rejected(fn()=>Service::command($action, $input), '', 403);
    $_SESSION = $accountantSession;

    $db->exec('SAVEPOINT occupied_packet');
    $conflict = (int)$db->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();
    $db->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,amount,status,source_json)
        SELECT ?,?,party_id,start_date,end_date,amount,'active','{}' FROM contracts WHERE id=?")
        ->execute([$conflict, 'occupancy-test-'.$conflict, $id]);
    $db->prepare('INSERT INTO contract_objects(contract_id,object_id)
        SELECT ?,object_id FROM contract_objects WHERE contract_id=?')->execute([$conflict, $id]);
    rejected(fn()=>Service::command($action, $input), 'Объект занят договором на выбранный период');
    check(effects($db, $id) === $before, 'A blocked approval wrote effects.');
    $db->exec('ROLLBACK TO SAVEPOINT occupied_packet');

    $db->exec('SAVEPOINT expired_packet');
    $db->prepare('UPDATE contracts SET end_date=? WHERE id=?')->execute([date('Y-m-d', strtotime('-1 day')), $id]);
    $legal = $db->prepare("SELECT file_id FROM rental_approvals WHERE entity_type='contract' AND entity_id=? AND role='lawyer'");
    $legal->execute([$id]);
    $db->prepare("UPDATE rental_approvals SET baseline_hash=? WHERE entity_type='contract' AND entity_id=? AND role='lawyer'")
        ->execute([Evidence::approvalSignature($db, $id, $legal->fetchColumn()), $id]);
    rejected(fn()=>Service::command($action, packetInput($db, $id)), 'Срок аренды уже истёк');
    $db->exec('ROLLBACK TO SAVEPOINT expired_packet');
    check(effects($db, $id) === $before, 'Rejected approval changed durable records.');

    if ($before[1] === 0 && $before[3] === 0) {
        $db->exec('SAVEPOINT legacy_packet');
        $db->prepare("UPDATE contracts SET status='approved' WHERE id=?")->execute([$id]);
        $legacyInput = packetInput($db, $id);
        $db->prepare("INSERT INTO rental_delivery_intents(id,request_key,provider,operation,entity_type,entity_id,entity_version,baseline_hash,actor_id,payload_json,status)
            VALUES(?,?,'email','contract-packet','contract',?,?,?,?,'{}','unknown')")
            ->execute([bin2hex(random_bytes(16)), 'test-intent-'.$legacyInput['command_key'], $id, $contract['version'],
                str_repeat('0',64), $actor['id']]);
        rejected(fn()=>Service::command($action, $legacyInput), 'Продолжите работу с существующим пакетом', 409);
        check(effects($db, $id) === [$before[0], $before[1], $before[2], 1], 'Uncertain legacy delivery changed.');
        $db->exec('ROLLBACK TO SAVEPOINT legacy_packet');
        $db->prepare("UPDATE contracts SET status='approved' WHERE id=?")->execute([$id]);
        check(Service::command($action, packetInput($db, $id))['status'] === 'active', 'First reviewed legacy package did not activate.');
        $db->exec('ROLLBACK TO SAVEPOINT legacy_packet');
    }

    $result = Service::command($action, $input);
    $after = effects($db, $id);
    $active = Service::get($db, 'contracts', $id);
    check($after === [$before[0], $before[1]+1, $before[2]+1, $before[3]],
        'Contract approval must create a receipt with no invoice or delivery intent.');
    check($result['status'] === 'active' && $active['status'] === 'active' && !(bool)$active['signed']
        && $active['start_date'] === $contract['start_date'] && $active['end_date'] === $contract['end_date'],
        'Approval must activate the contract without rewriting signature or dates.');
    check($active['version'] === $input['version']+1, 'Contract version did not advance.');

    $query = $db->prepare('SELECT fingerprint FROM rental_commands WHERE command_key=?');
    $query->execute([$input['command_key']]);
    check($query->fetchColumn() === hash('sha256', Importer::json([Auth::user()['id'], $action, $input, $epoch])),
        'Stored receipt did not retain the epoch.');
    check(NativePacket::current($db, 'contract', $id, Auth::user(), 'prepare')['state'] === 'confirmed',
        'Saved packet proof is unreadable.');
    $eventQuery = $db->prepare("SELECT id,created_at,source_json FROM rental_events WHERE contract_id=? AND action='contract.packet' ORDER BY id DESC LIMIT 1");
    $eventQuery->execute([$id]);
    $event = WorkflowEvents::decode($eventQuery->fetch());
    check(Importer::canonicalJson($event['accountant_packet_confirmation'] ?? null)
        === Importer::canonicalJson($summary+['external_operations'=>AccountantPacketSummary::EXTERNAL_DISABLED]),
        'The event did not record the confirmed summary.');
    check(Service::command($action, $input) === $result && effects($db, $id) === $after,
        'Retry wrote effects or returned a different receipt.');
    $db->prepare("UPDATE contracts SET status='approved' WHERE id=?")->execute([$id]);
    rejected(fn()=>Service::command($action, packetInput($db, $id)), 'Продолжите работу с существующим пакетом');
    check(effects($db, $id) === $after, 'Legacy confirmation may not be replayed.');
    echo "PASS: accounting activation, occupancy and expiry, epoch-bound receipt, roles, zero automatic invoices or deliveries, legacy protection and retry\n";
} finally {
    $db->rollBack();
}
