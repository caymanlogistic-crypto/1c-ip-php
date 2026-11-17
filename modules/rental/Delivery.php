<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

/** Compatibility entry point. ContractDelivery is the sole packet producer authority. */
final class Delivery
{
    /** Presentation only: a manual mark never changes the stored transport status. */
    public static function manualMarked(?string $receipt): bool
    {
        if ($receipt===null) return false;
        $value=json_decode($receipt,true);
        $mark=is_array($value)?($value['manual_completion'] ?? null):null;
        if (!is_array($mark) || ($mark['source'] ?? '')!=='operation-manual'
            || ($mark['accepted'] ?? null)!==false) return false;
        $actor=$mark['actor_context'] ?? [];
        if (!is_array($actor)) return false;
        foreach (['id','auth_version'] as $key) {
            if (!isset($actor[$key]) || !is_int($actor[$key]) && !is_string($actor[$key])
                || !ctype_digit((string)$actor[$key]) || (int)$actor[$key]<1) return false;
        }
        return is_string($actor['role_id'] ?? null) && $actor['role_id']!==''
            && is_string($mark['recorded_at'] ?? null)
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$mark['recorded_at'] ?? '')===1;
    }

    public static function prepare(PDO $db,array $actor,array $contract): array
    {
        return ContractDelivery::prepare($db,$actor,$contract);
    }

    public static function prepareDocument(PDO $db,array $actor,array $document): array
    {
        return ContractDelivery::prepareDocument($db,$actor,$document);
    }

    public static function validate(PDO $db,array $intent): void
    {
        ContractDelivery::validate($db,$intent);
    }

    public static function settle(PDO $db,string $id,string $status,array $receipt): void
    {
        ContractDelivery::settle($db,$id,$status,$receipt);
    }

    public static function presentation(PDO $db,string $kind,int $id): ?array
    {
        if ($kind==='contract') Access::contract($id);
        elseif ($kind==='document') Access::document($id);
        else throw new RuntimeException('Неизвестный вид пакета.',403);
        $operation=$kind==='contract'?'contract-packet':'addendum-packet';
        $query=$db->prepare("SELECT id,status,payload_json,receipt_json FROM rental_delivery_intents WHERE entity_type=? AND entity_id=? AND operation=? ORDER BY CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_order')),'0') AS UNSIGNED) DESC LIMIT 1");
        $query->execute([$kind,$id,$operation]);
        $intent=$query->fetch();
        if (!$intent) return null;
        $payload=json_decode($intent['payload_json'],true) ?: [];
        $manual=self::manualMarked($intent['receipt_json']);
        $status=$manual && in_array($intent['status'],['prepared','failed'],true)?'manual':$intent['status'];
        $message=match($status) {
            'blocked'=>$payload['preflight']['message'] ?? 'Проверьте состав пакета перед отправкой.',
            'prepared'=>'Пакет подготовлен. Отправка ещё не подтверждена.',
            'claimed'=>'Результат отправки уточняется.',
            'unknown'=>'Результат отправки неизвестен. Нужна сверка; повторная отправка запрещена.',
            'accepted'=>'Пакет отправлен.',
            'failed'=>'Не удалось отправить пакет. Проверьте документы и доставку.',
            'manual'=>'Документы переданы вручную. Транспорт не подтверждён.',
            default=>'',
        };
        return ['id'=>$intent['id'],'status'=>$status,'raw_status'=>$intent['status'],'manual_marked'=>$manual,'message'=>$message,
            'error'=>in_array($status,['blocked','failed','unknown'],true)];
    }
}
