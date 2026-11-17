<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Source probe.py robots_allowed + browser_runtime.py robots availability policy. */
final class MarketRobots
{
    public const AGENT = 'RentalSourceResearch/0.1 (bounded public availability experiment)';

    public static function blocked(string $text): bool
    {
        $start = mb_strtolower(mb_substr($text, 0, 30000));
        foreach (['smartcaptcha','captcha-container','подтвердите, что вы не робот','доступ ограничен',
            'access denied','just a moment...','challenge-platform'] as $token) if (str_contains($start, $token)) return true;
        return false;
    }

    private static function octets(string $value, bool $pattern): string
    {
        // RFC9309 §2.2.2: encoded unreserved octets compare as their characters;
        // non-ASCII octets and literal special characters retain encoded identity.
        $value = preg_replace_callback('/%([A-Fa-f0-9]{2})|[\x80-\xff]/', static function(array $match): string {
            if (isset($match[1]) && $match[1] !== '') {
                $character = chr(hexdec($match[1]));
                return preg_match('/^[A-Za-z0-9._~-]$/D', $character) ? $character : '%'.strtoupper($match[1]);
            }
            return sprintf('%%%02X', ord($match[0]));
        }, $value);
        $special = [':' => '%3A','[' => '%5B',']' => '%5D','@' => '%40','!' => '%21',
            '$' => '%24',"'" => '%27','(' => '%28',')' => '%29','+' => '%2B',',' => '%2C',';' => '%3B'];
        if (!$pattern) $special['*'] = '%2A';
        return strtr($value, $special);
    }

    public static function allowed(string $text, string $url, string $agent = self::AGENT): bool
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        $groups = []; $agents = []; $rules = []; $seenRule = false;
        foreach (preg_split('/\r\n|\n|\r/', $text) as $raw) {
            $line = trim(explode('#', $raw, 2)[0]);
            if (!str_contains($line, ':')) continue;
            [$key,$value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($seenRule) { $groups[] = [$agents,$rules]; $agents = $rules = []; $seenRule = false; }
                $agents[] = strtolower($value);
            } elseif (in_array($key, ['allow','disallow'], true) && $agents !== []) {
                $seenRule = true;
                if ($value !== '') $rules[] = [$key,$value];
            }
        }
        if ($agents !== []) $groups[] = [$agents,$rules];
        $candidates = []; $specificity = -1;
        foreach ($groups as [$names,$entries]) foreach ($names as $name) {
            if ($name !== '*' && !str_contains(strtolower($agent), $name)) continue;
            $score = $name === '*' ? 0 : strlen($name);
            $specificity = max($specificity, $score);
            $candidates[] = [$score,$entries];
        }
        $parts = parse_url($url);
        if ($parts === false) throw new RuntimeException('Invalid robots target.');
        $target = self::octets(($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''), false);
        $longest = -1; $allow = true;
        foreach ($candidates as [$score,$entries]) {
            if ($score !== $specificity) continue;
            foreach ($entries as [$kind,$pattern]) {
                $anchored = str_ends_with($pattern, '$');
                $body = self::octets($anchored ? substr($pattern, 0, -1) : $pattern, true);
                $regex = '~^'.implode('.*', array_map(static fn($part) => preg_quote($part, '~'), explode('*', $body))).($anchored ? '$' : '').'~';
                if (!preg_match($regex, $target)) continue;
                $length = strlen(str_replace('*', '', $body));
                if ($length > $longest || ($length === $longest && $kind === 'allow')) {
                    $longest = $length; $allow = $kind === 'allow';
                }
            }
        }
        return $allow;
    }

    public static function inspect(int $status, string $text, string $url, string $policy): array
    {
        if (!in_array($policy, ['strict_stop','rfc9309'], true)) throw new RuntimeException('Unknown robots availability policy.');
        $result = ['stage' => 'robots', 'robots_http' => $status, 'robots_unavailable_policy' => $policy];
        $text = str_replace(["\r\n","\r"], "\n", $text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        if ($status === 404 || ($status >= 400 && $status <= 499 && $status !== 429 && $policy === 'rfc9309')) {
            $text = '';
        } elseif ($status !== 200 || self::blocked($text) || str_contains(strtolower($text), '<html')) {
            return $result + ['outcome' => 'blocked', 'reason' => 'robots_unavailable'];
        }
        preg_match_all('/^\s*crawl-delay\s*:\s*(\d+(?:\.\d+)?)/im', $text, $matches);
        $delay = 0;
        foreach ($matches[1] as $value) {
            if (strlen($value) > 100 || bccomp($value, '60', max(6, strlen($value))) > 0) return $result + ['outcome' => 'blocked', 'reason' => 'crawl_delay_over_budget'];
            $delay = max($delay, (int)bcmul($value, '1000', 0));
        }
        return $result + ['outcome' => self::allowed($text, $url) ? 'allowed' : 'blocked', 'crawl_delay_ms' => $delay];
    }
}
