<?php
declare(strict_types=1);
namespace Modules\Rental;

/** Read-only registry facts; money and unknown values retain their SQL precision. */
final class InvoiceFacts
{
    public static function remaining(array $invoice): ?string
    {
        if ($invoice['amount'] === null || $invoice['paid'] === null) return null;
        return bccomp($invoice['amount'], $invoice['paid'], 2) > 0
            ? bcsub($invoice['amount'], $invoice['paid'], 2) : '0.00';
    }

    /** Same due-date predicate for the registry and its navigation counter. */
    public static function overdue(array $invoice, ?string $remaining = null): bool
    {
        $remaining ??= self::remaining($invoice);
        return $invoice['status'] === 'active' && empty($invoice['control_closed'])
            && $remaining !== null && bccomp($remaining,'0',2) > 0
            && !empty($invoice['due']) && $invoice['due'] < date('Y-m-d');
    }

    public static function menuCounts(\PDO $pdo): array
    {
        $count=0;
        foreach ($pdo->query('SELECT i.* FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id') as $invoice) {
            if (self::overdue($invoice)) $count++;
        }
        return [$count,0,0,0];
    }

    public static function view(array $invoice, string $severity = ''): array
    {
        $raw = json_decode($invoice['source_json'] ?? '{}', true) ?: [];
        $delivery=$invoice['_delivery'] ?? [];
        $sendError=empty($delivery['handed']) && (!empty($raw['sendError'])
            || in_array($delivery['intent']['status'] ?? '',['blocked','failed','unknown'],true));
        $remaining = self::remaining($invoice);
        $unpaid = $invoice['status'] !== 'revoked' && $remaining !== null && bccomp($remaining, '0', 2) > 0;
        $overdue = self::overdue($invoice,$remaining);
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
            $unpaid && $severity !== '' => 'Остаток '.strip_tags(Controller::money($remaining)).' · контроль оплаты по рабочему календарю.',
            $unpaid => 'Ожидается оплата за указанный период.',
            default => 'Оплата учтена, задолженности нет.',
        };
        return [
            'remaining'=>$remaining, 'unpaid'=>$unpaid, 'severity'=>$severity,
            'attention'=>$severity !== '',
            'overdue'=>$overdue,
            'waiting'=>$invoice['status'] === 'active' && empty($invoice['control_closed']) && $unpaid && !$overdue,
            'paid'=>$invoice['status'] === 'active' && $remaining !== null && bccomp($remaining,'0',2) === 0,
            'state'=>$state, 'explanation'=>$explanation,
            'send_error'=>$sendError,
        ];
    }
}
