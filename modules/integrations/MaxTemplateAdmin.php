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
        $html.='</select><span class="registry-ui-modal-help" id="template-format-help">Для текущей отправки без служебных знаков выберите обычный текст. Markdown и HTML показывают оформление в редакторе, но текущий путь отправки передаёт разметку как текст.</span></label>';
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

    public static function help(array $entry=[]): string
    {
        return 'Предпросмотр использует условные данные. В реальном сообщении подставляются данные события; отсутствующие значения могут быть пустыми. Сохранение обновляет шаблон и не отправляет сообщения. Для подключённых событий новая версия применяется только при действующем допуске отправки; после изменения он может потребовать повторной проверки. Для надёжного отображения текущим отправителем используйте обычный текст: оформление Markdown и HTML при доставке не поддерживается.';
    }

    /** Current server producer: Controller -> ServerWorkflowMax -> CurrentContractMaxProof. */
    public static function usage(array $entry): array
    {
        $key=(string)($entry['source_key']??'');
        if ($key==='staffHandoff') {
            return ['active'=>true,'text'=>'Используется при включённой отправке MAX.'];
        }
        if ($key===\Modules\Rental\HandoverQuestion::EVENT) {
            return ['active'=>true,'text'=>'Вопрос по дате передачи объекта. Отправка зависит от отдельного допуска события и версии шаблона.'];
        }
        // ServerWorkflowMax::KINDS is the exact list admitted by the current server path.
        if(in_array($key,['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'],true)) {
            return ['active'=>true,'text'=>'Подключён к событиям договора. Отправка зависит от действующего допуска MAX и версии шаблона.'];
        }
        // These keys are recognized only by the retained, separately guarded R2 rollout path.
        if(in_array($key,['addendum','activated','block','unblock'],true)) {
            return ['active'=>false,'text'=>'Сохранён для прежнего сценария. В текущей серверной отправке по событиям договора не используется.'];
        }
        return ['active'=>false,'text'=>'Сохранён в каталоге. В текущем приложении отправка по этому шаблону не подключена.'];
    }
}
