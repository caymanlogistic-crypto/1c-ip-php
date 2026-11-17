<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

/** Historical coverage evidence; callers supply independently part-relevant invoice IDs. */
final class BillingPartCoverage
{
    public static function read(PDO $pdo,array $relevantInvoiceIds,array $selectedPartIds,string $from,string $to): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        self::selection($selectedPartIds,$from,$to);
        if(!array_is_list($relevantInvoiceIds)||count($relevantInvoiceIds)>2000)self::deny();
        $scopes=[];$seen=[];
        foreach($relevantInvoiceIds as $id){
            if(!is_int($id)||$id<1||isset($seen[$id]))self::deny();$seen[$id]=true;
            $scopes[]=['invoice_id'=>$id,'scope'=>BillingPartScope::read($pdo,$id)];
        }
        return self::evaluate($scopes,$selectedPartIds,$from,$to);
    }

    public static function evaluate(array $invoiceScopes,array $selectedPartIds,string $from,string $to): array
    {
        self::selection($selectedPartIds,$from,$to);
        if(!array_is_list($invoiceScopes)||count($invoiceScopes)>2000)self::deny();
        $coverage=[];$gaps=[];$holds=[];$seen=[];
        foreach($invoiceScopes as $entry){
            $id=$entry['invoice_id']??null;$scope=$entry['scope']??null;
            if(!is_int($id)||$id<1||isset($seen[$id])||($scope!==null&&!is_array($scope)))self::deny();$seen[$id]=true;
            if($selectedPartIds===[])continue;
            if($scope===null||!in_array($scope['availability']??null,['KNOWN','PARTIAL','UNKNOWN'],true)
                ||!is_array($scope['coverage']??null)){
                $gaps[]=['invoice_id'=>$id,'reason'=>'PART_COVERAGE_NOT_PROVEN'];
            }else{
                if($scope['availability']!=='KNOWN')$gaps[]=['invoice_id'=>$id,'reason'=>'PART_COVERAGE_INCOMPLETE'];
                foreach($scope['coverage'] as $row){
                    if(!is_array($row)||!in_array($row['part_id']??null,$selectedPartIds,true))continue;
                    $start=$row['start_date']??null;$end=$row['end_date']??null;
                    if(($row['scope_state']??null)!=='KNOWN'||!self::isDate($start)||!self::isDate($end)||$start>$end){
                        $gaps[]=['invoice_id'=>$id,'part_id'=>$row['part_id'],'reason'=>'PART_INTERVAL_NOT_PROVEN'];continue;
                    }
                    if($end<$from||$start>$to)continue;
                    // Keep original dates/versions: current proof is not historical coverage authority.
                    $coverage[]=['invoice_id'=>$id,'coverage'=>$row,'scope_evidence_sha256'=>$scope['evidence_sha256']??null,'scope_revision'=>$scope['revision']??null];
                }
            }
            if(($scope['runtime_hold']??null)!==null)$holds[]=['invoice_id'=>$id,'reason'=>$scope['runtime_hold']];
        }
        return ['coverage'=>$coverage,'gaps'=>$gaps,'holds'=>$holds,'invoice_scopes'=>$invoiceScopes];
    }

    private static function selection(array $ids,string $from,string $to): void
    {
        if(!array_is_list($ids)||count($ids)>128||count(array_unique($ids,SORT_REGULAR))!==count($ids)||!self::isDate($from)||!self::isDate($to)||$from>$to)self::deny();
        foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id))self::deny();
    }

    private static function isDate(mixed $date): bool
    {
        $parsed=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        return $parsed!==false&&$parsed->format('Y-m-d')===$date;
    }

    private static function deny(): never
    {
        throw new RuntimeException('Explicit part coverage selection is invalid.',409);
    }
}
