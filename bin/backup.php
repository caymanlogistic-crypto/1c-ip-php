<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{DB,StorageBudget};

if (PHP_SAPI !== 'cli') exit(2);
$options = getopt('', ['output-dir:', 'plan']);
$directory = $options['output-dir'] ?? APP_ROOT.'/private';
if (!is_string($directory)) throw new RuntimeException('Explicit backup directory required.');
$pdo = DB::pdo();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$estimate = $pdo->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?');
$estimate->execute([$database]);
$sourceBytes = (int)$estimate->fetchColumn();
if ($sourceBytes > intdiv(PHP_INT_MAX - 16777216, 4)) throw new RuntimeException('Backup budget overflow.');
$budget = max(16777216, $sourceBytes * 4 + 16777216);
$directory = rtrim(str_replace('\\', '/', $directory), '/');
StorageBudget::target($directory.'/backup-budget-preflight.sql', $budget);
if (isset($options['plan'])) {
    echo json_encode(['database' => $database, 'output_directory' => $directory,
        'budget_bytes' => $budget, 'created' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($pdo->inTransaction()) throw new RuntimeException('Backup requires its own consistent snapshot.');
if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private backup directory.');
$out = $directory.'/backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sql';
$stream = fopen($out, 'xb');
if (!$stream) throw new RuntimeException('Cannot create private backup.');
$written = 0;
$write = static function(string $bytes) use($stream, $budget, &$written): void {
    $length = strlen($bytes);
    if ($written > $budget - $length) throw new RuntimeException('Backup exceeds reserved budget.');
    $offset = 0;
    while ($offset < $length) {
        $size = fwrite($stream, substr($bytes, $offset));
        if ($size === false || $size === 0) throw new RuntimeException('Backup write failed.');
        $offset += $size;
    }
    $written += $length;
};
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables, SORT_STRING);
$counts = $schemas = $rowHashes = [];
try {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $write('-- Independent project backup '.date(DATE_ATOM)."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table name.');
        $ddl = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $schemas[$table] = hash('sha256', $ddl);
        $write($ddl.";\n");
        $count = 0;
        $hashes = [];
        $query = $pdo->query('SELECT * FROM `'.$table.'`');
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $hashes[] = hash('sha256', Modules\Rental\Importer::canonicalJson($row));
            $values = array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value), array_values($row));
            $columns = implode(',', array_map(static fn($column) => '`'.$column.'`', array_keys($row)));
            $write('INSERT INTO `'.$table.'` ('.$columns.') VALUES('.implode(',', $values).");\n");
            $count++;
        }
        sort($hashes, SORT_STRING);
        $counts[$table] = $count;
        $rowHashes[$table] = ['rows' => $count, 'sha256' => hash('sha256', Modules\Rental\Importer::canonicalJson($hashes))];
    }
    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    if (!fflush($stream)) throw new RuntimeException('Backup flush failed.');
    $pdo->commit();
    fclose($stream);
    $stream = null;
    $receipt = [
        'schema' => 1, 'kind' => 'PROJECT_DATABASE_BACKUP', 'database' => $database,
        'created_at' => date(DATE_ATOM), 'path' => $out, 'file' => basename($out),
        'sha256' => hash_file('sha256', $out), 'bytes' => filesize($out),
        'tables' => $counts, 'schema_sha256' => $schemas,
        'all_rows_sha256' => hash('sha256', Modules\Rental\Importer::canonicalJson($rowHashes)),
        'all_schema_sha256' => hash('sha256', Modules\Rental\Importer::canonicalJson($schemas)),
        'files_included' => false, 'restore_verified' => false,
        'note' => 'Config and sessions are excluded; a separate restore proof is required.',
    ];
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $receiptStream = fopen($out.'.receipt.json', 'xb');
    if (!$receiptStream) throw new RuntimeException('Cannot create backup receipt.');
    try {
        if (fwrite($receiptStream, $bytes) !== strlen($bytes) || !fflush($receiptStream)) throw new RuntimeException('Backup receipt write failed.');
    } finally { fclose($receiptStream); }
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($stream)) fclose($stream);
    // Preserve failed partial output without an accepted receipt.
    throw $failure;
}
