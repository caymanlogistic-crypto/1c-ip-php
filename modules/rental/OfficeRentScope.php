<?php
declare(strict_types=1);

namespace Modules\Rental;

/** Office rent for the monthly comparison, as distinct from other rent and services. */
final class OfficeRentScope
{
    public static function contract(array $contract): bool
    {
        if (!in_array($contract['template_type'], ['office', 'office-person'], true)) return false;
        $source = Importer::decodeExact($contract['source_json']);
        $kinds = array_column($source['sourceLegalMapping']['sourceConditions'] ?? [], 'kind');
        return !in_array($source['archivedRentRestoration']['use'] ?? null, ['legal', 'billing-only'], true)
            && (!$kinds || in_array('RENT', $kinds, true));
    }

    public static function terms(array $terms): array
    {
        return array_values(array_filter($terms, static fn(array $term): bool =>
            $term['target_kind'] === 'physical-object' && $term['type'] === 'Офис'
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
                && !preg_match('/\b(?:машино[\p{Pd}\s]*мест\p{L}*|парков\p{L}*|паркинг\p{L}*)\b/iu', $text)
                && (self::officeDescription($text)
                    || preg_match('/^\s*аренда\s+помещен\p{L}*/iu', $text)
                    || preg_match('/^\s*арендная\s+плата\s+(?:по\s+дог|за\s+(?!аренду\s)|с\s+\d)/iu', $text));
        }));
    }

    public static function explicitlyOffice(array $lines): bool
    {
        foreach ($lines as $line) {
            if (self::officeDescription((string)$line['Содержание'])) return true;
        }
        return false;
    }

    private static function officeDescription(string $text): bool
    {
        if (preg_match('/^\s*услуги\s+по\s+аренде\s+нежилых\s+помещений\b/iu', $text)) return true;
        return (bool)preg_match('/^\s*(?:аренда|арендная\s+плата\s+(?:за(?:\s+аренду)?|по\s+договору\s+аренды))\s+(?:офис\p{L}*|нежил\p{L}*\s+помещен\p{L}*)\b/iu', $text);
    }

    private static function legalAddress(string $text): bool
    {
        return (bool)preg_match('/(?:\(\s*юр(?:\.?\s*адрес)?\.?\s*\)|\b(?:юр\.?\s*адрес|юридическ\p{L}*\s+адрес)\p{L}*\b)/iu', $text);
    }
}
