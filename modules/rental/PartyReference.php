<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,Http};
use Modules\Integrations\DadataLookup;
use RuntimeException;

final class PartyReference
{
    private const FIELDS=[
        'inn'=>['name','search_name','kpp','ogrn','ogrnip','address','signer','signer_position'],
        'bik'=>['bank','correspondent_account'],
    ];

    public static function lookup(): never
    {
        if ($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Используйте форму контрагента.',405);
        $id=(int)($_POST['id'] ?? 0);
        Access::pages(['parties'],2);
        $user=Service::roles(DirectoryRules::partySaveRoles($id));
        Auth::requirePermission(Service::commandGrant('party.save',$user));
        \App\PagePolicy::requireCommand('party.save');
        if ($id && !empty(Service::get(DB::pdo(),'parties',$id)['blocked']))
            throw new RuntimeException('Работа с контрагентом заблокирована.',403);
        header('Cache-Control: no-store');
        $key=(string)($_POST['kind'] ?? '');
        $value=trim((string)($_POST['value'] ?? ''));
        $valid=match($key) {
            'inn'=>preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',$value),
            'bik'=>preg_match('/^[0-9]{9}$/D',$value),
            default=>false
        };
        if (!$valid) self::reject($key==='bik'?'Укажите БИК из 9 цифр.':'Укажите ИНН из 10 или 12 цифр.',422);
        if ($key==='inn' && $id && trim((string)Service::get(DB::pdo(),'parties',$id)['inn'])!==$value)
            self::reject('Для обновления укажите ИНН сохранённой карточки.',422);
        $local=PHP_SAPI==='cli-server' && !getenv('APP_STAND') && getenv('APP_LOCAL_SESSION_NAME')
            && str_starts_with((string)DB::pdo()->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_');
        try {
            if ($local && is_file(APP_ROOT.'/.local/party-reference.json')) {
                self::legacyFixture($key,$value);
            }
            $kpp=$key==='inn' && strlen($value)===10? strtoupper(trim((string)($_POST['kpp'] ?? ''))):'';
            if ($kpp!=='' && !preg_match('/^[0-9]{4}[0-9A-Z]{2}[0-9]{3}$/D',$kpp)) {
                self::reject('Проверьте КПП или оставьте поле пустым.');
            }
            if ($id && $key==='inn' && ($_POST['type'] ?? '')!==(strlen($value)===10?'company':'entrepreneur')) {
                self::reject('Выберите тип «'.(strlen($value)===10?'Организация':'ИП').'» и повторите поиск.');
            }
            $fixture=APP_ROOT.'/.local/party-reference-dadata.json';
            $external=false;
            if ($local && is_file($fixture)) {
                $responses=json_decode(file_get_contents($fixture),true,32,JSON_THROW_ON_ERROR);
                $suggestions=DadataLookup::suggestions($responses[$key][$value] ?? ['suggestions'=>[]]);
                $source='Учебный ответ в формате DaData';
            } elseif ($id===0 && DadataLookup::available()) {
                $suggestions=DadataLookup::read($key,$value,$kpp);
                $source='DaData';
                $external=true;
            } else {
                self::reject('Справочный источник недоступен. Реквизиты можно ввести вручную.');
            }
            Http::json(['source'=>$source,'external'=>$external]+self::project($key,$value,$kpp,$suggestions));
        } catch (\RuntimeException|\JsonException) {
            self::reject('Не удалось получить реквизиты. Введите их вручную или повторите поиск позже.');
        }
    }

    public static function project(string $kind,string $number,string $kpp,array $suggestions): array
    {
        $matches=[];
        $type=strlen($number)===10?'LEGAL':'INDIVIDUAL';
        foreach ($suggestions as $suggestion) {
            $data=$suggestion['data'] ?? null;
            if (!is_array($data)) continue;
            $exact=match ($kind) {
                'bik'=>($data['bic'] ?? null)===$number,
                'inn'=>($data['inn'] ?? null)===$number && ($data['type'] ?? null)===$type
                    && ($kpp==='' || ($data['kpp'] ?? null)===$kpp),
                default=>false,
            };
            if ($exact) $matches[]=$data;
        }
        if (!$matches) {
            $error=match (true) {
                $kind==='bik'=>'Банк с таким БИК не найден.',
                $kpp!==''=>'Организация с таким ИНН и КПП не найдена. Проверьте КПП.',
                default=>'Точные реквизиты не найдены. Проверьте ИНН или заполните поля вручную.',
            };
            return ['values'=>[],'error'=>$error,'field'=>$kind==='inn' && $kpp!==''?'kpp':null];
        }
        if (count($matches)>1) {
            $organization=$kind==='inn' && $type==='LEGAL';
            $ogrns=array_unique(array_map(static fn(array $data): string=>(string)($data['ogrn'] ?? ''),$matches));
            $branches=$organization && $kpp==='' && count($ogrns)===1 && self::digits($ogrns[0],13)!==null
                && count(array_unique(array_column($matches,'kpp')))>1;
            $error=match (true) {
                $kind==='bik'=>'Найдено несколько записей для этого БИК. Сверьте реквизиты банка.',
                $type==='INDIVIDUAL'=>'Найдено несколько регистраций ИП с этим ИНН. Сверьте ОГРНИП по документам клиента.',
                $branches=>'По этому ИНН найдено несколько подразделений организации. Укажите КПП нужного подразделения.',
                $kpp===''=>'Найдено несколько записей по ИНН. Укажите КПП нужной организации или филиала.',
                default=>'Найдено несколько записей с этим ИНН и КПП. Сверьте реквизиты организации.',
            };
            return ['values'=>[],'error'=>$error,'field'=>$organization?'kpp':null,'partyType'=>$organization?'company':null];
        }
        $data=$matches[0];
        $message=null;
        if ($kind==='bik') {
            $values=['bank'=>$data['name']['payment'] ?? null,
                'correspondent_account'=>self::digits($data['correspondent_account'] ?? null,20)];
            $message='Расчётный счёт возьмите из реквизитов клиента.';
        } elseif ($data['type']==='INDIVIDUAL') {
            $name=$data['name']['full'] ?? $data['name']['full_with_opf'] ?? $data['fio']['source'] ?? null;
            $values=['name'=>$name,'search_name'=>$name,'ogrnip'=>self::digits($data['ogrn'] ?? null,15)];
            $message='Полный адрес ИП укажите по его документам.';
        } else {
            $values=['name'=>$data['name']['full_with_opf'] ?? $data['name']['full'] ?? null,
                'search_name'=>$data['name']['short_with_opf'] ?? $data['name']['short'] ?? null,
                'kpp'=>$data['kpp'] ?? null,'ogrn'=>self::digits($data['ogrn'] ?? null,13),
                'address'=>$data['address']['unrestricted_value'] ?? $data['address']['value'] ?? null];
        }
        $values=array_map(static fn(mixed $value): string=>is_string($value)? trim($value):'',$values);
        $values=array_filter($values,static fn(string $value): bool=>$value!=='');
        return $values?['values'=>$values,'message'=>$message,'partyType'=>$kind==='inn'?($data['type']==='LEGAL'?'company':'entrepreneur'):null]
            :['values'=>[],'error'=>'Справочник не вернул реквизиты для подстановки.'];
    }

    private static function digits(mixed $value,int $length): ?string
    {
        return is_string($value) && preg_match('/^[0-9]{'.$length.'}$/D',$value)? $value:null;
    }

    private static function legacyFixture(string $key,string $value): never
    {
        $reference=json_decode(file_get_contents(APP_ROOT.'/.local/party-reference.json'),true,32,JSON_THROW_ON_ERROR);
        $values=[];
        foreach (self::FIELDS[$key] as $field) {
            $candidate=$reference[$key][$value][$field] ?? null;
            if (is_string($candidate) && trim($candidate)!=='') $values[$field]=trim($candidate);
        }
        Http::json(['source'=>'Локальные учебные данные','values'=>$values]);
    }

    private static function reject(string $message,int $status=200): never
    {
        Http::json(['source'=>null,'values'=>[],'error'=>$message],$status);
    }
}
