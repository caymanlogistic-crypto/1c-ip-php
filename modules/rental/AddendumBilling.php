<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

/** Source WF44/59–70/165: the B preview and its committed invoice membership. */
final class AddendumBilling
{
    /** C packetPreview.stateSignature: membership and financial-state admission are separate. */
    public static function financialState(PDO $db,int $contractId): array
    {
        $query=$db->prepare('SELECT * FROM invoices WHERE contract_id=? ORDER BY id');
        $query->execute([$contractId]);
        $invoices=$query->fetchAll();
        foreach ($invoices as &$invoice) {
            unset($invoice['source_json']);
            $query=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
            $query->execute([$invoice['id']]);
            $invoice['object_ids']=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
            $query=$db->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date,end_date');
            $query->execute([$invoice['id']]);
            $invoice['coverage']=$query->fetchAll();
            foreach ($invoice['coverage'] as &$segment) $segment['object_id']=(int)$segment['object_id'];
            unset($segment);
            $invoice['scope_admission']=\App\InvoiceScope::metadata($db,(int)$invoice['id']);
        }
        unset($invoice);
        return $invoices;
    }

    public static function planned(PDO $db,array $contract,array $document,?string $today=null): array
    {
        $today=Service::date($today ?? date('Y-m-d'));
        if ($document['status']!=='approved' && in_array($contract['status'],['active','reconciling'],true)) {
            MonthlyBilling::assertCoverageReady($db,(int)$contract['id']);
        }
        $query=$db->prepare('SELECT * FROM document_terms WHERE document_id=? ORDER BY object_id');
        $query->execute([$document['id']]);
        $terms=$query->fetchAll();
        $query=$db->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');
        $query->execute([$contract['id']]);
        $previous=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
        $query=$db->prepare("SELECT * FROM invoices WHERE contract_id=? AND status<>'revoked' ORDER BY id");
        $query->execute([$contract['id']]);
        $invoices=$query->fetchAll();
        $coverage=[];
        foreach ($document['status']==='approved'?[]:$invoices as $invoice) {
            if ($invoice['kind']==='Обеспечительный платёж') continue;
            $coverage=array_merge($coverage,MonthlyBilling::coverage($db,$contract,$invoice));
        }
        $partState=\App\BillingPartTerms::state($db,'document',(int)$document['id']);
        if($partState!==null&&$partState['state']!=='KNOWN')throw new RuntimeException('Не подтверждён состав частей ДС.',409);
        $calendarTerms=$partState===null?$terms:[...$terms,...\App\BillingParts::terms($db,'document',(int)$document['id'])];
        $next=(new \DateTimeImmutable($today))->modify('first day of next month')->format('Y-m');
        $nextIssue=null;
        if ($document['status']!=='approved' && in_array($contract['status'],['active','reconciling'],true)
            && array_filter($calendarTerms,fn($term)=>$term['start_date']<=$next.'-31' && $term['end_date']>=$next.'-01')) {
            $reference=SourceCalendar::registered() ?? throw new RuntimeException('CALENDAR_REFERENCE_UNAVAILABLE');
            $nextIssue=$reference->issueDate($next);
        }
        $plans=self::calculate($contract,$document,$terms,$previous,$coverage,$today,$nextIssue);
        if($partState!==null){
            $plans=self::partPlans($db,$contract,$document,$plans,$invoices,$today,$nextIssue);
        }
        $byKey=[];
        foreach ($invoices as $invoice) if ($invoice['coverage_key']!==null) $byKey[$invoice['coverage_key']][]=$invoice;
        foreach ($plans as &$plan) $plan['existing']=$byKey[$plan['coverage_key']] ?? [];
        unset($plan);
        // Unsent regular rows already attributed to this DS remain packet members.
        $keys=array_column($plans,'coverage_key');
        foreach ($invoices as $invoice) {
            if ((int)$invoice['planning_document_id']!==(int)$document['id'] || $invoice['sent']
                || in_array($invoice['coverage_key'],$keys,true)) continue;
            $query=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
            $query->execute([$invoice['id']]);
            $retained=self::descriptor($invoice['kind'],$invoice['period'],$invoice['amount'],$invoice['coverage_key'],
                array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN)),MonthlyBilling::sourceCoverage($db,$contract,$invoice),
                null,(int)$document['id'])+['existing'=>[$invoice]];
            if($partState!==null){
                $receipt=\App\BillingInvoiceTargets::read($db,(int)$invoice['id']);
                if($receipt===null)throw new RuntimeException('Не подтверждён исходный план сохранённого счёта ДС.',409);
                \App\BillingInvoiceTargets::assertCurrent($db,(int)$invoice['id'],$receipt,$receipt['plan']);
                NativeInvoiceScope::assertSelected($db,(int)$invoice['id'],$receipt['plan']);
                $retained['invoice_targets']=$receipt['plan'];
            }
            $plans[]=$retained;
        }
        return $plans;
    }

    /** Pure source arithmetic. Existing coverage must already have passed typed admission. */
    public static function calculate(array $contract,array $document,array $terms,array $previous,array $coverage,string $today,?string $nextIssue): array
    {
        $plans=[];
        $added=array_values(array_filter($terms,fn($term)=>!in_array((int)$term['object_id'],$previous,true)));
        if ($added) {
            $security='0';$periods=[];$firstCoverage=[];
            foreach ($added as $term) {
                $start=max($term['start_date'],$document['effective_date']);
                $end=min($term['end_date'],(new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'));
                if ($end<$start || $term['amount']===null) throw new RuntimeException('Неподтверждённые условия добавленного помещения.');
                $security=bcadd($security,$term['amount'],12);
                $periods[]=substr($start,0,7);
                $firstCoverage[]=['object_id'=>(int)$term['object_id'],'start_date'=>$start,'end_date'=>$end];
            }
            $periods=array_values(array_unique($periods));sort($periods);
            $objects=array_map('intval',array_column($added,'object_id'));sort($objects,SORT_NUMERIC);
            $plans[]=self::descriptor('Обеспечительный платёж','Обеспечение',Billing::round($security),
                'addendum:'.$document['id'].':Обеспечительный платёж',$objects,[],(int)$document['id'],null);
            $plans[]=self::descriptor('Первый период',implode(' / ',$periods),InvoiceCalculation::expected($firstCoverage,$added),
                'addendum:'.$document['id'].':Первый период',$objects,$firstCoverage,(int)$document['id'],null);
            $coverage=array_merge($coverage,$firstCoverage);
        }
        if ($document['status']==='approved' || !in_array($contract['status'],['active','reconciling'],true)) return $plans;
        $next=(new \DateTimeImmutable($today))->modify('first day of next month')->format('Y-m');
        $from=substr($document['start_date'],0,7);
        $count=0;
        for ($period=$from;$period<=$next;$period=(new \DateTimeImmutable($period.'-01'))->modify('first day of next month')->format('Y-m')) {
            if (++$count>1200) throw new RuntimeException('План ДС превышает допустимый диапазон.');
            if ($period>substr($today,0,7) && ($nextIssue===null || $nextIssue>$today)) continue;
            $last=(new \DateTimeImmutable($period.'-01'))->modify('last day of this month')->format('Y-m-d');
            $missing=[];
            foreach ($terms as $term) {
                $start=max($term['start_date'],$period.'-01');
                $end=min($term['end_date'],$last);
                if ($start>$end) continue;
                $covered=false;
                foreach ($coverage as $existing) if ((int)$existing['object_id']===(int)$term['object_id']
                    && $existing['start_date']<=$start && $existing['end_date']>=$end) {$covered=true;break;}
                if ($covered) continue;
                if ($term['amount']===null) throw new RuntimeException('Цена помещения не подтверждена для расчёта ДС.');
                $missing[]=['object_id'=>(int)$term['object_id'],'start_date'=>$start,'end_date'=>$end];
            }
            if (!$missing) continue;
            $objects=array_column($missing,'object_id');sort($objects,SORT_NUMERIC);
            $key=$contract['id'].':Аренда:'.$period.':'.implode(',',$objects).':'.$document['id'];
            $plans[]=self::descriptor('Аренда',$period,InvoiceCalculation::expected($missing,$terms),$key,$objects,$missing,null,(int)$document['id']);
            $coverage=array_merge($coverage,$missing);
        }
        return $plans;
    }

    private static function descriptor(string $kind,string $period,?string $amount,?string $key,array $objects,array $coverage,?int $documentId,?int $planningId): array
    {
        if ($key===null || $amount===null) throw new RuntimeException('Состав ранее выставленного счёта ДС не подтверждён.');
        return ['kind'=>$kind,'period'=>$period,'amount'=>$amount,'coverage_key'=>$key,
            'objects'=>$objects,'coverage'=>$coverage,'document_id'=>$documentId,'planning_document_id'=>$planningId];
    }

    /** Explicit DS terms add rental coverage; part presence never creates added-room security. */
    private static function partPlans(PDO $db,array $contract,array $document,array $plans,array $invoices,string $today,?string $nextIssue): array
    {
        $terms=\App\BillableTerms::forDocument($db,(int)$document['id']);
        $parts=array_values(array_filter($terms,static fn($term)=>$term['target_kind']==='billing-part'));
        // First bind source-added physical descriptors to exactly their own selected terms.
        foreach($plans as &$plan){
            $security=$plan['kind']==='Обеспечительный платёж';
            if($security){
                $compiled=InitialBilling::targets($terms,$document['start_date'],$document['end_date'],true,'addendum:'.$document['id'].':'.$plan['coverage_key']);
            }else{
                $selected=[];$intervals=[];
                foreach($terms as $term){
                    if($term['target_kind']!=='physical-object')continue;
                    foreach($plan['coverage'] as $segment){
                        if($term['object_id']!==$segment['object_id'])continue;
                        $from=max($term['start_date']??'9999-12-31',$segment['start_date']);
                        $to=min($term['end_date']??'0001-01-01',$segment['end_date']);
                        if($from>$to)continue;
                        $selected[$term['source_term_key']]=$term;
                        $intervals[]=['source_term_key'=>$term['source_term_key'],'start_date'=>$from,'end_date'=>$to];
                    }
                }
                $compiled=\App\BillingInvoiceTargets::compile(array_values($selected),$intervals,
                    hash('sha256',Importer::canonicalJson(['addendum'=>(int)$document['id'],'key'=>$plan['coverage_key'],'source'=>\App\BillingPartAdmission::NATIVE_SOURCE])),$selected===[]);
            }
            $plan['invoice_targets']=$compiled;$plan['objects']=$compiled['physical_objects'];$plan['coverage']=$compiled['physical_coverage'];
        }
        unset($plan);
        if($document['status']==='approved'||!in_array($contract['status'],['active','reconciling'],true)||!$parts)return $plans;
        $next=(new \DateTimeImmutable($today))->modify('first day of next month')->format('Y-m');
        $count=0;
        for($period=substr($document['start_date'],0,7);$period<=$next;$period=(new \DateTimeImmutable($period.'-01'))->modify('first day of next month')->format('Y-m')){
            if(++$count>1200)throw new RuntimeException('План ДС превышает допустимый диапазон.',409);
            if($period>substr($today,0,7)&&($nextIssue===null||$nextIssue>$today))continue;
            $first=$period.'-01';$last=(new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
            $from=max($document['start_date'],$first);$to=min($document['end_date'],$last);
            if($from>$to)continue;
            $monthParts=array_values(array_filter($parts,static fn($term)=>$term['start_date']===null||$term['end_date']===null||$term['start_date']<=$to&&$term['end_date']>=$from));
            if(!$monthParts)continue;
            $candidate=InitialBilling::targets($monthParts,$from,$to,false,'addendum:'.$document['id'].':parts:'.$period);
            $covered=[];
            foreach($invoices as $invoice){
                if($invoice['kind']==='Обеспечительный платёж'||(int)($invoice['document_id']??0)===(int)$document['id']||(int)($invoice['planning_document_id']??0)===(int)$document['id'])continue;
                if(self::outsideNativePeriod($invoice,$period))continue;
                // A persisted producer marker or plan receipt is independent relevance.
                $source=Importer::decodeExact($invoice['source_json']??'{}');
                $receipt=\App\BillingInvoiceTargets::read($db,(int)$invoice['id']);
                if($receipt===null){
                    $binding=$db->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='invoice' AND entity_id=? AND relation_kind='invoice_part_control' LIMIT 1");
                    $binding->execute([$invoice['id']]);
                    if(self::independentPartPlan($invoice,$source,$binding->fetchColumn()!==false))throw new RuntimeException('Не подтверждён план ранее начисленных частей.',409);
                    continue;
                }
                $partIds=array_values(array_unique(array_column($monthParts,'part_id')));$relevant=false;
                foreach($receipt['plan']['part_scope_rows'] as $row)if(in_array($row['part_id'],$partIds,true)&&$row['start_date']<=$to&&$row['end_date']>=$from){$relevant=true;break;}
                if(!$relevant)continue;
                $scope=\App\BillingPartScope::read($db,(int)$invoice['id']);
                if($scope===null)throw new RuntimeException('Не подтверждено покрытие ранее начисленных частей.',409);
                \App\BillingPartScope::requireReady($scope);
                self::assertPartCoverage($scope,$receipt['plan']);
                foreach($scope['coverage'] as $row)if(in_array($row['part_id'],$partIds,true))$covered[]=['target_key'=>'part:'.$row['part_id'],'start_date'=>$row['start_date'],'end_date'=>$row['end_date']];
            }
            $byKey=[];foreach($candidate['selected_terms'] as $term)$byKey[$term['source_term_key']]=$term;
            $missing=[];
            foreach($candidate['selected_intervals'] as $interval){
                $target=$byKey[$interval['source_term_key']]['target_key'];$full=false;
                foreach($covered as $prior){
                    if($prior['target_key']!==$target)continue;
                    if($prior['start_date']<=$interval['start_date']&&$prior['end_date']>=$interval['end_date']){$full=true;break;}
                    if($prior['start_date']<=$interval['end_date']&&$prior['end_date']>=$interval['start_date'])throw new RuntimeException('Покрытие частей ДС требует сверки частичного периода.',409);
                }
                if(!$full)$missing[]=$interval;
            }
            if(!$missing)continue;
            $keys=array_column($missing,'source_term_key');$selected=array_values(array_filter($monthParts,static fn($term)=>in_array($term['source_term_key'],$keys,true)));
            $priced=array_map(static fn($interval)=>$interval+['target_key'=>$byKey[$interval['source_term_key']]['target_key']],$missing);
            $amount=InvoiceCalculation::expected($priced,InitialBilling::orderedTerms($selected));
            if($amount===null||bccomp($amount,'0',2)<=0)throw new RuntimeException('Цена начисления частей ДС не подтверждена.',409);
            $index=null;
            foreach($plans as $i=>$plan)if($plan['kind']==='Аренда'&&$plan['period']===$period){$index=$i;break;}
            if($index===null){
                $plans[]=self::descriptor('Аренда',$period,$amount,$contract['id'].':Аренда:'.$period.'::'.$document['id'],[],[],null,(int)$document['id']);$index=array_key_last($plans);
                $oldTerms=[];$oldIntervals=[];
            }else{
                $plans[$index]['amount']=bcadd($plans[$index]['amount'],$amount,2);
                $oldTerms=$plans[$index]['invoice_targets']['selected_terms'];$oldIntervals=$plans[$index]['invoice_targets']['selected_intervals'];
            }
            $combined=\App\BillingInvoiceTargets::compile([...$oldTerms,...$selected],[...$oldIntervals,...$missing],
                hash('sha256',Importer::canonicalJson(['addendum'=>(int)$document['id'],'key'=>$plans[$index]['coverage_key'],'source'=>\App\BillingPartAdmission::NATIVE_SOURCE])));
            $plans[$index]['invoice_targets']=$combined;$plans[$index]['objects']=$combined['physical_objects'];$plans[$index]['coverage']=$combined['physical_coverage'];
        }
        return $plans;
    }

    /** Only producer identities containing the service month can prove irrelevance without the receipt. */
    public static function outsideNativePeriod(array $invoice,string $period): bool
    {
        $key=$invoice['coverage_key']??'';
        foreach(['/^billable-monthly:[1-9][0-9]*:([0-9]{4}-[0-9]{2}):[a-f0-9]{64}$/D',
            '/^initial:[1-9][0-9]*:(?:Первый период|Аренда):([0-9]{4}-[0-9]{2})$/Du',
            '/^[1-9][0-9]*:Аренда:([0-9]{4}-[0-9]{2}):[0-9,]*:[1-9][0-9]*$/Du'] as $pattern){
            if(preg_match($pattern,$key,$match))return $match[1]!==$period;
        }
        return false;
    }

    public static function independentPartPlan(array $invoice,array $source,bool $sourcePartBinding): bool
    {
        return array_key_exists('native_invoice_targets',$source)||str_starts_with($invoice['coverage_key']??'','billable-monthly:')||$sourcePartBinding;
    }

    public static function assertPartCoverage(array $scope,array $plan): void
    {
        $normalize=static function(array $rows):array{
            $rows=array_map(static fn($row)=>array_intersect_key($row,array_flip(['part_id','part_version','scope_state','start_date','end_date'])),$rows);
            usort($rows,static fn($a,$b)=>strcmp(Importer::canonicalJson($a),Importer::canonicalJson($b)));return $rows;
        };
        if(($scope['evidence_sha256']??null)!==$plan['plan_sha256']
            ||Importer::canonicalJson($normalize($scope['coverage']))!==Importer::canonicalJson($normalize($plan['part_scope_rows']))) {
            throw new RuntimeException('Сохранённое покрытие частей отличается от исходного плана.',409);
        }
    }

    public static function issue(PDO $db,array $contract,array $document): array
    {
        if (!$db->inTransaction()) throw new RuntimeException('Расчёт ДС требует транзакции.');
        if ($document['status']!=='accounting') throw new RuntimeException('Новые счета формируются только при первичной проверке ДС бухгалтером.');
        $plans=self::planned($db,$contract,$document);
        InvoiceCalculation::review($db,$contract,$document);
        $ids=[];
        foreach ($plans as $plan) {
            if ($plan['existing']) {
                if (count($plan['existing'])!==1) throw new RuntimeException('Неоднозначный состав счёта ДС.');
                if(isset($plan['invoice_targets']))InitialBilling::assertExisting($db,(int)$plan['existing'][0]['id'],$plan);
                $ids[]=(int)$plan['existing'][0]['id'];continue;
            }
            if(isset($plan['invoice_targets']))\App\BillingInvoiceTargets::assertSelection($db,(int)$contract['id'],$plan['invoice_targets'],['kind'=>'document','id'=>(int)$document['id']]);
            $id=(int)$db->query('SELECT GREATEST(COALESCE(MAX(id),0),900000)+1 FROM invoices')->fetchColumn();
            $issued=NativeOperatingWriter::today($db);
            $origin=$plan['document_id']?'native-c-initial':'native-c-monthly';
            $creation=NativeOperatingWriter::begin($db,'invoice',$id,$origin,'addendum.invoice.create',$issued,$plan['coverage_key']);
            $source=isset($plan['invoice_targets'])?['native_invoice_targets'=>\App\BillingInvoiceTargets::producerMarker($plan['invoice_targets'],'addendum','document',(int)$document['id'])]:new \stdClass();
            $db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,due,status,initial_invoice,coverage_key,document_id,planning_document_id,source_json) VALUES(?,?,?,?,?,?,0,?,?,'active',?,?,?,?, ?)")
                ->execute([$id,'Д-'.$id,$contract['id'],$plan['kind'],$plan['period'],$plan['amount'],$issued,
                    $plan['document_id']?date('Y-m-d',strtotime('+3 days')):$plan['period'].'-01',
                    $plan['document_id']?1:0,$plan['coverage_key'],$plan['document_id'],$plan['planning_document_id'],Importer::json($source)]);
            foreach ($plan['objects'] as $object) $db->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([$id,$object]);
            foreach ($plan['coverage'] as $segment) $db->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)')
                ->execute([$id,$segment['object_id'],$segment['start_date'],$segment['end_date']]);
            Billing::snapshotInvoice($db,$id);
            NativeInvoiceScope::record($db,$id,$origin,null,$plan['invoice_targets']??null);
            if(isset($plan['invoice_targets']))\App\BillingInvoiceTargets::record($db,$id,$plan['invoice_targets'],InitialBilling::basis($plan,'addendum','document',(int)$document['id']));
            NativeOperatingWriter::finish($db,'invoice',$id,$origin,$creation);
            $ids[]=$id;
        }
        return $ids;
    }
}
