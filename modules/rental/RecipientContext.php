<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

/** Source runtime party selection and the current authenticated team identity. */
final class RecipientContext
{
    private static function flag(mixed $value): ?bool
    {
        return match ($value) {
            true,1,'1','true'=>true,
            false,0,'0','false'=>false,
            default=>null,
        };
    }

    public static function clients(array $party): array
    {
        $main=trim((string)($party['email'] ?? ''));
        $extra=trim((string)($party['account_email'] ?? ''));
        $extraSelected=self::flag($party['additional_document_recipient'] ?? null) ?? ($extra !== '');
        $mainSelected=self::flag($party['document_recipient'] ?? null) ?? ($main !== '' && !$extraSelected);
        $result=[];
        foreach ([[$mainSelected,$main],[$extraSelected,$extra]] as [$selected,$address]) {
            if (!$selected || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/D',$address)) continue;
            $result[mb_strtolower($address)]=$address;
        }
        return array_values($result);
    }

    public static function resolve(PDO $db,array $party): array
    {
        $clients=self::clients($party);
        if (!$clients) throw new DeliveryContextException('Не указан Email получателя документов.',['clientRecipients']);
        $members=$db->query(
            "SELECT u.id,u.username,u.name,u.role,u.version,u.auth_version FROM users u "
            ."JOIN role_profiles rp ON rp.role_id=u.role WHERE u.active=1 AND rp.kind='manager' ORDER BY u.id"
        )->fetchAll();
        $managers=[];
        foreach ($members as $member) {
            $address=trim($member['username']);
            if (filter_var($address,FILTER_VALIDATE_EMAIL)) $managers[]=$member;
        }
        if (count($managers)!==1) throw new DeliveryContextException('Для отправки нужен один действующий менеджер с Email в учётной записи.',['managerEmail']);
        $manager=$managers[0];
        $recipients=[];
        foreach (array_merge($clients,[trim($manager['username'])]) as $address) $recipients[mb_strtolower($address)]=$address;
        return [
            'recipients'=>array_values($recipients),'clients'=>$clients,
            'manager_id'=>(int)$manager['id'],'manager_role_id'=>$manager['role'],
            'manager_version'=>(int)$manager['version'],'manager_auth_version'=>(int)$manager['auth_version'],
            'manager'=>$manager['name'] ?: \App\People::roleLabel('manager'),
            'managerEmail'=>trim($manager['username']),
            'party_id'=>(int)$party['id'],'party_version'=>(int)$party['version'],
            'document_recipient'=>self::flag($party['document_recipient'] ?? null),
            'additional_document_recipient'=>self::flag($party['additional_document_recipient'] ?? null),
        ];
    }
}
