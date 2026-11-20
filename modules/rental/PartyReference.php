<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,Http};
use RuntimeException;

final class PartyReference
{
    private const FIELDS=[
        'inn'=>['name','search_name','kpp','ogrn','ogrnip','address','signer','signer_position'],
        'bik'=>['bank','correspondent_account'],
    ];

    public static function lookup(): never
    {
        $id=(int)($_GET['id'] ?? 0);
        Access::pages(['parties'],2);
        Service::roles(DirectoryRules::partySaveRoles($id));
        \App\PagePolicy::requireCommand('party.save');
        if ($id && !empty(Service::get(DB::pdo(),'parties',$id)['blocked']))
            throw new RuntimeException('Работа с контрагентом заблокирована.',403);
        $key=(string)($_GET['kind'] ?? '');
        $value=trim((string)($_GET['value'] ?? ''));
        $valid=match($key) {
            'inn'=>preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',$value),
            'bik'=>preg_match('/^[0-9]{9}$/D',$value),
            default=>false
        };
        if (!$valid) throw new RuntimeException('Проверьте ИНН или БИК.',422);
        if ($key==='inn' && $id && trim((string)Service::get(DB::pdo(),'parties',$id)['inn'])!==$value)
            throw new RuntimeException('Для обновления укажите ИНН сохранённой карточки.',422);
        $fixture=APP_ROOT.'/.local/party-reference.json';
        if (PHP_SAPI!=='cli-server' || !getenv('APP_LOCAL_SESSION_NAME') || !is_file($fixture)
            || !str_starts_with((string)DB::pdo()->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_')) {
            Http::json(['source'=>null,'values'=>[]]);
        }
        $reference=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
        $values=[];
        foreach (self::FIELDS[$key] as $field) {
            $candidate=$reference[$key][$value][$field] ?? null;
            if (is_string($candidate) && trim($candidate)!=='') $values[$field]=trim($candidate);
        }
        Http::json(['source'=>'Локальные учебные данные','values'=>$values]);
    }
}
