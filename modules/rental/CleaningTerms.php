<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;
use RuntimeException;

final class CleaningTerms
{
    public const BANDS=[['50','99','304.50'],['100','149','252.00'],['150','250','210.00']];

    public static function rate(string $area): ?string
    {
        foreach (self::BANDS as [$from,$until,$rate]) {
            if (bccomp($area,$from,4)>=0 && bccomp($area,$until,4)<=0) return $rate;
        }
        return null;
    }

    public static function amount(string $area,string $rate): string
    {
        return Billing::round(bcmul($area,$rate,6));
    }

    public static function resolve(array $agreement,array $amendments,string $date): ?array
    {
        $terms=$agreement['terms'];
        foreach ($amendments as $entry) {
            if ($entry['status']==='active' && $entry['effective']<=$date) {
                if ($entry['kind']==='terminate') return null;
                $terms=$entry['terms'];
            }
        }
        return $date<$terms['from'] || $date>$terms['until']?null:$terms;
    }

    public static function month(string $value): array
    {
        if (!preg_match('/^[1-9]\d{3}-(?:0[1-9]|1[0-2])$/D',$value)) throw new RuntimeException('Укажите месяц в формате ГГГГ-ММ.',422);
        return [$value.'-01',(new DateTimeImmutable($value.'-01'))->format('Y-m-t')];
    }

    public static function monthTerms(array $agreement,array $amendments,string $period): ?array
    {
        [$from,$until]=self::month($period);
        if ($agreement['status']!=='active') return null;
        $first=self::resolve($agreement,$amendments,$from);
        $last=self::resolve($agreement,$amendments,$until);
        if (!$first || $first!==$last) return null;
        return $first;
    }
}
