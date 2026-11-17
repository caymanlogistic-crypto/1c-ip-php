<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{BillingInvoiceTargets,BillingPartAdmission,BillingParts};
use DateTimeImmutable;
use RuntimeException;

/** The explicit typed monthly branch. Legacy physical planning remains in MonthlyBilling. */
final class BillableMonthlyPlan
{
    public static function calculate(array $contract,array $terms,array $coverage,string $period): array
    {
        $first=Service::date($period.'-01');$last=(new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $start=max($first,Service::date($contract['start_date']),$contract['servicing_effective_date'] ?? $first);
        $end=min($last,Service::date($contract['end_date']));
        $ordered=$terms;
        usort($ordered,static function(array $a,array $b):int{
            $date=strcmp($b['effective_date'] ?? '',$a['effective_date'] ?? '');
            if($date!==0)return $date;
            foreach([$a,$b] as $term)if(!preg_match('/^contract-(?:part-)?term:([1-9][0-9]*)$/D',$term['source_term_key'] ?? ''))throw new RuntimeException('Не подтверждён порядок условий начисления.',409);
            preg_match('/([0-9]+)$/D',$a['source_term_key'],$left);preg_match('/([0-9]+)$/D',$b['source_term_key'],$right);
            return bccomp($right[1],$left[1],0);
        });
        $targets=[];
        foreach($ordered as $term){
            if(!in_array($term['target_kind'] ?? '',['physical-object','billing-part'],true)||!is_string($term['target_key'] ?? null))throw new RuntimeException('Не подтверждён предмет ежемесячного начисления.',409);
            $targets[$term['target_key']][]=$term;
        }
        $segments=[];
        if($start<=$end)foreach($targets as $target=>$pool){
            $segment=null;
            for($day=new DateTimeImmutable($start);$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')){
                $date=$day->format('Y-m-d');$chosen=null;
                foreach($pool as $term){
                    if(!empty($term['effective_date'])&&$term['effective_date']>$date
                        ||!empty($term['superseded_on'])&&$term['superseded_on']<$date
                        ||$term['start_date']!==null&&$term['start_date']>$date
                        ||$term['end_date']!==null&&$term['end_date']<$date)continue;
                    if($term['start_date']===null||$term['end_date']===null||$term['amount']===null)throw new RuntimeException('Не подтверждены срок или цена предмета начисления.',409);
                    if(bccomp($term['amount'],'0',12)<=0)throw new RuntimeException('Цена предмета нового начисления должна быть положительной.',409);
                    if($term['target_kind']==='billing-part')BillingParts::requireReady($term);
                    $chosen=$term;break;
                }
                if($chosen===null){if($segment!==null){$segments[]=$segment;$segment=null;}continue;}
                if($segment!==null&&$segment['source_term_key']===$chosen['source_term_key'])$segment['end_date']=$date;
                else{
                    if($segment!==null)$segments[]=$segment;
                    $segment=['source_term_key'=>$chosen['source_term_key'],'target_key'=>$target,'start_date'=>$date,'end_date'=>$date];
                }
            }
            if($segment!==null)$segments[]=$segment;
        }
        $missing=[];
        foreach($segments as $segment){
            $covered=false;
            foreach($coverage as $prior){
                if(($prior['target_key'] ?? null)!==$segment['target_key'])continue;
                if($prior['start_date']<=$segment['start_date']&&$prior['end_date']>=$segment['end_date']){$covered=true;break;}
                if($prior['start_date']<=$segment['end_date']&&$prior['end_date']>=$segment['start_date']){
                    throw new RuntimeException('Период начисления уже частично покрыт счётом. Требуется сверка покрытия.',409);
                }
            }
            if(!$covered)$missing[]=$segment;
        }
        $keys=array_column($missing,'source_term_key');
        $selected=array_values(array_filter($terms,static fn($term)=>in_array($term['source_term_key'],$keys,true)));
        $intervals=array_map(static fn($row)=>array_intersect_key($row,array_flip(['source_term_key','start_date','end_date'])),$missing);
        $evidence=hash('sha256',Importer::canonicalJson(['source_sha256'=>BillingPartAdmission::NATIVE_SOURCE,'producer'=>'monthly',
            'contract_id'=>(int)$contract['id'],'period'=>$period,'selected_intervals'=>$intervals]));
        $targetsPlan=BillingInvoiceTargets::compile($selected,$intervals,$evidence,$selected===[]);
        $amount=InvoiceCalculation::expected($missing,$ordered);
        if($amount===null)throw new RuntimeException('Цена ежемесячного начисления не подтверждена.',409);
        return ['key'=>'billable-monthly:'.$contract['id'].':'.$period.':'.$targetsPlan['target_set_sha256'],
            'period'=>$period,'amount'=>$amount,'objects'=>$targetsPlan['physical_objects'],'coverage'=>$targetsPlan['physical_coverage'],
            'invoice_targets'=>$targetsPlan];
    }
}
