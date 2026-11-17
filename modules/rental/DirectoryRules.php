<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

final class DirectoryRules
{
    public static function organization(array $input): array
    {
        $fields=[];
        foreach (['name','inn','ogrnip','address','contact','phone','email','postal_address','edo','edo_id'] as $key) {
            $fields[$key]=trim((string)($input[$key] ?? ''));
        }
        if ($fields['name']==='' || !preg_match('/^(\d{10}|\d{12})$/D',$fields['inn'])
            || $fields['ogrnip']!=='' && !preg_match('/^\d{15}$/D',$fields['ogrnip'])) throw new RuntimeException('Укажите наименование, корректные ИНН и ОГРНИП.',422);
        if (!filter_var($fields['email'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Укажите корректную электронную почту организации.',422);
        if (!isset($input['vat']) || trim((string)$input['vat'])==='') throw new RuntimeException('Укажите ставку НДС.',422);
        $fields['vat']=Service::money($input['vat']);
        $fields['vat_from']=Service::date((string)($input['vat_from'] ?? ''));
        $fields['postal_same_as_legal']=self::boolean($input['postal_same_as_legal'] ?? 0);
        if ($fields['postal_same_as_legal']) $fields['postal_address']=$fields['address'];
        return $fields;
    }

    public static function boolean(mixed $value): int
    {
        return match($value) {
            true,1,'1','true'=>1,
            false,0,'0','false'=>0,
            default=>throw new RuntimeException('Выберите корректное значение переключателя.',422),
        };
    }

    public static function bank(array $input): array
    {
        $bank=[];
        foreach (['bank','account','bik','correspondent_account'] as $key) $bank[$key]=trim((string)($input[$key] ?? ''));
        if ($bank['bank']==='' || mb_strlen($bank['bank'])>255 || !preg_match('/^\d{20}$/D',$bank['account'])
            || !preg_match('/^\d{9}$/D',$bank['bik']) || !preg_match('/^\d{20}$/D',$bank['correspondent_account'])) {
            throw new RuntimeException('Заполните наименование банка, расчётный и корреспондентский счета по 20 цифр и БИК из 9 цифр.',422);
        }
        return $bank;
    }

    public static function party(array $input,?array $old): array
    {
        if (!empty($old['blocked'])) throw new RuntimeException('Работа с контрагентом заблокирована.',403);
        $name=trim((string)($input['name'] ?? ''));
        $inn=trim((string)($input['inn'] ?? ''));
        if ($name==='' || mb_strlen($name)>255) throw new RuntimeException('Введите полное наименование.',422);
        if ($inn!=='' && !preg_match('/^(\d{10}|\d{12})$/D',$inn)) throw new RuntimeException('ИНН должен содержать 10 или 12 цифр.',422);
        $fields=['name'=>$name,'inn'=>$inn];
        foreach (['address','contact','phone','email','account_email','additional_contact','additional_phone','postal_address','edo','edo_id','description','kpp','legal_address'] as $key) {
            if (array_key_exists($key,$input)) $fields[$key]=trim((string)$input[$key]);
        }
        foreach (['postal_same_as_legal','document_recipient','additional_document_recipient'] as $key) {
            if (array_key_exists($key,$input)) $fields[$key]=self::boolean($input[$key]);
        }
        foreach (['document_recipient'=>'email','additional_document_recipient'=>'account_email'] as $flag=>$email) {
            $selected=$fields[$flag] ?? ($old[$flag] ?? null);
            if (in_array($selected,[1,'1',true,'true'],true)) {
                $value=$fields[$email] ?? $old[$email] ?? '';
                if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/D',$value)) throw new RuntimeException('Укажите корректный Email выбранного получателя документации.',422);
            }
        }
        if (!empty($fields['postal_same_as_legal'])) $fields['postal_address']=$fields['address'] ?? $old['address'] ?? '';
        return $fields;
    }
}
