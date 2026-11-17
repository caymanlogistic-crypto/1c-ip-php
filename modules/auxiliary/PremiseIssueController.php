<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Http,View};
use RuntimeException;

/** Source S linked_correction.html, scoped to premise causes and OWNER read-only facts. */
final class PremiseIssueController
{
    public static function details(array $state): string
    {
        $html = '<section class="panel-section ui-detail-card"><details><summary class="registry-ui-primary">Открытые вопросы ('.$state['count'].') и основания исправления</summary><ul>';
        foreach ($state['issues'] as $issue) $html .= '<li><span class="field-msg is-error">'.View::e($issue['label']).'</span> · '.View::e($issue['key']).' <a href="/auxiliary/premises?resolve='.rawurlencode($issue['key']).'">Исправить или подтвердить с основанием</a></li>';
        return $html.'</ul></details></section>';
    }

    public static function index(): string
    {
        $key = (string)($_POST['issue_key'] ?? $_GET['resolve'] ?? '');
        $state = PremiseIssues::state();
        $issue = $state['issues'][$key] ?? throw new RuntimeException('Вопрос не найден или уже подтверждён.', 404);
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Http::checkCsrf();
            try {
                PremiseIssues::correct($_POST);
                Http::redirect('/auxiliary/premises');
            } catch (RuntimeException $failure) {
                if (!in_array($failure->getCode(), [409,422], true)) throw $failure;
                $error = $failure->getMessage();
                http_response_code(400);
            }
        }
        $html = '<section class="panel-section ui-detail-card"><h2 class="section-title">Исправление источника или документированное подтверждение</h2><p><span class="field-msg is-error">'.View::e($issue['label']).' '.View::e($error).'</span></p><p class="registry-ui-secondary">'.View::e($key).'. Изменение исходного поля пересчитывает зависимые реестры. Подтверждение действует только для текущих исходных значений; при изменении источника его нужно проверить заново.</p>';
        if (ConfirmedPremises::editable() && !str_starts_with($key, 'inventory:')) {
            $html .= '<form method="post" action="/auxiliary/premises?resolve='.rawurlencode($key).'"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="issue_key" value="'.View::e($key).'"><input type="hidden" name="fingerprint" value="'.View::e($issue['fingerprint']).'"><label class="field"><span class="field-label">Сущность (ID объекта либо клиента)</span><select class="field-select" name="entity">';
            foreach ($issue['entities'] as $entity) $html .= '<option>'.View::e($entity).'</option>';
            $html .= '</select></label><label class="field"><span class="field-label">Действие</span><select class="field-select" name="action"><option value="source">Исправление поля или подтверждение вопроса</option><option value="vacancy">Документированно свободный период физического объекта</option></select></label><label class="field"><span class="field-label">Свободен с (только для свободного периода)</span><input class="field-input" type="date" name="vacancy_start"></label><label class="field"><span class="field-label">Свободен по</span><input class="field-input" type="date" name="vacancy_end"></label><label class="field"><span class="field-label">Поле источника (пустое — документированное подтверждение)</span><select class="field-select" name="field">';
            foreach (['' => 'Подтверждение с основанием','area' => 'Площадь','designation' => 'Обозначение','cadastral_number' => 'Кадастровый номер','email' => 'Email','phone' => 'Телефон','edo_operator' => 'Оператор ЭДО','edo_id' => 'Идентификатор участника ЭДО','net' => 'Месячная цена без НДС','vat' => 'НДС','gross' => 'Месячная цена с НДС','start' => 'Начало периода','end' => 'Окончание периода'] as $field => $label) $html .= '<option value="'.View::e($field).'">'.View::e($label).'</option>';
            $html .= '</select></label><label class="field"><span class="field-label">Установленное значение</span><input class="field-input" name="value"></label><label class="field"><span class="field-label">Решение</span><textarea class="field-textarea" name="decision"></textarea></label><label class="field"><span class="field-label">Проверяемое основание / документ</span><textarea class="field-textarea" name="evidence" required minlength="10"></textarea></label><button class="btn btn-primary">Сохранить и пересчитать</button></form>';
        } else {
            $html .= '<p class="registry-ui-primary">Вопросы состава требуют нового доказательного соответствия OWNER, а не ручного изменения счётчика. Изменение остальных реквизитов доступно пользователю с правом редактирования. Локальный просмотр и статическая песочница не изменяют принятый снимок.</p>';
        }
        return View::page('Исправление источника', $html.'<a class="btn btn-toolbar" href="/auxiliary/premises">Вернуться к реестру</a></section>');
    }
}
