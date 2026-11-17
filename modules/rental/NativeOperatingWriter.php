<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,NativeOperatingMembership,OperatingMembership};
use PDO;

/** Provenance adapter for the existing authorized writers, not a billing policy. */
final class NativeOperatingWriter
{
    public static function today(PDO $db): string
    {
        // Preserve the existing CURRENT_DATE axis, freezing it before the ticket.
        return (string)$db->query('SELECT CURRENT_DATE')->fetchColumn();
    }

    public static function begin(PDO $db,string $kind,int $id,string $origin,string $writer,string $date,string $key,?int $actor=null): array
    {
        if (OperatingMembership::selected()===null) return ['ticket'=>null,'basis'=>[]];
        $basis=self::basis($kind,$writer,$key,$actor)+['expected_entity_version'=>1,'expected_event_date'=>$date];
        return ['ticket'=>NativeOperatingMembership::beginCreation($db,$kind,$id,$origin,$basis),'basis'=>$basis];
    }

    public static function finish(PDO $db,string $kind,int $id,string $origin,array $creation): void
    {
        if ($kind==='invoice') NativeOperatingMembership::recordInvoice($db,$id,$origin,$creation['basis'],$creation['ticket']);
        else NativeOperatingMembership::recordPayment($db,$id,$creation['basis'],$creation['ticket']);
    }

    public static function changeDate(PDO $db,string $kind,array $before,string $date,array $changes,string $key,int $actor,callable $legacyWrite): void
    {
        if (OperatingMembership::selected()===null) {$legacyWrite();return;}
        $membership=OperatingMembership::read($db,$kind,(int)$before['id']);
        NativeOperatingMembership::changeNativeDate($db,$kind,(int)$before['id'],(int)$before['version'],
            (int)($membership['revision'] ?? 0),$date,$changes,
            self::basis($kind,$kind==='invoice'?'finance.invoice.edit':'finance.payment.edit',$key,$actor),$legacyWrite);
    }

    public static function historicalPaymentAllowed(PDO $db,array $invoice,bool $newCash=true): bool
    {
        if (OperatingMembership::selected()===null || $invoice['status']!=='active'
            || $invoice['amount']===null || $invoice['paid']===null
            || bccomp($invoice['amount'],$invoice['paid'],2)<($newCash?1:0)) return false;
        $membership=OperatingMembership::read($db,'invoice',(int)$invoice['id']);
        return ($membership['runtime_hold'] ?? null)===null
            && in_array($membership['effective_membership'] ?? '',['OPERATING','CONTEXT'],true);
    }

    public static function requirePayment(PDO $db,int $id): void
    {
        if (OperatingMembership::selected()===null) return;
        OperatingMembership::requireNativePosting(OperatingMembership::read($db,'payment',$id));
    }

    public static function requireObligation(PDO $db,array $invoice,bool $newCash=true): void
    {
        if (OperatingMembership::selected()===null) return;
        if (!self::historicalPaymentAllowed($db,$invoice,$newCash)) {
            throw new \RuntimeException('Обязательство не подтверждено для новой оплаты.',409);
        }
    }

    public static function historicalCorrectionAllowed(PDO $db,array $invoice,int $partyId,int $paymentId): bool
    {
        if ($paymentId<1 || !self::historicalPaymentAllowed($db,$invoice,false)) return false;
        $payment=Service::get($db,'payments',$paymentId);
        self::requirePayment($db,$paymentId);
        if ($payment['party_id']===null || (int)$payment['party_id']!==$partyId) return false;
        $query=$db->prepare('SELECT invoice_id FROM payment_allocations WHERE payment_id=? ORDER BY invoice_id FOR UPDATE');
        $query->execute([$paymentId]);$allocated=$query->fetchAll(PDO::FETCH_COLUMN);
        return count($allocated)===1 && (int)$allocated[0]===(int)$invoice['id'];
    }

    private static function basis(string $kind,string $writer,string $key,?int $actor): array
    {
        if ($actor===null) {$current=Auth::user();$actor=$current?(int)$current['id']:null;}
        return ['writer'=>$writer,'operation_key'=>'rental-native:'.hash('sha256',$writer.':'.$key),
            'actor_id'=>$actor,
            'source_rule_sha256'=>$kind==='payment'||str_ends_with($writer,'.edit')
                ?NativeOperatingMembership::MANUAL_SOURCE:NativeOperatingMembership::INVOICE_SOURCE];
    }
}
