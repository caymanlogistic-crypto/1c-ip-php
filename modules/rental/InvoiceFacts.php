<?php
declare(strict_types=1);
namespace Modules\Rental;

/** Read-only registry facts; money and unknown values retain their SQL precision. */
final class InvoiceFacts
{
    public static function overdueDays(array $invoice, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $remaining = self::remaining($invoice);
        if ($invoice['status'] !== 'active' || !empty($invoice['control_closed'])
            || $remaining === null || bccomp($remaining,'0',2) <= 0
            || empty($invoice['due']) || $invoice['due'] >= $today) return 0;
        return (int)(new \DateTimeImmutable($invoice['due']))->diff(new \DateTimeImmutable($today))->days;
    }

    public static function overdueSeverity(array $invoice, ?string $today = null): string
    {
        $days = self::overdueDays($invoice,$today);
        return $days > 5 ? 'red' : ($days > 0 ? 'yellow' : '');
    }

    public static function remaining(array $invoice): ?string
    {
        if ($invoice['amount'] === null || $invoice['paid'] === null) return null;
        return bccomp($invoice['amount'], $invoice['paid'], 2) > 0
            ? bcsub($invoice['amount'], $invoice['paid'], 2) : '0.00';
    }

    public static function view(array $invoice, string $severity = ''): array
    {
        $raw = json_decode($invoice['source_json'] ?? '{}', true) ?: [];
        $delivery=$invoice['_delivery'] ?? [];
        $sendError=empty($delivery['handed']) && (!empty($raw['sendError'])
            || in_array($delivery['intent']['status'] ?? '',['blocked','failed','unknown'],true));
        $remaining = self::remaining($invoice);
        $overdueDays = self::overdueDays($invoice);
        $unpaid = $invoice['status'] !== 'revoked' && $remaining !== null && bccomp($remaining, '0', 2) > 0;
        $state = match (true) {
            !empty($raw['issueError']) => 'Счёт не сформирован',
            !empty($raw['mismatch']) => 'Рассинхронизация 1С и ERP',
            $sendError => ($delivery['intent']['status'] ?? '')==='unknown' ? 'Результат отправки не подтверждён' : 'Ошибка отправки',
            !empty($invoice['manual_review']) => 'Требуется повторная проверка',
            $invoice['status'] === 'revoked' => 'Отозван',
            $invoice['status'] === 'frozen' => 'Заморожен',
            !empty($invoice['control_closed']) => 'Контроль закрыт по финальной сверке',
            $remaining === null => 'Не подтверждено',
            bccomp($remaining, '0', 2) === 0 => 'Оплачен',
            bccomp($invoice['paid'], '0', 2) > 0 => 'Частично оплачен',
            default => 'Не оплачен',
        };
        $explanation = match (true) {
            !empty($invoice['control_closed']) && empty($invoice['manual_review']) && empty($raw['mismatch']) =>
                'Контроль закрыт бухгалтером '.($raw['controlClosedBy'] ?? '').' '.($raw['controlClosedAt'] ?? '').'. Исторические суммы и оплаты сохранены.',
            $invoice['status'] === 'revoked' => 'Счёт отозван, активный контроль задолженности прекращён. История сохранена.',
            !empty($raw['issueError']) => (string)$raw['issueError'],
            !empty($raw['mismatch']) => 'Рассинхронизация данных 1С и ERP. Сохранённые данные ERP имеют приоритет.',
            $sendError => ($delivery['intent']['status'] ?? '')==='unknown'
                ? 'Результат отправки требует сверки. Повторная отправка автоматически не выполняется.'
                : (($delivery['intent']['status'] ?? '')==='blocked'
                    ? (json_decode($delivery['intent']['payload_json'],true)['preflight']['message'] ?? 'Не удалось подготовить отправку счёта.')
                    : 'Письмо клиенту не отправлено. Повторите отправку или подтвердите передачу документов.'),
            !empty($invoice['manual_review']) => 'После изменения финансовых данных требуется ручная повторная проверка.',
            $remaining === null => 'Исходные данные не подтверждают сумму или оплату.',
            $overdueDays > 0 => 'Остаток '.strip_tags(Controller::money($remaining)).' · просрочка: '.$overdueDays.' календ. дн.',
            $unpaid => 'Ожидается оплата за указанный период.',
            default => 'Оплата учтена, задолженности нет.',
        };
        return [
            'remaining'=>$remaining, 'unpaid'=>$unpaid, 'severity'=>$severity,
            'attention'=>$severity !== '',
            'overdue'=>$overdueDays > 0, 'overdue_days'=>$overdueDays,
            'paid'=>$invoice['status'] === 'active' && $remaining !== null && bccomp($remaining,'0',2) === 0,
            'state'=>$state, 'explanation'=>$explanation,
            'send_error'=>$sendError,
        ];
    }
}
