<?php
declare(strict_types=1);
namespace Modules\Rental;

final class InvoiceDueDate
{
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
