<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Crash-fenced OTP workflow. The code and message bodies are RAM-only; persisted evidence contains UID/hash only. */
final class MesAutoOtp {
    public function __construct(private string $directory,
        private MesMail $mail,
        private mixed $clock = null,
        private mixed $pollFixture = null,
        private mixed $beforeJournal = null) {
    }
    private function now(): int {
        return is_callable($this->clock)?(int)($this->clock)():time();
    }
    private function read(string $path): array {
        if (!file_exists($path))return ['version' => 0,
            'consumed' => []];
        if (is_link($path) || !is_file($path) || filesize($path) > 65536)throw new \RuntimeException('MAIL_STATE_INVALID');
        $j = json_decode((string)file_get_contents($path),
            true,
            32,
            JSON_THROW_ON_ERROR);
        if (!is_array($j) || array_diff(array_keys($j),
            ['version',
                'consumed',
                'active']) || !is_int($j['version']??null) || $j['version'] < 0 || $j['version'] >= PHP_INT_MAX-1 || !is_array($j['consumed']??null) || count($j['consumed']) > 256)throw new \RuntimeException('MAIL_STATE_INVALID');
        foreach ($j['consumed'] as $pair)if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_int($pair[1]) || $pair[0] < 1 || $pair[0] >= 4294967296 || $pair[1] < 1 || $pair[1] >= 4294967296)throw new \RuntimeException('MAIL_STATE_INVALID');
        if (isset($j['active'])){
            $a = $j['active'];
            if (!is_array($a) || array_diff(array_keys($a),
                ['phase',
                    'binding',
                    'requested',
                    'validity',
                    'baseline']) || !in_array($a['phase']??'',
                ['LOGIN_INTENT',
                    'READY',
                    'REQUEST_INTENT',
                    'WAITING',
                    'VERIFY_INTENT'],
                true) || !preg_match('/^(?:[a-f0-9]{64})?$/D',
                $a['binding']??'') || !is_int($a['requested']??null))throw new \RuntimeException('MAIL_STATE_INVALID');
        }
        return $j;
    }
    private function write(string $path,
        array &$j): void {
        if ($this->read($path)['version'] !== $j['version'])throw new \RuntimeException('MAIL_STATE_CHANGED');
        $next = $j;
        $next['version']++;
        $raw = Support::json($next);
        if (strlen($raw) > 65536)throw new \RuntimeException('MAIL_STATE_INVALID');
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        $f = fopen($tmp,
            'xb');
        if (!$f)throw new \RuntimeException('MAIL_STATE_WRITE_FAILED');
        try{
            if (fwrite($f,
                $raw) !== strlen($raw))throw new \RuntimeException('MAIL_STATE_WRITE_FAILED');
            fflush($f);
            if (function_exists('fsync'))fsync($f);
        }
        finally{
            fclose($f);
        }
        chmod($tmp,
            0600);
        if (!rename($tmp,
            $path)){
            unlink($tmp);
            throw new \RuntimeException('MAIL_STATE_WRITE_FAILED');
        }
        $j = $next;
    }
    private function retireExpiredWaiting(MesClient $client,
        string $path,
        array &$journal): ?string {
        $active = $journal['active']??null;
        if (!is_array($active) || $active['phase'] !== 'WAITING' || $this->now()-$active['requested'] <= 300)return null;
        foreach (['auth',
                'access',
                'verified']as$key)if (!empty($client->session[$key]))return null;
        $transaction = $client->session['transactionId']??null;
        if (!is_string($transaction) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',
            $transaction) || !hash_equals($active['binding'],
            hash('sha256',
            $transaction)))return null;
        // Only WAITING proves a successful send ACK and no verification boundary.
        // A crash after retirement remains LOGIN_INTENT and cannot start another login.
        $archive = dirname($path) . '/expired-waiting-' . $active['binding'] . '-v' . $journal['version'] . '.json';
        $file = @fopen($archive,
            'xb');
        if (!$file)throw new \RuntimeException('MAIL_RECOVERY_REQUIRED');
        $raw = Support::json(['schema' => 1,
                'reason' => 'KNOWN_EXPIRED_WAITING',
                'journal' => $journal]);
        try{
            if (fwrite($file,
                $raw) !== strlen($raw))throw new \RuntimeException('MAIL_STATE_WRITE_FAILED');
            fflush($file);
            if (function_exists('fsync'))fsync($file);
        }
        finally{
            fclose($file);
        }
        chmod($archive,
            0600);
        $binding = $active['binding'];
        $journal['active'] = ['phase' => 'LOGIN_INTENT',
            'binding' => '',
            'requested' => $this->now()];
        $this->write($path,
            $journal);
        return $binding;
    }
    public function authenticate(MesClient $client,
        array $credentials,
        callable $saveSession): array {
        $root = $this->directory . '/' . $this->mail->key();
        if (!is_dir($root) && !mkdir($root,
            0700,
            true))throw new \RuntimeException('MAIL_STATE_NOT_PRIVATE');
        for ($p = $root;
            strlen($p) > 3;
            $p = dirname($p))if (is_link($p))throw new \RuntimeException('MAIL_STATE_NOT_PRIVATE');
        $lockPath = $root . '/mailbox.lock';
        if (is_link($lockPath))throw new \RuntimeException('MAIL_STATE_NOT_PRIVATE');
        $lock = fopen($lockPath,
            'c+b');
        if (!$lock || !flock($lock,
            LOCK_EX|LOCK_NB))throw new \RuntimeException('MAIL_FLOW_BUSY');
        $path = $root . '/journal.json';
        try{
            $j = $this->read($path);
            if (is_callable($this->beforeJournal)) ($this->beforeJournal)($j, $client->session);
            $retired = $this->retireExpiredWaiting($client,
                $path,
                $j);
            if (isset($j['active']) && $retired === null){
                $a = $j['active'];
                if (!in_array($a['phase'],
                    ['READY',
                        'WAITING'],
                    true))throw new \RuntimeException('MAIL_RECOVERY_REQUIRED');
                if ($this->now() < $a['requested'] || $this->now()-$a['requested'] > 300)throw new \RuntimeException('MAIL_REQUEST_EXPIRED');
            }
            else {
                $j['active'] = ['phase' => 'LOGIN_INTENT',
                    'binding' => '',
                    'requested' => $this->now()];
                $this->write($path,
                    $j);
                unset($client->session['transactionId'],
                    $client->session['types']);
                try{
                    $result = $client->login($credentials);
                }
                finally{
                    $saveSession($client->session);
                }
                if (($result['status']??'') === 'success'){
                    unset($j['active']);
                    $this->write($path,
                        $j);
                    return $result;
                }
                $tx = $client->session['transactionId']??'';
                if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',
                    $tx) || !in_array('EMAIL',
                    $client->session['types']??[],
                    true))throw new \RuntimeException('MAIL_TRANSACTION_UNAVAILABLE');
                if ($retired !== null && hash_equals($retired,
                    hash('sha256',
                    $tx)))throw new \RuntimeException('MAIL_RECOVERY_REQUIRED');
                $j['active'] = ['phase' => 'READY',
                    'binding' => hash('sha256',
                        $tx),
                    'requested' => $this->now()];
                $this->write($path,
                    $j);
            }
            $tx = $client->session['transactionId']??'';
            if (!is_string($tx) || !hash_equals($j['active']['binding'],
                hash('sha256',
                $tx)))throw new \RuntimeException('MAIL_RECOVERY_REQUIRED');
            $this->mail->open();
            try{
                [$validity,
                    $baseline] = $this->mail->watermark();
                if ($j['active']['phase'] === 'WAITING'){
                    if (($j['active']['validity']??null) !== $validity)throw new \RuntimeException('MAIL_UIDVALIDITY_CHANGED');
                    $baseline = $j['active']['baseline'];
                }
                else {
                    $j['active'] = array_replace($j['active'],
                        ['phase' => 'REQUEST_INTENT',
                            'requested' => $this->now(),
                            'validity' => $validity,
                            'baseline' => $baseline]);
                    $this->write($path,
                        $j);
                    try{
                        $client->requestCode();
                    }
                    finally{
                        $saveSession($client->session);
                    }
                    $j['active']['phase'] = 'WAITING';
                    $this->write($path,
                        $j);
                }
                $consumed = [];
                foreach ($j['consumed'] as $pair)if ($pair[0] === $validity)$consumed[] = $pair[1];
                $message = is_callable($this->pollFixture)
                    ? ($this->pollFixture)($this->mail, $validity, $baseline, $j['active']['requested'], $consumed)
                    : $this->mail->wait($validity, $baseline, $j['active']['requested'], $consumed);
                if ($message === null)return ['status' => 'awaiting_code',
                    'code' => 'MAIL_WAITING_FRESH_MESSAGE'];
                foreach ($message['uids'] as $uid)$j['consumed'][] = [$validity,
                    $uid];
                $j['consumed'] = array_slice($j['consumed'],
                    -256);
                $j['active']['phase'] = 'VERIFY_INTENT';
                $this->write($path,
                    $j);
                $code = $message['code'];
                try{
                    $result = $client->confirm($code);
                    if (($result['status']??'') !== 'success')throw new \RuntimeException('MAIL_VERIFICATION_FAILED');
                    $saveSession($client->session);
                }
                finally{
                    $code = null;
                    $message = null;
                }
                unset($j['active']);
                $this->write($path,
                    $j);
                return ['status' => 'success',
                    'code' => 'AUTHENTICATED_MAIL_OTP'];
            }
            finally{
                $this->mail->close();
            }
        }
        finally{
            flock($lock,
                LOCK_UN);
            fclose($lock);
        }
    }
}
