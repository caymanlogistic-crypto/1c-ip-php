<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\StorageBudget;
use RuntimeException;

/** Source S validate_config; configuration only, with no collection side effect. */
final class MarketSettings
{
    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys); sort($expected);
        if ($keys !== $expected) throw new RuntimeException('Market settings contain missing or unknown fields.');
    }

    private static function bounded(mixed $value, int $low, int $high): void
    {
        if (!is_int($value) || $value < $low || $value > $high) throw new RuntimeException('Market settings integer outside source bounds.');
    }

    private static function decimal(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/D', $value)
            || bccomp($value, '1000000000000000', 4) > 0) throw new RuntimeException('Market filter requires an exact nonnegative decimal.');
        return $value;
    }

    public static function validate(array $value, ?string $declaredRoot = null, bool $reserveSpace = true): array
    {
        self::keys($value, ['version','types','geography','filters','sources','limits','browser','output_root']);
        if ($value['version'] !== 1 || !is_array($value['types']) || !array_is_list($value['types'])
            || $value['types'] === [] || count(array_unique($value['types'], SORT_REGULAR)) !== count($value['types'])) throw new RuntimeException('Market types/version invalid.');
        foreach ($value['types'] as $kind) if (!in_array($kind, ['office','parking'], true)) throw new RuntimeException('Unsupported market type.');
        self::keys($value['geography'], ['base_address','nearby']);
        $base = MarketPrimaryCards::addressKey($value['geography']['base_address']);
        $nearby = $value['geography']['nearby'];
        if (!is_array($nearby) || !array_is_list($nearby) || count($nearby) > 20) throw new RuntimeException('Market nearby list exceeds source bounds.');
        $seen = [$base => true];
        foreach ($nearby as $neighbor) {
            self::keys($neighbor, ['address','include_subunits']);
            $key = MarketPrimaryCards::addressKey($neighbor['address']);
            if (isset($seen[$key]) || !is_bool($neighbor['include_subunits'])) throw new RuntimeException('Duplicate market address or invalid subunit policy.');
            $seen[$key] = true;
        }
        self::keys($value['filters'], ['office','parking']);
        foreach (['office','parking'] as $kind) {
            self::keys($value['filters'][$kind], ['area_min','area_max','price_min','price_max']);
            foreach ($value['filters'][$kind] as &$number) $number = self::decimal($number);
            unset($number);
            foreach ([['area_min','area_max'],['price_min','price_max']] as [$low,$high]) {
                $filter = $value['filters'][$kind];
                if ($filter[$low] !== null && $filter[$high] !== null && bccomp($filter[$low], $filter[$high], 4) > 0) throw new RuntimeException('Market minimum exceeds maximum.');
            }
        }
        self::keys($value['sources'], Market::SOURCES);
        foreach ($value['sources'] as $source => $settings) {
            self::keys($settings, ['enabled','office_urls','parking_urls']);
            if (!is_bool($settings['enabled'])) throw new RuntimeException('Market enabled requires a boolean.');
            foreach (['office','parking'] as $kind) {
                $urls = $settings[$kind.'_urls'];
                if (!is_array($urls) || !array_is_list($urls) || count($urls) > 5
                    || count(array_unique($urls, SORT_REGULAR)) !== count($urls)) throw new RuntimeException('Market seeds require at most five unique URLs.');
                if ($kind === 'parking' && in_array($source, ['morrow','fortex'], true) && $urls !== []) throw new RuntimeException('Dedicated parking adapter is unsupported for this source.');
                foreach ($urls as $url) {
                    if (!is_string($url)) throw new RuntimeException('Market seed must be a string.');
                    MarketSourceUrls::seed($source, $url);
                }
            }
        }
        self::keys($value['limits'], ['max_pages_per_seed','max_listings_per_scope']);
        self::bounded($value['limits']['max_pages_per_seed'], 1, 3);
        self::bounded($value['limits']['max_listings_per_scope'], 1, 100);
        self::keys($value['browser'], ['channel','headless','timeout_ms','pause_ms','robots_unavailable_policy']);
        if (!in_array($value['browser']['channel'], ['chromium','msedge','chrome'], true)
            || !is_bool($value['browser']['headless'])
            || !in_array($value['browser']['robots_unavailable_policy'], ['strict_stop','rfc9309'], true)) throw new RuntimeException('Market ordinary-browser policy invalid.');
        self::bounded($value['browser']['timeout_ms'], 5000, 60000);
        self::bounded($value['browser']['pause_ms'], 1000, 10000);
        $root = $value['output_root'];
        if (!is_string($root) || str_contains($root, '${') || str_contains($root, "\0")) throw new RuntimeException('Market output needs an explicit project-local external artifact path.');
        $own = strtolower(rtrim(str_replace('\\', '/', APP_ROOT), '/'));
        $target = strtolower(rtrim(str_replace('\\', '/', $root), '/'));
        if (!preg_match('~^(?:[a-z]:/|/)~', $target) || str_starts_with($target, '//') || preg_match('~(?:^|/)\.\.(?:/|$)~', $target)) throw new RuntimeException('Invalid absolute market output path.');
        if ($target === $own || str_starts_with($target, $own.'/')) throw new RuntimeException('Market collection output must stay outside the application worktree.');
        if ($reserveSpace) StorageBudget::target(rtrim($root, '/\\').'/market-budget-probe', 16777216, $declaredRoot);
        return $value;
    }
}
