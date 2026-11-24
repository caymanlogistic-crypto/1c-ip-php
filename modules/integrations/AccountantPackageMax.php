<?php
declare(strict_types=1);

namespace Modules\Integrations;

use Modules\Rental\{AccountantPacketSummary, Controller, InitialInvoice};
use RuntimeException;

final class AccountantPackageMax
{
    public const KEY = 'package';
    public const TOKEN = 'accountantPackagePayments';
    public const LEGACY_VERSION = 3;
    public const VERSION = 4;

    public static function template(): array
    {
        return Support::decode(file_get_contents(__DIR__.'/defaults/accountant-package-max.json'));
    }

    public static function matchesTemplate(array $entry): bool
    {
        if ((int)$entry['version'] < self::VERSION) return false;
        $approved = self::template();
        return $entry['format'] === $approved['format'] && $entry['body_text'] === $approved['body'];
    }

    public static function initialConfirmation(array $event): bool
    {
        return ($event['entity_kind'] ?? null) === 'contract' && ($event['event_kind'] ?? null) === self::KEY
            && ($event['action'] ?? null) === 'contract.packet' && ($event['target_role'] ?? null) === 'manager'
            && ($event['actor_role'] ?? null) === 'accountant'
            && isset($event['entity_id']) && ($event['contract_id'] ?? null) === $event['entity_id']
            && in_array($event['from_status'] ?? null,['accounting','approved'],true)
            && ($event['to_status'] ?? null) === 'active'
            && is_array($event['accountant_packet_confirmation'] ?? null);
    }

    public static function fromEvent(array $event): string
    {
        if (!self::initialConfirmation($event)) throw new RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_REQUIRED');
        $snapshot = $event['accountant_packet_confirmation'];
        $value = ['external_operations'=>$snapshot['external_operations'] ?? null];
        foreach (['planned','issued'] as $group) {
            if (!is_array($snapshot[$group] ?? null) || !array_is_list($snapshot[$group])) {
                throw new RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_INVALID');
            }
            $value[$group] = array_map(static fn (mixed $row): array => is_array($row)
                ? array_intersect_key($row, ['kind'=>true,'amount'=>true]) : [], $snapshot[$group]);
        }
        return Support::json(self::validate($value));
    }

    public static function parse(string $value): array
    {
        try {
            return self::validate(json_decode($value,true,8,JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_INVALID',0,$error);
        }
    }

    private static function validate(mixed $value): array
    {
        if (!is_array($value) || count($value)!==3
            || ($value['external_operations'] ?? null)!==AccountantPacketSummary::EXTERNAL_DISABLED
            || !is_array($value['planned'] ?? null) || !array_is_list($value['planned'])
            || !is_array($value['issued'] ?? null) || !array_is_list($value['issued'])
            || ($value['planned'] && $value['issued'])) {
            throw new RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_INVALID');
        }
        foreach ([...$value['planned'],...$value['issued']] as $row) {
            if (!is_array($row) || count($row)!==2 || !is_string($row['kind'] ?? null)
                || !isset(InitialInvoice::DUE_PARTS[$row['kind']]) || !AccountantPacketSummary::knownAmount($row['amount'] ?? null)) {
                throw new RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_INVALID');
            }
        }
        return $value;
    }

    public static function render(string $value,string $format,callable $escape): string
    {
        if ($value === '') return '';
        $summary = self::parse($value);
        $rows = $summary['issued'] ?: $summary['planned'];
        if (!$rows) return '';
        [$open,$close] = match ($format) {
            'html' => ['<b>','</b>'],
            'markdown' => ['**','**'],
            default => ['',''],
        };
        $bold = static fn (string $text): string => $open.$escape($text).$close;
        $lines = [$bold($summary['issued'] ? 'Ранее выставленные первые счета' : 'Расчёт первых платежей')];
        foreach ($rows as $row) {
            $lines[] = '• '.$escape(InitialInvoice::DUE_PARTS[$row['kind']]).' · '.$bold(Controller::money($row['amount']));
        }
        return implode("\n",$lines)."\n\n";
    }
}
