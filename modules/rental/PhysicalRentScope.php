<?php
declare(strict_types=1);

namespace Modules\Rental;

/** Fixed rent of physical objects in the monthly comparison. */
final class PhysicalRentScope
{
    public static function contract(array $contract): bool
    {
        if (!in_array($contract['template_type'], ['office', 'office-person', 'parking', 'parking-person', 'apartment-person'], true)) return false;
        $source = Importer::decodeExact($contract['source_json']);
        $kinds = array_column($source['sourceLegalMapping']['sourceConditions'] ?? [], 'kind');
        return !in_array($source['archivedRentRestoration']['use'] ?? null, ['legal', 'billing-only'], true)
            && (!$kinds || in_array('RENT', $kinds, true));
    }

    public static function terms(array $terms): array
    {
        return array_values(array_filter($terms, static fn(array $term): bool =>
            $term['target_kind'] === 'physical-object'
            && $term['physical_occupancy'] !== 0));
    }

    public static function lines(array $source): array
    {
        $rent = MonthlyRentReconciliation::rentLines($source);
        $comment = (string)($source['Комментарий'] ?? '');
        if (self::legalAddress($comment) && !array_filter($rent, static fn(array $line): bool =>
            self::legalAddress((string)$line['Содержание']))) return [];

        return array_values(array_filter($rent, static function(array $line): bool {
            $text = (string)$line['Содержание'];
            return !self::legalAddress($text)
                && (self::describesObject($text)
                    || preg_match('/^\s*арендная\s+плата\s+(?:по\s+дог|с\s+\d|за\s+(?:период\b|\d|(?:январ|феврал|март|апрел|ма[йя]|ию[нл]|август|сентябр|октябр|ноябр|декабр)\p{L}*\b))/iu', $text));
        }));
    }

    public static function explicitlyPhysical(array $lines): bool
    {
        foreach ($lines as $line) {
            if (self::describesObject((string)$line['Содержание'])) return true;
        }
        return false;
    }

    public static function describesObject(string $text): bool
    {
        $prefix = '(?:аренда|услуги\s+по\s+аренде|арендная\s+плата\s+(?:за(?:\s+аренду)?|по\s+договору\s+аренды))';
        $object = '(?:офис\p{L}*|(?:нежил\p{L}*\s+)?помещен\p{L}*|апартамент\p{L}*|склад\p{L}*'
            .'|машино[\p{Pd}\s]*мест\p{L}*|парковочн\p{L}*\s+(?:машино[\p{Pd}\s]*)?мест\p{L}*|парк\.\s*мест\p{L}*|парковк\p{L}*|паркинг\p{L}*)';
        return (bool)preg_match('/^\s*'.$prefix.'\s+'.$object.'\b/iu', $text);
    }

    private static function legalAddress(string $text): bool
    {
        return (bool)preg_match('/(?:\(\s*юр(?:\.?\s*адрес)?\.?\s*\)|\b(?:юр\.?\s*адрес|юридическ\p{L}*\s+адрес)\p{L}*\b)/iu', $text);
    }
}
