<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;

final class LeaseCalculationAddenda
{
    /** Show only references linked to an object rate effective on the register date. */
    public static function byContract(PDO $db,array $contracts,string $date): array
    {
        if (!$contracts) return [];
        $sources=[];
        $priorObjects=[];
        $priorIds=[];
        foreach ($contracts as $contract) {
            $id=(int)$contract['id'];
            $sources[$id]=Importer::decodeExact($contract['source_json']);
            $move=$sources[$id]['ownerParkingRegisterAssignment'] ?? [];
            if (($move['register_sha256'] ?? null)===ArchivedParkingAssignment::REGISTER_SHA
                && (int)($move['gidfinance']['contract'] ?? 0)===$id) {
                $from=(int)$move['gidfinance']['from'];
                $priorObjects[$id][(int)$move['gidfinance']['to']]=$from;
                $priorIds[$from]=$from;
            }
        }
        $priorDetails=self::objects($db,$priorIds);
        $params=implode(',',array_fill(0,count($contracts),'?'));
        $query=$db->prepare("WITH current_terms AS (
            SELECT t.*,o.name,k.number cadastre,
                ROW_NUMBER() OVER (PARTITION BY t.contract_id,t.object_id ORDER BY t.effective_date DESC,t.id DESC) position
            FROM contract_terms t
            JOIN contract_objects co ON co.contract_id=t.contract_id AND co.object_id=t.object_id
            JOIN rental_objects o ON o.id=t.object_id
            LEFT JOIN cadastre k ON k.id=o.cadastre_id
            WHERE t.contract_id IN ($params) AND t.start_date<=? AND t.end_date>=?
                AND (t.effective_date IS NULL OR t.effective_date<=?)
                AND (t.superseded_on IS NULL OR t.superseded_on>=?)
        )
        SELECT t.*,c.number contract_number,c.document_date,
            (SELECT COALESCE(
                MAX(CASE WHEN d.id=c.servicing_document_id AND d.effective_date=c.servicing_effective_date THEN d.number END),
                IF(COUNT(*)=1,MAX(d.number),NULL))
             FROM rental_documents d JOIN document_terms dt ON dt.document_id=d.id
             WHERE d.contract_id=t.contract_id AND d.status='signed' AND d.applied=1 AND d.kind<>'termination'
                 AND d.effective_date=t.effective_date AND (d.document_date IS NULL OR d.document_date<=?)
                 AND dt.object_id=t.object_id AND dt.start_date=t.start_date AND dt.end_date=t.end_date
                 AND dt.amount=t.amount) applied_number
        FROM current_terms t
        JOIN contracts c ON c.id=t.contract_id
        WHERE t.position=1 AND t.amount IS NOT NULL");
        $query->execute([...array_keys($sources),$date,$date,$date,$date,$date]);

        $result=[];
        foreach ($query as $term) {
            $id=(int)$term['contract_id'];
            $priorId=$priorObjects[$id][$term['object_id']] ?? null;
            $numbers=$term['applied_number']===null
                ? self::imported($term,$sources[$id],$priorDetails[$priorId] ?? [],$date)
                : [$term['applied_number']];
            foreach ($numbers as $number) {
                $label=self::label($number);
                if ($label!=='') $result[$id][$label]=$label;
            }
        }
        foreach ($result as &$labels) {
            natsort($labels);
            $labels=array_values($labels);
        }
        unset($labels);
        return $result;
    }

    private static function objects(PDO $db,array $ids): array
    {
        if (!$ids) return [];
        $parameters=implode(',',array_fill(0,count($ids),'?'));
        $query=$db->prepare("SELECT o.id,o.name,k.number cadastre FROM rental_objects o
            LEFT JOIN cadastre k ON k.id=o.cadastre_id WHERE o.id IN ($parameters)");
        $query->execute(array_values($ids));
        return array_column($query->fetchAll(),null,'id');
    }

    private static function imported(array $term,array $contractSource,array $priorObject,string $date): array
    {
        if (empty($contractSource['imported'])) return [];
        $source=Importer::decodeExact($term['source_json']);
        $effective=$term['effective_date'] ?? $term['start_date'];
        if (($source['owner_confirmed'] ?? null)===true
            && ($source['effective_date'] ?? null)===$effective
            && !empty($source['source_invoice']) && !empty($source['condition_id'])
            && preg_match('/^[a-f0-9]{64}$/D',$source['source_sha256'] ?? '')) {
            return [$source['number'] ?? ''];
        }

        // Older imports repeat the entire contract's conditions in every object row.
        $direct=$source['sourceCondition'] ?? null;
        $conditions=$direct ? [$direct] : ($source['sourceConditions'] ?? []);
        $matches=[];
        foreach ($conditions as $condition) {
            if (($condition['kind'] ?? null)!=='RENT'
                || ($condition['contract_number'] ?? null)!==$term['contract_number']
                || ($condition['contract_date'] ?? null)!==$term['document_date']
                || empty($condition['party_id'])
                || $condition['party_id']!==($contractSource['sourceLegalMapping']['party1CRef_Key'] ?? null)
                || ($condition['start'] ?? null)!==$effective || ($condition['end'] ?? '')<$date
                || ($condition['monthly_gross'] ?? null)!==$term['amount']
                || empty($condition['condition_id'])) continue;
            if (!self::matchesObject($condition,$term) && !($direct && self::matchesObject($condition,$priorObject))) continue;
            $matches[$condition['condition_id']]=$condition;
        }
        if (count($matches)!==1) return [];
        $condition=reset($matches);
        $confirmed=($condition['outcome'] ?? null)==='ACTIVE' && empty($condition['issues']);
        if ($direct && ($source['sourceContinuationConfirmed'] ?? null)===true) {
            $confirmed=$confirmed || ($condition['identity_grade'] ?? null)==='OWNER_CONFIRMED';
        }
        if ($direct && ($source['ownerConfirmedReplacement'] ?? null)===true) {
            $confirmed=$confirmed || ($contractSource['ownerConfirmedReplacement']['planned_condition'] ?? null)===$condition['condition_id'];
        }
        return $confirmed ? ($condition['ds'] ?? []) : [];
    }

    private static function matchesObject(array $condition,array $object): bool
    {
        return isset($object['name']) && in_array($object['name'],$condition['rooms'] ?? [],true)
            && (empty($condition['cadastre']) || $condition['cadastre']===($object['cadastre'] ?? null));
    }

    private static function label(string $number): string
    {
        $number=preg_replace('/^(?:ДС\s*)?№?\s*/ui','',trim($number));
        return preg_match('/^[\pL\d][\pL\d._\/-]*$/uD',$number) ? 'ДС'.$number : '';
    }
}
