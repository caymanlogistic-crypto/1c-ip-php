<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;

/** First-payment amounts shown while the accountant confirms the packet. */
final class AccountantPacketSummary
{
    public const EXTERNAL_DISABLED = 'disabled_for_confirmation';

    public static function read(PDO $db, array $contract): array
    {
        $preview = InitialInvoice::preview($db, $contract, InitialInvoiceCard::defaultIssueDate($contract));
        $summary = ['planned'=>[], 'issued'=>[]];
        $group = $preview['existing'] ? 'issued' : 'planned';
        foreach (InitialInvoice::securityFirst($preview['existing'] ?: $preview['rows']) as $row) {
            if (($row['status'] ?? null) === 'revoked' || !self::knownAmount($row['amount'] ?? null)) continue;
            $kind = match ($row['kind']) {
                'Обеспечительный платёж' => 'security',
                'Первый период' => 'rent',
                'Аренда' => 'next',
                default => null,
            };
            if ($kind === null) continue;
            $item = ['kind'=>$kind,'label'=>InitialInvoice::DUE_PARTS[$kind],'amount'=>$row['amount']];
            if ($group === 'issued') $item['number'] = InvoiceDocument::number($row['number']);
            $summary[$group][] = $item;
        }
        return $summary;
    }

    public static function knownAmount(mixed $amount): bool
    {
        return is_string($amount) && preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $amount) === 1;
    }
}
