<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,Auth,BillableTerms};
use PDO;
use RuntimeException;

/** Source expectedForInvoice: a covered target is priced day by day, never by whole-contract total. */
final class InvoiceCalculation
{
    public static function reviewFacts(PDO $db,array $invoiceIds): array
    {
        $ids=array_values(array_unique(array_map('intval',$invoiceIds)));
        if(!$ids)return [];
        $q=$db->prepare("SELECT f.source_key,f.fact_json,d.number document_number,d.effective_date document_effective_date,n.number replacement_number FROM rental_facts f JOIN invoices i ON i.id=CAST(f.source_key AS UNSIGNED) LEFT JOIN rental_documents d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.document')) AS UNSIGNED) LEFT JOIN invoices n ON n.document_id=d.id AND n.contract_id=i.contract_id AND n.kind=i.kind AND n.period=i.period AND n.id<>i.id AND n.status<>'revoked' WHERE f.namespace='calculation_review' AND f.source_key IN (".implode(',',array_fill(0,count($ids),'?')).") ORDER BY n.id");
        $q->execute(array_map('strval',$ids));$result=[];
        foreach($q as $row){
            $id=(int)$row['source_key'];
            $result[$id]??=json_decode($row['fact_json'],true)+['document_number'=>$row['document_number'],'document_effective_date'=>$row['document_effective_date'],'replacement_invoices'=>[]];
            if($row['replacement_number']!==null)$result[$id]['replacement_invoices'][]=$row['replacement_number'];
        }
        return $result;
    }

    private static function target(array $row): string
    {
        if (isset($row['target_key']) && is_string($row['target_key'])) return $row['target_key'];
        if (isset($row['object_id'])) return 'object:'.(string)$row['object_id'];
        throw new RuntimeException('Не подтверждён предмет расчёта счёта.');
    }

    public static function expected(array $coverage,array $before,array $after=[],?string $effective=null): ?string
    {
        // LCM(28,29,30,31) keeps all daily month fractions exact until final rounding.
        $sum='0';$visited=0;
        foreach ($coverage as $segment) {
            $target=self::target($segment);
            $start=Service::date($segment['start_date']);$end=Service::date($segment['end_date']);
            for ($day=new \DateTimeImmutable($start);$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')) {
                if (++$visited>37000) throw new RuntimeException('Период расчёта счёта превышает допустимый диапазон.');
                $date=$day->format('Y-m-d');
                $pool=$effective!==null && $date>=$effective?$after:$before;
                $targetKnown=false;
                foreach ($pool as $term) {
                    if (self::target($term)!==$target) continue;
                    $targetKnown=true;
                    if ($term['start_date']===null || $term['end_date']===null) return null;
                    if ($term['start_date']>$date || $term['end_date']<$date
                        || !empty($term['effective_date']) && $term['effective_date']>$date
                        || !empty($term['superseded_on']) && $term['superseded_on']<$date) continue;
                    if ($term['amount']===null) return null;
                    if (!preg_match('/^-?[0-9]{1,18}(?:\.[0-9]{1,12})?$/D',$term['amount'])) throw new RuntimeException('Цена должна сохранять точную десятичную запись.');
                    $units=bcmul($term['amount'],'1000000000000',0);
                    $weight=bcdiv('377580',$day->format('t'),0);
                    $sum=bcadd($sum,bcmul($units,$weight,0),0);
                    break;
                }
                if (!$targetKnown && ($effective===null || $date<$effective)) return null;
            }
        }
        return Billing::round(bcdiv($sum,'377580000000000000',12));
    }

    public static function review(PDO $db,array $contract,?array $document=null): void
    {
        if (!$db->inTransaction()) throw new RuntimeException('Проверка расчёта требует транзакции.');
        MonthlyBilling::assertCoverageReady($db,(int)$contract['id']);
        $before=array_reverse(BillableTerms::forContract($db,(int)$contract['id']));
        $after=$document?array_reverse(BillableTerms::forDocument($db,(int)$document['id'])):[];
        $query=$db->prepare("SELECT * FROM invoices WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж' ORDER BY id FOR UPDATE");
        $query->execute([$contract['id']]);
        $changes=[];
        foreach ($query->fetchAll() as $invoice) {
            $coverage=MonthlyBilling::coverage($db,$contract,$invoice);
            if (!$coverage || $document && !array_filter($coverage,fn($segment)=>$segment['end_date']>=$document['effective_date'])) continue;
            $expected=self::expected($coverage,$before,$after,$document['effective_date'] ?? null);
            if ($expected===null || $invoice['amount']===null || bccomp($expected,$invoice['amount'],2)===0) continue;
            $query=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='calculation_review' AND source_key=?");
            $query->execute([(string)$invoice['id']]);$prior=$query->fetchColumn();
            $prior=$prior?json_decode($prior,true):null;
            // A completed human review is not reopened for an unchanged expected price.
            if (isset($prior['expectedAmount']) && (string)$prior['expectedAmount']===$expected) continue;
            $excluded=[];
            if($document){
                $afterTargets=array_column($after,'target_key');
                $names=array_column($before,'name','target_key');
                foreach($coverage as $segment)if($segment['end_date']>=$document['effective_date']&&!in_array(self::target($segment),$afterTargets,true))$excluded[]=$names[self::target($segment)] ?? self::target($segment);
            }
            $changes[]=[$invoice,$expected,$prior,$coverage,array_values(array_unique($excluded))];
        }
        foreach ($changes as [$invoice,$expected,$prior,$coverage,$excluded]) {
            $query=$db->prepare('UPDATE invoices SET manual_review=1,control_closed=0,version=version+1 WHERE id=? AND version=?');
            $query->execute([$invoice['id'],$invoice['version']]);
            if ($query->rowCount()!==1) throw new RuntimeException('Счёт изменён во время проверки расчёта.',409);
            $details=['expectedAmount'=>$expected,'historicalAmount'=>$invoice['amount'],
                'difference'=>bcsub($expected,$invoice['amount'],2),'document'=>$document['id'] ?? null,
                'checkedAt'=>date('Y-m-d'),'reviewRequired'=>true,'coverage'=>$coverage,
                'effectiveDate'=>$document['effective_date'] ?? null,'excludedTargets'=>$excluded];
            $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('calculation_review',?,?,0) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")
                ->execute([(string)$invoice['id'],Importer::json($details)]);
            Audit::record('invoice.calculation-review','invoice',$invoice['id'],$prior,$details);
            $actor=Auth::user();
            $db->prepare("INSERT INTO rental_events(contract_id,actor_id,actor_role,actor_name,actor_role_id,action,description) VALUES(?,?,'system','Система',?,'invoice.calculation-review',?)")
                ->execute([$contract['id'],$actor['id'] ?? null,$actor['role_id'] ?? null,
                    'Расчёт счёта '.$invoice['number'].' по новым условиям требует проверки: '.$expected.' вместо '.$invoice['amount']]);
        }
    }
}
