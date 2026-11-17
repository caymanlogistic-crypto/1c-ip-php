<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Public listing URL contract from the approved source collector; no HTTP here. */
final class MarketSourceUrls
{
    private const HOSTS = [
        'cian' => ['cian.ru', 'www.cian.ru'],
        'yandex' => ['realty.yandex.ru'],
        'morrow' => ['morrowgroup.ru', 'www.morrowgroup.ru'],
        'fortex' => ['fortexgroup.ru', 'www.fortexgroup.ru'],
    ];
    private const CIAN_SEARCH = [
        'deal_type' => 'rent', 'engine_version' => '2', 'offer_type' => 'offices',
        'office_type[0]' => '1', 'street[0]' => '[1-9][0-9]{0,9}', 'p' => '[1-9][0-9]{0,3}',
    ];

    private static function parts(string $source, string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\\\\]/', $url)) throw new RuntimeException('Invalid public market URL.');
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (!isset(self::HOSTS[$source]) || $parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($host, self::HOSTS[$source], true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) throw new RuntimeException('Market URL outside public source allowlist.');
        $path = $parts['path'] ?? '/';
        $prefixes = match ($source) {
            'cian' => ['/snyat-', '/rent/commercial/'], 'yandex' => ['/moskva', '/offer/'],
            'morrow' => ['/biznescentr/'], 'fortex' => ['/bc/'],
        };
        $allowed = $source === 'cian' && $path === '/cat.php';
        foreach ($prefixes as $prefix) $allowed = $allowed || str_starts_with($path, $prefix);
        if (!$allowed || preg_match('~/(?:login|account|api|passport)(?:/|$)~i', $path)
            || preg_match('/%2e|%2f|%5c/i', $path) || in_array('..', explode('/', $path), true)) throw new RuntimeException('Market URL is not an unambiguous public listing/detail path.');
        $parts['host'] = $host;
        return $parts;
    }

    private static function query(string $raw): array
    {
        $pairs = [];
        if ($raw === '') return $pairs;
        foreach (explode('&', $raw) as $pair) {
            $split = explode('=', $pair, 2);
            $key = urldecode($split[0]);
            $value = urldecode($split[1] ?? '');
            if (array_key_exists($key, $pairs)) throw new RuntimeException('Duplicate market query key.');
            $pairs[$key] = $value;
        }
        return $pairs;
    }

    private static function validators(string $source, string $path): array
    {
        return $source === 'cian' && $path === '/cat.php' ? self::CIAN_SEARCH : ['page' => '[1-9][0-9]{0,3}', 'p' => '[1-9][0-9]{0,3}'];
    }

    /** Configuration seeds reject tracking, fragments and every unknown parameter. */
    public static function seed(string $source, string $url): string
    {
        $parts = self::parts($source, $url);
        if (isset($parts['fragment'])) throw new RuntimeException('Market seed fragments are not admitted.');
        $pairs = self::query($parts['query'] ?? '');
        $validators = self::validators($source, $parts['path']);
        if ($source === 'cian' && $parts['path'] === '/cat.php') {
            $keys = array_keys($pairs); $expected = array_keys(self::CIAN_SEARCH);
            sort($keys); sort($expected);
            if ($keys !== $expected) throw new RuntimeException('Cian search must preserve the complete observed filter set.');
        }
        foreach ($pairs as $key => $value) if (!isset($validators[$key]) || !preg_match('~^(?:'.$validators[$key].')$~D', $value)) throw new RuntimeException('Unsupported market query.');
        return $url;
    }

    /** Source card cleanup strips tracking before the strict transport validates it. */
    public static function clean(string $source, string $url): string
    {
        $parts = self::parts($source, $url);
        $validators = self::validators($source, $parts['path']);
        $pairs = [];
        foreach (self::query($parts['query'] ?? '') as $key => $value) {
            if (isset($validators[$key]) && preg_match('~^(?:'.$validators[$key].')$~D', $value)) $pairs[$key] = $value;
        }
        $query = http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
        $clean = 'https://'.$parts['host'].(isset($parts['port']) ? ':443' : '').$parts['path'].($query === '' ? '' : '?'.$query);
        return self::seed($source, $clean);
    }

    private static function cianScope(string $url): ?array
    {
        $parts = self::parts('cian', $url);
        if ($parts['path'] === '/snyat-ofis-moskva-mosfilmovskaya-ulica-021531/') return ['rent', '2', 'offices', '1', '1531'];
        if ($parts['path'] !== '/cat.php') return null;
        self::seed('cian', $url);
        $pairs = self::query($parts['query'] ?? '');
        return array_map(fn($key) => $pairs[$key], ['deal_type', 'engine_version', 'offer_type', 'office_type[0]', 'street[0]']);
    }

    /** Absolute candidate only; caller resolves relative links against the same source. */
    public static function nextCian(string $current, array $candidates): ?string
    {
        $scope = self::cianScope($current);
        if ($scope === null) return null;
        $parts = self::parts('cian', $current);
        $page = (int)(self::query($parts['query'] ?? '')['p'] ?? 1);
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) throw new RuntimeException('Invalid pagination URL.');
            self::seed('cian', $candidate);
            if (self::cianScope($candidate) !== $scope) throw new RuntimeException('Cian pagination changed the original search filters.');
            $parts = self::parts('cian', $candidate);
            if ((int)(self::query($parts['query'] ?? '')['p'] ?? 1) === $page + 1) return $candidate;
        }
        return null;
    }
}
