<?php
declare(strict_types=1);
namespace Modules\Rental;

use Modules\Integrations\{Config, Details, YakovlevImport};
use PDO;
use RuntimeException;

final class SecurityDepositEvidence
{
    public static function incoming(PDO $db,array $invoice,array $contract): array
    {
        $q=$db->prepare("SELECT p.*,a.amount AS allocated,
            (SELECT SUM(b.amount) FROM payment_allocations b WHERE b.payment_id=p.id) AS allocated_total
            FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.invoice_id=? ORDER BY p.id");
        $q->execute([$invoice['id']]);
        $files=$db->prepare("SELECT * FROM rental_files WHERE entity_type='payment' AND entity_id=? AND version=?
            AND kind='payment-order' ORDER BY upload_order DESC");
        $seen=[];
        $received='0.00';
        $rows=[];
        $unverified=false;
        foreach ($q->fetchAll() as $payment) {
            $proof=null;
            $source=Importer::decodeExact($payment['source_json']);
            $valid=(int)$payment['party_id']===(int)$contract['party_id'] && $payment['payment_date']
                && $payment['payment_date']<=date('Y-m-d') && bccomp($payment['allocated'],'0',2)>0
                && bccomp($payment['allocated_total'],$payment['amount'],2)<=0;
            if ($valid && is_string($source['bank_source'] ?? null) && is_string($source['bank_external_id'] ?? null)) {
                $key=hash('sha256',$source['bank_source']."\0".$source['bank_external_id']);
                $receipt=FinalSettlement::fact($db,'rental_bank_payment_identity',$key);
                if (($receipt['payment_id'] ?? 0)===(int)$payment['id'] && (int)$payment['version']===1)
                    $proof=['kind'=>'bank','key'=>$key,'sha256'=>$receipt['sha256']];
            }
            $order=null;
            if ($valid) {
                $files->execute([$payment['id'],$payment['version']]);
                foreach ($files->fetchAll() as $file) {
                    try {
                        Evidence::integrity($file);
                        $duplicate=$db->prepare("SELECT 1 FROM rental_files WHERE entity_type='payment' AND kind='payment-order'
                            AND sha256=? AND entity_id<>? LIMIT 1");
                        $duplicate->execute([$file['sha256'],$payment['id']]);
                        if ($duplicate->fetchColumn()) continue;
                        $order=['id'=>$file['id'],'sha256'=>$file['sha256']];
                        break;
                    } catch (RuntimeException $error) {
                        if ($error instanceof \PDOException) throw $error;
                    }
                }
            }
            if ($proof && !isset($seen[$proof['key']])) {
                $seen[$proof['key']]=true;
                $received=bcadd($received,$payment['allocated'],2);
            } else {
                $proof=null;
                $unverified=true;
            }
            $rows[]=['id'=>(int)$payment['id'],'version'=>(int)$payment['version'],'amount'=>$payment['allocated'],
                'date'=>$payment['payment_date'],'proof'=>$proof,'order'=>$order];
        }
        $unverified=$unverified || $invoice['paid']===null || bccomp($received,$invoice['paid'],2)!==0;
        return compact('received','rows','unverified');
    }

    public static function agreement(PDO $db,array $contract): array
    {
        $id=(int)$contract['id'];
        $agreement=FinalSettlement::read($db,$id)['agreement'] ?? [];
        $file=FinalSettlement::files($db,$id)[$agreement['id'] ?? ''] ?? null;
        if (($agreement['state'] ?? '')!=='signed' || !$file || empty($agreement['signed_on'])
            || !hash_equals($agreement['sha256'],$file['sha256']))
            throw new RuntimeException('Сначала подтвердите подписанное итоговое соглашение в финальной сверке.',409);
        Evidence::integrity($file);
        return ['id'=>$file['id'],'sha256'=>$file['sha256'],'date'=>$agreement['signed_on'],'name'=>$file['filename']];
    }

    public static function successor(PDO $db,array $old,array $new,array $reference): void
    {
        if ($reference['source_number']!==$old['number'] || $reference['target_number']!==$new['number']
            || $old['party_id']!==$new['party_id'] || !$old['start_date'] || !$new['start_date']
            || $new['start_date']<=$old['start_date']
            || !$old['signed'] || !$new['signed'] || !in_array($new['status'],['sent','active'],true)
            || $new['id']===$old['id'])
            throw new RuntimeException('Сверьте оба номера и контрагента с подписанным D20 и выберите подписанный новый договор.',409);
        Evidence::latest($db,'contract',(int)$new['id'],'signed-contract');
    }

    public static function outgoing(PDO $db,array $contract,string $document): array
    {
        $q=$db->prepare("SELECT * FROM external_documents WHERE (id=? OR number=?) AND provider=?
            AND account_id='bank_out' ORDER BY id LIMIT 2 FOR UPDATE");
        $q->execute([$document,$document,YakovlevImport::PROVIDER]);
        $rows=$q->fetchAll();
        if (count($rows)!==1 || $rows[0]['status']!=='Проведён')
            throw new RuntimeException('Укажите однозначно определяемое проведённое списание из сохранённого реестра 1С.',409);
        $row=$rows[0];
        try {
            $raw=Importer::decodeExact(Config::store()->read($row['raw_path'],$row['raw_sha256']));
            $normalized=YakovlevImport::normalize($raw);
        } catch (\InvalidArgumentException|RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            throw new RuntimeException('Оригинал списания недоступен или не подтверждён.',409);
        }
        $record=$raw['fields']['record'];
        if (($record['ПроведеноБанком'] ?? null)!==true && ($record['ПодтвержденоВыпиской'] ?? null)!==true)
            throw new RuntimeException('В оригинале нет подтверждения исполнения банком. Сверьте выписку, возврат не учтён.',409);
        $q=$db->prepare('SELECT inn,source_json FROM parties WHERE id=?');
        $q->execute([$contract['party_id']]);
        $party=$q->fetch();
        $identity=Importer::decodeExact($party['source_json']);
        $recipient=$record['Контрагент_Key'] ?? $record['Получатель_Key'] ?? null;
        $inn=(string)($record['ИННПолучателя'] ?? '');
        $signedParty=($recipient && in_array($recipient,[$identity['Ref_Key'] ?? null,$identity['source_party_guid'] ?? null],true))
            || (preg_match('/^(?:\d{10}|\d{12})$/D',$inn) && $inn===$party['inn']);
        $amount=bcadd($row['amount'] ?? '0','0',2);
        if (!$signedParty || $normalized['source_id']!==$row['source_id'] || $normalized['status']!==$row['status']
            || $normalized['document_date']!==$row['document_date'] || $row['document_date']>date('Y-m-d')
            || bccomp($normalized['amount'],$amount,8)!==0 || bccomp($amount,'0',2)<=0)
            throw new RuntimeException('Сверьте получателя, дату и сумму по оригиналу проведённого списания.',409);
        $purpose=Details::fields($raw)['bank_purpose'];
        if (!self::refundPurpose($purpose,$contract['number']))
            throw new RuntimeException('Назначение списания не подтверждает возврат обеспечения именно по выбранному договору. Нужна сверка бухгалтера.',409);
        return ['id'=>$row['id'],'source_id'=>$row['source_id'],'sha256'=>$row['raw_sha256'],'name'=>'Списание 1С № '.$row['number'],
            'date'=>$row['document_date'],'amount'=>$amount,'version'=>(int)$row['version'],'purpose'=>$purpose];
    }

    public static function refundPurpose(string $purpose,string $number): bool
    {
        if (!preg_match('/^\s*возврат\s+обеспечительн\p{L}*\s+плат[её]ж\p{L}*\s+по\s+договору\s+/iu',$purpose)
            || preg_match('/\b(оплата|погашение|клининг)\b/iu',$purpose)) return false;
        preg_match_all('/\bдоговор(?:у|а|ом)?\s*(?:№\s*|N\s*)?([\p{L}\p{N}][\p{L}\p{N}.\/_-]*)/iu',$purpose,$references);
        $references=array_values(array_unique(array_map(static fn(string $ref)=>mb_strtolower(rtrim($ref,'.')),$references[1])));
        return $references===[mb_strtolower(rtrim($number,'.'))];
    }
}
