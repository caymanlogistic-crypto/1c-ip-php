<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Relation meaning only. Billing, invoice scope and displayed composition must not filter it. */
final class ContractOccupancy
{
    private static function alias(string $alias): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/D',$alias)) throw new RuntimeException('Invalid occupancy SQL alias.');
        return $alias;
    }

    public static function physicalPredicate(string $alias='co'): string
    {
        $alias=self::alias($alias);
        return InvoiceScope::enabled()?'COALESCE('.$alias.'.physical_occupancy,1)=1':'1=1';
    }

    public static function selectColumns(string $alias='co'): string
    {
        $alias=self::alias($alias);
        return InvoiceScope::enabled()?$alias.'.physical_occupancy,'.$alias.'.occupancy_evidence_sha256':'NULL AS physical_occupancy,NULL AS occupancy_evidence_sha256';
    }

    /** Preserve historical NULL and explicitly proved false through edit/DS/removed holds. */
    public static function insertFields(?array $priorRow=null): array
    {
        if (!InvoiceScope::enabled()) return [];
        if ($priorRow===null) return ['physical_occupancy'=>1,'occupancy_evidence_sha256'=>null];
        $value=$priorRow['physical_occupancy']??null;
        if ($value!==null && !in_array($value,[0,1,'0','1',false,true],true)) throw new RuntimeException('Invalid relation occupancy state.');
        $value=$value===null?null:(int)$value;
        $proof=$priorRow['occupancy_evidence_sha256']??null;
        if ($proof!==null && !preg_match('/^[a-f0-9]{64}$/D',$proof)) throw new RuntimeException('Invalid occupancy proof hash.');
        if ($value===0 && $proof===null) throw new RuntimeException('Nonphysical relation requires explicit source evidence.');
        return ['physical_occupancy'=>$value,'occupancy_evidence_sha256'=>$proof];
    }
}
