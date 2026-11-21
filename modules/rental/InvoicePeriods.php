<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;

final class InvoicePeriods
{
    public static function month(mixed $value): string
    {
        return is_string($value) && preg_match('/^[1-9]\d{3}-(?:0[1-9]|1[0-2])$/D',$value) ? $value : '';
    }

    public static function bounds(string $month): array
    {
        $from = Service::date($month.'-01');
        return [$from,(new DateTimeImmutable($from))->format('Y-m-t')];
    }

    public static function rentalPredicate(): string
    {
        return "COALESCE(i.kind,'')<>'Обеспечительный платёж' AND (i.period=? OR EXISTS (
            SELECT 1 FROM invoice_coverage ic WHERE ic.invoice_id=i.id AND ic.start_date<=? AND ic.end_date>=?
        ) OR (JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.printDetails.number'))=i.number
           AND JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.printDetails.issued'))=i.issued
           AND (JSON_CONTAINS(JSON_EXTRACT(i.source_json,'$.printDetails.lines[*].rentalPeriod'),JSON_QUOTE(?))
             OR JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.printDetails.rentalPeriod'))=?)))";
    }

    public static function rentalParameters(string $month): array
    {
        [$from,$until] = self::bounds($month);
        return [$month,$until,$from,$month,$month];
    }
}
