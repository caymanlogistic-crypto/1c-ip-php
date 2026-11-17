<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\Runtime;
use Closure;
use RuntimeException;

/** Bounded public HTTP adapter. Browser rendering and product job admission are separate. */
final class MarketHttpClient
{
    public const MAX_BYTES = 3000000;
    private Closure $resolver;
    private Closure $wire;
    private Closure $clock;
    private Closure $pause;
    private ?int $last = null;
    private array $robots = [];

    public function __construct(private bool $networkAdmitted = false, private int $timeoutMs = 15000,
        private int $pauseMs = 1500, ?callable $resolver = null, ?callable $wire = null,
        ?callable $clock = null, ?callable $pause = null)
    {
        if ($timeoutMs < 5000 || $timeoutMs > 60000 || $pauseMs < 1000 || $pauseMs > 10000) throw new RuntimeException('Market HTTP bounds invalid.');
        $this->resolver = $resolver ? Closure::fromCallable($resolver) : static function(string $host): array {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false) throw new RuntimeException('market_dns_failed');
            $result = [];
            foreach ($records as $record) if (isset($record['ip']) || isset($record['ipv6'])) $result[] = $record['ip'] ?? $record['ipv6'];
            return $result;
        };
        $this->wire = $wire ? Closure::fromCallable($wire) : self::nativeWire(...);
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn(): int => intdiv(hrtime(true), 1000000);
        $this->pause = $pause ? Closure::fromCallable($pause) : static fn(int $milliseconds) => usleep($milliseconds * 1000);
    }

    private static function cidr(string $address, string $network): bool
    {
        [$base,$bits] = explode('/', $network);
        $raw = inet_pton($address); $prefix = inet_pton($base);
        if ($raw === false || $prefix === false || strlen($raw) !== strlen($prefix)) return false;
        $whole = intdiv((int)$bits, 8); $remainder = (int)$bits % 8;
        if (substr($raw, 0, $whole) !== substr($prefix, 0, $whole)) return false;
        return $remainder === 0 || (ord($raw[$whole]) >> (8 - $remainder)) === (ord($prefix[$whole]) >> (8 - $remainder));
    }

    public static function publicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || in_array($address, ['89.208.213.14','89.208.213.17'], true)) return false;
        $raw = inet_pton($address);
        if (strlen($raw) === 16) {
            // No mapped/tunnel/local aliases: an actual public unicast AAAA is required.
            return self::cidr($address, '2000::/3') && !self::cidr($address, '2001:db8::/32')
                && !self::cidr($address, '2001::/32');
        }
        foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16',
            '172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.168.0.0/16','198.18.0.0/15',
            '198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) {
            if (self::cidr($address, $range)) return false;
        }
        return true;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\\\\]/', $url)) throw new RuntimeException('market_unsafe_origin');
        return 'https://'.strtolower($parts['host']);
    }

    private static function validateUrl(string $url, string $expectedOrigin, bool $resource = false): void
    {
        if (self::origin($url) !== $expectedOrigin) throw new RuntimeException('market_cross_origin');
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if (preg_match('~/(?:login|account|passport'.($resource?'':'|api').')(?:/|$)|%2e|%2f|%5c|%(?![0-9a-f]{2})|(?:^|/)\.\.(?:/|$)~i', $path)) throw new RuntimeException('market_unsafe_path');
    }

    private static function plan(string $url, string $expectedOrigin, array $addresses, bool $resource = false): array
    {
        self::validateUrl($url, $expectedOrigin, $resource);
        if (!array_is_list($addresses) || $addresses === [] || count($addresses) > 16) throw new RuntimeException('market_dns_no_addresses');
        foreach ($addresses as $address) if (!is_string($address) || !self::publicAddress($address)) throw new RuntimeException('market_forbidden_ip');
        $host = parse_url($url, PHP_URL_HOST);
        $address = $addresses[0];
        return ['url' => $url, 'host' => $host, 'address' => $address,
            'resolve' => $host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)];
    }

    /** Pure policy fixture seam: it does not resolve DNS or send a request. */
    public static function destination(string $source, string $seed, array $addresses): array
    {
        MarketSourceUrls::seed($source, $seed);
        return self::plan($seed, self::origin($seed), $addresses);
    }

    private static function nativeWire(array $options): array
    {
        $body = ''; $headers = []; $headerBytes = 0; $tooLarge = false;
        $options[CURLOPT_WRITEFUNCTION] = static function($curl, string $chunk) use(&$body,&$tooLarge): int {
            if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        };
        $options[CURLOPT_HEADERFUNCTION] = static function($curl, string $line) use(&$headers,&$headerBytes,&$tooLarge): int {
            $headerBytes += strlen($line);
            if ($headerBytes > 32768) { $tooLarge = true; return 0; }
            if (str_starts_with($line, 'HTTP/')) $headers = [];
            elseif (str_contains($line, ':')) {
                [$name,$value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                if (in_array($name, ['location','content-type','content-encoding','content-length','content-security-policy',
                    'x-content-type-options','referrer-policy','access-control-allow-origin','access-control-allow-methods','cross-origin-resource-policy'], true)) {
                    $headers[$name] = isset($headers[$name]) ? $headers[$name].','.trim($value) : trim($value);
                }
            }
            return strlen($line);
        };
        $curl = curl_init();
        try {
            curl_setopt_array($curl, $options);
            curl_exec($curl);
            if ($tooLarge) throw new RuntimeException('market_response_too_large');
            if (curl_errno($curl) !== 0) throw new RuntimeException('market_transport_failed', curl_errno($curl));
            return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body,
                'primary_ip' => (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP),
                'ssl_verify_result' => (int)curl_getinfo($curl, CURLINFO_SSL_VERIFYRESULT)];
        } finally { curl_close($curl); }
    }

    private function fetch(string $url, string $origin, string $method = 'GET', bool $resource = false): array
    {
        if (!$this->networkAdmitted) throw new RuntimeException('market_collection_not_admitted');
        self::validateUrl($url, $origin, $resource);
        if (!in_array($method,['GET','HEAD'],true)) throw new RuntimeException('market_non_read_method');
        $plan = self::plan($url, $origin, ($this->resolver)(parse_url($url, PHP_URL_HOST)), $resource);
        $now = ($this->clock)();
        if ($this->last !== null) {
            $wait = max(0, $this->pauseMs - ($now - $this->last));
            if ($wait) ($this->pause)($wait);
        }
        $this->last = ($this->clock)();
        $options = [CURLOPT_URL => $url, CURLOPT_RESOLVE => [$plan['resolve']], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $this->timeoutMs), CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_USERAGENT => MarketRobots::AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain,application/json','Accept-Encoding: identity']];
        $ca = getenv('APP_INTEGRATION_CAFILE') ?: Runtime::localCaFile();
        if ($method==='HEAD') $options[CURLOPT_NOBODY]=true;
        if ($ca) $options[CURLOPT_CAINFO] = $ca;
        $response = ($this->wire)($options);
        if (!is_array($response) || !is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599 || !is_array($response['headers'] ?? null)
            || !is_string($response['body'] ?? null) || !is_string($response['primary_ip'] ?? null)
            || ($response['ssl_verify_result'] ?? null) !== 0
            || inet_pton($response['primary_ip']) !== inet_pton($plan['address'])) throw new RuntimeException('market_transport_identity_failed');
        $body = $response['body'];
        if (strlen($body) > self::MAX_BYTES) throw new RuntimeException('market_response_too_large');
        if (isset($response['headers']['content-length'])) {
            $length = $response['headers']['content-length'];
            if (!is_string($length) || !preg_match('/^[0-9]{1,10}$/D', $length) || (int)$length>self::MAX_BYTES
                || ($method!=='HEAD' && (int)$length !== strlen($body))) throw new RuntimeException('market_response_length_mismatch');
        }
        if (!in_array(strtolower($response['headers']['content-encoding'] ?? 'identity'), ['','identity'], true)) throw new RuntimeException('market_unsupported_content_encoding');
        $charset = 'UTF-8';
        if (preg_match('/charset\s*=\s*["\x27]?([A-Za-z0-9_-]+)/i', $response['headers']['content-type'] ?? '', $match)) $charset = $match[1];
        try { $text = mb_convert_encoding($body, 'UTF-8', $charset); }
        catch (\ValueError) { throw new RuntimeException('market_unsupported_charset'); }
        return ['url' => $url, 'http_status' => $response['status'], 'bytes' => strlen($body),
            'sha256' => hash('sha256', $body), 'text' => $text, 'body' => $body, 'headers' => $response['headers']];
    }

    private static function redirect(string $current, string $location, string $origin): string
    {
        if ($location === '' || str_starts_with($location, '//') || str_contains($location, ',')) throw new RuntimeException('market_unsafe_redirect');
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location) && !str_starts_with($location, 'https://')) throw new RuntimeException('market_unsafe_redirect_scheme');
        if (str_starts_with($location, 'https://')) $target = $location;
        elseif (str_starts_with($location, '/')) $target = $origin.$location;
        else $target = $origin.rtrim(dirname(parse_url($current, PHP_URL_PATH) ?: '/'), '/').'/'.$location;
        if (self::origin($target) !== $origin) throw new RuntimeException('market_cross_origin_redirect_requires_robots');
        return $target;
    }

    public function load(string $source, string $seed, string $policy = 'strict_stop'): array
    {
        if (!in_array($policy, ['strict_stop','rfc9309'], true)) throw new RuntimeException('Unknown robots availability policy.');
        MarketSourceUrls::seed($source, $seed);
        $origin = self::origin($seed);
        if (!isset($this->robots[$origin])) {
            $url = $origin.'/robots.txt';
            for ($count = 0; $count < 4; $count++) {
                $response = $this->fetch($url, $origin);
                if (!in_array($response['http_status'], [301,302,303,307,308], true)) break;
                if ($count === 3) throw new RuntimeException('market_too_many_redirects');
                $url = self::redirect($url, $response['headers']['location'] ?? '', $origin);
            }
            $this->robots[$origin] = $response;
        }
        $robots = $this->robots[$origin];
        $decision = MarketRobots::inspect($robots['http_status'], $robots['text'], $seed, $policy);
        if ($decision['outcome'] !== 'allowed') return $decision + ['html' => null];
        $this->pauseMs = max($this->pauseMs, $decision['crawl_delay_ms']);
        $response = $this->fetch($seed, $origin);
        $result = ['stage' => 'page', 'http_status' => $response['http_status'], 'bytes' => $response['bytes'], 'sha256' => $response['sha256']];
        if (in_array($response['http_status'], [301,302,303,307,308], true)) return $result + ['outcome' => 'blocked', 'reason' => 'page_redirect_not_followed', 'html' => null];
        if ($response['http_status'] !== 200 || MarketRobots::blocked($response['text'])) return $result + ['outcome' => 'blocked', 'html' => null];
        return $result + ['outcome' => 'fetched', 'html' => $response['text']];
    }

    /** Browser request fulfillment uses the same strict pinned wire, never browser credentials. */
    public function resource(string $source,string $url,string $method,string $type,string $policy='strict_stop'): array
    {
        if (!in_array($policy,['strict_stop','rfc9309'],true)) throw new RuntimeException('Unknown robots availability policy.');
        $origin=MarketBrowserPolicy::check($source,$url,$method,$type);
        if (!isset($this->robots[$origin])) {
            $robotUrl=$origin.'/robots.txt';
            for($index=0;$index<4;$index++) {
                $robots=$this->fetch($robotUrl,$origin);
                if(!in_array($robots['http_status'],[301,302,303,307,308],true)) break;
                if($index===3) throw new RuntimeException('market_too_many_redirects');
                $robotUrl=self::redirect($robotUrl,$robots['headers']['location']??'',$origin);
            }
            $this->robots[$origin]=$robots;
        }
        $robots=$this->robots[$origin];
        $decision=MarketRobots::inspect($robots['http_status'],$robots['text'],$url,$policy);
        if($decision['outcome']!=='allowed') return $decision+['body'=>null];
        $this->pauseMs=max($this->pauseMs,$decision['crawl_delay_ms']);
        $response=$this->fetch($url,$origin,$method,true);
        if($response['http_status']!==200 || !MarketBrowserPolicy::contentType($type,$response['headers']['content-type']??'')) {
            return ['outcome'=>'blocked','stage'=>'resource','http_status'=>$response['http_status'],'body'=>null];
        }
        return ['outcome'=>'fetched','stage'=>'resource','http_status'=>200,'body'=>$response['body'],
            'headers'=>$response['headers'],'sha256'=>$response['sha256'],'bytes'=>$response['bytes']];
    }
}
