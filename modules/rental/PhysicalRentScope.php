<?php
declare(strict_types=1);

namespace Modules\Rental;

/** Fixed rent of physical objects in the monthly comparison. */
final class PhysicalRentScope
{
    // Verified source contract and customer pairs whose invoice text alone suggests physical rent.
    private const EXCLUDED_ARCHIVE_PAIRS = [
        'de203b0be4ea47ff9b119c7a36e1f8f26f8a1a84058bdbc073dc6280fce4222b'=>true, // IDATUR 56/10, legal address
        'e25529cd3677c03c95db575783715ecb2de743c90768f7d975611c5f3bfcac25'=>true, // Tesmebel 32, legal address
        'be5b94a1beb488fe87436f6e9b6572533abdee1ac350aa4b0be2cb0863be956c'=>true, // Stroy-OVK 148, legal address
        '47496a82708bd61c053b06c8841b80bf423f6dd3b6ecffd5809caa82739f6a6f'=>true, // ABS-Chashnikovo 132, legal address
        '7ee9c61589d983b21ae6812a01ba7934305bfc608ea87fde680ef9190a6a7158'=>true, // RailSlot 75, historical billing
    ];

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
        $contract = $source['ДоговорКонтрагента_Key'] ?? null;
        $party = $source['Контрагент_Key'] ?? $source['Контрагент'] ?? null;
        if (is_string($contract) && is_string($party) && isset(self::EXCLUDED_ARCHIVE_PAIRS[hash('sha256',$contract.':'.$party)])) {
            return [];
        }
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
