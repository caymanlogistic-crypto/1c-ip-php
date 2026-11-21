<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,InvoiceScope,PagePolicy};
use Modules\Integrations\{Config,TemplateCatalog};
use PDO;
use RuntimeException;

/** Source clientPackage producer. Only the outbox worker invokes a transport. */
final class ContractDelivery
{
    private static function descriptor(string $kind):array
    {
        return match($kind){
            'contract'=>['table'=>'contracts','operation'=>'contract-packet','policy'=>'rental-approved-packet-v1'],
            'document'=>['table'=>'rental_documents','operation'=>'addendum-packet','policy'=>'rental-approved-addendum-packet-v1'],
            default=>throw new RuntimeException('Неизвестный вид пакета.',403),
        };
    }

    private static function latest(PDO $db,string $kind,int $id):?array
    {
        $operation=self::descriptor($kind)['operation'];
        $query=$db->prepare('SELECT * FROM rental_delivery_intents WHERE entity_type=? AND entity_id=? AND operation=? '
            ."ORDER BY CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_order')),'0') AS UNSIGNED) DESC LIMIT 2 FOR UPDATE");
        $query->execute([$kind,$id,$operation]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1){
            $first=json_decode($rows[0]['payload_json'],true)['delivery_order']??0;
            $second=json_decode($rows[1]['payload_json'],true)['delivery_order']??0;
            if($first===$second)throw new RuntimeException('Порядок прежних операций требует сверки.');
        }
        return $rows[0]??null;
    }

    private static function actor(array $actor):array
    {
        $current=Service::roles(['accountant']);
        Auth::requirePermission('rental.read');Auth::requirePermission('finance.write');
        if((int)$actor['id']!==(int)$current['id'])throw new RuntimeException('Автор отправки изменился.',403);
        return ['id'=>(int)$current['id'],'role_id'=>$current['role_id'],'auth_version'=>(int)$current['auth_version']];
    }

    public static function prepare(PDO $db,array $actor,array $contract):array
    {
        return self::prepareRecord($db,$actor,'contract',$contract);
    }

    public static function prepareDocument(PDO $db,array $actor,array $document):array
    {
        return self::prepareRecord($db,$actor,'document',$document);
    }

    private static function record(PDO $db,array $actor,string $kind,array $record,array $payload,string $status):array
    {
        $descriptor=self::descriptor($kind);$id=bin2hex(random_bytes(16));
        $baseline=hash('sha256',Importer::canonicalJson([$kind,(int)$record['id'],(int)$record['version'],$payload]));
        $request='rental-'.$descriptor['operation'].':'.$record['id'].':'.$record['version'].':'.$payload['delivery_order'].':'.$baseline;
        $db->prepare('INSERT INTO rental_delivery_intents(id,request_key,provider,operation,entity_type,entity_id,entity_version,baseline_hash,actor_id,payload_json,status) '
            ."VALUES(?,?,'email',?,?,?,?,?,?,?,?)")
            ->execute([$id,$request,$descriptor['operation'],$kind,$record['id'],$record['version'],$baseline,$actor['id'],Importer::json($payload),$status]);
        return ['id'=>$id,'status'=>$status]+($status==='blocked'?['send_error'=>$payload['preflight']['message']]:[]);
    }

    private static function blocked(PDO $db,array $actor,string $kind,array $record,int $order,string $state,string $message,array $missing=[],array $invoiceIds=[],?array $manual=null):array
    {
        return self::record($db,$actor,$kind,$record,[
            'policy'=>self::descriptor($kind)['policy'],'delivery_order'=>$order,'invoice_ids'=>$invoiceIds,
            'packet_record'=>['kind'=>$kind,'id'=>(int)$record['id'],'version'=>(int)$record['version']],
            'preflight'=>['state'=>$state,'missing'=>$missing,'message'=>$message],
            ...($manual===null?[]:['manual_packet'=>$manual]),
        ],'blocked');
    }

    private static function prepareRecord(PDO $db,array $actor,string $kind,array $input):array
    {
        if(!$db->inTransaction())throw new RuntimeException('Подготовка пакета требует предметной транзакции.');
        FileOrder::lock($db);$actor=self::actor($actor);
        PagePolicy::requireCommand($kind==='contract'?'contract.send':'document.send');
        $record=$kind==='contract'?Access::contract((int)$input['id'],2):Access::document((int)$input['id'],2);
        $descriptor=self::descriptor($kind);
        // Uncertainty freezes the entity before context/version checks or any new payload.
        $query=$db->prepare("SELECT id,status FROM rental_delivery_intents WHERE entity_type=? AND entity_id=? AND operation=? AND status IN ('claimed','unknown') LIMIT 1 FOR UPDATE");
        $query->execute([$kind,$record['id'],$descriptor['operation']]);
        if($frozen=$query->fetch(PDO::FETCH_ASSOC))return $frozen+['reconciliation_required'=>true];
        $old=self::latest($db,$kind,(int)$record['id']);
        if($old&&$old['status']==='accepted'&&$record['status']==='sent')return ['id'=>$old['id'],'status'=>'accepted'];
        Service::version($record,['version'=>$input['version']]);
        if($record['status']!=='approved')throw new RuntimeException('Пакет не подтверждён бухгалтером.');
        if($old&&$old['status']==='prepared'){
            try{self::validate($db,$old);return ['id'=>$old['id'],'status'=>'prepared'];}
            catch(\PDOException $error){throw $error;}
            catch(RuntimeException){$db->prepare("UPDATE rental_delivery_intents SET status='superseded' WHERE id=? AND status='prepared'")->execute([$old['id']]);}
        }
        $order=$old?(int)(json_decode($old['payload_json'],true)['delivery_order']??0)+1:1;
        try{$packet=NativePacket::current($db,$kind,(int)$record['id'],$actor,'prepare');}
        catch(\PDOException $error){throw $error;}
        catch(RuntimeException $error){
            if($error->getCode()===403)throw $error;
            return self::blocked($db,$actor,$kind,$record,$order,'normal_confirmation_required','Состав пакета изменился. Повторите проверку бухгалтера.',['normalConfirmation']);
        }
        if(($packet['state']??null)!=='confirmed')return self::blocked($db,$actor,$kind,$record,$order,'normal_confirmation_required',
            'Бухгалтер должен подтвердить актуальный состав пакета.',['normalConfirmation']);
        if($old&&$old['status']==='failed'&&(int)$old['entity_version']===(int)$record['version']){
            $previous=json_decode($old['payload_json'],true,128,JSON_THROW_ON_ERROR);
            if(Importer::canonicalJson($previous['b_binding']??null)!==Importer::canonicalJson($packet['b_packet'])){
                return self::blocked($db,$actor,$kind,$record,$order,'normal_confirmation_required',
                    'Прежняя отправка относится к другому подтверждению пакета. Повторите проверку бухгалтера.',['normalConfirmation']);
            }
            // Existing failed wire belongs to this B capture. Only the explicit Ops retry
            // may create its successor; initial send never creates an unrelated root.
            return ['id'=>$old['id'],'status'=>'failed','send_error'=>'Предыдущая отправка не выполнена. Повторите этот шаг на странице операций.'];
        }
        $invoiceIds=array_map('intval',$packet['invoice_ids']);
        foreach($packet['invoices']as$invoice){
            $scope=InvoiceScope::metadata($db,(int)$invoice['id']);
            if($scope!==null&&(!empty($scope['runtime_hold'])||$scope['availability']!=='KNOWN'||$scope['classification']==='UNCLASSIFIED')){
                return self::blocked($db,$actor,$kind,$record,$order,'scope_unconfirmed','Состав помещений или период счёта требуют проверки перед отправкой.',['invoiceScope'],$invoiceIds);
            }
        }
        // Freeze a physical handover capability under the real B actor before Email
        // recipient/catalog preflight. A later Ops actor only reads these exact files.
        $manual=null;$attachments=null;
        try{
            $document=WordDerivative::produce($db,$packet['file']['id']);
            $attachments=[self::attachment($document)];
            foreach($invoiceIds as$id)$attachments[]=self::attachment(InvoiceDelivery::packetAttachment($db,$id,$kind,(int)$record['id'],'manual-neutral'));
            // current() deliberately exposes no actor-independent signer tuple.
            // Read the original signer from its verified B receipt, never synthesize it.
            $manualPacket=PacketDeliveryProof::currentBusinessProof($db,$kind,(int)$record['id'],$packet['b_packet']);
            $manual=ManualPacketDescriptor::freeze($manualPacket,$attachments);
        }catch(\PDOException $error){throw $error;}
        catch(RuntimeException){$attachments=null;}
        foreach($packet['invoices']as$invoice){
            try{\App\OperatingInvoiceContext::requireOrdinary($invoice['operating_membership']??null);}
            catch(RuntimeException){
                return self::blocked($db,$actor,$kind,$record,$order,'operating_membership_unconfirmed',
                    'Счёт не допущен к обычной отправке. Проверьте операционную принадлежность.',['operatingMembership'],$invoiceIds,$manual);
            }
            try{\App\BillingInvoiceTargets::requireReady($invoice['billing_targets']??null);}
            catch(RuntimeException){
                return self::blocked($db,$actor,$kind,$record,$order,'billing_targets_unconfirmed',
                    'Предмет счёта требует проверки перед электронной отправкой.',['billingTargets'],$invoiceIds,$manual);
            }
        }
        $contract=$kind==='contract'?$record:Service::get($db,'contracts',(int)$record['contract_id']);
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        try{$recipient=RecipientContext::resolve($db,$party);}
        catch(DeliveryContextException $error){return self::blocked($db,$actor,$kind,$record,$order,'missing_context',$error->getMessage(),$error->missing,$invoiceIds,$manual);}
        $numbers=implode(', ',array_column($packet['invoices'],'number'));
        $label=$kind==='contract'?'Договор №'.$contract['number']:'Допсоглашение №'.$record['number'].' к договору №'.$contract['number'];
        $values=[
            'documentLabel'=>$label,'effectiveSummary'=>$kind==='document'&&$record['effective_date']?'Новые условия действуют с '.$record['effective_date'].'.':'Условия и даты действия указаны в приложенном документе.',
            'invoiceSummary'=>$numbers!==''?'Счета: '.$numbers.'.':'Состав вложений соответствует подтверждённому пакету.',
            'manager'=>$recipient['manager'],'managerEmail'=>$recipient['managerEmail'],
            'number'=>(string)$contract['number'],'party'=>(string)$party['name'],
            'version'=>(string)$record['version'],'invoiceNumbers'=>$numbers,
            'documentNumber'=>(string)$record['number'],
            'effective'=>$kind==='document'?(string)$record['effective_date']:(string)$contract['start_date'],
        ];
        try{
            $event=(new TemplateCatalog($db,Config::store()))->event('email','clientPackage',$values);
            if($event['binding']['recipients_policy']!=='clientAndManager'||$event['binding']['attachment_policy']!=='documentAndInvoices')throw new RuntimeException('CATALOG_POLICY_MISMATCH');
        }catch(\PDOException $error){throw $error;}
        catch(RuntimeException|\InvalidArgumentException){return self::blocked($db,$actor,$kind,$record,$order,'catalog_unavailable','Проверьте шаблон письма для подтверждённого пакета.',['clientPackage'],$invoiceIds,$manual);}
        if($attachments===null)return self::blocked($db,$actor,$kind,$record,$order,'attachment_unavailable','Не удалось подготовить подтверждённые PDF пакета. Проверьте актуальный файл и счета.',['packetFiles'],$invoiceIds);
        $total=0;foreach($attachments as$file)$total+=filesize($file['path']);
        if(count($attachments)>10||$total>10485760)return self::blocked($db,$actor,$kind,$record,$order,'attachment_limit','Вложения письма превышают допустимый размер или количество.',['attachments'],$invoiceIds,$manual);
        $payload=[
            'policy'=>$descriptor['policy'],'delivery_order'=>$order,
            'packet_record'=>['kind'=>$kind,'id'=>(int)$record['id'],'version'=>(int)$record['version']],
            'actor_context'=>$actor,'b_binding'=>$packet['b_packet'],'legal_file'=>$packet['file'],
            'recipients'=>$recipient['recipients'],'recipient_context'=>$recipient,
            'subject'=>$event['subject'],'text'=>$event['body'],'catalog_binding'=>$event['binding'],'catalog_values'=>$event['values'],
            'attachments'=>$attachments,'invoice_ids'=>$invoiceIds,'contract_number'=>$contract['number'],
            'invoice_versions'=>array_map(static fn($invoice)=>['id'=>(int)$invoice['id'],'version'=>(int)$invoice['version']],$packet['invoices']),
        ];
        return self::record($db,$actor,$kind,$record,$payload,'prepared');
    }

    private static function attachment(array $file):array
    {
        return PacketDeliveryProof::attachment($file);
    }

    public static function validate(PDO $db,array $intent):void
    {
        $kind=$intent['entity_type'];$descriptor=self::descriptor($kind);
        if($intent['operation']!==$descriptor['operation']||$intent['provider']!=='email')throw new RuntimeException('Неверный вид отправки пакета.');
        $record=Service::get($db,$descriptor['table'],(int)$intent['entity_id']);
        if($record['status']!=='approved'||(int)$record['version']!==(int)$intent['entity_version'])throw new RuntimeException('Версия пакета изменилась.');
        $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
        if(($payload['policy']??null)!==$descriptor['policy']||!is_array($payload['actor_context']??null)||(int)$payload['actor_context']['id']!==(int)$intent['actor_id'])throw new RuntimeException('Подтверждение отправки пакета не найдено.');
        $hash=hash('sha256',Importer::canonicalJson([$kind,(int)$record['id'],(int)$record['version'],$payload]));
        if(!hash_equals($intent['baseline_hash'],$hash))throw new RuntimeException('Состав пакета изменился.');
        $packet=NativePacket::current($db,$kind,(int)$record['id'],$payload['actor_context'],'prepare');
        if(($packet['state']??null)!=='confirmed'||Importer::canonicalJson($packet['b_packet'])!==Importer::canonicalJson($payload['b_binding'])
            ||array_map('intval',$packet['invoice_ids'])!==$payload['invoice_ids'])throw new RuntimeException('Подтверждённый состав пакета изменился.');
        foreach($packet['invoices']as$invoice){
            \App\OperatingInvoiceContext::assertCurrent($db,(int)$invoice['id'],$invoice['operating_membership']??null);
            \App\BillingInvoiceTargets::assertSnapshotCurrent($db,(int)$invoice['id'],$invoice['billing_targets']??null);
            $scope=InvoiceScope::metadata($db,(int)$invoice['id']);if($scope!==null&&(!empty($scope['runtime_hold'])||$scope['availability']!=='KNOWN'||$scope['classification']==='UNCLASSIFIED'))throw new RuntimeException('Покрытие счёта требует проверки.');
        }
        $contract=$kind==='contract'?$record:Service::get($db,'contracts',(int)$record['contract_id']);
        $recipient=RecipientContext::resolve($db,Service::get($db,'parties',(int)$contract['party_id']));
        if(Importer::canonicalJson($recipient)!==Importer::canonicalJson($payload['recipient_context']))throw new RuntimeException('Получатели пакета изменились.');
        (new TemplateCatalog($db,Config::store()))->verifyEvent($payload['catalog_binding'],$payload['catalog_values'],$payload['subject'],$payload['text']);
        PacketDeliveryProof::validateAttachments($db,$payload,$packet);
    }

    public static function settle(PDO $db,string $id,string $status,array $receipt):?array
    {
        if(!$db->inTransaction()||!in_array($status,['accepted','failed','unknown','manual'],true))throw new RuntimeException('Недопустимый результат отправки.');
        $query=$db->prepare('SELECT * FROM rental_delivery_intents WHERE id=? FOR UPDATE');$query->execute([$id]);
        $intent=$query->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Отправка не найдена.');
        if(in_array($intent['status'],['accepted','unknown'],true))throw new RuntimeException('Повторная отправка запрещена.');
        $prior=json_decode($intent['receipt_json']??'{}',true,128,JSON_THROW_ON_ERROR);
        if(isset($prior['manual_completion']))$receipt['manual_completion']=$prior['manual_completion'];
        if($status==='manual'){
            $completion=DeliveryRetry::manualExisting($db,$id,Auth::user()??throw new RuntimeException('Требуется вход.',401));
            return $completion['staff_handoff']??null;
        }
        if($status==='accepted'){
            if(empty($receipt['accepted']))throw new RuntimeException('Нет подтверждения транспорта.');
            if(isset($prior['manual_completion']))throw new RuntimeException('Ручная отметка во время отправки требует сверки результата.');
            self::validate($db,$intent);$descriptor=self::descriptor($intent['entity_type']);
            $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
            $db->prepare('UPDATE rental_delivery_intents SET status=?,receipt_json=? WHERE id=?')->execute([$status,Importer::json($receipt),$id]);
            return DeliveryRetry::completeInitialAccepted($db,$intent,$payload);
        }
        $db->prepare('UPDATE rental_delivery_intents SET status=?,receipt_json=? WHERE id=?')->execute([$status,Importer::json($receipt),$id]);
        return null;
    }
}
