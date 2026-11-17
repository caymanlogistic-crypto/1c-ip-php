<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/** Source-S Morrow/Fortex HTML projection. No transport, SQL or price inference. */
final class MarketOfficeHtml
{
    private const NUMBER = '([0-9][0-9 \x{00a0}\x{202f}]*(?:[.,][0-9]+)?)';

    private static function decimal(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $text = str_replace(',', '.', preg_replace('/[\s\x{00a0}\x{202f}]/u', '', (string)$value));
        if (!preg_match('/^([0-9]{1,31})(?:\.([0-9]{1,31}))?(?:[eE]([+-]?[0-9]{1,2}))?$/D', $text, $match)) return null;
        $whole = $match[1];
        $fraction = $match[2] ?? '';
        $position = strlen($whole) + (int)($match[3] ?? 0);
        if ($position < -12 || $position > 19) return null;
        $digits = $whole.$fraction;
        $text = $position <= 0 ? '0.'.str_repeat('0', -$position).$digits
            : substr(str_pad($digits, $position, '0'), 0, $position).($position < strlen($digits) ? '.'.substr($digits, $position) : '');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 19 || strlen($fraction) > 12 || !preg_match('/[1-9]/', $whole.$fraction)) return null;
        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private static function address(string $text): ?string
    {
        if (!preg_match('/Мосфильмовск[а-яё]*\s*(?:улица|ул\.?|,)??\s*,?\s*(?:д\.?\s*)?(74\s*б|38\s*[аa]|70\s*(?:к(?:орп\.?)*\.?\s*4)?|88\s*(?:к(?:орп\.?)*\.?\s*[0-9]+)?)(?![0-9а-я])/iu', $text, $match)) return null;
        return 'Москва, Мосфильмовская, '.mb_strtoupper(preg_replace('/\s+/u', '', $match[1]));
    }

    private static function text(DOMNode $node): string
    {
        if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['script', 'style', 'noscript'], true)) return '';
        if ($node->nodeType === XML_TEXT_NODE) return $node->nodeValue ?? '';
        $text = '';
        foreach ($node->childNodes as $child) $text .= self::text($child);
        return $node instanceof DOMElement && in_array(strtolower($node->tagName), ['p','div','br','li','tr','h1','h2','h3','td'], true) ? "\n".$text."\n" : $text;
    }

    private static function nodes(array $node): iterable
    {
        yield $node;
        foreach ($node as $child) if (is_array($child)) yield from self::nodes($child);
    }

    /** Preserve unquoted JSON numeric lexemes before PHP can convert them to floats. */
    private static function json(string $text): ?array
    {
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            static fn(array $match): string => $match[0][0] === '"' ? $match[0] : json_encode($match[0], JSON_THROW_ON_ERROR), $text);
        try { $value = json_decode($quoted, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        return is_array($value) ? $value : null;
    }

    private static function row(string $source, string $url, string $observed, string $address, ?string $area, ?string $price, string $period, string $kind, string $method, string $status): array
    {
        preg_match_all('/[0-9]{6,}/', $url, $ids);
        return [
            'source' => $source, 'source_url' => $url, 'source_id' => $ids[0] === [] ? null : end($ids[0]),
            'observed_at' => $observed, 'address' => $address, 'area_m2' => $area, 'price_rub' => $price,
            'price_period' => $period, 'object_type' => $kind, 'listing_status' => $status,
            'extraction_method' => $method, 'floor' => null, 'annual_rate_rub_m2' => null,
        ];
    }

    /** @return array{records:array,state:string} */
    public static function extract(string $source, string $url, string $html, string $observed): array
    {
        if (!in_array($source, ['morrow', 'fortex'], true) || strlen($html) > 3 * 1024 * 1024
            || !mb_check_encoding($html, 'UTF-8') || !preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $observed)) throw new RuntimeException('Invalid office HTML source/bound/observation.');
        new DateTimeImmutable($observed);
        $url = MarketSourceUrls::clean($source, $url);
        if (MarketRobots::blocked($html)) return ['records' => [], 'state' => 'challenge'];
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) return ['records' => [], 'state' => 'unsupported_structure'];
        $xpath = new DOMXPath($document);
        $text = str_replace("\xc2\xa0", ' ', preg_replace('/[ \t\r\f\v]+/u', ' ', self::text($document)));
        $status = preg_match('/объявление снято с публикации|предложение неактуально/iu', $text) ? 'removed' : 'not_stated';
        $rows = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = self::json($script->textContent);
            if ($data === null) continue;
            foreach (self::nodes($data) as $item) {
                $offer = $item['offers'] ?? null;
                $floor = $item['floorSize'] ?? null;
                if (!is_array($offer) || !is_array($floor)) continue;
                $rawAddress = $item['address'] ?? '';
                if (is_array($rawAddress)) $rawAddress = implode(' ', array_filter($rawAddress, 'is_scalar'));
                if (!is_scalar($rawAddress)) continue;
                $address = self::address((string)$rawAddress);
                $area = self::decimal($floor['value'] ?? null);
                $price = self::decimal($offer['price'] ?? null);
                $unit = $floor['unitCode'] ?? $floor['unitText'] ?? '';
                $currency = $offer['priceCurrency'] ?? '';
                if (!is_string($unit) || !in_array(mb_strtolower($unit), ['mtk','m2','m²','sqm','кв.м','м²'], true)
                    || !is_string($currency) || strtoupper($currency) !== 'RUB' || $address === null || $area === null || $price === null) continue;
                $periodUnit = $offer['priceSpecification']['unitText'] ?? '';
                $periodUnit = is_string($periodUnit) ? $periodUnit : '';
                $period = preg_match('/месяц|month|MON/iu', $periodUnit) ? 'month' : (preg_match('/год|year|ANN/iu', $periodUnit) ? 'year' : 'unknown');
                $description = implode(' ', array_filter([$item['name'] ?? '', $item['description'] ?? ''], 'is_scalar'));
                $kind = preg_match('/машиномест|парковоч/iu', $description) ? 'parking' : (mb_stripos($description, 'офис') !== false ? 'office' : 'unknown');
                $rows[] = self::row($source, $url, $observed, $address, $area, $price, $period, $kind, 'json_ld', $status);
            }
        }
        if ($source === 'morrow' && ($address = self::address($text)) !== null) {
            foreach ($xpath->query('//table') as $table) {
                $valid = false;
                foreach ($xpath->query('.//tr', $table) as $tr) {
                    if ($xpath->query('ancestor::table[1]', $tr)->item(0) !== $table) continue;
                    $cells = [];
                    foreach ($xpath->query('./th|./td', $tr) as $cell) $cells[] = trim(preg_replace('/\s+/u', ' ', self::text($cell)));
                    $columns = array_map(static fn(string $cell): string => preg_replace('/\s+/u', '', mb_strtolower($cell)), array_slice($cells, 0, 4));
                    if ($columns === ['площадь','этаж','ценазакв.м/год','ценавмесяц']) { $valid = true; continue; }
                    if (!$valid || count($cells) < 4 || !preg_match('/^'.self::NUMBER.'\s*кв\.?\s*м\.?$/iuD', $cells[0], $areaMatch)
                        || !preg_match('/^[0-9]{1,2}$/D', $cells[1])) continue;
                    $area = self::decimal($areaMatch[1]);
                    if ($area === null) continue;
                    $prices = [];
                    foreach (array_slice($cells, 2, 2) as $cell) $prices[] = preg_match('/^'.self::NUMBER.'\s*[⃏₽]$/uD', $cell, $match) ? self::decimal($match[1]) : null;
                    $row = self::row($source, $url, $observed, $address, $area, $prices[1], $prices[1] === null ? 'unknown' : 'month', 'office', 'morrow_labeled_table', $status);
                    $row['floor'] = (int)$cells[1];
                    $row['annual_rate_rub_m2'] = $prices[0];
                    $rows[] = $row;
                }
            }
        }
        if ($source === 'fortex' && preg_match('/Аренда офиса\s+'.self::NUMBER.'\s*м/iu', $text, $heading, PREG_OFFSET_CAPTURE)) {
            $main = explode('Похожие предложения', substr($text, $heading[0][1]), 2)[0];
            $address = self::address($main);
            $area = self::decimal($heading[1][0]);
            if ($address !== null && $area !== null && preg_match('/Арендная плата в месяц\s*'.self::NUMBER.'\s*[₽р]/iu', $main, $match)
                && ($price = self::decimal($match[1])) !== null) $rows[] = self::row($source, $url, $observed, $address, $area, $price, 'month', 'office', 'fortex_labeled_html', $status);
        }
        $unique = [];
        foreach ($rows as $row) {
            $identity = $row;
            unset($identity['extraction_method']);
            $unique[json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] = $row;
        }
        return ['records' => array_values($unique), 'state' => $unique === [] ? 'unsupported_structure' : 'extracted'];
    }
}
