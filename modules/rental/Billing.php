<?php
declare(strict_types=1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
final class Billing
{
    public static function snapshotInvoice(PDO $p,int $id):void{$q=$p->prepare('SELECT pa.* FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties pa ON pa.id=c.party_id WHERE i.id=?');$q->execute([$id]);$payer=$q->fetch();$payee=$p->query('SELECT * FROM organization WHERE id=1')->fetch();unset($payer['source_json'],$payee['source_json']);$q=$p->prepare('SELECT bank,account,bik,correspondent_account FROM party_bank_accounts WHERE party_id=? AND operational=1 LIMIT 1');$q->execute([$payer['id']]);$payer['bankDetails']=$q->fetch()?:null;$payee['bankDetails']=$p->query('SELECT bank,account,bik,correspondent_account FROM organization_bank_accounts WHERE organization_id=1 AND is_primary=1 LIMIT 1')->fetch()?:null;$p->prepare('UPDATE invoices SET vat=?,payer_snapshot=?,payee_snapshot=? WHERE id=?')->execute([$payee['vat'],Importer::json($payer),Importer::json($payee),$id]);}
    public static function month(PDO $p,int $contractId,string $period):?string
    {
        $first=Service::date($period.'-01');$last=(new \DateTimeImmutable($first))->modify('last day of this month');$q=$p->prepare('SELECT * FROM contract_terms WHERE contract_id=? ORDER BY effective_date DESC,id DESC');$q->execute([$contractId]);$terms=$q->fetchAll();if(!$terms){$c=Service::get($p,'contracts',$contractId);if($c['amount']===null)return null;$start=max($first,$c['start_date']??$first);$end=min($last->format('Y-m-d'),$c['end_date']??$last->format('Y-m-d'));if($end<$start)return '0.00';$count=(int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days+1;return self::round(bcdiv(bcmul($c['amount'],(string)$count,12),$last->format('t'),12));}
        $sum='0';for($day=new \DateTimeImmutable($first);$day<=$last;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$seen=[];foreach($terms as $term){$oid=$term['object_id'];if(isset($seen[$oid])||($term['superseded_on']!==null&&$term['superseded_on']<$date)||($term['effective_date']!==null&&$term['effective_date']>$date))continue;$seen[$oid]=true;if(!$term['start_date']||!$term['end_date']||$term['start_date']>$date||$term['end_date']<$date)continue;if($term['amount']===null)return null;$sum=bcadd($sum,bcdiv($term['amount'],$day->format('t'),12),12);}}return self::round($sum);
    }
    public static function round(string $v):string{return bcdiv(bcadd($v,bccomp($v,'0',12)>=0?'0.005':'-0.005',12),'1',2);}
    public static function reviewAfterTerms(PDO $p,int $id):void
    {
        $contract=Service::get($p,'contracts',$id);
        try {
            InvoiceCalculation::review($p,$contract);
        } catch (RuntimeException $error) {
            if (!in_array($error->getMessage(),['IMPORTED_COVERAGE_REVIEW_REQUIRED','INVOICE_SCOPE_UNKNOWN'],true)) throw $error;
            \App\Audit::record('contract.calculation-review-unavailable','contract',$id,null,
                ['state'=>'scope_unavailable','reason'=>$error->getMessage()]);
        }
    }
}
