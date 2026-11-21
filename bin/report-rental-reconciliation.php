<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{Config, Support};
use Modules\Rental\MonthlyRentReconciliation;

if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

if ($argc !== 3) {
    fwrite(STDERR, "Укажите первый и последний месяц. Пример: php bin/report-rental-reconciliation.php 2026-10 2026-12\n");
    exit(2);
}

try {
    $months = MonthlyRentReconciliation::range($argv[1], $argv[2]);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(2);
}
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
$report = (new MonthlyRentReconciliation(DB::pdo(), Config::store()))->capture($months);
$snapshot = Support::json($report);
$directory = APP_ROOT.'/.local/reports';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Не удалось открыть папку отчётов.');
}
if (is_link($directory)) throw new RuntimeException('Папка отчётов должна быть локальной.');
$path = $directory.'/rental-'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
$temp = $path.'.tmp';
if (file_put_contents($temp, $snapshot, LOCK_EX) !== strlen($snapshot)) {
    if (is_file($temp)) unlink($temp);
    throw new RuntimeException('Не удалось сохранить отчёт.');
}
chmod($temp, 0600);
if (!rename($temp, $path)) {
    unlink($temp);
    throw new RuntimeException('Не удалось сохранить итоговый файл отчёта.');
}
echo $path, PHP_EOL;
