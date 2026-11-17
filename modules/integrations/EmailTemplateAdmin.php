<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Email-specific copy and fields for the shared template administration screen. */
final class EmailTemplateAdmin
{
    public static function fields(array $entry): string
    {
        $e=Support::e(...);
        return '<label class="field" for="template-subject"><span class="field-label">Тема письма <span class="req">*</span></span>'
            .'<input class="field-input" id="template-subject" name="subject" value="'.$e((string)($entry['subject']??'')).'" maxlength="200" required aria-describedby="template-subject-help">'
            .'<span class="registry-ui-modal-help" id="template-subject-help">Одна строка, до 200 символов с учётом подстановок.</span></label>'
            .'<label class="field" for="template-body"><span class="field-label">Текст письма <span class="req">*</span></span>'
            .'<textarea class="field-textarea" id="template-body" name="body" rows="8" maxlength="4000" required aria-describedby="template-body-help">'.$e((string)($entry['body_text']??'')).'</textarea>'
            .'<span class="registry-ui-modal-help" id="template-body-help">Обычный текст с переносами строк, до 4000 символов с учётом подстановок. HTML и Markdown будут показаны как текст.</span></label>';
    }

    /** Only offer values actually supplied by the corresponding delivery producer. */
    public static function variables(array $entry=[]): array
    {
        $variables=[
            'number'=>'Номер договора',
            'party'=>'Наименование контрагента',
            'manager'=>'Имя менеджера',
            'managerEmail'=>'Email менеджера',
            'invoiceNumbers'=>'Номера приложенных счетов',
            'documentLabel'=>'Название и номер документа',
            'documentNumber'=>'Номер договора или допсоглашения',
            'version'=>'Версия документа',
            'effective'=>'Дата начала действия документа',
            'effectiveSummary'=>'Пояснение о начале действия условий',
            'invoiceSummary'=>'Пояснение о составе счетов',
            'accountant'=>'Имя бухгалтера',
            'lawyer'=>'Имя юриста',
        ];
        $keys=match($entry['source_key']??''){
            'invoice'=>['number','party','manager','managerEmail','invoiceNumbers'],
            'legalApproval'=>['accountant','lawyer','documentLabel','party','version'],
            'clientPackage'=>['number','party','manager','managerEmail','invoiceNumbers','documentLabel','documentNumber','version','effective','effectiveSummary','invoiceSummary'],
            default=>array_keys($variables),
        };
        return array_intersect_key($variables,array_flip($keys));
    }

    /** Neutral examples, with unavailable values blank exactly as in TemplateCatalog. */
    public static function sampleValues(array $entry=[]): array
    {
        $values=[
            'number'=>'173',
            'party'=>'ООО «Пример»',
            'manager'=>'Менеджер по аренде',
            'managerEmail'=>'manager@example.com',
            'invoiceNumbers'=>'2026-115, 2026-116',
            'documentLabel'=>'Договор №173',
            'documentNumber'=>'173',
            'version'=>'3',
            'effective'=>'2026-12-01',
            'effectiveSummary'=>'Условия и даты действия указаны в приложенном документе.',
            'invoiceSummary'=>'Счета: 2026-115, 2026-116.',
            'accountant'=>'Бухгалтер',
            'lawyer'=>'Юрист',
        ];
        return array_replace(array_fill_keys(TemplateCatalog::MESSAGE_VARS,''),array_intersect_key($values,self::variables($entry)));
    }

    public static function help(array $entry=[]): string
    {
        $what=match($entry['source_key']??''){
            'invoice'=>'Файл счёта прикладывается при отправке автоматически.',
            'legalApproval'=>'Это сохранённый шаблон проекта бухгалтеру. Отправка письма по этому событию пока не подключена.',
            default=>'Состав вложений определяется подтверждённым пакетом документов.',
        };
        $example=($entry['source_key']??'')==='legalApproval'?'{documentLabel}':'{number}';
        return '<span class="registry-ui-modal-help">'.$what.' Получатели определяются документом и его контактами. В этом разделе изменяются тема и текст письма; сохранение не отправляет письмо.</span>'
            .'<span class="registry-ui-modal-help">В предпросмотре используются вымышленные данные. Подстановки вставляются в фигурных скобках, например '.$example.'. Список ниже содержит данные для выбранного шаблона.</span>';
    }

    public static function usage(array $entry): array
    {
        return match($entry['source_key']??''){
            'invoice'=>['text'=>'Используется при подготовке письма со счётом.'],
            'clientPackage'=>['text'=>'Используется при подготовке подтверждённого пакета клиенту и менеджеру.'],
            'legalApproval'=>['text'=>'Сохранён в каталоге. Отправка письма по этому событию пока не подключена.'],
            default=>['text'=>'Сохранён в каталоге. Применение зависит от обработчика события.'],
        };
    }
}
