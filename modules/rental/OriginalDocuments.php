<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

/** The owner's retained Word layouts, with bounded named data slots. */
final class OriginalDocuments
{
    public const TITLES=[
        'office'=>'Договор аренды помещений', 'parking'=>'Договор аренды машино-места',
        'services'=>'Договор оказания услуг', 'handover'=>'Акт приёма-передачи помещений',
        'room-change'=>'Допсоглашение о смене номера помещения',
    ];

    public static function catalog(): array
    {
        return json_decode(file_get_contents(__DIR__.'/templates/original-templates.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function source(string $kind): array
    {
        $definition=self::catalog()[$kind] ?? throw new RuntimeException('Неизвестный исходный шаблон.',422);
        $path=__DIR__.'/templates/'.$definition['filename'];
        if (!hash_equals($definition['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Исходный Word-шаблон изменился.',409);
        return $definition+['path'=>$path];
    }

    public static function missing(array $kinds,array $values): array
    {
        $required=[];
        foreach ($kinds as $kind)foreach(self::source($kind)['fields'] as $field)$required[$field]=true;
        return array_values(array_filter(array_keys($required),static fn($field)=>!is_string($values[$field] ?? null)||trim($values[$field])===''));
    }

    public static function build(string $kind,array $values,string $target): void
    {
        $source=self::source($kind);
        $missing=self::missing([$kind],$values);
        if ($missing)throw new RuntimeException('Заполните данные для документа: '.implode(', ',array_map(self::label(...),$missing)),422);
        DocxTemplate::build($source['path'],array_intersect_key($values,array_flip($source['fields'])),$kind,$target,$source['fields']);
    }

    public static function label(string $field): string
    {
        return [
            'number'=>'Номер договора', 'date'=>'Дата договора', 'date_words'=>'Дата договора прописью',
            'end_date_words'=>'Окончание аренды прописью', 'area'=>'Общая площадь, м²',
            'room_numbers'=>'Внутренние номера помещений / машино-мест', 'building_room_numbers'=>'Номера помещений по документам здания',
            'cadastre_numbers'=>'Кадастровые номера', 'floor'=>'Этаж', 'floor_words'=>'Этаж прописью (на каком этаже)',
            'ownership_record'=>'Номер записи ЕГРН о праве собственности', 'ownership_date_words'=>'Дата записи ЕГРН прописью',
            'party_full_name'=>'Полное наименование арендатора', 'party_short_name'=>'Краткое наименование арендатора',
            'party_signer_title'=>'Должность подписанта', 'party_signer_title_genitive'=>'Должность в обороте «в лице кого»',
            'party_signer_genitive'=>'ФИО подписанта в обороте «в лице кого»', 'party_signer_short'=>'Фамилия и инициалы в подписи',
            'party_authority'=>'Основание полномочий подписанта', 'party_email'=>'Email арендатора',
            'party_address'=>'Юридический адрес арендатора', 'party_postal_address'=>'Почтовый адрес арендатора',
            'party_requisites'=>'Реквизиты арендатора', 'party_inn'=>'ИНН арендатора', 'party_kpp'=>'КПП арендатора (или «не применяется»)',
            'party_ogrn'=>'ОГРН / ОГРНИП арендатора', 'party_bank_line'=>'Расчётный счёт и банк арендатора',
            'party_correspondent'=>'Корреспондентский счёт арендатора', 'party_bik'=>'БИК банка арендатора',
            'vat'=>'Ставка НДС, %', 'rent_net'=>'Аренда без НДС, целые рубли', 'rent_net_words'=>'Аренда без НДС прописью',
            'rent_net_kopecks'=>'Аренда без НДС, копейки', 'rent_vat'=>'Сумма НДС аренды',
            'handover_deadline_words'=>'Крайний срок приёма помещения прописью', 'act_date_words'=>'Дата акта прописью',
            'meter_readings'=>'Показания счётчиков с номерами и единицами', 'passes_count'=>'Количество пропусков для входа',
            'parking_passes_count'=>'Количество брелоков на парковку', 'service_number'=>'Номер договора услуг',
            'service_date_words'=>'Дата договора услуг прописью', 'service_start_words'=>'Начало услуг прописью',
            'service_end_words'=>'Окончание услуг прописью', 'service_gross_words'=>'Стоимость услуг в месяц с НДС (сумма и пропись)',
            'service_vat'=>'НДС услуг', 'addendum_date_words'=>'Дата допсоглашения прописью',
        ][$field] ?? $field;
    }
}
