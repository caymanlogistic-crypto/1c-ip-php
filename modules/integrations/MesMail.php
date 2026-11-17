<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Assigned mailbox only. Strict TLS, EXAMINE, bounded UID search and BODY.PEEK; no mail mutations. */
final class MesMail {
    public const HOST = 'mail.dltkmsk.ru';
    public const MAX_MESSAGE = 262144;
    private mixed $socket = null;
    private int $sequence = 0;
    private float $deadline;
    private string $recipient;
    public function __construct(private array $settings,
        private mixed $fixture = null,
        private mixed $wall = null,
        private mixed $connector = null) {
        $keys = array_keys($settings);
        sort($keys);
        if ($keys !== ['host',
                'mailbox',
                'password',
                'username'] || $settings['host'] !== self::HOST)throw new \RuntimeException('MAIL_PROFILE_INVALID');
        foreach ($settings as $s)if (!is_string($s) || $s === '' || strlen($s) > 2048 || preg_match('/[\x00-\x1f\x7f]/',
            $s))throw new \RuntimeException('MAIL_PROFILE_INVALID');
        if (!filter_var($settings['mailbox'],
            FILTER_VALIDATE_EMAIL))throw new \RuntimeException('MAIL_PROFILE_INVALID');
        $this->recipient = strtolower($settings['mailbox']);
        $this->deadline = hrtime(true)/1e9+110;
    }
    public function key(): string {
        return hash('sha256',
            self::HOST . "\0" . strtolower($this->settings['username']));
    }
    public function now(): int {
        return is_callable($this->wall)?(int)($this->wall)():time();
    }
    public function connect(): void {
        if (is_callable($this->fixture)){
            ($this->fixture)('CONNECT',
                []);
            return;
        }
        if (!is_callable($this->connector) && (!Config::liveAllowed() || getenv('IPPHP_ALLOW_MES_MAIL') !== 'owner-authorized-assigned-mailbox'))throw new \RuntimeException('MAIL_ACCESS_NOT_AUTHORIZED');
        $ssl = ['verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => self::HOST,
            'SNI_enabled' => true,
            'allow_self_signed' => false];
        if ($ca = Support::caFile())$ssl['cafile'] = $ca;
        $context = stream_context_create(['ssl' => $ssl]);
        $this->socket = is_callable($this->connector)?($this->connector)($ssl):@stream_socket_client('tls://' . self::HOST . ':993',
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT,
            $context);
        if (!is_resource($this->socket) || empty(stream_get_meta_data($this->socket)['crypto']['protocol']))throw new \RuntimeException('MAIL_TLS_OR_NETWORK');
        stream_set_timeout($this->socket,
            15);
        $greeting = $this->line();
        if (!preg_match('/^\* OK\b/',
            $greeting))throw new \RuntimeException('MAIL_GREETING_INVALID');
    }
    private static function quote(string $text): string {
        if (preg_match('/[\x00-\x1f\x7f]/',
            $text))throw new \RuntimeException('MAIL_PROFILE_INVALID');
        return '"' . str_replace(['\\',
                '"'],
            ['\\\\',
                '\\"'],
            $text) . '"';
    }
    private function line(): string {
        if (hrtime(true)/1e9 > $this->deadline)throw new \RuntimeException('MAIL_TIMEOUT');
        $line = fgets($this->socket,
            8194);
        if (!is_string($line) || strlen($line) > 8192 || !str_ends_with($line,
            "\r\n"))throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        return substr($line,
            0,
            -2);
    }
    private function command(string $operation,
        array $args = []): array {
        if (!in_array($operation,
            ['LOGIN',
                'EXAMINE',
                'STATUS',
                'SEARCH',
                'HEADER',
                'BODY',
                'LOGOUT'],
            true))throw new \RuntimeException('MAIL_OPERATION_REJECTED');
        if (is_callable($this->fixture)){
            $r = ($this->fixture)($operation,
                $args);
            if (!is_array($r))throw new \RuntimeException('MAIL_RESPONSE_INVALID');
            return $r;
        }
        $command = match ($operation){
            'LOGIN' => 'LOGIN ' . self::quote($this->settings['username']) . ' ' . self::quote($this->settings['password']),
            'EXAMINE' => 'EXAMINE INBOX',
            'STATUS' => 'STATUS INBOX (UIDVALIDITY UIDNEXT)',
            'SEARCH' => 'UID SEARCH UID ' . $args['baseline'] . ':* HEADER To ' . self::quote($this->recipient) . ' HEADER From "mosenergosbyt.ru"',
            'HEADER' => 'UID FETCH ' . $args['uid'] . ' (UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO DATE)])',
            'BODY' => 'UID FETCH ' . $args['uid'] . ' (UID FLAGS BODY.PEEK[])',
            'LOGOUT' => 'LOGOUT'
        }
        ;
        $tag = 'A' . str_pad((string)++$this->sequence,
            5,
            '0',
            STR_PAD_LEFT);
        $wire = $tag . ' ' . $command . "\r\n";
        if (fwrite($this->socket,
            $wire) !== strlen($wire))throw new \RuntimeException('MAIL_TLS_OR_NETWORK');
        $lines = [];
        $literals = [];
        $total = 0;
        for ($i = 0;
            $i < 256;
            $i++){
            $line = $this->line();
            $total+=strlen($line);
            if ($total > 1048576)throw new \RuntimeException('MAIL_RESPONSE_LIMIT');
            if (str_starts_with($line,
                $tag . ' ')){
                if (!preg_match('/^' . preg_quote($tag,
                    '/') . ' OK\b/',
                    $line))throw new \RuntimeException($operation === 'LOGIN'?'MAIL_AUTH_FAILED':'MAIL_READONLY_FAILED');
                return ['lines' => [...$lines,
                    $line],
                    'literals' => $literals];
            }
            $lines[] = $line;
            if (preg_match('/\{([0-9]{1,10})\}$/D',
                $line,
                $m)){
                $length = (int)$m[1];
                if ($length > self::MAX_MESSAGE)throw new \RuntimeException('MAIL_MESSAGE_LIMIT');
                $raw = '';
                while (strlen($raw) < $length){
                    $part = fread($this->socket,
                        $length-strlen($raw));
                    if ($part === false || $part === '')throw new \RuntimeException('MAIL_RESPONSE_INVALID');
                    $raw .= $part;
                }
                $total+=strlen($raw);
                $literals[] = ['metadata' => $line,
                    'raw' => $raw];
            }
        }
        throw new \RuntimeException('MAIL_RESPONSE_LIMIT');
    }
    public function open(): void {
        $this->connect();
        $this->command('LOGIN');
        $r = $this->command('EXAMINE');
        if (!str_contains(implode(' ',
            $r['lines']??[]),
            'READ-ONLY'))throw new \RuntimeException('MAIL_READONLY_FAILED');
    }
    public function close(): void {
        if (is_callable($this->fixture)){
            try{
                $this->command('LOGOUT');
            }
            catch (\Throwable){
            }
            return;
        }
        if (is_resource($this->socket)){
            try{
                $this->command('LOGOUT');
            }
            catch (\Throwable){
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }
    public function watermark(): array {
        $r = $this->command('STATUS');
        $text = implode(' ',
            $r['lines']??[]);
        preg_match_all('/\bUIDVALIDITY ([0-9]+)\b/',
            $text,
            $v);
        preg_match_all('/\bUIDNEXT ([0-9]+)\b/',
            $text,
            $n);
        if (count($v[1]) !== 1 || count($n[1]) !== 1)throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        $validity = (int)$v[1][0];
        $next = (int)$n[1][0];
        if ($validity < 1 || $validity >= 4294967296 || $next < 1 || $next >= 4294967296)throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        return [$validity,
            $next];
    }
    private function fetch(int $uid,
        bool $body): array {
        $r = $this->command($body?'BODY':'HEADER',
            ['uid' => $uid]);
        $literals = $r['literals']??[];
        if (count($literals) !== 1)throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        $meta = $literals[0]['metadata']??'';
        $raw = $literals[0]['raw']??null;
        preg_match_all('/\bUID ([0-9]+)\b/',
            $meta,
            $ids);
        preg_match_all('/\bFLAGS \(([^)]*)\)/',
            $meta,
            $flags);
        if ($ids[1] !== [(string)$uid] || count($flags[1]) !== 1 || !preg_match('/\{([0-9]{1,10})\}$/D',
            $meta,
            $length) || !is_string($raw))throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        if (strlen($raw) > self::MAX_MESSAGE || (int)$length[1] > self::MAX_MESSAGE)throw new \RuntimeException('MAIL_MESSAGE_LIMIT');
        if (strlen($raw) !== (int)$length[1])throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        $f = preg_split('/\s+/',
            trim($flags[1][0]),
            -1,
            PREG_SPLIT_NO_EMPTY);
        sort($f);
        return ['metadata' => $meta,
            'raw' => $raw,
            'flags' => $f];
    }
    public function poll(int $validity,
        int $baseline,
        int $requested,
        array $consumed = []): ?array {
        $now = $this->now();
        if ($now < $requested || $now-$requested > 300)throw new \RuntimeException('MAIL_REQUEST_EXPIRED');
        if ($this->watermark()[0] !== $validity)throw new \RuntimeException('MAIL_UIDVALIDITY_CHANGED');
        if ($baseline < 1 || $baseline >= 4294967296)throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        $r = $this->command('SEARCH',
            ['baseline' => $baseline]);
        $lines = array_values(array_filter($r['lines']??[],
            static fn($l) => str_starts_with($l,
            '* SEARCH')));
        if (count($lines) !== 1 || !preg_match('/^\* SEARCH(?: ([0-9]+(?: [0-9]+)*))?$/D',
            $lines[0],
            $m))throw new \RuntimeException('MAIL_RESPONSE_INVALID');
        $ids = isset($m[1])?explode(' ',
            $m[1]):[];
        if (count($ids) > 64 || count(array_unique($ids)) !== count($ids))throw new \RuntimeException('MAIL_MESSAGE_LIMIT');
        $found = [];
        foreach ($ids as $id){
            $uid = (int)$id;
            if ($uid < 1 || $uid >= 4294967296)throw new \RuntimeException('MAIL_RESPONSE_INVALID');
            if ($uid < $baseline)continue;
            if (in_array($uid,
                $consumed,
                true))throw new \RuntimeException('MAIL_REPLAY');
            $header = $this->fetch($uid,
                false);
            if (!self::matches($header['raw'],
                $this->recipient,
                $requested,
                $now))continue;
            preg_match_all('/\bRFC822.SIZE ([0-9]+)\b/',
                $header['metadata'],
                $sizes);
            preg_match_all('/\bINTERNALDATE "([^"]+)"/',
                $header['metadata'],
                $dates);
            if (count($sizes[1]) !== 1 || (int)$sizes[1][0] > self::MAX_MESSAGE)throw new \RuntimeException('MAIL_MESSAGE_LIMIT');
            if (count($dates[1]) !== 1 || !self::fresh($dates[1][0],
                $requested,
                $now))continue;
            $message = $this->fetch($uid,
                true);
            if ($header['flags'] !== $message['flags'])throw new \RuntimeException('MAIL_FLAGS_CHANGED');
            if (!self::matches($message['raw'],
                $this->recipient,
                $requested,
                $now))throw new \RuntimeException('MAIL_RESPONSE_INVALID');
            $found[$uid] = self::code($message['raw']);
        }
        if (!$found)return null;
        if ($this->watermark()[0] !== $validity)throw new \RuntimeException('MAIL_UIDVALIDITY_CHANGED');
        $codes = array_unique(array_values($found));
        if (count($codes) !== 1)throw new \RuntimeException('MAIL_AMBIGUOUS_CODE');
        return ['code' => reset($codes),
            'uids' => array_keys($found)];
    }
    /** Wait for this original cursor only. Local bounds are not an issuer OTP TTL. */
    public function wait(int $validity, int $baseline, int $requested, array $consumed = [], ?callable $pause = null, ?callable $monotonic = null): ?array
    {
        $tick = $monotonic ?? static fn(): float => hrtime(true)/1e9;
        $sleep = $pause ?? static function(float $seconds): void { usleep((int)round($seconds*1000000)); };
        $age = $this->now()-$requested;
        if ($age < 0 || $age > 300) throw new \RuntimeException('MAIL_REQUEST_EXPIRED');
        $deadline = min($this->deadline, $tick()+110, $tick()+300-$age);
        while (true) {
            $message = $this->poll($validity, $baseline, $requested, $consumed);
            if ($message !== null) return $message;
            $remaining = $deadline-$tick();
            if ($remaining <= 0) return null;
            $sleep(min(5.0, $remaining));
            if ($tick() >= $deadline) return null;
        }
    }

    private static function parts(string $raw): array {
        if (strlen($raw) > self::MAX_MESSAGE || !str_contains($raw,
            "\r\n\r\n"))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
        [$header,
            $body] = explode("\r\n\r\n",
            $raw,
            2);
        $header = preg_replace('/\r\n[ \t]+/',
            ' ',
            $header);
        $headers = [];
        foreach (explode("\r\n",
            $header) as $line){
            if (!preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/D',
                $line,
                $m))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
            $headers[strtolower($m[1])][] = $m[2];
        }
        return [$headers,
            $body];
    }
    private static function fresh(string $date,
        int $requested,
        int $now): bool {
        if (!preg_match('/(?:[+-][0-9]{4}|\b(?:GMT|UTC|UT))$/iD',
            trim($date)))return false;
        $stamp = strtotime($date);
        return $stamp !== false && $stamp >= $requested-5 && $stamp <= $now+120;
    }
    public static function matches(string $raw,
        string $recipient,
        int $requested,
        int $now): bool {
        try{
            [$h] = self::parts($raw);
            foreach (['from',
                    'to',
                    'subject',
                    'date'] as $key)if (count($h[$key]??[]) !== 1)return false;
            preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+/i',
                $h['from'][0],
                $from);
            if (count($from[0]) !== 1)return false;
            $domain = strtolower(substr(strrchr($from[0][0],
                '@'),
                1));
            if ($domain !== 'mosenergosbyt.ru' && !str_ends_with($domain,
                '.mosenergosbyt.ru'))return false;
            preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+/i',
                $h['to'][0],
                $to);
            if (!in_array(strtolower($recipient),
                array_map('strtolower',
                $to[0]),
                true))return false;
            $subject = iconv_mime_decode($h['subject'][0],
                ICONV_MIME_DECODE_STRICT,
                'UTF-8');
            if (!is_string($subject) || !preg_match('/код|провер|подтверж|вход|code|verification|login/iu',
                $subject))return false;
            return self::fresh($h['date'][0],
                $requested,
                $now);
        }
        catch (\Throwable){
            return false;
        }
    }
    public static function code(string $raw): string {
        $texts = [];
        $count = 0;
        $walk = function(string $part,
            int $depth = 0)use(&$walk,
            &$texts,
            &$count){
            if (++$count > 64 || $depth > 16)throw new \RuntimeException('MAIL_MESSAGE_LIMIT');
            [$h,
                $body] = self::parts($part);
            foreach (['content-type',
                    'content-transfer-encoding',
                    'content-disposition'] as $key)if (count($h[$key]??[]) > 1)throw new \RuntimeException('MAIL_MESSAGE_INVALID');
            $type = $h['content-type'][0]??'text/plain; charset=us-ascii';
            if (preg_match('/filename\s*=|attachment/i',
                ($h['content-disposition'][0]??'') . ';' . $type))return;
            if (preg_match('~^multipart/(alternative|mixed|related)\b~i',
                $type)){
                if (!preg_match('/boundary\s*=\s*(?:"([^"\r\n]{1,70})"|([^;\s]{1,70}))/i',
                    $type,
                    $m))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
                $boundary = $m[1]?:$m[2];
                if (!str_contains($body,
                    '--' . $boundary . '--'))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
                $pieces = explode('--' . $boundary,
                    $body);
                array_shift($pieces);
                foreach ($pieces as $piece){
                    if (str_starts_with($piece,
                        '--'))break;
                    if (!str_starts_with($piece,
                        "\r\n") || !str_ends_with($piece,
                        "\r\n"))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
                    $walk(substr($piece,
                        2,
                        -2),
                        $depth+1);
                }
                return;
            }
            if (!preg_match('~^text/(plain|html)(?:;|$)~i',
                $type,
                $kind))return;
            $transfer = strtolower($h['content-transfer-encoding'][0]??'7bit');
            if ($transfer === 'base64'){
                $body = base64_decode(preg_replace('/\s/',
                    '',
                    $body),
                    true);
                if ($body === false)throw new \RuntimeException('MAIL_MESSAGE_INVALID');
            }
            elseif($transfer === 'quoted-printable'){
                if (preg_match('/=(?![a-f0-9]{2}|\r\n)/i',
                    $body))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
                $body = quoted_printable_decode($body);
            }
            elseif(!in_array($transfer,
                ['7bit',
                    '8bit',
                    'binary'],
                true))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
            $charset = 'us-ascii';
            if (preg_match('/charset\s*=\s*"?([A-Za-z0-9_-]+)/i',
                $type,
                $m))$charset = strtolower($m[1]);
            if (!in_array($charset,
                ['utf-8',
                    'us-ascii',
                    'windows-1251',
                    'iso-8859-1'],
                true) || !mb_check_encoding($body,
                $charset))throw new \RuntimeException('MAIL_MESSAGE_INVALID');
            $body = mb_convert_encoding($body,
                'UTF-8',
                $charset);
            if (strtolower($kind[1]) === 'html'){
                $body = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is',
                    ' ',
                    $body);
                $body = html_entity_decode(strip_tags(preg_replace('/<[^>]+>/',
                    ' ',
                    $body)),
                    ENT_QUOTES|ENT_HTML5,
                    'UTF-8');
            }
            $texts[] = $body;
        }
        ;
        $walk($raw);
        preg_match_all('/(?<!\p{Nd})([0-9]{5})(?!\p{Nd})/u',
            implode(' ',
            $texts),
            $m);
        $codes = array_values(array_unique($m[1]));
        if (count($codes) !== 1)throw new \RuntimeException('MAIL_AMBIGUOUS_CODE');
        return $codes[0];
    }
}
