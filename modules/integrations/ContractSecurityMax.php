<?php
declare(strict_types=1);

namespace Modules\Integrations;

use Modules\Rental\Controller;
use RuntimeException;

final class ContractSecurityMax
{
    public const KEY = 'legalApprove';
    public const TOKEN = 'contractSecurityMultipleChange';
    public const LEGACY_VERSION = 4;
    public const VERSION = 5;

    public static function template(): array
    {
        return Support::decode(file_get_contents(__DIR__.'/defaults/legal-security-change-max.json'));
    }

    public static function matchesTemplate(array $entry): bool
    {
        if ((int)$entry['version'] < self::VERSION) return false;
        $expected = self::template();
        return $entry['format'] === $expected['format'] && $entry['body_text'] === $expected['body'];
    }

    public static function value(array $event): string
    {
        if (!array_key_exists('security_multiple_change', $event)) return '';
        if (($event['entity_kind'] ?? null) !== 'contract' || ($event['event_kind'] ?? null) !== self::KEY) {
            throw new RuntimeException('CONTRACT_MAX_SECURITY_CHANGE_INVALID');
        }
        return Support::json(self::validated($event['security_multiple_change']));
    }

    private static function validated(mixed $change): array
    {
        if (!is_array($change) || count($change) !== 3
            || !is_int($change['previous'] ?? null) || !is_int($change['current'] ?? null)
            || $change['previous'] < 0 || $change['current'] < 0 || $change['previous'] === $change['current']
            || !is_string($change['amount'] ?? null) || !preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $change['amount'])
            || ($change['amount'] === '0.00') !== ($change['current'] === 0)) {
            throw new RuntimeException('CONTRACT_MAX_SECURITY_CHANGE_INVALID');
        }
        return ['previous'=>$change['previous'],'current'=>$change['current'],'amount'=>$change['amount']];
    }

    public static function parse(string $value): array
    {
        try {
            return self::validated(json_decode($value, true, 4, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new RuntimeException('CONTRACT_MAX_SECURITY_CHANGE_INVALID', 0, $error);
        }
    }

    public static function render(string $value, string $format, callable $escape): string
    {
        if ($value === '') return '';
        $change = self::parse($value);
        [$open,$close] = match ($format) {
            'html' => ['<b>','</b>'],
            'markdown' => ['**','**'],
            default => ['',''],
        };
        $bold = static fn (string $text): string => $open.$escape($text).$close;
        $payment = $change['current'] === 0 ? 'Обеспечительный платёж не предусмотрен.'
            : 'Обеспечительный платёж с НДС — '.$bold(Controller::money($change['amount'])).'.';
        return 'Кратность обеспечения изменена с '.$bold((string)$change['previous']).' до '
            .$bold((string)$change['current']).'. '.$payment."\n";
    }
}
