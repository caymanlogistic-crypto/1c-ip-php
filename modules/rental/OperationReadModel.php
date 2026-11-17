<?php
declare(strict_types=1);
namespace Modules\Rental;

use Modules\Integrations\CaptureReader;

/** An operation view is derived from durable runtime intents, never revived archive queues. */
final class OperationReadModel
{
    public static function state(string $channel,string $status,bool $manualMarked=false): array
    {
        if ($manualMarked && in_array($status,['prepared','failed','blocked'],true)) $status='manual';
        $accepted=in_array($channel,['Email','MAX'],true) && $status==='accepted';
        $manual=$status==='manual';
        $success=$accepted || in_array($status,['success','generated','saved','existing_reference','existing_verified'],true);
        $uncertain=in_array($status,['unknown','claimed','unconfirmed','posting','ack_received'],true);
        $pending=in_array($status,['prepared','preflight','pending'],true);
        $state=$manual?'manual':($success?'success':($pending?'pending':'error'));
        $label=match($state) {'success'=>$accepted?'Принято транспортом':'Выполнено',
            'manual'=>'Сделано вручную','pending'=>'Ожидает отправки',default=>$uncertain?'Доставка не подтверждена':'Ошибка'};
        return ['status'=>$state,'label'=>$label,'accepted'=>$accepted,'uncertain'=>$uncertain];
    }

    /** Display capability only. The GET/POST handlers still verify current B/files and current grants. */
    public static function blockedManualPacket(array $intent): ?array
    {
        if(($intent['status']??null)!=='blocked'||($intent['provider']??null)!=='email')return null;
        try{
            $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
            $descriptor=ManualPacketDescriptor::verify($payload['manual_packet']??[]);
            $kind=$intent['entity_type'];$operation=match($kind){'contract'=>'contract-packet','document'=>'addendum-packet',default=>null};
            $policy=$kind==='contract'?'rental-approved-packet-v1':'rental-approved-addendum-packet-v1';
            $record=['kind'=>$kind,'id'=>(int)$intent['entity_id'],'version'=>(int)$intent['entity_version']];
            $keys=array_keys($payload);sort($keys,SORT_STRING);
            if($keys!==['delivery_order','invoice_ids','manual_packet','packet_record','policy','preflight']||!$operation||$intent['operation']!==$operation
                ||$payload['policy']!==$policy||!is_int($payload['delivery_order'])||$payload['delivery_order']<1
                ||!is_array($payload['preflight'])||!is_string($payload['preflight']['state']??null)
                ||Importer::canonicalJson($record)!==Importer::canonicalJson($payload['packet_record'])
                ||Importer::canonicalJson($record)!==Importer::canonicalJson($descriptor['packet_record'])
                ||$payload['invoice_ids']!==$descriptor['invoice_ids']||(int)$intent['actor_id']!==$descriptor['original_actor_context']['id']
                ||!hash_equals($intent['baseline_hash'],hash('sha256',Importer::canonicalJson([$kind,(int)$intent['entity_id'],(int)$intent['entity_version'],$payload]))))return null;
            return $descriptor;
        }catch(\Throwable){return null;}
    }

    private static function binding(string $kind,int $id): ?array
    {
        if ($kind==='document') {
            $rows=Controller::query('SELECT d.contract_id,d.number document_number,c.number contract_number,p.name party_name FROM rental_documents d JOIN contracts c ON c.id=d.contract_id JOIN parties p ON p.id=c.party_id WHERE d.id=?',[$id]);
        } elseif ($kind==='invoice') {
            $rows=Controller::query('SELECT i.contract_id,c.number contract_number,p.name party_name FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id WHERE i.id=?',[$id]);
        } else {
            $rows=Controller::query('SELECT c.id contract_id,c.number contract_number,p.name party_name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?',[$id]);
        }
        if (!$rows) return null;
        $row=$rows[0];
        $row['document_id']=$kind==='document'?$id:null;
        $row['row_key']=$kind==='document'?'document:'.$id:'contract:'.$row['contract_id'];
        $row['label']=$kind==='document'?'ДС №'.$row['document_number'].' к договору №'.$row['contract_number']:'Договор №'.$row['contract_number'];
        return $row;
    }

    public static function records(): array
    {
        Access::pages(['operations']);
        $groups=[];
        foreach (Controller::query("SELECT id,provider,actor_id,entity_type,entity_id,entity_version,operation,status,baseline_hash,created_at,payload_json,receipt_json,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.preflight.message')) public_message,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.legal_file.filename')) file_name,
            SHA2(payload_json,256) payload_digest,SHA2(COALESCE(receipt_json,'null'),256) receipt_digest
            FROM rental_delivery_intents ORDER BY created_at,id") as $intent) {
            if (!in_array($intent['entity_type'],['contract','document','invoice'],true)) continue;
            $binding=self::binding($intent['entity_type'],(int)$intent['entity_id']);
            if ($binding===null) continue;
            $manualMarked=Delivery::manualMarked($intent['receipt_json']);
            $manualPacket=self::blockedManualPacket($intent);
            $lineage=[];
            if (in_array($intent['operation'],['contract-packet','addendum-packet'],true)) {
                try {
                    $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
                    $lineage=[
                        'operation'=>$intent['operation'],'entity_version'=>(int)$intent['entity_version'],
                        'delivery_order'=>$payload['delivery_order'] ?? null,
                        'wire_sha256'=>FrozenPacketWire::digest($payload),
                        'retry_parent_id'=>isset($payload['retry_of'])?'email:'.$payload['retry_of']:null,
                    ];
                } catch (\Throwable) {
                    // Legacy or blocked packets without a verified frozen projection remain separate.
                }
                if($manualPacket!==null)$lineage=['operation'=>$intent['operation'],'entity_version'=>(int)$intent['entity_version'],
                    'delivery_order'=>$payload['delivery_order'],'manual_descriptor_sha256'=>$manualPacket['sha256']];
            }
            $count=Controller::query("SELECT COUNT(DISTINCT request_key) n FROM messaging_attempts WHERE provider='email' AND request_key=?",['rental-intent-'.$intent['id']])[0]['n'];
            $error=match(true) {
                $manualMarked && in_array($intent['status'],['prepared','failed','blocked'],true)=>'',
                $intent['status']==='blocked'=>$intent['public_message'] ?? 'Проверьте состав пакета и сведения для отправки.',
                in_array($intent['status'],['unknown','claimed'],true)=>'Результат требует сверки. Повторная отправка запрещена.',
                $intent['status']==='failed'=>'Не удалось завершить отправку. Проверьте подключение и актуальность пакета.',
                default=>'',
            };
            $step=self::state('Email',$intent['status'],$manualMarked)+[
                'id'=>'email:'.$intent['id'],'provider'=>'email','reference'=>$intent['id'],'channel'=>'Email',
                'description'=>$intent['entity_type']==='invoice'?'Отправка счёта':'Отправка пакета документов и счетов',
                'error'=>$error,
                'file_name'=>$intent['file_name'] ?: ($manualPacket['legal_file']['filename'] ?? ''),
                'attempts'=>(int)$count,'native_status'=>$intent['status'],
                'version'=>(int)$intent['entity_version'],'manual_marked'=>$manualMarked,
                'blocked_manual_available'=>$manualPacket!==null,
            ];
            $groups[]=$binding+$lineage+['id'=>'email:'.$intent['id'],'description'=>$step['description'],
                'created_at'=>$intent['created_at'],'fingerprint'=>hash('sha256',Importer::canonicalJson($intent)),'steps'=>[$step]];
        }
        foreach (Controller::query("SELECT i.id,i.kind,i.state,i.revision,i.body_sha256,i.post_started,i.created_at,p.record_kind,p.record_id,p.record_version FROM integration_native_intents i JOIN integration_native_prepared p ON p.id=i.prepared_id WHERE i.project_id=? ORDER BY i.created_at,i.id",[CaptureReader::PROJECT]) as $intent) {
            if (!in_array($intent['record_kind'],['contract','document'],true)) continue;
            $binding=self::binding($intent['record_kind'],(int)$intent['record_id']);
            if ($binding===null) continue;
            $step=self::state('1C',$intent['state'])+[
                'id'=>'native:'.$intent['id'],'provider'=>'onec','reference'=>$intent['id'],'channel'=>'1C',
                'description'=>$intent['kind']==='counterparty'?'Создать контрагента в 1С':'Создать счёт в 1С',
                'error'=>in_array($intent['state'],['unknown','unconfirmed','ack_received','posting'],true)
                    ?'Результат требует сверки. Повторная запись запрещена.'
                    :(in_array($intent['state'],['saved','existing_reference','existing_verified','prepared','preflight'],true)?'':'Проверьте актуальность пакета и состояние подключения.'),
                'file_name'=>'','attempts'=>(int)$intent['post_started'],'native_status'=>$intent['state'],
                'version'=>(int)$intent['revision'],
            ];
            $groups[]=$binding+['id'=>'native:'.$intent['id'],'description'=>$step['description'],
                'created_at'=>$intent['created_at'],'fingerprint'=>hash('sha256',Importer::canonicalJson($intent)),'steps'=>[$step]];
        }
        return OperationLineage::collapse($groups);
    }

    public static function rows(array $groups,string $query='',string $flag='all'): array
    {
        $rows=[];
        foreach ($groups as $group) {
            $key=$group['row_key'];
            if (!isset($rows[$key])) $rows[$key]=['id'=>$group['id'],'label'=>$group['label'],'party_name'=>$group['party_name'],'created_at'=>$group['created_at'] ?? null,'groups'=>[],'errors'=>[],'pending'=>false];
            $rows[$key]['groups'][]=$group;
            foreach ($group['steps'] as $step) {
                if ($step['status']==='error') $rows[$key]['errors'][]=$step['description'].($step['error']!==''?': '.$step['error']:'');
                if ($step['status']==='pending') $rows[$key]['pending']=true;
            }
        }
        return array_values(array_filter($rows,fn($row)=>($flag!=='errors' || $row['errors'])
            && WorkRegistry::matches($query,[$row['label'],$row['party_name'],implode('; ',$row['errors'])])));
    }
}
