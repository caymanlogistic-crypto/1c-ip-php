<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;
use App\OperatingInvoiceContext;
use App\BillingInvoiceTargets;
use Modules\Integrations\{Config,TemplateCatalog};

/** Creates immutable invoice mail intents; transport is exclusively the outbox worker. */
final class InvoiceDelivery
{
    public static function latest(PDO $db,int $id): ?array
    {
        $query=$db->prepare(
            "SELECT * FROM rental_delivery_intents WHERE entity_type='invoice' AND entity_id=? AND operation='invoice' "
            ."ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_order')) AS UNSIGNED) DESC LIMIT 1"
        );
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public static function presentation(PDO $db,int $id): array
    {
        $intent=self::latest($db,$id);
        $query=$db->prepare("SELECT after_json FROM audit_log WHERE entity_type='invoice' AND entity_id=? AND action='invoice.handed' ORDER BY id DESC LIMIT 1");
        $query->execute([(string)$id]);
        $json=$query->fetchColumn();
        $handed=$json ? json_decode($json,true) : null;
        $order=$intent ? (int)(json_decode($intent['payload_json'],true)['delivery_order'] ?? 0) : 0;
        if ($handed && (int)($handed['delivery_order'] ?? 0)!==$order) $handed=null;
        return ['intent'=>$intent,'handed'=>$handed];
    }

    private static function context(PDO $db,array $invoice): array
    {
        $contract=Service::get($db,'contracts',(int)$invoice['contract_id']);
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        return [$contract,$party,RecipientContext::resolve($db,$party)];
    }

    private static function scope(PDO $db,int $invoice): array
    {
        $query=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
        $query->execute([$invoice]);
        $objects=$query->fetchAll(PDO::FETCH_COLUMN);
        $query=$db->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date,end_date,id');
        $query->execute([$invoice]);
        // Empty scope remains unknown; imported coverage is never reconstructed here.
        $scope=['object_ids'=>$objects,'coverage'=>$query->fetchAll()];
        $admitted=\App\InvoiceScope::read($db,$invoice);
        // Keep raw rows bound as evidence, but admitted UNKNOWN never means a proved empty scope.
        if ($admitted!==null) {
            $scope['admission']=$admitted;
            $query=$db->prepare('SELECT co.object_id,'.\App\ContractOccupancy::selectColumns('co')
                .' FROM contract_objects co WHERE co.contract_id=(SELECT contract_id FROM invoices WHERE id=?) ORDER BY co.object_id');
            $query->execute([$invoice]);
            // Meaning is a fence, never a filter of the billable object list.
            $scope['contract_relations']=$query->fetchAll();
        }
        $operating=OperatingInvoiceContext::snapshot($db,$invoice);
        if ($operating!==null) $scope['operating_membership']=$operating;
        $targets=BillingInvoiceTargets::snapshot($db,$invoice);
        if ($targets!==null) $scope['billing_targets']=$targets;
        return $scope;
    }

    /** Original payload stays whole; only current owner workflow version is separate from term proof. */
    private static function comparisonScope(mixed $scope): mixed
    {
        if(is_array($scope)&&array_key_exists('billing_targets',$scope))$scope['billing_targets']=BillingInvoiceTargets::semanticSnapshot($scope['billing_targets']);
        return $scope;
    }

    private static function record(PDO $db,array $actor,array $invoice,array $payload,string $status,array $context=[]): array
    {
        $baseline=hash('sha256',Importer::canonicalJson([$invoice,$context,$payload]));
        $id=bin2hex(random_bytes(16));
        $request='rental-invoice:'.$invoice['id'].':'.$invoice['version'].':'.$payload['delivery_order'].':'.$baseline;
        $db->prepare(
            "INSERT INTO rental_delivery_intents(id,request_key,provider,operation,entity_type,entity_id,entity_version,baseline_hash,actor_id,payload_json,status) "
            ."VALUES(?,?,'email','invoice','invoice',?,?,?,?,?,?)"
        )->execute([$id,$request,$invoice['id'],$invoice['version'],$baseline,$actor['id'],Importer::json($payload),$status]);
        return ['id'=>$id,'status'=>$status]+($status==='blocked' ? ['send_error'=>$payload['preflight']['message']] : []);
    }

    private static function blocked(PDO $db,array $actor,array $invoice,int $order,string $state,string $message,array $missing=[]): array
    {
        return self::record($db,$actor,$invoice,[
            'policy'=>'rental-approved-invoice-v1','delivery_order'=>$order,
            'invoice_ids'=>[$invoice['id']],'invoice_scope'=>self::scope($db,(int)$invoice['id']),
            'preflight'=>['state'=>$state,'missing'=>$missing,'message'=>$message],
        ],'blocked');
    }

    private static function attachment(PDO $db,array $invoice): array
    {
        $query=$db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND kind='invoice-file'");
        $query->execute([$invoice['id']]);
        if ($query->fetchColumn()) return Evidence::latest($db,'invoice',(int)$invoice['id'],'invoice-file');
        $query=$db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND kind='pdf' AND version=?");
        $query->execute([$invoice['id'],$invoice['version']]);
        if ($query->fetchColumn()) return Evidence::latest($db,'invoice',(int)$invoice['id'],'pdf',(int)$invoice['version']);
        $bytes=Documents::renderPdf(InvoiceDocument::html($db,$invoice));
        $id=bin2hex(random_bytes(16));
        $directory=APP_ROOT.'/.local/documents';
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Не удалось сохранить файл счёта.');
        $path=$directory.'/'.$id.'.pdf';
        try {
            if (file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Не удалось сохранить файл счёта.');
            $db->prepare(
                "INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,'invoice',?,?,'pdf',?,?,?,'application/pdf')"
            )->execute([$id,$invoice['id'],$invoice['version'],'Счёт '.$invoice['number'].'.pdf',basename($path),hash('sha256',$bytes)]);
            FileOrder::assign($db,$id);
        } catch (\Throwable $error) {
            if (is_file($path)) unlink($path);
            throw $error;
        }
        $query=$db->prepare('SELECT * FROM rental_files WHERE id=?');
        $query->execute([$id]);
        return $query->fetch();
    }

    /** Packet-level authority: an invoice is an immutable child of the approved B record. */
    public static function packetAttachment(PDO $db,int $invoiceId,string $kind,int $recordId,string $purpose='ordinary-email'): array
    {
        if (!in_array($purpose,['ordinary-email','manual-neutral'],true)) throw new RuntimeException('Неизвестное назначение файла пакета.',403);
        Service::roles(['accountant']);
        \App\Auth::requirePermission('rental.read');
        \App\Auth::requirePermission('finance.write');
        if (!in_array($kind,['contract','document'],true)) throw new RuntimeException('Неизвестный вид пакета.',403);
        \App\PagePolicy::requireCommand($kind==='contract'?'contract.send':'document.send');
        $record=$kind==='contract'?Access::contract($recordId,2):Access::document($recordId,2);
        if ($record['status']!=='approved') throw new RuntimeException('Пакет не подтверждён бухгалтером.',409);
        FileOrder::lock($db);
        $invoice=Service::get($db,'invoices',$invoiceId);
        $contract=$kind==='contract'?$recordId:(int)$record['contract_id'];
        if ((int)$invoice['contract_id']!==$contract || $kind==='document'
            && (int)$invoice['document_id']!==$recordId && (int)$invoice['planning_document_id']!==$recordId
            || $invoice['status']==='revoked') throw new RuntimeException('Счёт не относится к подтверждённому пакету.',403);
        if ($kind==='document' && (int)$invoice['planning_document_id']===$recordId) {
            $packet=NativePacket::current($db,$kind,$recordId,\App\Auth::user(),'prepare');
            if (($packet['state'] ?? '')!=='confirmed' || !in_array($invoiceId,array_map('intval',$packet['invoice_ids'] ?? []),true)) {
                throw new RuntimeException('Счёт планирования не входит в подтверждённый пакет ДС.',403);
            }
        }
        $scope=\App\InvoiceScope::metadata($db,$invoiceId);
        if ($scope!==null && (!empty($scope['runtime_hold']) || $scope['availability']!=='KNOWN' || $scope['classification']==='UNCLASSIFIED')) {
            throw new RuntimeException('Покрытие счёта не подтверждено для пакета.',409);
        }
        if ($purpose==='ordinary-email') {
            try {OperatingInvoiceContext::requireOrdinary(OperatingInvoiceContext::snapshot($db,$invoiceId));}
            catch (RuntimeException $error) {throw new RuntimeException('Дата выставления счёта не подтверждена для отправки в текущем рабочем периоде.',409,$error);}
        }
        BillingInvoiceTargets::requireReady(BillingInvoiceTargets::snapshot($db,$invoiceId));
        return self::attachment($db,$invoice);
    }

    public static function prepare(PDO $db,array $actor,array $invoice): array
    {
        $current=Service::roles(['manager','accountant']);
        \App\PagePolicy::requireCommand('invoice.send');
        \App\Auth::requirePermission($current['role']==='manager' ? 'rental.write' : 'finance.write');
        if ((int)$actor['id']!==(int)$current['id']) throw new RuntimeException('Автор отправки изменился.',403);
        Access::finance(2);
        FileOrder::lock($db);
        $fresh=Service::get($db,'invoices',(int)$invoice['id']);
        Service::version($fresh,['version'=>$invoice['version']]);
        $invoice=$fresh;
        $raw=json_decode($invoice['source_json'],true) ?: [];
        if ($invoice['status']==='revoked' || !empty($raw['issueError'])) throw new RuntimeException('Счёт недоступен для отправки.');
        if ($invoice['amount']===null || bccomp($invoice['amount'],'0',2)<=0) throw new RuntimeException('Сумма счёта не подтверждена.');
        $old=self::latest($db,(int)$invoice['id']);
        if ($old && in_array($old['status'],['claimed','unknown'],true)) return ['id'=>$old['id'],'status'=>$old['status']];
        if ($old && $old['status']==='prepared') {
            try {
                self::validate($db,$old);
                return ['id'=>$old['id'],'status'=>$old['status']];
            } catch (\PDOException $error) {
                throw $error;
            } catch (RuntimeException) {
                $db->prepare("UPDATE rental_delivery_intents SET status='superseded' WHERE id=? AND status='prepared'")->execute([$old['id']]);
            }
        }
        $order=$old ? (int)(json_decode($old['payload_json'],true)['delivery_order'] ?? 0)+1 : 1;
        $metadata=\App\InvoiceScope::metadata($db,(int)$invoice['id']);
        if ($metadata!==null && (!empty($metadata['runtime_hold']) || $metadata['availability']!=='KNOWN'
            || $metadata['classification']==='UNCLASSIFIED')) {
            return self::blocked($db,$actor,$invoice,$order,'scope_unconfirmed',
                'Состав помещений или период счёта требуют проверки перед отправкой.',['invoiceScope']);
        }
        try {OperatingInvoiceContext::requireOrdinary(OperatingInvoiceContext::snapshot($db,(int)$invoice['id']));}
        catch (RuntimeException $error) {
            if ($error->getCode()!==409) throw $error;
            return self::blocked($db,$actor,$invoice,$order,'operating_membership_unconfirmed',
                'Дата выставления счёта не подтверждена для отправки в текущем рабочем периоде.',['operatingMembership']);
        }
        try {BillingInvoiceTargets::requireReady(BillingInvoiceTargets::snapshot($db,(int)$invoice['id']));}
        catch(RuntimeException $error){
            if($error->getCode()!==409)throw $error;
            return self::blocked($db,$actor,$invoice,$order,'billing_targets_unconfirmed',
                'Состав частей или их условия требуют проверки перед отправкой.',['billingTargets']);
        }
        try {
            [$contract,$party,$context]=self::context($db,$invoice);
        } catch (DeliveryContextException $error) {
            return self::blocked($db,$actor,$invoice,$order,'missing_context',$error->getMessage(),$error->missing);
        }
        $catalog=new TemplateCatalog($db,Config::store());
        $event=$catalog->event('email','invoice',[
            'invoiceNumbers'=>$invoice['number'],'number'=>$contract['number'],'party'=>$party['name'],
            'manager'=>$context['manager'],'managerEmail'=>$context['managerEmail'],
        ]);
        if ($event['binding']['recipients_policy']!=='clientAndManager' || $event['binding']['attachment_policy']!=='invoices') {
            throw new RuntimeException('Политика шаблона письма не соответствует отправке счёта.');
        }
        try {
            $file=self::attachment($db,$invoice);
            Evidence::integrity($file);
        } catch (\PDOException $error) {
            throw $error;
        } catch (RuntimeException) {
            return self::blocked($db,$actor,$invoice,$order,'attachment_unavailable','Не удалось подготовить вложение счёта. Проверьте файл и реквизиты.');
        }
        $path=APP_ROOT.'/.local/documents/'.basename($file['path']);
        if (filesize($path)>10*1024*1024) return self::blocked($db,$actor,$invoice,$order,'attachment_limit','Email-вложение превышает 10 МБ.');
        $payload=[
            'policy'=>'rental-approved-invoice-v1','delivery_order'=>$order,
            'recipients'=>$context['recipients'],'recipient_context'=>$context,
            'subject'=>$event['subject'],'text'=>$event['body'],
            'catalog_binding'=>$event['binding'],'catalog_values'=>$event['values'],
            'attachments'=>[[
                'file_id'=>$file['id'],'filename'=>$file['filename'],'path'=>$path,
                'sha256'=>$file['sha256'],'mime'=>$file['mime'],
            ]],
            'invoice_ids'=>[$invoice['id']],'contract_number'=>$contract['number'],
            'invoice_scope'=>self::scope($db,(int)$invoice['id']),
        ];
        return self::record($db,$actor,$invoice,$payload,'prepared',$context);
    }

    public static function validate(PDO $db,array $intent): void
    {
        $invoice=Service::get($db,'invoices',(int)$intent['entity_id']);
        if ($invoice['status']==='revoked' || (int)$invoice['version']!==(int)$intent['entity_version']) throw new RuntimeException('Версия счёта изменилась.');
        $metadata=\App\InvoiceScope::metadata($db,(int)$invoice['id']);
        if ($metadata!==null && (!empty($metadata['runtime_hold']) || $metadata['availability']!=='KNOWN'
            || $metadata['classification']==='UNCLASSIFIED')) throw new RuntimeException('Покрытие счёта не подтверждено для отправки.');
        $payload=json_decode($intent['payload_json'],true,512,JSON_THROW_ON_ERROR);
        OperatingInvoiceContext::assertCurrent($db,(int)$invoice['id'],$payload['invoice_scope']['operating_membership'] ?? null);
        BillingInvoiceTargets::assertSnapshotCurrent($db,(int)$invoice['id'],$payload['invoice_scope']['billing_targets'] ?? null);
        [,,$context]=self::context($db,$invoice);
        if (Importer::canonicalJson(self::comparisonScope($payload['invoice_scope'] ?? null))!==Importer::canonicalJson(self::comparisonScope(self::scope($db,(int)$invoice['id'])))) {
            throw new RuntimeException('Состав помещений или период покрытия счёта изменился.');
        }
        $hash=hash('sha256',Importer::canonicalJson([$invoice,$context,$payload]));
        if (!hash_equals($intent['baseline_hash'],$hash)) throw new RuntimeException('Счёт или получатели изменились после подготовки письма.');
        (new TemplateCatalog($db,Config::store()))->verifyEvent($payload['catalog_binding'],$payload['catalog_values'],$payload['subject'],$payload['text']);
        foreach ($payload['attachments'] as $attachment) {
            $query=$db->prepare('SELECT * FROM rental_files WHERE id=?');
            $query->execute([$attachment['file_id']]);
            $file=$query->fetch() ?: throw new RuntimeException('Вложение счёта не найдено.');
            Evidence::integrity($file);
            if (!hash_equals($file['sha256'],$attachment['sha256'])) throw new RuntimeException('Вложение счёта изменилось.');
        }
    }

    public static function settle(PDO $db,string $id,string $status,array $receipt): void
    {
        if (!in_array($status,['accepted','failed','unknown','manual'],true)) throw new RuntimeException('Недопустимый результат отправки.');
        $query=$db->prepare('SELECT * FROM rental_delivery_intents WHERE id=? FOR UPDATE');
        $query->execute([$id]);
        $intent=$query->fetch() ?: throw new RuntimeException('Отправка не найдена.');
        if (in_array($intent['status'],['accepted','unknown'],true)) throw new RuntimeException('Повторная отправка запрещена.');
        if ($status==='accepted') {
            if (empty($receipt['accepted'])) throw new RuntimeException('Нет подтверждения транспорта.');
            self::validate($db,$intent);
            $db->prepare('UPDATE invoices SET sent=1,version=version+1 WHERE id=?')->execute([$intent['entity_id']]);
        }
        $db->prepare('UPDATE rental_delivery_intents SET status=?,receipt_json=? WHERE id=?')->execute([$status,Importer::json($receipt),$id]);
    }
}
