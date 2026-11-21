<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{ContractOccupancy,InvoiceScope};
use PDO;
use RuntimeException;

/** Per-relation physical meaning. Never use this filter for rent or document composition. */
final class Occupancy
{
    public static function proofColumns(string $alias): string
    {
        return InvoiceScope::enabled()?','.ContractOccupancy::selectColumns($alias):'';
    }

    public static function boundary(string $field,string $relation='co',string $contract='c'): string
    {
        if (!in_array($field,['start_date','end_date'],true)) throw new RuntimeException('Invalid term boundary.');
        ContractOccupancy::physicalPredicate($relation);
        ContractOccupancy::physicalPredicate($contract);
        return 'COALESCE((SELECT occupancy_boundary.'.$field.' FROM contract_terms occupancy_boundary WHERE occupancy_boundary.contract_id='
            .$relation.'.contract_id AND occupancy_boundary.object_id='.$relation.'.object_id ORDER BY occupancy_boundary.effective_date DESC,occupancy_boundary.id DESC LIMIT 1),'.$contract.'.'.$field.')';
    }

    public static function relations(PDO $db,int $contract): array
    {
        $query=$db->prepare('SELECT co.*,'.ContractOccupancy::exceptionAt('c','co').' billing_exception
            FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE co.contract_id=? ORDER BY co.object_id');
        $query->execute([$contract]);
        $rows=[];
        foreach ($query->fetchAll() as $row) {
            if (empty($row['billing_exception'])) unset($row['billing_exception']);
            $rows[(int)$row['object_id']]=$row;
        }
        return $rows;
    }

    public static function objects(PDO $db,int $contract,bool $physicalOnly=false): array
    {
        $q=$db->prepare('SELECT o.id,o.name,o.type FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id
            WHERE co.contract_id=?'.($physicalOnly?' AND '.ContractOccupancy::contractPredicate('co'):'').' ORDER BY o.id');
        $q->execute([$contract]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function leasesOn(string $date,?int $object=null): array
    {
        $rows=Controller::query('SELECT co.object_id,c.id,c.number,c.document_date,c.status,c.return_confirmed,p.name tenant,t.source_json term_source,
            COALESCE(t.start_date,c.start_date) term_start,COALESCE(t.end_date,c.end_date) end_date
            FROM contract_objects co JOIN contracts c ON c.id=co.contract_id JOIN parties p ON p.id=c.party_id
            LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2 WHERE t2.contract_id=c.id AND t2.object_id=co.object_id
                AND (t2.effective_date IS NULL OR t2.effective_date<=?))
            WHERE '.ContractOccupancy::contractPredicate('co')." AND (? IS NULL OR co.object_id=?)
            AND (c.status='reconciling' OR (c.status='active' AND COALESCE(t.start_date,c.start_date)<=? AND COALESCE(t.end_date,c.end_date)>=?)
                OR ".self::expiredWithoutReturn('COALESCE(t.end_date,c.end_date)','?','?').')
            ORDER BY c.id DESC',[$date,$object,$object,$date,$date,$date,$date]);
        $leases=[];
        foreach($rows as $row) {
            $id=$row['object_id'];
            unset($row['object_id']);
            $leases[$id]??=$row;
        }
        return $leases;
    }

    /** A recorded, signed successor displaces only this predecessor and physical object from its first day. */
    public static function expiredWithoutReturn(string $end,string $cutoff,string $requested): string
    {
        return "(c.status='active' AND $end<$cutoff AND c.return_confirmed IS NULL AND NOT EXISTS (
            SELECT 1 FROM rental_facts x JOIN contracts successor
                ON successor.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(x.fact_json,'$.id')) AS UNSIGNED)
            JOIN contract_terms successor_term ON successor_term.contract_id=successor.id AND successor_term.object_id=co.object_id
                AND successor_term.start_date=DATE_ADD($end,INTERVAL 1 DAY)
            JOIN contract_objects successor_object ON successor_object.contract_id=successor.id AND successor_object.object_id=co.object_id
            WHERE x.namespace='".ExpiryContinuation::NS."' AND x.operative=1 AND x.source_key=CONCAT(c.id,':',$end)
                AND ".ContractOccupancy::contractPredicate('successor_object')."
                AND JSON_UNQUOTE(JSON_EXTRACT(x.fact_json,'$.kind'))='contract'
                AND successor.signed=1 AND successor.status IN ('active','reconciling','closed','ended')
                AND successor.party_id=c.party_id AND successor_term.start_date<=$requested))";
    }

    public static function physical(?array $relation): bool
    {
        if (!empty($relation['billing_exception'])) return false;
        return !InvoiceScope::enabled() || $relation===null || ($relation['physical_occupancy'] ?? null)===null
            || (int)$relation['physical_occupancy']!==0;
    }

    public static function insert(PDO $db,string $table,array $fields,?array $prior=null): void
    {
        if (!in_array($table,['contract_objects','document_terms','rental_object_holds'],true)) throw new RuntimeException('Invalid occupancy relation table.');
        $fields+=ContractOccupancy::insertFields($prior);
        $db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')
            ->execute(array_values($fields));
    }

    public static function reservationPredicate(string $alias='r'): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/iD',$alias)) throw new RuntimeException('Invalid reservation alias.');
        return '('.$alias.'.document_id IS NULL OR NOT EXISTS(SELECT 1 FROM document_terms occupancy_term WHERE occupancy_term.document_id='
            .$alias.'.document_id AND occupancy_term.object_id='.$alias.'.object_id AND NOT('.ContractOccupancy::documentPredicate('occupancy_term').')))';
    }

    public static function documentTerms(PDO $db,int $id): array
    {
        $q=$db->prepare('SELECT t.*,NOT('.ContractOccupancy::documentPredicate('t').') billing_exception
            FROM document_terms t WHERE t.document_id=? ORDER BY t.object_id');
        $q->execute([$id]);
        return $q->fetchAll();
    }
}
