<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

/** Retained high water marks prevent identity reuse after an explicit commercial restore. */
final class BusinessIds
{
    public static function next(PDO $db,string $table,int $floor=0,bool $minimum=true): int
    {
        if (!preg_match('/^[a-z_]+$/D',$table)) throw new RuntimeException('Invalid identity table.');
        $max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM `'.$table.'`')->fetchColumn();
        $query=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_sequence' AND source_key=?");
        $query->execute([$table]);$mark=json_decode($query->fetchColumn() ?: '{}',true);
        return max($minimum || !$max?$floor:0,$max,(int)($mark['max_id'] ?? 0))+1;
    }

    public static function numberFloor(PDO $db): int
    {
        $query=$db->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_sequence' AND source_key='contract_number'");
        return (int)(json_decode($query->fetchColumn() ?: '{}',true)['max_id'] ?? 0);
    }
}
