<?php
declare(strict_types=1);
namespace Modules\Rental;

final class InvoiceDueDate
{
    public static function monthlyRent(string $period): string
    {
        $first = new \DateTimeImmutable(Service::date($period.'-01'));
        return $first->modify('-1 month')->format('Y-m-25');
    }

    public static function firstRent(string $issued): string
    {
        return (new \DateTimeImmutable(Service::date($issued)))->modify('+2 days')->format('Y-m-d');
    }

    /** Use the original rent coverage for a legacy invoice with no stored deadline. */
    public static function archivedRent(array $invoice, array $periods): ?string
    {
        if (!empty($invoice['due'])) return $invoice['due'];
        if ((!empty($invoice['initial_invoice']) || $invoice['kind']==='Первый период') && !empty($invoice['issued']))
            return self::firstRent($invoice['issued']);
        return $periods ? self::monthlyRent(min(array_keys($periods))) : null;
    }

    /** Keep the normal month boundary; late automatic creation must not backdate payment. */
    public static function automatic(string $issued,string $period,bool $initial): string
    {
        $issued=Service::date($issued);
        $after=(new \DateTimeImmutable($issued))->modify('+3 days')->format('Y-m-d');
        if($initial)return $after;
        $monthly=Service::date($period.'-01');
        return $monthly<$issued?$after:$monthly;
    }
}
