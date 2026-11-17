<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy};
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Existing packet attempts: frozen wire, current sender and separate original B proof. */
final class DeliveryRetry
{
    private static function audit(PDO $db,array $actor,string $action,string $type,string|int $id,mixed $before,array $after): void
    {
        $db->prepare('INSERT INTO audit_log(user_id,action,entity_type,entity_id,before_json,after_json) VALUES(?,?,?,?,?,?)')
            ->execute([$actor['id'],$action,$type,$id,$before===null?null:Importer::json($before),Importer::json($after)]);
    }

    private static function operator(PDO $db,array $context): array
    {
        $context=self::sender($context);
        $lock=$db->inTransaction()?' FOR UPDATE':'';
        $query=$db->prepare("SELECT u.id,u.name,u.role role_id,u.auth_version,u.active,r.grants_configured,
            CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END role
            FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?".$lock);
        $query->execute([$context['id']]);$actor=$query->fetch(PDO::FETCH_ASSOC);
        $grants=['manager'=>'rental.write','lawyer'=>'rental.approve.legal','accountant'=>'finance.write',
            'director'=>'rental.approve.executive','admin'=>'*'];
        if (!$actor || !$actor['active'] || !isset($grants[$actor['role']]) || $actor['role_id']!==$context['role_id']
            || (int)$actor['auth_version']!==$context['auth_version']) throw new RuntimeException('Права исполнителя изменились.',403);
        foreach (['rental.read',$grants[$actor['role']]] as $grant) {
            $query=$db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*') LIMIT 1".$lock);
            $query->execute([$actor['role_id'],$grant]);
            if (!$query->fetchColumn()) throw new RuntimeException('Недостаточно прав для выполнения операции.',403);
        }
        if ($actor['role_id']!=='admin' && $actor['grants_configured']) {
            $query=$db->prepare("SELECT level FROM role_page_grants WHERE role_id=? AND page_key='rental.operations'".$lock);
            $query->execute([$actor['role_id']]);$level=(int)($query->fetchColumn() ?: 0);
        } else $level=PagePolicy::levelForRole($actor['role_id'],$actor['role'],(bool)$actor['grants_configured'],'rental.operations');
        if ($level<2) throw new RuntimeException('Недостаточно прав для страницы операций.',403);
        $actor['id']=(int)$actor['id'];$actor['auth_version']=(int)$actor['auth_version'];
        return $actor;
    }

    private static function currentOperator(PDO $db,array $expected): array
    {
        $current=Auth::user() ?? throw new RuntimeException('Требуется вход.',401);
        if (self::sender($current)!==self::sender($expected)) throw new RuntimeException('Исполнитель операции изменился.',403);
        return self::operator($db,$expected);
    }

    private static function intent(PDO $db,string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('Операция не найдена.',404);
        $query=$db->prepare('SELECT * FROM rental_delivery_intents WHERE id=? FOR UPDATE');$query->execute([$id]);
        $intent=$query->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Операция не найдена.',404);
        $operation=match($intent['entity_type']) {'contract'=>'contract-packet','document'=>'addendum-packet',default=>null};
        if ($intent['provider']!=='email' || !$operation || $intent['operation']!==$operation) throw new RuntimeException('Этот шаг выполняется другим обработчиком.',403);
        return $intent;
    }

    private static function result(array $intent): array
    {
        $result=['id'=>$intent['id'],'status'=>$intent['status'],'reused'=>true];
        if (isset(self::receipt($intent)['manual_completion'])) $result['manual_completed']=true;
        if (in_array($intent['status'],['unknown','claimed'],true)) $result['reconciliation_required']=true;
        return $result;
    }

    private static function currentProof(PDO $db,array $intent): array
    {
        if($intent['status']==='blocked'){
            $payload=self::blockedEnvelope($intent);
            PacketDeliveryProof::validateManualDescriptor($db,$payload['manual_packet']);
            self::blockedLeaf($db,$intent,$payload);
            return $payload;
        }
        [$payload]=self::envelope($intent);
        self::lineageProof($db,$intent,$payload);
        $packet=PacketDeliveryProof::currentBusinessProof($db,$intent['entity_type'],(int)$intent['entity_id'],$payload['b_binding']);
        PacketDeliveryProof::validateFrozen($db,$payload,$packet);
        return $payload;
    }

    /** No full-wire replay may resend a recipient accepted by an ancestor. */
    private static function lineageProof(PDO $db,array $child,array $payload): void
    {
        $visited=[];
        while (isset($payload['retry_of'])) {
            if (isset($visited[$child['id']])) throw new RuntimeException('История попыток требует сверки.',409);
            $visited[$child['id']]=true;
            $parent=self::intent($db,$payload['retry_of']);
            if ($parent['entity_type']!==$child['entity_type'] || $parent['operation']!==$child['operation']
                || (int)$parent['entity_id']!==(int)$child['entity_id']
                || (int)$parent['entity_version']!==(int)$child['entity_version']) {
                throw new RuntimeException('История попыток относится к другому пакету.',409);
            }
            $expected=self::prepareChild($parent,$payload['sender_context'],$child['id'],(int)$payload['delivery_order']);
            [$expectedPayload]=self::envelope($expected);
            if (!hash_equals(FrozenPacketWire::digest($payload),FrozenPacketWire::digest($expectedPayload))
                || ($payload['retry_root'] ?? null)!==$expectedPayload['retry_root']) {
                throw new RuntimeException('Состав истории попыток изменился.',409);
            }
            $child=$parent;
            [$payload]=self::envelope($parent);
        }
    }

    /** Stored current sender authorization, also required before worker terminal replay. */
    public static function authorizeStored(PDO $db,array $intent): void
    {
        [$payload]=self::envelope($intent);
        if (!isset($payload['retry_of'],$payload['sender_context'])) throw new RuntimeException('Подтверждение повторной попытки не найдено.',403);
        self::operator($db,$payload['sender_context']);
    }

    public static function validate(PDO $db,array $intent): void
    {
        self::authorizeStored($db,$intent);
        self::currentProof($db,$intent);
        if (isset(self::receipt($intent)['manual_completion'])) throw new RuntimeException('Шаг уже отмечен выполненным вручную.');
    }

    /** Caller owns the transaction. A replay of the same parent never creates another child. */
    public static function retryExisting(PDO $db,string $intentId,array $currentActor): array
    {
        if (!$db->inTransaction()) throw new RuntimeException('Повтор операции требует предметной транзакции.');
        FileOrder::lock($db);$actor=self::currentOperator($db,$currentActor);
        $parent=self::intent($db,$intentId);
        $query=$db->prepare("SELECT * FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=? AND status IN ('unknown','claimed') FOR UPDATE");
        $query->execute([$parent['entity_type'],$parent['entity_id'],$parent['operation']]);
        if ($frozen=$query->fetch(PDO::FETCH_ASSOC)) return self::result($frozen);
        if (in_array($parent['status'],['accepted','manual'],true) || isset(self::receipt($parent)['manual_completion'])) return self::result($parent);
        [$payload,$digest]=self::envelope($parent);
        $requestKey='rental-packet-retry:'.$parent['id'].':'.$digest;
        $query=$db->prepare('SELECT * FROM rental_delivery_intents WHERE request_key=? FOR UPDATE');$query->execute([$requestKey]);
        if ($child=$query->fetch(PDO::FETCH_ASSOC)) {
            [$childPayload,$childDigest]=self::envelope($child);
            if (($childPayload['retry_of'] ?? null)!==$parent['id'] || !hash_equals($digest,$childDigest)
                || $child['entity_type']!==$parent['entity_type'] || (int)$child['entity_id']!==(int)$parent['entity_id']
                || (int)$child['entity_version']!==(int)$parent['entity_version']) throw new RuntimeException('История попыток требует сверки.',409);
            if (!in_array($child['status'],['accepted','manual','unknown','claimed'],true)
                && !isset(self::receipt($child)['manual_completion'])) self::currentProof($db,$child);
            return self::result($child);
        }
        self::currentProof($db,$parent);
        if ($parent['status']==='prepared') return self::result($parent);
        $query=$db->prepare("SELECT * FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=? AND status='prepared' FOR UPDATE");
        $query->execute([$parent['entity_type'],$parent['entity_id'],$parent['operation']]);
        if ($pending=$query->fetch(PDO::FETCH_ASSOC)) {self::currentProof($db,$pending);return self::result($pending);}
        $query=$db->prepare("SELECT MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_order')) AS UNSIGNED)) FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=?");
        $query->execute([$parent['entity_type'],$parent['entity_id'],$parent['operation']]);$order=(int)$query->fetchColumn()+1;
        $child=self::prepareChild($parent,$actor,bin2hex(random_bytes(16)),$order);
        $columns=array_keys($child);
        $db->prepare('INSERT INTO rental_delivery_intents (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($child));
        self::audit($db,$actor,'operation.retry','rental_delivery_intent',$child['id'],['parent_id'=>$parent['id']],['root_id'=>$payload['retry_root'] ?? $parent['id'],'wire_sha256'=>$digest]);
        Service::event($db,$actor,'operation.retry','Подготовлена повторная отправка подтверждённого пакета.',
            $parent['entity_type']==='contract'?(int)$parent['entity_id']:(int)$db->query('SELECT contract_id FROM rental_documents WHERE id='.(int)$parent['entity_id'])->fetchColumn());
        return ['id'=>$child['id'],'status'=>'prepared','retry_of'=>$parent['id']];
    }

    public static function manualExisting(PDO $db,string $intentId,array $currentActor): array
    {
        if (!$db->inTransaction()) throw new RuntimeException('Подтверждение требует предметной транзакции.');
        FileOrder::lock($db);$actor=self::currentOperator($db,$currentActor);$intent=self::intent($db,$intentId);
        if (in_array($intent['status'],['accepted','manual'],true) || isset(self::receipt($intent)['manual_completion'])) return self::result($intent);
        $query=$db->prepare("SELECT id FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.retry_of'))=? LIMIT 1 FOR UPDATE");
        $query->execute([$intent['entity_type'],$intent['entity_id'],$intent['operation'],$intentId]);
        if ($query->fetchColumn()) throw new RuntimeException('Появилась новая попытка. Откройте актуальную операцию.',409);
        $payload=self::currentProof($db,$intent);
        $patch=self::prepareManualAnnotation($intent,$actor,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
        if (!$patch['created']) return self::result($intent);
        $db->prepare("UPDATE rental_delivery_intents SET receipt_json=JSON_SET(COALESCE(receipt_json,JSON_OBJECT()),'$.manual_completion',CAST(? AS JSON)) WHERE id=? AND status=?")
            ->execute([Importer::json($patch['annotation']),$intentId,$intent['status']]);
        self::audit($db,$actor,'operation.manual','rental_delivery_intent',$intentId,null,$patch['annotation']);
        Service::event($db,$actor,'operation.manual','Шаг отмечен выполненным вручную. Электронная доставка не подтверждена.',
            $intent['entity_type']==='contract'?(int)$intent['entity_id']:(int)$db->query('SELECT contract_id FROM rental_documents WHERE id='.(int)$intent['entity_id'])->fetchColumn());
        $completion=$intent['status']==='blocked'
            ?self::finishPacket($db,$intent,$payload['manual_packet'],$actor,$patch['annotation']['recorded_at'],true)
            :self::completePacket($db,$intent,$payload,$actor,$patch['annotation']['recorded_at']);
        return ['id'=>$intentId,'status'=>$intent['status'],'manual_completed'=>true]+$completion;
    }

    /** Workflow fulfilment is separate from acceptance of any electronic delivery. */
    private static function completePacket(PDO $db,array $selected,array $payload,array $actor,string $time): array
    {
        $query=$db->prepare("SELECT * FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=? FOR UPDATE");
        $query->execute([$selected['entity_type'],$selected['entity_id'],$selected['operation']]);
        $current=[];
        $superseded=[];
        $digest=FrozenPacketWire::digest($payload);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // An uncertain transport result cannot be converted into fulfilment by a manual mark.
            if (in_array($row['status'],['unknown','claimed'],true)) return ['workflow_completed'=>false];
            $receipt=self::receipt($row);
            if ($row['status']!=='accepted') {
                if (!empty($receipt['accepted'])) return ['workflow_completed'=>false];
                foreach ($receipt['attempts'] ?? [] as $attempt) {
                    if (in_array($attempt['status'] ?? '',['pending','uncertain','unknown','claimed'],true)) {
                        return ['workflow_completed'=>false];
                    }
                }
            }
            if ((int)$row['entity_version']!==(int)$selected['entity_version']) continue;
            // A failed preflight has no client Email wire to fulfil or transport.
            if ($row['status']==='blocked') continue;
            [$wire,$rowDigest]=self::envelope($row);
            if (!hash_equals($digest,$rowDigest)) return ['workflow_completed'=>false];
            $current[$row['id']]=$row;
            if (isset($wire['retry_of'])) $superseded[$wire['retry_of']]=true;
        }
        $pending=[];
        $hasManual=false;
        foreach ($current as $id=>$row) {
            if (isset($superseded[$id])) continue;
            $manual=self::receipt($row)['manual_completion'] ?? null;
            $manual=is_array($manual) && ($manual['source'] ?? '')==='operation-manual'
                && ($manual['accepted'] ?? null)===false;
            if ($row['status']==='manual' || $manual) {$hasManual=true;continue;}
            if ($row['status']==='accepted') continue;
            if ($row['status']==='prepared') $pending[]=$id;
            else return ['workflow_completed'=>false];
        }
        if ($pending) return ['workflow_completed'=>false,'pending_intents'=>$pending];
        if (!isset($current[$selected['id']]) || isset($superseded[$selected['id']])) {
            throw new RuntimeException('История ручного выполнения требует сверки.',409);
        }
        return self::finishPacket($db,$selected,$payload,$actor,$time,$hasManual);
    }

    /** Shared physical/workflow transition; the caller has validated its typed proof and leaves. */
    private static function finishPacket(PDO $db,array $selected,array $payload,array $actor,string $time,bool $hasManual):array
    {
        $kind=$selected['entity_type'];
        $table=$kind==='contract'?'contracts':'rental_documents';
        $update=$db->prepare("UPDATE $table SET status='sent',version=version+1 WHERE id=? AND version=? AND status='approved'");
        $update->execute([$selected['entity_id'],$selected['entity_version']]);
        if ($update->rowCount()!==1) throw new RuntimeException('Переданный пакет требует сверки текущей версии.',409);
        $handedTime=(new DateTimeImmutable($time))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
        foreach ($payload['invoice_versions'] as $invoice) {
            if ($hasManual) FinanceService::remember($db,Service::get($db,'invoices',(int)$invoice['id']),(int)$actor['id']);
            $update=$db->prepare('UPDATE invoices SET '.($hasManual?'':'sent=1,').'version=version+1 WHERE id=? AND version=?');
            $update->execute([$invoice['id'],$invoice['version']]);
            if ($update->rowCount()!==1) throw new RuntimeException('Переданный счёт требует сверки текущей версии.',409);
            if (!$hasManual) continue;
            $latest=InvoiceDelivery::latest($db,(int)$invoice['id']);
            $order=$latest ? (int)(json_decode($latest['payload_json'],true)['delivery_order'] ?? 0) : 0;
            self::audit($db,$actor,'invoice.handed','invoice',$invoice['id'],null,[
                'invoice_version'=>(int)$invoice['version']+1,'handed_at'=>$handedTime,
                'actor_id'=>$actor['id'],'delivery_order'=>$order,'packet_intent_id'=>$selected['id'],
            ]);
        }
        if (!$hasManual) return ['workflow_completed'=>true];
        $fact=[
            'status'=>'sent','record_version'=>(int)$selected['entity_version']+1,
            'sent_at'=>$handedTime,'handed_at'=>$handedTime,'delivery_mode'=>'manual','accepted'=>false,
            'intent_id'=>$selected['id'],'b_binding'=>$payload['b_binding'],
            'legal_file'=>$payload['legal_file'],'invoice_ids'=>$payload['invoice_ids'],
        ];
        self::audit($db,$actor,$kind.'.handed',$kind,$selected['entity_id'],null,$fact);
        $contractId=$kind==='contract'?(int)$selected['entity_id']
            :(int)$db->query('SELECT contract_id FROM rental_documents WHERE id='.(int)$selected['entity_id'])->fetchColumn();
        Service::event($db,$actor,$kind.'.handed','Документы переданы клиенту. Электронная доставка не подтверждена.',$contractId);
        return ['workflow_completed'=>true];
    }

    /** Caller has validated the strict initial B sender and stored the accepted result. */
    public static function completeInitialAccepted(PDO $db,array $intent,array $payload): void
    {
        if (!$db->inTransaction()) throw new RuntimeException('Завершение пакета требует предметной транзакции.');
        [$frozen]=self::envelope($intent);
        if (isset($frozen['retry_of']) || Importer::canonicalJson($frozen)!==Importer::canonicalJson($payload)) {
            throw new RuntimeException('Неверное подтверждение исходной отправки.',409);
        }
        FileOrder::lock($db);
        $stored=self::intent($db,$intent['id']);
        if ($stored['status']!=='accepted' || $stored['baseline_hash']!==$intent['baseline_hash']) {
            throw new RuntimeException('Нет сохранённого подтверждения исходной отправки.',409);
        }
        $context=self::sender($frozen['actor_context']);
        $query=$db->prepare("SELECT u.id,u.name,u.role role_id,u.auth_version,u.active,
            CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END role
            FROM users u LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=? FOR UPDATE");
        $query->execute([$context['id']]);$actor=$query->fetch(PDO::FETCH_ASSOC);
        if (!$actor || !$actor['active'] || !in_array($actor['role'],['accountant','admin'],true)
            || self::sender($actor)!==$context) throw new RuntimeException('Права отправителя изменились.',403);
        self::currentProof($db,$stored);
        $actor['id']=(int)$actor['id'];$actor['auth_version']=(int)$actor['auth_version'];
        self::completePacket($db,$stored,$frozen,$actor,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
    }

    /** Worker settlement only; manual annotations never change transport status. */
    public static function settle(PDO $db,string $id,string $status,array $receipt): void
    {
        if (!$db->inTransaction() || !in_array($status,['accepted','failed','unknown'],true)) throw new RuntimeException('Недопустимый результат отправки.');
        FileOrder::lock($db);$intent=self::intent($db,$id);self::authorizeStored($db,$intent);
        if (in_array($intent['status'],['accepted','unknown'],true)) throw new RuntimeException('Результат уже сохранён; повтор запрещён.');
        $prior=self::receipt($intent);
        if (isset($prior['manual_completion'])) $receipt['manual_completion']=$prior['manual_completion'];
        if ($status==='accepted') {
            if (empty($receipt['accepted'])) throw new RuntimeException('Нет подтверждения транспорта.');
            self::validate($db,$intent);[$payload]=self::envelope($intent);
            $actor=self::operator($db,$payload['sender_context']);
            $db->prepare('UPDATE rental_delivery_intents SET status=?,receipt_json=? WHERE id=?')->execute([$status,Importer::json($receipt),$id]);
            self::completePacket($db,$intent,$payload,$actor,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
            return;
        }
        $db->prepare('UPDATE rental_delivery_intents SET status=?,receipt_json=? WHERE id=?')->execute([$status,Importer::json($receipt),$id]);
    }

    private static function sender(array $sender): array
    {
        foreach (['id','auth_version'] as $key) {
            if (!isset($sender[$key]) || !is_int($sender[$key]) && !is_string($sender[$key])
                || !ctype_digit((string)$sender[$key]) || (int)$sender[$key]<1) {
                throw new RuntimeException('Контекст исполнителя не подтверждён.');
            }
        }
        if (!is_string($sender['role_id'] ?? null) || $sender['role_id']===''
            || mb_strlen($sender['role_id'])>32 || str_contains($sender['role_id'],"\0")) {
            throw new RuntimeException('Контекст роли исполнителя не подтверждён.');
        }
        return ['id'=>(int)$sender['id'],'role_id'=>$sender['role_id'],'auth_version'=>(int)$sender['auth_version']];
    }

    private static function envelope(array $intent): array
    {
        $kind=$intent['entity_type'] ?? '';
        $operation=match($kind) {'contract'=>'contract-packet','document'=>'addendum-packet',default=>null};
        if (!$operation || ($intent['provider'] ?? '')!=='email' || ($intent['operation'] ?? '')!==$operation
            || !preg_match('/^[a-f0-9]{32}$/D',$intent['id'] ?? '')
            || (int)($intent['entity_id'] ?? 0)<1 || (int)($intent['entity_version'] ?? 0)<1) {
            throw new RuntimeException('Операция не относится к подтверждённому пакету.');
        }
        $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Состав исходной операции не подтверждён.');
        $digest=FrozenPacketWire::digest($payload);
        $policy=$kind==='contract'?'rental-approved-packet-v1':'rental-approved-addendum-packet-v1';
        $record=$payload['packet_record'];
        if ($payload['policy']!==$policy || ($record['kind'] ?? '')!==$kind
            || (int)($record['id'] ?? 0)!==(int)$intent['entity_id']
            || (int)($record['version'] ?? 0)!==(int)$intent['entity_version']
            || !ctype_digit((string)($payload['delivery_order'] ?? '')) || (int)$payload['delivery_order']<1) {
            throw new RuntimeException('Состав исходной операции изменился.');
        }
        if (isset($payload['frozen_wire_sha256']) && (!is_string($payload['frozen_wire_sha256'])
            || !hash_equals($digest,$payload['frozen_wire_sha256']))) throw new RuntimeException('Отпечаток исходного письма изменился.');
        $original=self::sender($payload['actor_context']);
        $operator=isset($payload['retry_of'])?self::sender($payload['sender_context'] ?? []):$original;
        if ((int)($intent['actor_id'] ?? 0)!==$operator['id']) throw new RuntimeException('Автор исходной операции изменился.');
        foreach (['retry_of','retry_root'] as $key) {
            if (isset($payload[$key]) && (!is_string($payload[$key]) || !preg_match('/^[a-f0-9]{32}$/D',$payload[$key]))) {
                throw new RuntimeException('История попыток требует сверки.');
            }
        }
        $baseline=hash('sha256',Importer::canonicalJson([$kind,(int)$intent['entity_id'],(int)$intent['entity_version'],$payload]));
        if (!hash_equals((string)($intent['baseline_hash'] ?? ''),$baseline)) throw new RuntimeException('Подтверждение исходной операции изменилось.');
        return [$payload,$digest];
    }

    /** A blocked preflight has physical proof, never a fabricated client Email envelope. */
    private static function blockedEnvelope(array $intent):array
    {
        $payload=json_decode($intent['payload_json'],true,128,JSON_THROW_ON_ERROR);
        if(!is_array($payload))throw new RuntimeException('Ручной состав пакета не подтверждён.',409);
        $keys=array_keys($payload);sort($keys,SORT_STRING);
        if($keys!==['delivery_order','invoice_ids','manual_packet','packet_record','policy','preflight']
            ||!is_array($payload['manual_packet']??null)
            ||($intent['status']??null)!=='blocked'||($intent['provider']??null)!=='email'
            ||!preg_match('/^[a-f0-9]{32}$/D',$intent['id']??''))throw new RuntimeException('Ручной состав пакета не подтверждён.',409);
        $kind=$intent['entity_type'];
        $operation=match($kind){'contract'=>'contract-packet','document'=>'addendum-packet',default=>null};
        $policy=$kind==='contract'?'rental-approved-packet-v1':'rental-approved-addendum-packet-v1';
        $descriptor=ManualPacketDescriptor::verify($payload['manual_packet']);
        $record=['kind'=>$kind,'id'=>(int)$intent['entity_id'],'version'=>(int)$intent['entity_version']];
        if(!$operation||$intent['operation']!==$operation||$payload['policy']!==$policy
            ||!is_int($payload['delivery_order'])||$payload['delivery_order']<1
            ||!is_array($payload['preflight'])||!is_string($payload['preflight']['state']??null)
            ||Importer::canonicalJson($record)!==Importer::canonicalJson($payload['packet_record'])
            ||Importer::canonicalJson($record)!==Importer::canonicalJson($descriptor['packet_record'])
            ||$payload['invoice_ids']!==$descriptor['invoice_ids']
            ||(int)$intent['actor_id']!==$descriptor['original_actor_context']['id'])throw new RuntimeException('Ручной состав пакета изменился.',409);
        $baseline=hash('sha256',Importer::canonicalJson([$kind,(int)$intent['entity_id'],(int)$intent['entity_version'],$payload]));
        if(!hash_equals($intent['baseline_hash'],$baseline))throw new RuntimeException('Подтверждение ручного пакета изменилось.',409);
        return $payload;
    }

    private static function blockedLeaf(PDO $db,array $selected,array $payload):void
    {
        $query=$db->prepare("SELECT * FROM rental_delivery_intents WHERE provider='email' AND entity_type=? AND entity_id=? AND operation=? FOR UPDATE");
        $query->execute([$selected['entity_type'],$selected['entity_id'],$selected['operation']]);
        self::assertBlockedLeafRows($query->fetchAll(PDO::FETCH_ASSOC),$selected,$payload);
    }

    private static function assertBlockedLeafRows(array $rows,array $selected,array $payload):void
    {
        foreach($rows as$row){
            if(in_array($row['status'],['unknown','claimed'],true))throw new RuntimeException('Результат электронной отправки требует сверки.',409);
            $receipt=self::receipt($row);
            // Like completePacket, uncertainty is global, including older B versions.
            if($row['status']!=='accepted'){
                if(!empty($receipt['accepted']))throw new RuntimeException('Результат электронной отправки требует сверки.',409);
                foreach($receipt['attempts']??[]as$attempt)if(in_array($attempt['status']??'',
                    ['pending','uncertain','unknown','claimed'],true))throw new RuntimeException('Результат электронной отправки требует сверки.',409);
            }
            if((int)$row['entity_version']!==(int)$selected['entity_version'])continue;
            if(!empty($receipt['accepted']))throw new RuntimeException('Подтверждённая отправка относится к другому шагу.',409);
            foreach($receipt['attempts']??[]as$attempt)if(in_array($attempt['status']??'',
                ['accepted','pending','uncertain','unknown','claimed'],true))throw new RuntimeException('Результат электронной отправки требует сверки.',409);
            $other=json_decode($row['payload_json'],true,128,JSON_THROW_ON_ERROR);
            if((int)($other['delivery_order']??0)>=$payload['delivery_order']&&$row['id']!==$selected['id'])throw new RuntimeException('Откройте актуальный шаг передачи пакета.',409);
            if(!in_array($row['status'],['blocked','superseded'],true))throw new RuntimeException('Состав шагов пакета требует сверки.',409);
        }
    }

    private static function receipt(array $intent): array
    {
        $raw=$intent['receipt_json'] ?? null;
        if ($raw===null) return [];
        $receipt=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        if (!is_array($receipt)) throw new RuntimeException('Результат исходной операции требует сверки.');
        return $receipt;
    }

    /** The caller must validate current sender/business proof before persisting this DTO. */
    public static function prepareChild(array $parent,array $currentSender,string $childId,int $deliveryOrder): array
    {
        if (($parent['status'] ?? '')!=='failed') throw new RuntimeException('Повтор доступен только после подтверждённой неудачи.');
        $receipt=self::receipt($parent);
        if (array_key_exists('manual_completion',$receipt)) throw new RuntimeException('Ручное выполнение не повторяется автоматически.');
        if (!empty($receipt['accepted'])) throw new RuntimeException('Подтверждённая отправка не повторяется.');
        foreach ($receipt['attempts'] ?? [] as $attempt) {
            if (in_array($attempt['status'] ?? '',['accepted','pending','uncertain','unknown','claimed'],true)) {
                throw new RuntimeException('Результат отправки требует сверки; повтор запрещён.');
            }
        }
        [$payload,$digest]=self::envelope($parent);
        if (!preg_match('/^[a-f0-9]{32}$/D',$childId) || $childId===$parent['id']
            || $childId===($payload['retry_root'] ?? null) || $deliveryOrder<=(int)$payload['delivery_order']) {
            throw new RuntimeException('Не подтверждён логический порядок новой попытки.');
        }
        $root=$payload['retry_root'] ?? $parent['id'];
        if (!is_string($root) || !preg_match('/^[a-f0-9]{32}$/D',$root)
            || isset($payload['retry_of']) && (!isset($payload['retry_root']) || $payload['retry_of']===$parent['id'])) {
            throw new RuntimeException('История попыток требует сверки.');
        }
        $sender=self::sender($currentSender);
        $payload['delivery_order']=$deliveryOrder;
        $payload['retry_of']=$parent['id'];
        $payload['retry_root']=$root;
        $payload['sender_context']=$sender;
        $payload['frozen_wire_sha256']=$digest;
        if (!hash_equals($digest,FrozenPacketWire::digest($payload))) throw new RuntimeException('Содержимое повторной попытки изменилось.');
        return [
            'id'=>$childId,'request_key'=>'rental-packet-retry:'.$parent['id'].':'.$digest,
            'provider'=>'email','operation'=>$parent['operation'],'entity_type'=>$parent['entity_type'],
            'entity_id'=>(int)$parent['entity_id'],'entity_version'=>(int)$parent['entity_version'],
            'baseline_hash'=>hash('sha256',Importer::canonicalJson([$parent['entity_type'],(int)$parent['entity_id'],(int)$parent['entity_version'],$payload])),
            'actor_id'=>$sender['id'],'payload_json'=>Importer::json($payload),'status'=>'prepared','receipt_json'=>null,
        ];
    }

    /** Only the annotation is returned; the SQL owner must preserve the existing receipt. */
    public static function prepareManualAnnotation(array $intent,array $currentSender,string $recordedAt): array
    {
        if (in_array($intent['status'] ?? '',['accepted','manual'],true)) {
            return ['status'=>$intent['status'],'created'=>false,'annotation'=>null];
        }
        if (!in_array($intent['status'] ?? '',['prepared','failed','unknown','claimed','blocked'],true)) {
            throw new RuntimeException('Нет подтверждённой незавершённой операции.');
        }
        if($intent['status']==='blocked')self::blockedEnvelope($intent);else self::envelope($intent);
        $receipt=self::receipt($intent);
        if (isset($receipt['manual_completion'])) {
            $existing=$receipt['manual_completion'];
            if (!is_array($existing) || ($existing['source'] ?? '')!=='operation-manual' || ($existing['accepted'] ?? null)!==false) {
                throw new RuntimeException('Ручное подтверждение требует сверки.');
            }
            self::sender($existing['actor_context'] ?? []);
            return ['status'=>$intent['status'],'created'=>false,'annotation'=>$receipt['manual_completion']];
        }
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$recordedAt,new DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z')!==$recordedAt) throw new RuntimeException('Некорректное время подтверждения.');
        return ['status'=>$intent['status'],'created'=>true,'annotation'=>[
            'actor_context'=>self::sender($currentSender),'recorded_at'=>$recordedAt,'source'=>'operation-manual','accepted'=>false,
        ]];
    }
}
