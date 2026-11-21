<?php
declare(strict_types=1);
namespace Modules\Rental;

/** Read-only registry facts; money and unknown values retain their SQL precision. */
final class InvoiceFacts
{
    private static function reviewExplanation(array $invoice): string
    {
        $review=$invoice['_calculation_review'] ?? null;
        if(!is_array($review)||!isset($review['historicalAmount'],$review['expectedAmount'],$review['difference']))
            return 'Финансовые данные изменены. Конкретное расхождение не сохранено — проверьте историю счёта.';
        $money=static fn($v)=>strip_tags(Controller::money($v));
        $document=!empty($review['document_number'])?'ДС №'.$review['document_number']:'изменения условий';
        $effective=$review['document_effective_date'] ?? $review['effectiveDate'] ?? null;
        $text='При проверке после '.$document.($effective?' с '.Documents::dateText($effective):'').': в счёте '.$money($review['historicalAmount']).', расчёт '.$money($review['expectedAmount']).'; расхождение '.$money($review['difference']).'.';
        if(!empty($review['excludedTargets']))$text.=' Исключены из начисления: '.implode(', ',$review['excludedTargets']).'.';
        if($invoice['paid']!==null)$text.=' Оплачено '.$money($invoice['paid']).'.';
        if(!empty($review['replacement_invoices']))$text.=' По этому ДС выставлен отдельный счёт: '.implode(', ',$review['replacement_invoices']).'. Проверьте корректировку старого начисления и распределение оплаты.';
        return $text;
    }

    public static function overdueDays(array $invoice, ?string $today = null): int
    {
        $today ??= $invoice['_payment_control']['date'] ?? date('Y-m-d');
        if (empty($invoice['_payment_control']['confirmed']) || empty($invoice['_payment_control']['overdue_ready'])) return 0;
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
        $remainder=bcsub(bcsub($invoice['amount'],$invoice['paid'],2),$invoice['_security_credit'] ?? '0',2);
        return bccomp($remainder,'0',2)>0?$remainder:'0.00';
    }

    /** Same due-date predicate for the registry and its navigation counter. */
    public static function overdue(array $invoice): bool
    {
        return self::overdueDays($invoice) > 0;
    }

    public static function overdueCounts(iterable $invoices): array
    {
        $counts=[0,0,0,0];
        foreach ($invoices as $invoice) {
            $severity=self::overdueSeverity($invoice);
            if ($severity === 'red') $counts[0]++;
            elseif ($severity === 'yellow') $counts[1]++;
        }
        return $counts;
    }

    public static function menuCounts(\PDO $pdo, bool $graded = false): array
    {
        $now = BankPaymentRefresh::now($pdo);
        $invoices = $pdo->query('SELECT i.* FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id')->fetchAll();
        $counts = self::overdueCounts(array_map(static fn(array $invoice): array=>PaymentReview::attach($pdo,$invoice,$now),$invoices));
        return $graded ? $counts : [$counts[0]+$counts[1],0,0,0];
    }

    public static function view(array $invoice, string $severity = ''): array
    {
        $raw = json_decode($invoice['source_json'] ?? '{}', true) ?: [];
        $delivery=$invoice['_delivery'] ?? [];
        $sendError=empty($delivery['handed']) && (!empty($raw['sendError'])
            || in_array($delivery['intent']['status'] ?? '',['blocked','failed','unknown'],true));
        $remaining = self::remaining($invoice);
        $overdueDays = self::overdueDays($invoice);
        $control = $invoice['_payment_control'] ?? [];
        $bookUnpaid = $invoice['status'] !== 'revoked' && $remaining !== null && bccomp($remaining,'0',2)>0;
        $unverified = $bookUnpaid && empty($control['confirmed']);
        $unpaid = $bookUnpaid && !$unverified;
        $overdue = $overdueDays > 0;
        $state = match (true) {
            !empty($raw['issueError']) => 'Счёт не сформирован',
            !empty($raw['mismatch']) => 'Рассинхронизация 1С и ERP',
            $sendError => ($delivery['intent']['status'] ?? '')==='unknown' ? 'Результат отправки не подтверждён' : 'Ошибка отправки',
            !empty($invoice['manual_review']) => 'Требуется повторная проверка',
            $invoice['status'] === 'revoked' => 'Отозван',
            $invoice['status'] === 'frozen' => 'Заморожен',
            !empty($invoice['control_closed']) => 'Контроль закрыт по финальной сверке',
            $remaining === null => 'Не подтверждено',
            bccomp($remaining, '0', 2) === 0 => bccomp($invoice['_security_credit'] ?? '0','0',2)>0
                ? 'Обязательство покрыто с учётом зачёта' : 'Оплачен',
            default => $control['label'] ?? 'Оплата требует сверки',
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
            !empty($invoice['manual_review']) => self::reviewExplanation($invoice),
            $remaining === null => 'Исходные данные не подтверждают сумму или оплату.',
            $overdueDays > 0 => 'Долг '.strip_tags(Controller::money($remaining)).' · просрочка: '.$overdueDays.' календ. дн. '.($control['detail'] ?? 'Свежая выписка подтверждена.'),
            $bookUnpaid => $control['detail'] ?? 'Свежая выписка не подтверждена. Остаток указан по сохранённым данным.',
            default => bccomp($invoice['_security_credit'] ?? '0','0',2)>0
                ? 'Перенос обеспечения учтён отдельно от банковских поступлений.' : 'Оплата учтена, задолженности нет.',
        };
        return [
            'remaining'=>$remaining, 'unpaid'=>$unpaid, 'unverified_balance'=>$unverified, 'severity'=>$severity,
            'attention'=>$severity !== '',
            'overdue'=>$overdue, 'overdue_days'=>$overdueDays,
            'waiting'=>$invoice['status'] === 'active' && empty($invoice['control_closed']) && $unpaid && !$overdue,
            'paid'=>$invoice['status'] === 'active' && $remaining !== null && bccomp($remaining,'0',2) === 0,
            'state'=>$state, 'explanation'=>$explanation,
            'send_error'=>$sendError,
        ];
    }
}
