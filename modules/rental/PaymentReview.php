<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PaymentReview
{
    public static function attach(PDO $db,array $invoice,?DateTimeImmutable $now = null): array
    {
        if (($invoice['kind'] ?? '')===SecurityDeposit::KIND)
            $invoice['_security_credit']=SecurityDeposit::invoiceCredit($db,$invoice);
        $invoice['_payment_control'] = self::state($db,$invoice,$now ?? BankPaymentRefresh::now($db));
        return $invoice;
    }

    public static function state(PDO $db,array $invoice,DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('Europe/Moscow'));
        $date = $now->format('Y-m-d');
        $result = ['date'=>$date,'confirmed'=>false,'overdue_ready'=>false];
        $remaining = InvoiceFacts::remaining($invoice);
        if ($remaining === null || bccomp($remaining,'0',2)===0) return $result;
        if ($now->format('H:i:s') < '11:00:00') return $result+[
            'label'=>'Сверка оплаты ожидается','detail'=>'Свежая выписка проверяется к 11:00 по Москве.'];
        $source = Importer::decodeExact($invoice['source_json'] ?? '{}');
        $payee = isset($invoice['payee_snapshot']) ? Importer::decodeExact($invoice['payee_snapshot']) : ($source['payeeSnapshot'] ?? []);
        $account = $payee['bankDetails']['account'] ?? null;
        if (!is_string($account) || !preg_match('/^[0-9]{20}$/D',$account)) return $result+[
            'label'=>'Покрытие выписки не подтверждено','detail'=>'Для счёта не сохранён подтверждённый расчётный счёт получателя.'];
        $query = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute([BankPaymentRefresh::WINDOWS,BankPaymentRefresh::window($account,$date)]);
        $raw = $query->fetchColumn();
        $receipt = $raw === false ? [] : Importer::decodeExact($raw);
        if (($receipt['state'] ?? null) !== 'complete') return $result+match ($receipt['state'] ?? '') {
            'unavailable'=>['label'=>'1С недоступна','detail'=>'Выписка к 11:00 МСК не получена. Остаток по учёту сохранён.'],
            'incomplete'=>['label'=>'Выписка неполная','detail'=>match ($receipt['code'] ?? '') {
                'SOURCE_WATERMARK_MISSING'=>'Источник не подтвердил полноту выписки к 11:00 МСК.',
                'PAYMENT_IDENTITY_CHANGED'=>'Повтор чтения вернул изменившееся поступление. Сверьте данные по исходному идентификатору.',
                default=>'Полнота данных к 11:00 МСК не подтверждена. Требуется сверка.',
            }],
            default=>['label'=>'Свежая выписка не получена','detail'=>'За это окно нет подтверждённой выгрузки к 11:00 МСК.'],
        };
        if (($receipt['cutoff'] ?? null) !== $date.'T11:00:00' || empty($invoice['issued'])
            || ($receipt['from'] ?? '9999') > $invoice['issued']) return $result+[
            'label'=>'Покрытие выписки неполное','detail'=>'Подтвердите банковские данные за период с даты выставления счёта.'];

        $query = $db->prepare('SELECT party_id FROM contracts WHERE id=?');
        $query->execute([$invoice['contract_id']]);
        $partyId = (int)$query->fetchColumn();
        if (!is_array($receipt['payment_ids'] ?? null) || self::unresolved($db,$receipt['payment_ids'],$partyId,$date)) return $result+[
            'label'=>'Приход ждёт сверки',
            'detail'=>'В выписке есть поступление без однозначного распределения. Бухгалтеру нужно сверить основание.'];
        if (!$invoice['due']) return $result+[
            'label'=>'Срок оплаты не подтверждён','detail'=>'Получена свежая полная выписка. Для контроля нужен договорный срок.'];
        try {
            $businessDay = PaymentDayCalendar::after($invoice['due'],1,'working',true);
            if (!isset($businessDay['date']) && BankPaymentRefresh::local($db)
                && !getenv('APP_RENTAL_WORKING_CALENDAR_FILE'))
                $businessDay = PaymentDayCalendar::after($invoice['due'],1,'working');
        } catch (RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            $businessDay = [];
        }
        if (!isset($businessDay['date'])) return $result+[
            'label'=>'Нужен рабочий календарь','detail'=>'Получена свежая полная выписка. Бухгалтеру нужно уточнить первый рабочий день после срока.'];
        $bookedPayment = bccomp($invoice['paid'],'0',2)>0;
        return array_replace($result,[
            'confirmed'=>true,'overdue_ready'=>$date >= $businessDay['date'],
            'first_day'=>$businessDay['date'],'cutoff'=>$receipt['cutoff'],
            'label'=>bccomp($invoice['_security_credit'] ?? '0','0',2)>0?'Часть обязательства покрыта зачётом':
                ($bookedPayment?'Частично оплачен':'Оплата не поступила'),
            'detail'=>'Полная выписка к 11:00 МСК. Первый рабочий день после срока — '.Documents::dateText($businessDay['date']).'.',
        ]);
    }

    private static function unresolved(PDO $db,array $ids,int $party,string $date): bool
    {
        if (!$party) return true;
        $older = $db->prepare('SELECT 1 FROM payments p
            WHERE p.party_id=? AND (p.payment_date<=? OR p.payment_date IS NULL)
            AND p.amount<>COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.payment_id=p.id),0) LIMIT 1');
        $older->execute([$party,$date]);
        if ($older->fetchColumn()) return true;
        if (!$ids) return false;
        $query = $db->prepare('SELECT p.id,p.party_id,p.amount,COALESCE(SUM(a.amount),0) allocated FROM payments p
            LEFT JOIN payment_allocations a ON a.payment_id=p.id WHERE p.id IN ('.implode(',',array_fill(0,count($ids),'?')).') GROUP BY p.id');
        $query->execute($ids);
        $payments = $query->fetchAll();
        if (!$party || count($payments)!==count($ids)) return true;
        foreach ($payments as $payment) {
            if ($payment['party_id'] === null || ((int)$payment['party_id'] === $party
                && bccomp($payment['allocated'],$payment['amount'],2)!==0)) return true;
        }
        return false;
    }
}
