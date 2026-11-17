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
        $query=$db->prepare('SELECT * FROM contract_objects WHERE contract_id=? ORDER BY object_id');
        $query->execute([$contract]);
        $rows=[];
        foreach ($query->fetchAll() as $row) $rows[(int)$row['object_id']]=$row;
        return $rows;
    }

    public static function physical(?array $relation): bool
    {
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
        if (!InvoiceScope::enabled()) return '1=1';
        return '('.$alias.'.document_id IS NULL OR NOT EXISTS(SELECT 1 FROM document_terms occupancy_term WHERE occupancy_term.document_id='
            .$alias.'.document_id AND occupancy_term.object_id='.$alias.'.object_id AND occupancy_term.physical_occupancy=0))';
    }
}
