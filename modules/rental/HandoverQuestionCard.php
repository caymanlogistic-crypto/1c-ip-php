<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, DB, PagePolicy, View};
use RuntimeException;

final class HandoverQuestionCard
{
    public const TITLES = [
        HandoverQuestion::OPEN=>'Сообщить о сдвиге даты передачи',
        HandoverQuestion::RESOLVE=>'Записать решение по дате передачи',
        HandoverQuestion::RETRY=>'Повторить уведомление MAX',
        HandoverQuestion::RECONCILE=>'Записать результат сверки MAX',
    ];

    private static function can(string $action, array $contract): bool
    {
        $actor = Auth::user();
        return HandoverQuestion::can($action, $actor, $contract)
            && PagePolicy::level(PagePolicy::commandPage($action), $actor) >= 2;
    }

    public static function section(array $contract): string
    {
        $db = DB::pdo();
        $id = (int)$contract['id'];
        $case = HandoverQuestion::read($db, $id);
        $actions = [];
        $body = '';
        if ($case) {
            $delivery = HandoverQuestion::delivery($db, $id, $case);
            $status = match ($delivery['status']) {
                'local'=>'Вопрос сохранён в карточке для бухгалтера',
                'accepted'=>'MAX принял уведомление',
                'failed'=>'Уведомление MAX не доставлено',
                'reconciled_accepted'=>'Доставка MAX подтверждена сотрудником',
                'reconciled_failed'=>'По результатам сверки MAX не принял уведомление',
                'pending'=>'Отправка MAX ожидает подтверждения',
                'uncertain'=>'Результат MAX неизвестен. Сначала сверьте доставку в группе MAX',
                default=>'Уведомление MAX не отправлено',
            };
            if ($delivery['group'] ?? null) $status .= ' · '.$delivery['group'];
            $resolution = $case['resolution'] ?? null;
            [$firstMonth, $lastMonth] = HandoverQuestion::affectedMonths($case);
            $period = (new \DateTimeImmutable($firstMonth.'-01'))->format('m.Y');
            if ($lastMonth !== $firstMonth) $period .= ' — '.(new \DateTimeImmutable($lastMonth.'-01'))->format('m.Y');
            $body = RentalUI::alert(
                'Вопрос бухгалтерии по дате передачи', $case['question'],
                $resolution ? 'info' : 'warning'
            ).RentalUI::pairs([
                ['Начисление аренды за '.$period, $resolution ? 'Решение сохранено, пауза снята' : 'Ожидает решения бухгалтера'],
                ['Внутреннее уведомление', $status],
                ...($resolution ? [['Решение', $resolution['text']], ['Записал', $resolution['actor']]] : []),
            ]).RentalUI::button('История договора', '/rental/history?id='.$id);
            if (!$resolution) {
                $actions[] = HandoverQuestion::RESOLVE;
                if (HandoverQuestion::canRetry($delivery)) $actions[] = HandoverQuestion::RETRY;
            }
            if ($delivery['status'] === 'uncertain') $actions[] = HandoverQuestion::RECONCILE;
        }
        if ((!$case || !empty($case['resolution'])) && self::can(HandoverQuestion::OPEN, $contract)
            && HandoverQuestion::initialInvoice($db, $contract)) {
            $actions[] = HandoverQuestion::OPEN;
        }
        foreach ($actions as $action) {
            if (self::can($action, $contract)) {
                $body .= RentalUI::button(self::TITLES[$action], '/rental/action-form?id='.$id.'&action='.$action);
            }
        }
        return $body;
    }

    public static function fields(string $action, array $contract): string
    {
        if (!self::can($action, $contract)) throw new RuntimeException('Действие недоступно для этой роли.', 403);
        $db = DB::pdo();
        if ($action === HandoverQuestion::OPEN) {
            $invoice = HandoverQuestion::initialInvoice($db, $contract);
            if (!$invoice) throw new RuntimeException('Для вопроса нужен выставленный счёт за первый период аренды.', 409);
            return RentalUI::pairs([
                ['Начало по договору', HandoverQuestion::displayDate(HandoverQuestion::plannedDate($contract,$invoice))],
                ['Счёт за первый период', $invoice['number']],
            ]).Controller::input('handover_date', 'Фактическая дата передачи', '', 'date', true);
        }
        $case = HandoverQuestion::read($db, (int)$contract['id']);
        if (!$case || (!empty($case['resolution']) && $action !== HandoverQuestion::RECONCILE)) {
            throw new RuntimeException('Вопрос изменился. Обновите карточку договора.', 409);
        }
        $fields = '<input type="hidden" name="question_key" value="'.View::e($case['key']).'">'
            .RentalUI::alert('Вопрос по дате передачи', $case['question']);
        if ($action === HandoverQuestion::RESOLVE) {
            return $fields.'<label class="field"><span class="field-label">Решение бухгалтера <span class="req">*</span></span>'
                .'<textarea class="field-textarea" name="resolution" rows="4" maxlength="2000" required></textarea></label>'
                .'<label class="registry-ui-checkbox"><input type="checkbox" name="billing_ready" value="1" required><span>Начисление аренды можно продолжить</span></label>';
        }
        $delivery = HandoverQuestion::delivery($db, (int)$contract['id'], $case);
        $fields .= '<input type="hidden" name="attempt_id" value="'.View::e($delivery['attempt_id'] ?? '').'">';
        if ($action === HandoverQuestion::RECONCILE) {
            if ($delivery['status'] !== 'uncertain') throw new RuntimeException('Статус попытки MAX изменился.', 409);
            return $fields
                .'<label class="field"><span class="field-label">Результат сверки <span class="req">*</span></span>'
                .'<select class="field-select" name="delivery_outcome" required><option value="">Выберите результат</option>'
                .'<option value="accepted">Доставка подтверждена</option><option value="failed">Подтверждено, что MAX не принял сообщение</option></select></label>'
                .'<label class="field"><span class="field-label">Основание сверки <span class="req">*</span></span>'
                .'<textarea class="field-textarea" name="delivery_evidence" rows="3" maxlength="2000" required></textarea></label>';
        }
        if (!HandoverQuestion::canRetry($delivery)) {
            throw new RuntimeException('Повтор доступен после подтверждённого сбоя доставки.', 409);
        }
        return $fields.RentalUI::alert('Подтверждено, что уведомление не доставлено', 'Внутренняя группа MAX получит один повтор.');
    }
}
