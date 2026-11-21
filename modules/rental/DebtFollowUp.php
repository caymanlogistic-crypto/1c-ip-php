<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,Audit};
use PDO;
use RuntimeException;

final class DebtFollowUp
{
    public const SAVE = 'contract.payment-followup';
    public const FILE = 'payment-guarantee';
    private const NOTES = 'rental_payment_followup';
    private const FILES = 'rental_payment_guarantee';

    public static function canView(array $invoice): bool
    {
        if (!in_array(Auth::user()['role'] ?? '',['manager','accountant','admin'],true)) return false;
        try {
            Access::contract((int)$invoice['contract_id']);
            return true;
        } catch (RuntimeException $error) {
            if (!in_array($error->getCode(),[403,404],true)) throw $error;
            return false;
        }
    }

    public static function requireWritable(PDO $db,int $invoiceId,int $contractId): array
    {
        Access::finance();
        Auth::requirePermission('rental.write');
        Service::roles(['manager']);
        Access::contract($contractId,2);
        $invoice = Service::get($db,'invoices',$invoiceId);
        if ((int)$invoice['contract_id'] !== $contractId) throw new RuntimeException('Счёт не относится к этому договору.',404);
        $invoice = PaymentReview::attach($db,$invoice);
        if (!self::eligible($invoice)) throw new RuntimeException('Нужна подтверждённая задолженность и рабочий день для разговора.',409);
        return $invoice;
    }

    public static function eligible(array $invoice): bool
    {
        $source = Importer::decodeExact($invoice['source_json']);
        return InvoiceFacts::overdue($invoice) && empty($invoice['manual_review'])
            && empty($source['issueError']) && empty($source['mismatch']);
    }

    public static function write(PDO $db,array $actor,array $input): array
    {
        $invoiceId = (int)($input['invoice_id'] ?? 0);
        $contract = Service::get($db,'contracts',(int)($input['id'] ?? 0));
        Service::version($contract,$input);
        $invoice = self::requireWritable($db,$invoiceId,(int)$contract['id']);
        if ((int)($input['invoice_version'] ?? 0) !== (int)$invoice['version'])
            throw new RuntimeException('Счёт изменился. Обновите карточку.',409);
        $conversation = DirectoryDetails::text($input,'conversation',1200);
        $cause = DirectoryDetails::text($input,'cause',600);
        $file = DirectoryDetails::text($input,'file_id',32);
        if ($conversation === '' || $cause === '') throw new RuntimeException('Укажите результат разговора и причину.',422);
        if ($file !== '') {
            $files = self::files($db,$invoiceId);
            if (!isset($files[$file])) throw new RuntimeException('Письмо не относится к этому счёту.',422);
            Evidence::integrity($files[$file]);
        }
        $fact = ['invoice_id'=>$invoiceId,'contract_id'=>(int)$contract['id'],'conversation'=>$conversation,
            'cause'=>$cause,'file_id'=>$file ?: null,'recorded_at'=>BankPaymentRefresh::now($db)->format(DATE_ATOM),
            'actor'=>$actor['name'],'actor_id'=>(int)$actor['id'],'cutoff'=>$invoice['_payment_control']['cutoff'],
            'due'=>$invoice['due'],'remaining'=>InvoiceFacts::remaining($invoice)];
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::NOTES,hash('sha256',$input['command_key']),Importer::json($fact)]);
        Audit::record(self::SAVE,'invoice',$invoiceId,null,$fact);
        Service::event($db,$actor,self::SAVE,'Зафиксирован разговор об оплате счёта '.$invoice['number'].'.',(int)$contract['id']);
        return ['id'=>(int)$contract['id'],'message'=>'Разговор сохранён.'];
    }

    public static function notes(PDO $db,int $invoiceId): array
    {
        $q = $db->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1
            AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.invoice_id'))=? ORDER BY id DESC");
        $q->execute([self::NOTES,(string)$invoiceId]);
        return array_map(Importer::decodeExact(...),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function files(PDO $db,int $invoiceId): array
    {
        $q = $db->prepare("SELECT f.* FROM rental_files f JOIN rental_facts b ON b.source_key=f.id AND b.namespace=? AND b.operative=1
            JOIN invoices i ON i.id=? AND f.entity_type='contract' AND f.entity_id=i.contract_id
            WHERE f.kind=? AND JSON_UNQUOTE(JSON_EXTRACT(b.fact_json,'$.invoice_id'))=? ORDER BY f.upload_order DESC");
        $q->execute([self::FILES,$invoiceId,self::FILE,(string)$invoiceId]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function pin(PDO $db,string $file,int $invoiceId): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::FILES,$file,Importer::json(['invoice_id'=>$invoiceId])]);
    }
}
