<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;
use RuntimeException;

final class FileOrder
{
    public static function lock(PDO $db): void
    {
        if (!$db->inTransaction()) {
            throw new RuntimeException('Запись файла требует предметной транзакции.');
        }
        $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
    }

    public static function assign(PDO $db, string $fileId): string
    {
        self::lock($db);
        $value = $db->query('SELECT next_value FROM rental_file_order WHERE id=1 FOR UPDATE')->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('Не настроен логический порядок документов.');
        }
        $order = bcadd((string)$value, '1', 0);
        $db->prepare('UPDATE rental_file_order SET next_value=? WHERE id=1')->execute([$order]);
        $update = $db->prepare('UPDATE rental_files SET upload_order=? WHERE id=? AND upload_order IS NULL');
        $update->execute([$order, $fileId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Логический порядок файла уже назначен или файл отсутствует.');
        }
        return $order;
    }
}
