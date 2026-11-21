<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Relation meaning only. Billing, invoice scope and displayed composition must not filter it. */
final class ContractOccupancy
{
    public const EXCEPTIONS = 'occupied_billing_exceptions';

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

    /** Independent of invoice-scope admission. The pair may also come from a historical term. */
    public static function contractPredicate(string $alias='co', ?string $pair=null): string
    {
        $pair=self::alias($pair ?? $alias);
        return self::physicalPredicate($alias).' AND NOT EXISTS(SELECT 1 FROM contracts occupancy_contract
            WHERE occupancy_contract.id='.$pair.'.contract_id AND '.self::exceptionAt('occupancy_contract',$pair).')';
    }

    public static function documentPredicate(string $alias='t'): string
    {
        $alias=self::alias($alias);
        return self::physicalPredicate($alias).' AND NOT EXISTS(SELECT 1 FROM rental_documents occupancy_document
            JOIN contracts occupancy_contract ON occupancy_contract.id=occupancy_document.contract_id
            WHERE occupancy_document.id='.$alias.'.document_id AND '.self::exceptionAt('occupancy_contract',$alias).')';
    }

    public static function exceptionAt(string $contract,string $pair): string
    {
        $contract=self::alias($contract);
        $pair=self::alias($pair);
        return "JSON_CONTAINS_PATH($contract.source_json,'one',CONCAT('$.".self::EXCEPTIONS.".\"',$pair.object_id,'\"'))=1";
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
