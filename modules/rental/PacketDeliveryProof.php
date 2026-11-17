<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\InvoiceScope;
use Modules\Integrations\{Config,TemplateCatalog};
use PDO;
use RuntimeException;

/** Read-only original approval/current business/file checks. The caller authorizes its sender. */
final class PacketDeliveryProof
{
    public static function currentBusinessProof(PDO $db,string $kind,int $id,array $binding):array
    {
        return NativePacket::deliveryBusinessProof($db,$kind,$id,$binding);
    }

    public static function attachment(array $file):array
    {
        Evidence::integrity($file);
        $path=APP_ROOT.'/.local/documents/'.basename($file['path']);
        Documents::validateFile($path,'pdf');
        return ['file_id'=>$file['id'],'entity_type'=>$file['entity_type'],'entity_id'=>(int)$file['entity_id'],
            'filename'=>$file['filename'],'path'=>$path,'sha256'=>$file['sha256'],'mime'=>$file['mime'],
            'version'=>(int)$file['version'],'baseline_hash'=>$file['baseline_hash']??null];
    }

    /** Physical handover: original B/current business and existing files, never an Email grant. */
    public static function validateManualDescriptor(PDO $db,array $descriptor):array
    {
        $descriptor=ManualPacketDescriptor::verify($descriptor);
        $record=$descriptor['packet_record'];
        $packet=self::currentBusinessProof($db,$record['kind'],$record['id'],$descriptor['b_binding']);
        if(Importer::canonicalJson(ManualPacketDescriptor::freeze($packet,$descriptor['attachments']))!==Importer::canonicalJson($descriptor))throw new RuntimeException('MANUAL_PACKET_CURRENT_CHANGED',409);
        self::validateInvoiceScopes($db,$packet['invoices'],false);
        self::validateAttachments($db,$descriptor,$packet);
        return $packet;
    }

    private static function validateInvoiceScopes(PDO $db,array $invoices,bool $ordinary):void
    {
        foreach($invoices as$invoice){
            if($ordinary){
                \App\OperatingInvoiceContext::assertCurrent($db,(int)$invoice['id'],$invoice['operating_membership']??null);
                \App\BillingInvoiceTargets::assertSnapshotCurrent($db,(int)$invoice['id'],$invoice['billing_targets']??null);
            }else \App\BillingInvoiceTargets::requireReady($invoice['billing_targets']??null); // Identity/date scope, not ordinary permission.
            $scope=InvoiceScope::metadata($db,(int)$invoice['id']);
            if($scope!==null&&(!empty($scope['runtime_hold'])||$scope['availability']!=='KNOWN'||$scope['classification']==='UNCLASSIFIED'))throw new RuntimeException('Покрытие счёта требует проверки.');
        }
    }

    public static function validateFrozen(PDO $db,array $payload,array $packet):void
    {
        FrozenPacketWire::projection($payload);
        $record=$packet['record'];$kind=$record['kind'];
        if(($packet['state']??null)!=='confirmed'||Importer::canonicalJson($packet['b_packet'])!==Importer::canonicalJson($payload['b_binding'])
            ||Importer::canonicalJson($record)!==Importer::canonicalJson($payload['packet_record'])
            ||Importer::canonicalJson($packet['original_actor_context'])!==Importer::canonicalJson($payload['actor_context'])
            ||array_map('intval',$packet['invoice_ids'])!==$payload['invoice_ids'])throw new RuntimeException('PACKET_FROZEN_APPROVAL_CHANGED',409);
        $versions=array_map(static fn(array $invoice):array=>['id'=>(int)$invoice['id'],'version'=>(int)$invoice['version']],$packet['invoices']);
        if(Importer::canonicalJson($versions)!==Importer::canonicalJson($payload['invoice_versions']))throw new RuntimeException('PACKET_FROZEN_INVOICES_CHANGED',409);
        self::validateInvoiceScopes($db,$packet['invoices'],true);
        // An existing operation preserves its rendered recipients and body. It never
        // resolves another manager, rerenders the catalog, or generates a missing PDF.
        (new TemplateCatalog($db,Config::store()))->verifyEvent($payload['catalog_binding'],$payload['catalog_values'],$payload['subject'],$payload['text']);
        self::validateAttachments($db,$payload,$packet);
    }

    public static function validateAttachments(PDO $db,array $payload,array $packet):void
    {
        $record=$packet['record'];$kind=$record['kind'];
        if(count($payload['attachments'])!==count($payload['invoice_ids'])+1)throw new RuntimeException('Состав вложений пакета изменился.');
        foreach($payload['attachments']as$index=>$attachment){
            $query=$db->prepare('SELECT * FROM rental_files WHERE id=?');$query->execute([$attachment['file_id']]);
            $file=$query->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Вложение пакета не найдено.');
            if(Importer::canonicalJson(self::attachment($file))!==Importer::canonicalJson($attachment))throw new RuntimeException('Вложение пакета изменилось.');
            if($index===0){
                if($file['entity_type']!==$kind||(int)$file['entity_id']!==(int)$record['id'])throw new RuntimeException('Документ не относится к пакету.');
                if($file['id']!==$packet['file']['id']&&($file['kind']!=='word-pdf'||$file['baseline_hash']!==$packet['file']['sha256']||(int)$file['version']!==(int)$record['version']))throw new RuntimeException('PDF не связан с подтверждённым Word.');
            }else{
                $invoiceId=$payload['invoice_ids'][$index-1];
                if($file['entity_type']!=='invoice'||(int)$file['entity_id']!==$invoiceId)throw new RuntimeException('Вложение не относится к счёту пакета.');
                $invoice=Service::get($db,'invoices',$invoiceId);
                $query=$db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND kind='invoice-file'");$query->execute([$invoiceId]);
                $current=$query->fetchColumn()?Evidence::latest($db,'invoice',$invoiceId,'invoice-file'):Evidence::latest($db,'invoice',$invoiceId,'pdf',(int)$invoice['version']);
                if($current['id']!==$file['id'])throw new RuntimeException('Файл счёта заменён после подготовки пакета.');
            }
        }
    }
}
