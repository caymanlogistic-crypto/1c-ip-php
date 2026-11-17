<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use DateTimeImmutable;
use RuntimeException;

/** Cian/Yandex visible primary-card semantics. Browser/network collection is separate. */
final class MarketPrimaryCards
{
    public static function addressKey(string $address): string
    {
        if (mb_strlen($address) > 180) throw new RuntimeException('Market address exceeds source bound.');
        $key = str_replace(['ё', 'москва', 'улица', 'ул.'], ['е', '', '', ''], mb_strtolower($address));
        $key = preg_replace('/[\s,\x{00a0}\x{202f}]+/u', '', $key);
        $key = str_replace(['корпус', 'корп.', 'строение', 'стр.'], ['к', 'к', 'с', 'с'], $key);
        if (!preg_match('/^мосфильмовская[0-9]+[а-я]?(?:[кс][0-9]+)*$/uD', $key)) throw new RuntimeException('Only explicit source Mosfilmovskaya house/subunit addresses are admitted.');
        return $key;
    }

    public static function scope(string $address, array $geography): ?string
    {
        try { $key = self::addressKey($address); } catch (RuntimeException) { return null; }
        if ($key === self::addressKey($geography['base_address'])) return 'base';
        foreach ($geography['nearby'] as $candidate) {
            $allowed = self::addressKey($candidate['address']);
            if ($key === $allowed || ($candidate['include_subunits'] === true && preg_match('/^'.preg_quote($allowed, '/').'(?:[кс][0-9]+)+$/uD', $key))) return 'nearby';
        }
        return null;
    }

    private static function cardAddress(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (!preg_match('/Москва.*Мосфильмовск/iu', $line)) continue;
            if (preg_match('/Мосфильмовская\s*(?:улица|ул\.?)?\s*,?\s*([0-9]+(?:[А-Яа-я](?![0-9]))?(?:\s*(?:к(?:орп\.?|орпус)?|с(?:тр\.?|троение)?)\s*[0-9]+)*)/iu', $line, $match)) {
                $address = 'Москва, Мосфильмовская улица, '.$match[1];
                try { self::addressKey($address); return $address; } catch (RuntimeException) { return null; }
            }
        }
        return null;
    }

    private static function decimal(string $text): string
    {
        $value = str_replace(',', '.', preg_replace('/[\s\x{00a0}\x{202f}]/u', '', $text));
        if (!preg_match('/^[0-9]{1,19}(?:\.[0-9]{1,12})?$/D', $value)) throw new RuntimeException('Market decimal exceeds supported precision.');
        return $value;
    }

    public static function parse(string $source, string $url, string $text, string $observed): ?array
    {
        if (!in_array($source, ['cian', 'yandex'], true)) throw new RuntimeException('This primary-card adapter supports Cian/Yandex only.');
        if (strlen($text) > 1048576 || !preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $observed)) throw new RuntimeException('Invalid market card bound/observation date.');
        new DateTimeImmutable($observed);
        $url = MarketSourceUrls::clean($source, $url);
        $lines = preg_split('/\r\n|\n|\r/', $text);
        foreach (array_slice($lines, 0, 3) as $line) if (preg_match('/^\s*(?:Реклама|Похожие предложения|Другие предложения)\s*$/iu', $line)) return null;
        $address = self::cardAddress($lines);
        $title = implode(' ', array_slice($lines, 0, 5));
        $kind = preg_match('/машино.?мест|парковочн.{0,8}мест/iu', $title) ? 'parking' : (preg_match('/\bофис\b/iu', $title) ? 'office' : null);
        if ($address === null || $kind === null) return null;
        preg_match_all('/[0-9]{6,}/', parse_url($url, PHP_URL_PATH), $ids);
        if ($ids[0] === []) return null;
        $number = '([0-9](?:[0-9 \x{00a0}\x{202f}]*[0-9])?(?:[.,][0-9]+)?)';
        $space = '[ \x{00a0}\x{202f}]*';
        $area = preg_match('/'.$number.$space.'м[²2]/u', $text, $match) ? self::decimal($match[1]) : null;
        $price = preg_match('/'.$number.$space.'(?:₽|руб\.?)\s*(?:в\s*месяц|\/\s*мес)/iu', $text, $match) ? self::decimal($match[1]) : null;
        $annual = preg_match('/'.$number.$space.'(?:₽|руб\.?)\s*(?:за\s*|\/\s*)?м[²2]\s*(?:в\s*год|\/\s*год)/iu', $text, $match) ? self::decimal($match[1]) : null;
        $floor = preg_match('/(-?[0-9]+)\s*этаж/iu', $title, $match) ? (int)$match[1] : null;
        return [
            'source' => $source, 'source_url' => $url, 'source_id' => end($ids[0]), 'observed_at' => $observed,
            'address' => $address, 'area_m2' => $area, 'price_rub' => $price,
            'price_period' => $price === null ? 'unknown' : 'month', 'object_type' => $kind,
            'listing_status' => preg_match('/объявление.{0,40}(?:снято|устарело)|снято\s+с\s+публикации|предложение.{0,20}неактуально/iu', $text) ? 'removed' : 'observed_in_listing',
            'floor' => $floor, 'annual_rate_rub_m2' => $annual, 'extraction_method' => $source.'_visible_primary_card',
        ];
    }

    public static function accepted(array $row, string $kind, array $filters): bool
    {
        if (($row['object_type'] ?? null) !== $kind || ($row['listing_status'] ?? null) === 'removed') return false;
        foreach (['area_m2' => 'area', 'price_rub' => 'price'] as $field => $prefix) {
            $value = $row[$field] ?? null;
            $low = $filters[$prefix.'_min']; $high = $filters[$prefix.'_max'];
            foreach ([$value, $low, $high] as $decimal) {
                if ($decimal !== null && ((!is_string($decimal) && !is_int($decimal)) || !preg_match('/^[0-9]{1,19}(?:\.[0-9]{1,12})?$/D', (string)$decimal))) throw new RuntimeException('Market filters and card values require exact nonnegative decimal lexemes.');
            }
            if (($low !== null || $high !== null) && ($value === null || ($prefix === 'price' && $row['price_period'] !== 'month'))) return false;
            if ($value !== null && (($low !== null && bccomp((string)$value, (string)$low, 12) < 0) || ($high !== null && bccomp((string)$value, (string)$high, 12) > 0))) return false;
        }
        return true;
    }
}
