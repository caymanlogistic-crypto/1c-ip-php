<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;

final class RenewalIndexation
{
    public static function facts(PDO $db,array $record,string $kind): array
    {
        if ($kind==='addendum') {
            if (($record['kind'] ?? '')!=='renewal') return [];
            $choice=Importer::decodeExact($record['source_json'])['addendum_choice'] ?? [];
            if (($choice['scope'] ?? '')==='same' && ($percent=self::format($choice['percent'] ?? null))!==null) {
                return [['Индексация',$percent]];
            }
        }

        $offer=ExpiryContinuation::linkedOffer($db,$record,$kind);
        if (($offer['price_mode'] ?? '')!=='percent') return [];
        $percent=self::format($offer['percent'] ?? null);
        return $percent===null?[]:[['Индексация',$percent]];
    }

    private static function format(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $percent=str_replace(',','.',trim((string)$value));
        if (!preg_match('/^-?\d{1,3}(?:\.\d{1,4})?$/D',$percent)
            || bccomp($percent,'0',4)===0 || bccomp($percent,'-100',4)<=0) return null;
        return str_replace('.',',',rtrim(rtrim(bcadd($percent,'0',4),'0'),'.')).' %';
    }
}
