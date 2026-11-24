<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;
use PDO;

/** The owner's retained Word layouts, with bounded named data slots. */
final class OriginalDocuments
{
    public const TITLES=[
        'mixed'=>'Смешанный договор аренды',
        'office'=>'Договор аренды помещений', 'parking'=>'Договор аренды машино-места',
        'services'=>'Договор оказания услуг', 'handover'=>'Акт приёма-передачи помещений',
        'room-change'=>'Допсоглашение о смене номера помещения',
        'parking-handover'=>'Акт приёма-передачи машино-места',
        'apartment-person'=>'Договор аренды апартаментов · физическое лицо',
        'office-person'=>'Договор аренды помещений · физическое лицо',
        'parking-person'=>'Договор аренды машино-места · физическое лицо',
    ];
    public const OPTIONAL_FIELDS=['custom_clauses','party_kpp_line','party_signature_title','party_registration_line'];

    public static function optionalFields(string $partyType): array
    {
        return $partyType==='company'?['custom_clauses']:self::OPTIONAL_FIELDS;
    }

    public static function catalog(): array
    {
        return json_decode(file_get_contents(__DIR__.'/templates/original-templates.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function ownerKinds(): array
    {
        return array_intersect_key(self::TITLES,self::catalog());
    }

    public static function source(string $kind,?PDO $db=null): array
    {
        if ($db!==null && ($current=OwnerWordTemplates::current($db,$kind))!==null) return $current;
        $definition=self::catalog()[$kind] ?? throw new RuntimeException('Неизвестный исходный шаблон.',422);
        $path=__DIR__.'/templates/'.$definition['filename'];
        if (!hash_equals($definition['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Исходный Word-шаблон изменился.',409);
        return $definition+['path'=>$path];
    }

    public static function choices(PDO $db,string $kind): array
    {
        if(!isset(self::TITLES[$kind]))throw new RuntimeException('Неизвестный исходный шаблон.',422);
        if (isset(self::ownerKinds()[$kind])) return [0=>self::TITLES[$kind]];
        $result=[];
        foreach($db->query('SELECT * FROM rental_templates WHERE version>0 AND word_file_id IS NOT NULL ORDER BY approved_at DESC,id DESC') as $row) {
            $meta=json_decode($row['source_json'],true)?:[];
            if(($meta['generation_kind']??null)===$kind)$result[(int)$row['id']]=$row['name'].' · версия '.$row['version'];
        }
        return $result;
    }

    public static function resolve(PDO $db,string $kind,int $templateId): array
    {
        if (isset(self::ownerKinds()[$kind]) && $templateId!==0) throw new RuntimeException('Используйте текущий Word-шаблон.',422);
        if(!$templateId) {
            $source=self::source($kind,$db);
            return $source+['template_id'=>0,'template_version'=>$source['version']??1,'kind'=>$kind];
        }
        $q=$db->prepare('SELECT * FROM rental_templates WHERE id=?');$q->execute([$templateId]);$row=$q->fetch();
        if(!$row||!$row['word_file_id']||(int)$row['version']<1||(json_decode($row['source_json'],true)['generation_kind']??null)!==$kind)throw new RuntimeException('Утверждённый шаблон этого вида не найден.',422);
        $asset=TemplateAssets::file($db,$templateId,$row['word_file_id']);$fields=self::fieldsForKind($asset['path'],$kind);
        if(!$fields)throw new RuntimeException('В шаблоне нет полей для подстановки.',422);
        return $asset+['fields'=>$fields,'template_id'=>$templateId,'template_version'=>(int)$row['version'],'kind'=>$kind,
            'template_values'=>['custom_clauses'=>(string)$row['body'],'template_version'=>(string)$row['version']]];
    }

    public static function fieldsForKind(string $path,string $kind): array
    {
        return DocxTemplate::fields($path,str_ends_with($kind,'-person'));
    }

    public static function buildSource(array $source,array $values,string $target,array $plans=[],array $phrases=[]): void
    {
        $values=array_replace($values,$source['template_values']??[]);
        $values+=array_fill_keys(self::OPTIONAL_FIELDS,'');
        $required=array_values(array_diff($source['fields'],self::OPTIONAL_FIELDS,['office_plan']));
        foreach($required as $key)if(!is_string($values[$key]??null)||trim($values[$key])==='')throw new RuntimeException('Заполните: '.self::label($key),422);
        OwnerWordTemplates::withFile($source,static fn(string $path)=>DocxTemplate::build($path,
            array_intersect_key($values,array_flip($source['fields'])),$source['kind'],$target,$required,$plans,phrases:$phrases));
    }

    public static function missing(array $kinds,array $values): array
    {
        $required=[];
        foreach ($kinds as $kind)foreach(array_diff(self::source($kind)['fields'],self::OPTIONAL_FIELDS,['office_plan']) as $field)$required[$field]=true;
        return array_values(array_filter(array_keys($required),static fn($field)=>!is_string($values[$field] ?? null)||trim($values[$field])===''));
    }

    public static function build(string $kind,array $values,string $target,array $plans=[]): void
    {
        $source=self::source($kind)+['kind'=>$kind];
        $missing=self::missing([$kind],$values);
        if ($missing)throw new RuntimeException('Заполните данные для документа: '.implode(', ',array_map(self::label(...),$missing)),422);
        self::buildSource($source,$values,$target,$plans);
    }

    public static function label(string $field): string
    {
        return [
            'number'=>'Номер договора', 'date'=>'Дата договора', 'date_words'=>'Дата договора прописью',
            'security_clause'=>'Согласованное условие об обеспечительном платеже',
            'clause_reference'=>'Номер изменяемого пункта действующего договора (например, 2.1)',
            'end_date_words'=>'Окончание аренды прописью', 'area'=>'Общая площадь, м²',
            'room_numbers'=>'Внутренние номера помещений / машино-мест', 'building_room_numbers'=>'Номера помещений по документам здания',
            'cadastre_numbers'=>'Кадастровые номера', 'floor'=>'Этаж', 'floor_words'=>'Этаж прописью (на каком этаже)',
            'ownership_record'=>'Номер записи ЕГРН о праве собственности', 'ownership_date_words'=>'Дата записи ЕГРН прописью',
            'organization_full_name'=>'Полное наименование Арендодателя', 'organization_short_name'=>'Краткое наименование Арендодателя',
            'organization_signer_short'=>'Подписант Арендодателя, фамилия и инициалы','organization_signer_genitive'=>'Подписант Арендодателя в обороте «в лице кого»',
            'organization_ogrnip'=>'ОГРНИП Арендодателя','organization_inn'=>'ИНН Арендодателя','organization_address'=>'Юридический адрес Арендодателя',
            'organization_postal_address'=>'Почтовый адрес Арендодателя','organization_account'=>'Расчётный счёт Арендодателя','organization_bank'=>'Банк Арендодателя',
            'organization_correspondent'=>'Корреспондентский счёт Арендодателя','organization_bik'=>'БИК банка Арендодателя','organization_registration_date'=>'Дата свидетельства ОГРНИП (дд.мм.гггг)',
            'party_full_name'=>'Полное наименование арендатора', 'party_short_name'=>'Краткое наименование арендатора',
            'party_signer_title'=>'Должность подписанта', 'party_signer_title_genitive'=>'Должность в обороте «в лице кого»',
            'party_signature_name'=>'Полные ФИО для подписи','party_passport'=>'Паспортные сведения',
            'party_intro'=>'Преамбула арендатора','party_signer_name'=>'Полные ФИО подписанта',
            'party_act_intro'=>'Преамбула арендатора в акте','party_act_signature'=>'Подпись арендатора в акте',
            'party_signature_title'=>'Должность подписанта','premises_address'=>'Адрес объекта',
            'lease_duration'=>'Срок аренды прописью',
            'party_signer_genitive'=>'ФИО подписанта в обороте «в лице кого»', 'party_signer_short'=>'Фамилия и инициалы в подписи',
            'party_authority'=>'Основание полномочий подписанта', 'party_email'=>'Email арендатора',
            'party_address'=>'Юридический адрес арендатора', 'party_postal_address'=>'Почтовый адрес арендатора',
            'party_requisites'=>'Реквизиты арендатора', 'party_inn'=>'ИНН арендатора', 'party_kpp'=>'КПП арендатора (или «не применяется»)',
            'party_ogrn'=>'ОГРН / ОГРНИП арендатора', 'party_bank_line'=>'Расчётный счёт и банк арендатора',
            'party_correspondent'=>'Корреспондентский счёт арендатора', 'party_bik'=>'БИК банка арендатора',
            'vat'=>'Ставка НДС, %', 'rent_net'=>'Аренда без НДС, целые рубли', 'rent_net_words'=>'Аренда без НДС прописью',
            'rent_net_kopecks'=>'Аренда без НДС, копейки', 'rent_vat'=>'Сумма НДС аренды',
            'rent_object_rates'=>'Месячные ставки по объектам с НДС',
            'office_subjects'=>'Перечень помещений с площадями, этажами и реквизитами ЕГРН',
            'parking_subjects'=>'Перечень машино-мест с площадями и этажами',
            'handover_deadline_words'=>'Крайний срок приёма помещения прописью', 'act_date_words'=>'Дата акта прописью',
            'meter_readings'=>'Показания счётчиков с номерами и единицами', 'passes_count'=>'Количество пропусков для входа',
            'parking_passes_count'=>'Количество брелоков на парковку', 'service_number'=>'Номер договора услуг',
            'service_date_words'=>'Дата договора услуг прописью', 'service_start_words'=>'Начало услуг прописью',
            'service_end_words'=>'Окончание услуг прописью', 'service_gross_words'=>'Стоимость услуг в месяц с НДС (сумма и пропись)',
            'service_vat'=>'НДС услуг', 'addendum_date_words'=>'Дата допсоглашения прописью',
        ][$field] ?? $field;
    }
}
