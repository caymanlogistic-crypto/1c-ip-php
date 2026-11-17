<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\InvoiceScope;
use PDO;

final class NativeInvoiceScope
{
    private const SOURCE='213de8b874100f2ff00251625e25113ed4e164a3f3cb7796e89573eccb3c6eac';

    /** Called after validated native creation, never to repair an imported invoice. */
    public static function record(PDO $db,int $id,string $origin,?string $command=null,?array $selectedPlan=null): void
    {
        if ($selectedPlan!==null) {self::selected($db,$id,$origin,$command,$selectedPlan);return;}
        if (!InvoiceScope::enabled()) return;
        $invoice=Service::get($db,'invoices',$id);
        $contract=Service::get($db,'contracts',(int)$invoice['contract_id']);
        $security=$invoice['kind']==='Обеспечительный платёж';
        $coverage=$security?[]:MonthlyBilling::sourceCoverage($db,$contract,$invoice);
        $query=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
        $query->execute([$id]); $objects=$query->fetchAll(PDO::FETCH_COLUMN);
        if (!$objects && !($security && $origin==='native-c-manual')) {
            $query=$db->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');
            $query->execute([$contract['id']]); $objects=$query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($objects as $object) $db->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([$id,$object]);
        }
        $query=$db->prepare('SELECT COUNT(*) FROM invoice_coverage WHERE invoice_id=?');
        $query->execute([$id]);
        if (!(int)$query->fetchColumn()) foreach ($coverage as $period) {
            $db->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)')
                ->execute([$id,$period['object_id'],$period['start_date'],$period['end_date']]);
        }
        InvoiceScope::recordNative($db,$id,$origin,[
            'objects_state'=>'KNOWN','periods_state'=>$security || $coverage?'KNOWN':'UNKNOWN',
            'classification'=>$security?'NOT_RENTAL_SECURITY':'RENTAL',
            'source_sha256'=>self::SOURCE,'command_key'=>$command,
        ]);
    }

    /** Explicit native targets never enter the legacy all-contract-object fallback. */
    private static function selected(PDO $db,int $id,string $origin,?string $command,array $plan): void
    {
        if (!$db->inTransaction() || \App\BillingPartAdmission::selected()===null || !in_array($origin,['native-c-monthly','native-c-initial'],true)) {
            throw new \RuntimeException('Явный план предметов начисления не допущен.',409);
        }
        $invoice=self::assertSelected($db,$id,$plan,false);
        InvoiceScope::recordNative($db,$id,$origin,['objects_state'=>$plan['physical_objects_state'],
            'periods_state'=>$plan['physical_periods_state'],'classification'=>$plan['classification']??'RENTAL','source_sha256'=>self::SOURCE,'command_key'=>$command]);
        \App\BillingPartScope::record($db,$id,$plan['part_scope_state'],$plan['part_scope_rows'],0,[
            'admission_sha256'=>\App\BillingPartAdmission::selected(),'source_sha256'=>self::SOURCE,
            'evidence_sha256'=>$plan['plan_sha256'],'invoice_version'=>(int)$invoice['version'],
            'command_key'=>$command ?? 'selected-plan:'.$id,'known_empty_proven'=>$plan['part_empty_proven'],
        ]);
    }

    /** Read-only replay verification. It never repairs a missing header or overwrites scope. */
    public static function assertSelected(PDO $db,int $id,array $plan,bool $stored=true): array
    {
        if (\App\BillingPartAdmission::selected()===null)throw new \RuntimeException('Допуск явного плана счёта выключен.',409);
        $compiled=\App\BillingInvoiceTargets::compile($plan['selected_terms'] ?? [],$plan['selected_intervals'] ?? [],
            $plan['producer_evidence_sha256'] ?? '',empty($plan['selected_terms']),$plan['classification']??'RENTAL');
        if (Importer::canonicalJson($compiled)!==Importer::canonicalJson($plan))throw new \RuntimeException('План предметов счёта изменился.',409);
        $invoice=Service::get($db,'invoices',$id);
        $classification=$plan['classification']??'RENTAL';
        if (($invoice['kind']==='Обеспечительный платёж')!==($classification==='NOT_RENTAL_SECURITY')
            ||!in_array($invoice['kind'],['Аренда','Первый период','Обеспечительный платёж'],true))throw new \RuntimeException('Назначение счёта отличается от явного плана.',409);
        $query=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');$query->execute([$id]);
        if (array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN))!==$plan['physical_objects'])throw new \RuntimeException('Помещения счёта отличаются от явного плана.',409);
        $query=$db->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date,end_date');$query->execute([$id]);$actual=$query->fetchAll();
        foreach($actual as &$row)$row['object_id']=(int)$row['object_id'];unset($row);
        $expected=$plan['physical_coverage'];usort($expected,static fn($a,$b)=>$a['object_id']<=>$b['object_id'] ?: strcmp($a['start_date'],$b['start_date']) ?: strcmp($a['end_date'],$b['end_date']));
        if ($actual!==$expected)throw new \RuntimeException('Периоды помещений отличаются от явного плана.',409);
        if ($stored) {
            $scope=\App\BillingPartScope::read($db,$id);
            if ($scope===null)throw new \RuntimeException('Не подтверждён сохранённый состав частей счёта.',409);
            \App\BillingPartScope::requireReady($scope);
            $normalize=static function(array $rows):array{$rows=array_map(static fn($row)=>array_intersect_key($row,array_flip(['part_id','part_version','scope_state','start_date','end_date'])),$rows);
                usort($rows,static fn($a,$b)=>strcmp(Importer::canonicalJson($a),Importer::canonicalJson($b)));return $rows;};
            if ($scope['evidence_sha256']!==$plan['plan_sha256']
                ||Importer::canonicalJson($normalize($scope['coverage']))!==Importer::canonicalJson($normalize($plan['part_scope_rows']))) {
                throw new \RuntimeException('Сохранённый состав частей отличается от плана.',409);
            }
            $physical=InvoiceScope::read($db,$id);
            if ($physical!==null && ($physical['availability']!=='KNOWN'||!empty($physical['runtime_hold'])||$physical['classification']!==$classification)) {
                throw new \RuntimeException('Не подтверждено сохранённое покрытие помещений счёта.',409);
            }
        }
        return $invoice;
    }
}
