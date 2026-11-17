<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** One CLI-only owner admission. It never performs document collection or mail sending. */
final class MesFreshFlow
{
    private const ADMISSION = 'owner-mes-fresh-20261116-v1';
    private const BOUNDED_ADMISSION = 'owner-mes-fresh-20261116-v2-bounded-poll';

    public function __construct(private PDO $db, private PrivateStore $store) {}

    private static function authorized(): string
    {
        if (PHP_SAPI !== 'cli' || !Config::liveAllowed()
            || getenv('IPPHP_ALLOW_MES_MAIL') !== 'owner-authorized-assigned-mailbox'
            || !in_array(getenv('IPPHP_MES_FRESH_ADMISSION'), [self::ADMISSION,self::BOUNDED_ADMISSION], true)) {
            throw new RuntimeException('MES_FRESH_FLOW_NOT_AUTHORIZED');
        }
        return getenv('IPPHP_MES_FRESH_ADMISSION');
    }

    private function context(int $expectedRevision): array
    {
        $connection = Config::get($this->db, 'mosenergo');
        $query = $this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='mosenergo'");
        $source = $query->fetch(PDO::FETCH_ASSOC);
        $public = Support::decode($connection['config_json']);
        if (!$connection['enabled'] || (int)$connection['revision'] !== $expectedRevision || !$source
            || ($public['access_reference'] ?? null) !== $source['selected_profile']) {
            throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
        }
        return ['revision'=>$expectedRevision, 'public_json'=>$connection['config_json'],
            'profile'=>$source['selected_profile'], 'source_receipt_sha256'=>$source['source_receipt_sha256']];
    }

    private function unchanged(array $context): void
    {
        if ($this->context($context['revision']) !== $context) throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
    }

    private function previousFlow(array $context): array
    {
        $query = $this->db->prepare("SELECT * FROM integration_runs WHERE provider='mosenergo' AND operation='fresh-mail-auth' AND request_key=?");
        $query->execute([self::ADMISSION.':'.$context['revision']]);
        $run = $query->fetch(PDO::FETCH_ASSOC);
        $coverage = $run ? Support::decode($run['coverage_json']) : [];
        $stages = $coverage['stages'] ?? [];
        if (!$run || $run['status'] !== 'awaiting_code' || $run['result_code'] !== 'MAIL_WAITING_FRESH_MESSAGE'
            || !hash_equals($run['fingerprint'], hash('sha256',Support::canonical($context)))
            || count($stages) !== 2 || ($stages[0]['alias'] ?? '') !== 'fresh_login'
            || ($stages[0]['http_status'] ?? null) !== 424 || ($stages[1]['alias'] ?? '') !== 'otp_request'
            || ($stages[1]['http_status'] ?? null) !== 200) throw new RuntimeException('MES_PREVIOUS_FLOW_NOT_PROVEN');
        $receipt = $this->store->verifySpool((string)($coverage['receipt_path'] ?? ''));
        $original = Support::decode(base64_decode($receipt['raw_base64'], true));
        if (($receipt['context']['run_id'] ?? null) !== $run['id'] || ($receipt['context']['operation'] ?? null) !== 'fresh-mail-auth'
            || ($original['status'] ?? null) !== 'awaiting_code' || ($original['code'] ?? null) !== 'MAIL_WAITING_FRESH_MESSAGE'
            || Support::canonical($original['coverage']['stages'] ?? []) !== Support::canonical($stages)) throw new RuntimeException('MES_PREVIOUS_FLOW_NOT_PROVEN');
        return $run;
    }

    /** Runs while both the provider lock and MesAutoOtp mailbox lock are held. */
    private function closeExpired(array $context,array $journal,array $session): void
    {
        $this->unchanged($context);
        $run = $this->previousFlow($context);
        $active = $journal['active'] ?? null;
        $transaction = $session['transactionId'] ?? null;
        $age = is_int($active['requested'] ?? null) ? time()-$active['requested'] : -1;
        if (!is_array($active) || ($active['phase'] ?? null) !== 'WAITING' || $age <= 300
            || !is_string($transaction) || !hash_equals((string)($active['binding'] ?? ''),hash('sha256',$transaction))
            || !empty($session['auth']) || !empty($session['access']) || !empty($session['verified'])) throw new RuntimeException('MES_PREVIOUS_FLOW_NOT_PROVEN');
        $coverage = Support::decode($run['coverage_json']);
        $closure = ['previous_run_id'=>$run['id'], 'revision'=>$context['revision'], 'reason'=>'LOCAL_WINDOW_EXPIRED',
            'request_age_seconds'=>$age, 'provider_expiry'=>'UNKNOWN', 'verify_requests'=>0,
            'same_binding'=>true, 'original_receipt_hmac_verified'=>true, 'original_receipt_path'=>$coverage['receipt_path'] ?? null];
        $path = $this->store->spool(['provider'=>'mosenergo','run_id'=>$run['id'],'operation'=>'local-window-closure'],Support::json($closure));
        $coverage['local_window_closure'] = $closure+['receipt_path'=>$path];
        $query = $this->db->prepare("UPDATE integration_runs SET status='failed',result_code='LOCAL_WINDOW_EXPIRED',coverage_json=? WHERE id=? AND status='awaiting_code' AND result_code='MAIL_WAITING_FRESH_MESSAGE'");
        $query->execute([Support::json($coverage),$run['id']]);
        if ($query->rowCount() !== 1) throw new RuntimeException('MES_PREVIOUS_FLOW_NOT_PROVEN');
    }

    /** Consume this exact owner admission durably before entering any network flow. */
    public function reserve(int $expectedRevision): array
    {
        $admission = self::authorized();
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) $this->db->beginTransaction();
        try {
            $this->db->query("SELECT provider FROM integration_connections WHERE provider='mosenergo' FOR UPDATE")->fetchColumn();
            $context = $this->context($expectedRevision);
            $requestKey = $admission.':'.$expectedRevision;
            $query = $this->db->prepare("SELECT id FROM integration_runs WHERE provider='mosenergo' AND operation='fresh-mail-auth' AND request_key=? LIMIT 1 FOR UPDATE");
            $query->execute([$requestKey]);
            if ($query->fetchColumn()) throw new RuntimeException('MES_FRESH_FLOW_ALREADY_CONSUMED');
            if ($admission === self::BOUNDED_ADMISSION) $this->previousFlow($context);
            $id = Support::uuid();
            $safe = ['scope'=>'single-fresh-otp-and-account-metadata', 'operative_revision'=>$expectedRevision,
                'source_receipt_sha256'=>$context['source_receipt_sha256'], 'auth_raw_body_archive_forbidden'=>true,
                'mail_body_archive_forbidden'=>true, 'local_request_age_limit_seconds'=>300, 'issuer_otp_ttl'=>'UNKNOWN',
                'metadata_only'=>true, 'documents_read'=>0, 'phase'=>'ADMITTED','admission'=>$admission];
            $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,result_code,coverage_json) VALUES(?,'mosenergo','fresh-mail-auth',?,0,?,?,'pending','ADMITTED',?)")
                ->execute([$id, $requestKey, $expectedRevision, hash('sha256', Support::canonical($context)), Support::json($safe)]);
            if ($ownTransaction) $this->db->commit();
            return ['id'=>$id, 'context'=>$context, 'coverage'=>$safe];
        } catch (\Throwable $error) {
            if ($ownTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function run(int $expectedRevision): array
    {
        $admission = self::authorized();
        if ($this->db->inTransaction()) throw new RuntimeException('MES_FLOW_REQUIRES_NO_OPEN_TRANSACTION');
        $lock = 'ipphp-provider:mosenergo';
        $query = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $query->execute([$lock]);
        if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('PROVIDER_BUSY');
        try {
            $context = $this->context($expectedRevision);
            $profile = Config::profile('mosenergo', $context['profile']);
            if (!is_array($profile['mail'] ?? null)) throw new RuntimeException('MES_ASSIGNED_MAIL_PROFILE_REQUIRED');
            $mail = new MesMail($profile['mail']); // Schema/host/recipient checks, no connection yet.
            foreach (['username','password'] as $key) if (!is_string($profile[$key] ?? null) || $profile[$key] === '') throw new RuntimeException('MES_PROFILE_CREDENTIALS_MISSING');
            if (!empty($profile['session']['auth']) || !empty($profile['session']['access'])) throw new RuntimeException('MES_SESSION_REQUIRES_READONLY_REFRESH');
            $reservation = $this->reserve($expectedRevision);
            if ($reservation['context'] !== $context) throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
            $id = $reservation['id'];
            $safe = $reservation['coverage'];
            $status = 'failed';
            $code = 'MES_FLOW_NOT_CONFIRMED';
            $stages = [];
            $started = microtime(true);
            try {
                $transport = function(string $method, string $path, ?array $body, array $query, array $headers) use ($context, &$stages, $started): array {
                    $this->unchanged($context);
                    // Shared transport has a hard 30s request timeout. Reserve that full
                    // budget before starting a request instead of overshooting the 180s flow.
                    if (microtime(true) - $started > 150) throw new RuntimeException('MES_FLOW_DEADLINE');
                    $alias = match (true) {
                        $method === 'POST' && $path === '/api/v8/users/auth' => 'fresh_login',
                        $method === 'POST' && preg_match('~^/api/v7/users/[a-f0-9-]{36}/email/check/confirmation/send$~D', $path) === 1 => 'otp_request',
                        $method === 'POST' && preg_match('~^/api/v7/users/[a-f0-9-]{36}/email/check/verification$~D', $path) === 1 => 'otp_verify',
                        $method === 'GET' && $path === '/api/v8/accounts' => 'account_metadata',
                        default => throw new RuntimeException('MES_FLOW_ENDPOINT_REJECTED'),
                    };
                    foreach ($stages as $stage) if ($stage['alias'] === $alias) throw new RuntimeException('MES_FLOW_STAGE_NOT_REPLAYABLE');
                    $stages[] = ['alias'=>$alias, 'method'=>$method, 'http_status'=>null];
                    $rawBody = $body === null ? null : ($body === [] ? '{}' : Support::json($body));
                    $response = HttpTransport::request($method, 'https://lkkjr.mosenergosbyt.ru'.$path
                        .($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), $headers, $rawBody);
                    $stages[array_key_last($stages)]['http_status'] = $response['status'];
                    return $response; // Auth bytes remain in memory only.
                };
                $client = new MesClient($profile['session'] ?? [], $transport);
                $save = function(array $session) use (&$profile, $context): void {
                    $this->db->beginTransaction();
                    try {
                        $this->db->query("SELECT provider FROM integration_connections WHERE provider='mosenergo' FOR UPDATE")->fetchColumn();
                        $this->unchanged($context);
                        $profile['session'] = $session;
                        Config::saveProfile('mosenergo', $context['profile'], $profile);
                        $this->db->commit();
                    } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
                };
                $guard = $admission === self::BOUNDED_ADMISSION
                    ? fn(array $journal,array $session)=>$this->closeExpired($context,$journal,$session) : null;
                $result = (new MesAutoOtp(Config::root().'/private/integrations/mes-mail', $mail, beforeJournal:$guard))->authenticate($client, $profile, $save);
                if (($result['status'] ?? null) === 'success') {
                    $accounts = $client->accounts();
                    $safe['accounts_count'] = count($accounts);
                    unset($accounts);
                    $status = 'success';
                    $code = 'MES_AUTH_AND_ACCOUNTS_CONFIRMED';
                } else {
                    $status = 'awaiting_code';
                    $code = 'MAIL_WAITING_FRESH_MESSAGE';
                }
                $this->unchanged($context);
            } catch (\Throwable $error) {
                $allowed = ['AUTH_REQUIRED','MAIL_WAITING_FRESH_MESSAGE','MAIL_RECOVERY_REQUIRED','MAIL_TLS_OR_NETWORK',
                    'MAIL_FLAGS_CHANGED','MAIL_UIDVALIDITY_CHANGED','MAIL_REPLAY','MAIL_AMBIGUOUS_CODE','MAIL_REQUEST_EXPIRED',
                    'MAIL_TRANSACTION_UNAVAILABLE','MAIL_VERIFICATION_FAILED','MES_HTTP_REJECTED','MES_CURRENT_PROFILE_CHANGED',
                    'NETWORK_OR_TLS_FAILED','MES_FLOW_DEADLINE','MES_FLOW_STAGE_NOT_REPLAYABLE','MFA_FACTOR_UNSUPPORTED','MES_PREVIOUS_FLOW_NOT_PROVEN'];
                $code = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'MES_FLOW_NOT_CONFIRMED';
                $status = 'failed';
            } finally { unset($profile, $client, $result, $mail, $transport, $save); }
            $safe['stages'] = $stages;
            $safe['phase'] = $code;
            $safe['automatic_retry_allowed'] = false;
            $receipt = $this->store->spool(['provider'=>'mosenergo', 'run_id'=>$id, 'operation'=>'fresh-mail-auth', 'revision'=>$expectedRevision],
                Support::json(['status'=>$status, 'code'=>$code, 'coverage'=>$safe]));
            $safe['receipt_path'] = $receipt;
            $this->db->prepare('UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=?')
                ->execute([$status, $code, Support::json($safe), $id]);
            return ['id'=>$id, 'provider'=>'mosenergo', 'revision'=>$expectedRevision, 'status'=>$status, 'code'=>$code,
                'stages'=>$stages, 'receipt_path'=>$receipt, 'automatic_retry_allowed'=>false];
        } finally {
            $query = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$lock]);
        }
    }
}
