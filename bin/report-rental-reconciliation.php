<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\Config;
use Modules\Rental\{InvoicePeriods, MonthlyRentReconciliation};

if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

if ($argc !== 3 || !InvoicePeriods::month($argv[1]) || !InvoicePeriods::month($argv[2]) || $argv[1] > $argv[2]) {
    fwrite(STDERR, "Укажите первый и последний месяц. Пример: php bin/report-rental-reconciliation.php 2026-10 2026-12\n");
    exit(2);
}

$zone = new DateTimeZone('Europe/Moscow');
$months = [];
$end = new DateTimeImmutable($argv[2].'-01', $zone);
for ($month = new DateTimeImmutable($argv[1].'-01', $zone); $month <= $end; $month = $month->modify('+1 month')) {
    if (count($months) === 24) {
        fwrite(STDERR, "За один запуск можно сохранить сводку за 24 месяца.\n");
        exit(2);
    }
    $months[] = $month->format('Y-m');
}

$db = DB::pdo();
$now = new DateTimeImmutable('now', $zone);
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$db->beginTransaction();
try {
    $report = (new MonthlyRentReconciliation($db, Config::store()))->collect($months, $now);
} finally {
    $db->rollBack();
}

ob_start();
require __DIR__.'/report-rental-reconciliation-view.php';
$html = ob_get_clean();
$directory = APP_ROOT.'/.local/reports';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Не удалось открыть папку отчётов.');
}
if (is_link($directory)) throw new RuntimeException('Папка отчётов должна быть локальной.');
$path = $directory.'/rental-'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.html';
$temp = $path.'.tmp';
if (file_put_contents($temp, $html, LOCK_EX) !== strlen($html)) {
    if (is_file($temp)) unlink($temp);
    throw new RuntimeException('Не удалось сохранить отчёт.');
}
chmod($temp, 0600);
if (!rename($temp, $path)) {
    unlink($temp);
    throw new RuntimeException('Не удалось сохранить итоговый файл отчёта.');
}
echo $path, PHP_EOL;
