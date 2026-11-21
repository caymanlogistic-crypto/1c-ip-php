<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{BillableTerms,BillingInvoiceTargets,BillingPartTerms,BillingPartAdmission};
use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Preserves historical initial coverage keys and records the approved invoice plan. */
final class InitialBilling
{
    public static function calculate(array $contract): array
    {
        if ($contract['amount']===null || bccomp($contract['amount'],'0',2)<=0)throw new RuntimeException('Месячная аренда должна быть больше нуля.');
        $start=new DateTimeImmutable(Service::date($contract['start_date']));
        $end=new DateTimeImmutable(Service::date($contract['end_date']));
        if($end<$start)throw new RuntimeException('Окончание договора предшествует началу.',409);
        $last=$start->modify('last day of this month');$stop=min($end,$last);
        $first=bcdiv(bcadd(bcdiv(bcmul($contract['amount'],(string)((int)$start->diff($stop)->days+1),8),$start->format('t'),8),'0.005',8),'1',2);
        $rows=[['Обеспечительный платёж','Обеспечение',$contract['amount'],1],['Первый период',$start->format('Y-m'),$first,1]];
        if((int)$start->format('d')>=25 && $end>$last)$rows[]=['Аренда',$start->modify('first day of next month')->format('Y-m'),$contract['amount'],0];
        return array_map(static fn($row)=>['kind'=>$row[0],'period'=>$row[1],'amount'=>$row[2],'initial'=>$row[3],
            'coverage_key'=>'initial:'.$contract['id'].':'.$row[0].':'.$row[1]],$rows);
    }

    public static function planned(PDO $db,array $contract,?array $explicit=null): array
    {
        $plans=$explicit ?? self::calculate($contract);
        $query=$db->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');$query->execute([$contract['id']]);
        $objects=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
        $state=BillingPartTerms::state($db,'contract',(int)$contract['id']);
        if($state!==null && $state['state']!=='KNOWN')throw new RuntimeException('Не подтверждены условия частей договора.',409);
        $terms=$state!==null || $explicit!==null ? BillableTerms::forContract($db,(int)$contract['id']) : null;
        foreach($plans as &$plan){
            $security=$plan['kind']==='Обеспечительный платёж';
            $plan+=['objects'=>$objects,'coverage'=>[],
                'coverage_key'=>'initial:'.$contract['id'].':'.$plan['kind'].':'.$plan['period']];
            if($terms!==null){
                $from=$plan['from'] ?? ($security?$contract['start_date']:max($contract['start_date'],$plan['period'].'-01'));
                $to=$plan['to'] ?? ($security?$contract['end_date']:min($contract['end_date'],(new DateTimeImmutable($plan['period'].'-01'))->modify('last day of this month')->format('Y-m-d')));
                $targets=self::targets($terms,$from,$to,$security,$plan['coverage_key']);
                if($state!==null)$plan['invoice_targets']=$targets;
                $plan['objects']=$targets['physical_objects'];
                $plan['coverage']=$targets['physical_coverage'];
            }
        }
        unset($plan);return $plans;
    }

    /** Select dated coverage without repricing the source financial descriptor. */
    public static function targets(array $terms,string $from,string $to,bool $security,string $identity): array
    {
        $evidence=hash('sha256',Importer::canonicalJson(['source_sha256'=>BillingPartAdmission::NATIVE_SOURCE,'producer_identity'=>$identity,'from'=>$from,'to'=>$to]));
        if($security)return BillingInvoiceTargets::compile($terms,[],$evidence,$terms===[],'NOT_RENTAL_SECURITY');
        Service::date($from);Service::date($to);
        if($from>$to)throw new RuntimeException('Не подтверждён период первоначального начисления.',409);
        if((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>37000)throw new RuntimeException('Период плана превышает допустимый диапазон.',409);
        $ordered=self::orderedTerms($terms);
        $pools=[];foreach($ordered as $term)$pools[$term['target_key']][]=$term;
        $intervals=[];
        foreach($pools as $pool){
            $segment=null;
            for($day=new DateTimeImmutable($from);$day->format('Y-m-d')<=$to;$day=$day->modify('+1 day')){
                $date=$day->format('Y-m-d');$chosen=null;
                foreach($pool as $term){
                    if(($term['effective_date']??null)!==null && $term['effective_date']>$date
                        ||($term['superseded_on']??null)!==null && $term['superseded_on']<$date)continue;
                    if($term['start_date']===null||$term['end_date']===null)throw new RuntimeException('Не подтверждены сроки предмета начисления.',409);
                    if($term['start_date']<=$date&&$term['end_date']>=$date){$chosen=$term;break;}
                }
                if($chosen===null){if($segment!==null){$intervals[]=$segment;$segment=null;}continue;}
                if($segment!==null&&$segment['source_term_key']===$chosen['source_term_key'])$segment['end_date']=$date;
                else{if($segment!==null)$intervals[]=$segment;$segment=['source_term_key'=>$chosen['source_term_key'],'start_date'=>$date,'end_date'=>$date];}
            }
            if($segment!==null)$intervals[]=$segment;
        }
        if(!$intervals)throw new RuntimeException('Не подтверждены предметы первоначального начисления.',409);
        $keys=array_column($intervals,'source_term_key');
        $selected=array_values(array_filter($terms,static fn($term)=>in_array($term['source_term_key'],$keys,true)));
        return BillingInvoiceTargets::compile($selected,$intervals,$evidence);
    }

    public static function orderedTerms(array $terms): array
    {
        usort($terms,static function($a,$b){
            $date=strcmp($b['effective_date']??'',$a['effective_date']??'');
            if($date)return $date;
            preg_match('/([0-9]+)$/D',$a['source_term_key'],$left);preg_match('/([0-9]+)$/D',$b['source_term_key'],$right);
            return bccomp($right[1]??'0',$left[1]??'0',0);
        });
        return $terms;
    }

    public static function issue(PDO $db,array $contract): array
    {
        return self::write($db,$contract,self::planned($db,$contract),NativeOperatingWriter::today($db));
    }

    public static function write(PDO $db,array $contract,array $plans,string $issued,?array $proof=null): array
    {
        if($proof!==null && !$db->inTransaction())throw new RuntimeException('Выпуск первых счетов требует транзакции.',409);
        $ids=[];
        foreach($plans as $plan){
            $typed=isset($plan['invoice_targets']);
            if($typed&&!$db->inTransaction())throw new RuntimeException('Явный план счёта требует общей транзакции.',409);
            $query=$db->prepare('SELECT id FROM invoices WHERE coverage_key=?');$query->execute([$plan['coverage_key']]);
            if($id=$query->fetchColumn()){
                if($typed)self::assertExisting($db,(int)$id,$plan);
                $ids[]=(int)$id;continue;
            }
            if($typed)BillingInvoiceTargets::assertSelection($db,(int)$contract['id'],$plan['invoice_targets']);
            $id=\App\BusinessIds::next($db,'invoices',900000);
            $creation=NativeOperatingWriter::begin($db,'invoice',$id,'native-c-initial','evidence.initial-invoice',$issued,$plan['coverage_key']);
            $source=[];
            if($typed)$source['native_invoice_targets']=BillingInvoiceTargets::producerMarker($plan['invoice_targets'],'initial','contract',(int)$contract['id']);
            if($proof!==null)$source['separate_initial_invoice']=$proof+['from'=>$plan['from'],'to'=>$plan['to'],'due_basis'=>$plan['due_basis'],'due_part'=>$plan['due_part']];
            $due=$proof!==null ? $plan['due'] : InvoiceDueDate::automatic($issued,$plan['period'],(bool)$plan['initial']);
            $db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,due,status,initial_invoice,coverage_key,source_json) VALUES(?,?,?,?,?,?,0,?,?,'active',?,?,?)")
                ->execute([$id,'Д-'.$id,$contract['id'],$plan['kind'],$plan['period'],$plan['amount'],$issued,$due,$plan['initial'],$plan['coverage_key'],Importer::json($source ?: new \stdClass())]);
            // Preserve the physical fallback at creation, before a later addendum changes it.
            if(!$typed && $proof===null)$plan['coverage']=MonthlyBilling::sourceCoverage($db,$contract,Service::get($db,'invoices',$id));
            self::links($db,$id,$plan);
            Billing::snapshotInvoice($db,$id);
            NativeInvoiceScope::record($db,$id,'native-c-initial',null,$plan['invoice_targets']??null);
            if($typed)BillingInvoiceTargets::record($db,$id,$plan['invoice_targets'],self::basis($plan,'initial','contract',(int)$contract['id']));
            NativeOperatingWriter::finish($db,'invoice',$id,'native-c-initial',$creation);$ids[]=$id;
        }
        return $ids;
    }

    public static function links(PDO $db,int $id,array $plan): void
    {
        foreach(array_unique($plan['objects']) as $object)$db->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([$id,$object]);
        foreach($plan['coverage'] as $segment)$db->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)')->execute([$id,$segment['object_id'],$segment['start_date'],$segment['end_date']]);
    }

    public static function assertExisting(PDO $db,int $id,array $plan): void
    {
        $receipt=BillingInvoiceTargets::read($db,$id);
        if($receipt===null)throw new RuntimeException('Не подтверждён сохранённый план счёта.',409);
        BillingInvoiceTargets::assertCurrent($db,$id,$receipt,$plan['invoice_targets']);
        $invoice=NativeInvoiceScope::assertSelected($db,$id,$receipt['plan']);
        if($invoice['status']==='revoked'||$invoice['kind']!==$plan['kind']||$invoice['period']!==$plan['period']
            ||$invoice['amount']===null||bccomp($invoice['amount'],$plan['amount'],2)!==0)throw new RuntimeException('Ранее выставленный счёт отличается от плана.',409);
    }

    public static function basis(array $plan,string $producer,string $ownerKind,int $ownerId): array
    {
        return ['source_sha256'=>BillingPartAdmission::NATIVE_SOURCE,'source_version'=>'workflow-v2:initial-addendum',
            'producer'=>$producer,'source_identity'=>$producer.':'.hash('sha256',$plan['coverage_key']),
            'invoice_version'=>1,'coverage_key'=>$plan['coverage_key'],'term_owner_kind'=>$ownerKind,'term_owner_id'=>$ownerId];
    }
}
