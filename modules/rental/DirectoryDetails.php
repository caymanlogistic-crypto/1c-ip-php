<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

final class DirectoryDetails
{
    public static function read(PDO $db, string $directory, int $id): array
    {
        $query=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute(['directory_'.$directory,(string)$id]);
        $raw=$query->fetchColumn();
        return $raw===false?[]:json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }

    public static function store(PDO $db, string $directory, int $id, array $values): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1) '
            .'ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute(['directory_'.$directory,(string)$id,Importer::json($values)]);
    }

    public static function text(array $input, string $field, int $limit): string
    {
        $value=$input[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>$limit || str_contains($value,"\0"))
            throw new RuntimeException('Проверьте длину и формат реквизитов.',422);
        return trim($value);
    }
}
