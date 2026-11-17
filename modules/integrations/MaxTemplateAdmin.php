<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** MAX-specific content for the common administration form. No delivery side effects. */
final class MaxTemplateAdmin
{
    public static function fields(array $entry): string
    {
        $e=Support::e(...);
        $html='<label class="field"><span class="field-label">Формат сообщения <span class="req">*</span></span><select class="field-select" name="format" id="template-format" aria-describedby="template-format-help">';
        foreach(['plain'=>'Обычный текст','markdown'=>'Markdown — выделение и ссылки','html'=>'HTML — теги форматирования'] as $format=>$label) {
            $html.='<option value="'.$format.'"'.(($entry['format']??'plain')===$format?' selected':'').'>'.$e($label).'</option>';
        }
        $html.='</select><span class="registry-ui-modal-help" id="template-format-help">Для простого сообщения выберите обычный текст. Markdown и HTML сохраняют разметку выбранного формата; при переключении проверьте результат в предпросмотре.</span></label>';
        $html.='<label class="field"><span class="field-label">Текст сообщения <span class="req">*</span></span><textarea class="field-textarea" id="template-body" name="body" rows="6" maxlength="4000" aria-describedby="template-body-help" required>'.$e($entry['body_text']??'').'</textarea><span class="registry-ui-modal-help" id="template-body-help">До 4000 символов после подстановки данных. Поля в фигурных скобках, например {number}, заменяются данными события.</span></label>';
        return $html;
    }

    public static function variables(): array
    {
        return [
            'text'=>'Текст события', 'number'=>'Номер договора', 'party'=>'Контрагент',
            'lawyer'=>'Юрист', 'manager'=>'Менеджер', 'accountant'=>'Бухгалтер',
            'director'=>'Директор', 'admin'=>'Администратор', 'version'=>'Версия документа',
            'reason'=>'Причина возврата или изменения', 'invoiceNumbers'=>'Номера счетов',
            'recipientNames'=>'Ответственные получатели', 'actor'=>'Исполнитель действия',
            'documentNumber'=>'Номер документа или допсоглашения', 'effective'=>'Дата начала действия условий',
            'context'=>'Договор и контрагент', 'documentLabel'=>'Название документа с номером',
            'invoiceSummary'=>'Состав счетов', 'effectiveSummary'=>'Описание начала действия условий',
            'managerEmail'=>'Email менеджера',
        ];
    }

    public static function sampleValues(): array
    {
        return [
            'text'=>'Документ направлен на юридическую проверку.', 'number'=>'173',
            'party'=>'ООО «Пример»', 'lawyer'=>'Юрист', 'manager'=>'Менеджер',
            'accountant'=>'Бухгалтер', 'director'=>'Директор', 'admin'=>'Администратор',
            'version'=>'3', 'reason'=>'Уточнить условия оплаты.', 'invoiceNumbers'=>'101, 102',
            'recipientNames'=>'Юрист', 'actor'=>'Менеджер', 'documentNumber'=>'ДС-2',
            'effective'=>'01.12.2026', 'context'=>'Договор №173 · ООО «Пример»',
            'documentLabel'=>'Допсоглашение №ДС-2 к договору №173',
            'invoiceSummary'=>'Счета: 101, 102.',
            'effectiveSummary'=>'Новые условия действуют с 01.12.2026.',
            'managerEmail'=>'manager@example.com',
        ];
    }

    public static function help(): string
    {
        return 'Предпросмотр использует условные данные. В реальном сообщении подставляются данные события; отсутствующие значения могут быть пустыми. Сохранение меняет текст будущих сообщений и не отправляет их. Получатели и правила отправки задаются отдельно. После изменения шаблона ранее согласованный допуск отправки может потребовать повторной проверки.';
    }
}
