<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Presentation metadata only. WorkflowNotices remains authoritative for events and severity. */
final class TopTemplateAdmin
{
    private const ACTIVE_KEYS = [
        'task-received', 'document-submitted', 'agreement-submitted',
        'document-approved', 'document-returned', 'packet-confirmed',
    ];

    public static function fields(array $entry): string
    {
        $e = Support::e(...);
        return '<label class="field" for="template-title-field"><span class="field-label">Заголовок <span class="req">*</span></span>'
            .'<input class="field-input" id="template-title-field" name="title" value="'.$e((string)($entry['title'] ?? '')).'" maxlength="80" required aria-describedby="template-title-help">'
            .'<span class="registry-ui-modal-help" id="template-title-help">Кратко назовите результат или задачу. До 80 символов с подстановками.</span></label>'
            .'<label class="field" for="template-body"><span class="field-label">Текст уведомления <span class="req">*</span></span>'
            .'<textarea class="field-textarea" id="template-body" name="body" rows="5" maxlength="600" required aria-describedby="template-body-help">'.$e((string)($entry['body_text'] ?? '')).'</textarea>'
            .'<span class="registry-ui-modal-help" id="template-body-help">Объясните, что произошло и что делать дальше. До 600 символов; обычный текст.</span></label>';
    }

    /** Labels explicitly distinguish the workflow's populated fields from reserved catalog fields. */
    public static function variables(): array
    {
        return [
            'record' => 'Документ и номер, например «Договор №173»',
            'text' => 'Текст события или текущей задачи',
            'why' => 'Причина возврата или описание текущей задачи',
            'section' => 'Раздел задачи; для входящей задачи используется системное имя раздела',
            'actor' => 'Имя сотрудника, выполнившего действие',
            'number' => 'Номер договора или документа',
            'party' => 'Наименование контрагента',
            'title' => 'Служебный заголовок; в событиях согласования «Задача по документу»',
            'template' => 'Название шаблона; в событиях согласования не заполняется',
            'details' => 'Дополнительные сведения; в событиях согласования не заполняются',
        ];
    }

    public static function sampleValues(): array
    {
        return [
            'record' => 'Договор №173', 'text' => 'Документ направлен на проверку.',
            'why' => 'Проверьте условия и актуальный файл документа.',
            'section' => 'approvals', 'actor' => 'Анна Иванова', 'number' => '173',
            'party' => 'ООО «Пример»', 'title' => 'Задача по документу',
            'template' => '', 'details' => '',
        ];
    }

    public static function help(): string
    {
        return 'Редактируются заголовок и текст. Цвет задаёт результат действия или текущая задача. '
            .'Получатели, условия появления и время показа 10 секунд определяются системой. '
            .'Закрытие уведомления не снимает задачу. Предпросмотр использует учебные данные.';
    }

    /** Exact current producer coverage, not a promise inferred from the imported catalog's name. */
    public static function usage(array $entry): array
    {
        $active = in_array((string)($entry['source_key'] ?? ''), self::ACTIVE_KEYS, true);
        return ['active' => $active, 'text' => $active
            ? 'Используется в уведомлениях согласования документов.'
            : 'Сохранён в каталоге. В текущем приложении событие этого шаблона не подключено.'];
    }

    /** Pass tone/title/message to the existing PlanexNotifications.show; never create a second toast renderer. */
    public static function previewConfig(array $entry): array
    {
        $computed = ($entry['source_key'] ?? '') === 'task-received' || ($entry['tone'] ?? '') === 'computed';
        $tone = $computed ? 'warning' : (string)($entry['tone'] ?? 'success');
        if (!in_array($tone, ['success', 'warning', 'danger', 'info'], true)) $tone = 'success';
        $values = self::sampleValues();
        if (!$computed) {
            $values['section'] = 'Согласования';
            $values['why'] = 'Уточните сроки в документе.';
        }
        if (in_array($entry['source_key'] ?? '', ['document-submitted', 'agreement-submitted'], true)) $values['text'] = $values['party'];
        if (($entry['source_key'] ?? '') === 'agreement-submitted') $values['record'] = 'Документ №173';
        return ['tone' => $tone, 'values' => $values, 'computedTone' => $computed,
            'toneHelp' => $computed
                ? 'Для примера показана жёлтая задача. В работе цвет определяется её текущей важностью.'
                : 'Цвет предпросмотра соответствует типу события; шаблон его не меняет.'];
    }
}
