<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** Explicit operator login. The hourly importer never calls this class. */
final class MesCabinetAuthorization
{
    private const PROVIDER = 'mosenergo';
    private const OPERATION = 'mes-manual-auth';
    private const LOCK = 'ipphp-provider:mosenergo';
    private const WINDOW_SECONDS = 600;

    public function __construct(private PDO $db) {}

    public function execute(string $action, string $key, ?string $code = null): array
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_STAND') !== 'ui'
            || !in_array($action, ['start', 'request-code', 'confirm'], true)
            || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $key)
            || ($action === 'confirm' ? !preg_match('/^\d{5}$/D', $code ?? '') : $code !== null)) {
            throw new RuntimeException('MES_MANUAL_ARGUMENTS_REJECTED');
        }
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([self::LOCK]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('PROVIDER_BUSY');
        try {
            $context = $this->context();
            $reference = $context['profile'];
            $profile = Config::profile(self::PROVIDER, $reference);
            Config::assertSecretRevision($profile, $context['revision']);
            if ($action === 'start' && (empty($profile['username']) || empty($profile['password']))) {
                throw new RuntimeException('MES_PROFILE_CREDENTIALS_MISSING');
            }
            $transport = CabinetNetwork::mesTransport();
            $run = $action === 'start' ? $this->reserve($key, $context) : $this->pending($key, $context, $action, $profile);
            $phase = ['start'=>'login-intent', 'request-code'=>'request-intent', 'confirm'=>'verify-intent'][$action];
            $client = new MesClient($action === 'start' ? [] : ($profile['session'] ?? []),
                function (string $method, string $path, ?array $body, array $query, array $headers) use ($transport, $context): array {
                    if ($this->context() !== $context) throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
                    return $transport($method,$path,$body,$query,$headers);
                });
            try {
                $result = match ($action) {
                    'start' => $client->login($profile),
                    'request-code' => $client->requestCode(),
                    'confirm' => $client->confirm($code),
                };
                $this->saveSession($context, $client->session);
                if (($result['status'] ?? null) === 'success') {
                    $this->finish($run['id'], 'success', 'MES_AUTHENTICATED', ['phase'=>'authenticated']);
                    return ['id'=>$run['id'], 'status'=>'success', 'code'=>'MES_AUTHENTICATED'];
                }
                if ($action === 'confirm') {
                    throw new RuntimeException('MES_CONFIRMATION_NOT_COMPLETED');
                }
                $transaction = $client->session['transactionId'] ?? null;
                $requestedAt = $client->session['otp_started_at'] ?? null;
                if (!is_string($transaction) || !is_numeric($requestedAt)
                    || !in_array('EMAIL', $client->session['types'] ?? [], true)) {
                    throw new RuntimeException('MES_TRANSACTION_NOT_BOUND');
                }
                $codeResult = $action === 'start' ? 'OTP_REQUIRED' : 'OTP_SENT';
                $this->finish($run['id'], 'awaiting_code', $codeResult, [
                    'phase'=>$action === 'start' ? 'ready' : 'sent',
                    'transaction_sha256'=>hash('sha256', $transaction), 'otp_started_at'=>(int)$requestedAt,
                ]);
                return ['id'=>$run['id'], 'status'=>'awaiting_code', 'code'=>$codeResult];
            } catch (\Throwable $failure) {
                $safeCode = match ($failure->getMessage()) {
                    'AUTH_REQUIRED', 'NETWORK_OR_TLS_FAILED', 'MES_HTTP_REJECTED', 'MFA_FACTOR_UNSUPPORTED',
                    'MES_CURRENT_PROFILE_CHANGED', 'OTP_EXPIRED_RELOGIN_REQUIRED', 'OTP_REQUEST_NOT_REPLAYABLE' => $failure->getMessage(),
                    default => 'MES_MANUAL_RESULT_UNCONFIRMED',
                };
                $this->finish($run['id'], 'uncertain', $safeCode, ['phase'=>$phase]);
                return ['id'=>$run['id'], 'status'=>'uncertain', 'code'=>$safeCode];
            }
        } finally {
            $unlock = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([self::LOCK]);
        }
    }

    private function context(): array
    {
        $connection = Config::get($this->db, self::PROVIDER);
        $public = Support::decode($connection['config_json']);
        $source = $this->db->query("SELECT selected_profile,source_revision,source_receipt_sha256
            FROM integration_configuration_sources WHERE provider='mosenergo'")->fetch(PDO::FETCH_ASSOC);
        $profile = $public['access_reference'] ?? '';
        if (!$connection['enabled'] || !$source || !is_string($profile) || $source['selected_profile'] !== $profile
            || (int)$source['source_revision'] !== (int)$connection['revision']
            || !is_string($source['source_receipt_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $source['source_receipt_sha256'])) {
            throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
        }
        if (!CabinetSyncAdmission::allows($this->db, self::PROVIDER, 'authenticate', $profile)) {
            throw new RuntimeException('MES_AUTH_NOT_ADMITTED');
        }
        return ['profile'=>$profile, 'revision'=>(int)$connection['revision'],
            'source_sha256'=>$source['source_receipt_sha256'], 'configuration'=>$connection['config_json']];
    }

    private function fingerprint(array $context): string
    {
        return hash('sha256', Support::canonical($context));
    }

    private function reserve(string $key, array $context): array
    {
        $existing = $this->find($key);
        if ($existing) throw new RuntimeException('MES_LOGIN_REQUEST_ALREADY_USED');
        $active = $this->db->query("SELECT id FROM integration_runs WHERE provider='mosenergo'
            AND status IN ('pending','awaiting_code','uncertain') AND created_at>NOW(6)-INTERVAL 10 MINUTE
            AND operation IN ('mes-manual-auth','fresh-mail-auth','login','send_code','confirm') LIMIT 1")->fetchColumn();
        if ($active) throw new RuntimeException('MES_RECENT_AUTH_ATTEMPT_EXISTS');
        $id = Support::uuid();
        $query = $this->db->prepare("INSERT INTO integration_runs
            (id,provider,operation,request_key,actor_id,revision,fingerprint,status,result_code,coverage_json)
            VALUES(?,'mosenergo','mes-manual-auth',?,0,?,?,'pending','MES_LOGIN_INTENT',?)");
        $query->execute([$id,$key,$context['revision'],$this->fingerprint($context),Support::json(['phase'=>'login-intent'])]);
        return ['id'=>$id];
    }

    private function find(string $key): array|false
    {
        $query = $this->db->prepare('SELECT id,status,fingerprint,coverage_json FROM integration_runs
            WHERE provider=? AND operation=? AND actor_id=0 AND request_key=?');
        $query->execute([self::PROVIDER,self::OPERATION,$key]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function pending(string $key, array $context, string $action, array $profile): array
    {
        $run = $this->find($key);
        $coverage = $run ? Support::decode($run['coverage_json']) : [];
        $session = $profile['session'] ?? [];
        $transaction = $session['transactionId'] ?? null;
        $expected = $action === 'request-code' ? 'ready' : 'sent';
        $expectedOtp = $action === 'request-code' ? 'not_requested' : 'awaiting_code';
        $age = time() - (int)($coverage['otp_started_at'] ?? 0);
        if (!$run || $run['status'] !== 'awaiting_code' || !hash_equals($run['fingerprint'], $this->fingerprint($context))
            || ($coverage['phase'] ?? null) !== $expected || $age < 0 || $age >= self::WINDOW_SECONDS
            || !is_string($transaction) || ($session['otp_state'] ?? null) !== $expectedOtp
            || !hash_equals($coverage['transaction_sha256'] ?? '', hash('sha256', $transaction))) {
            throw new RuntimeException('MES_AUTH_PHASE_NOT_READY');
        }
        $phase = $action === 'request-code' ? 'request-intent' : 'verify-intent';
        $query = $this->db->prepare("UPDATE integration_runs SET status='pending',result_code=?,coverage_json=?,finished_at=NULL
            WHERE id=? AND status='awaiting_code'");
        $query->execute(['MES_AUTH_INTENT',Support::json(['phase'=>$phase]+$coverage),$run['id']]);
        if ($query->rowCount() !== 1) throw new RuntimeException('MES_AUTH_PHASE_NOT_READY');
        return $run;
    }

    private function saveSession(array $context, array $session): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT provider FROM integration_connections WHERE provider='mosenergo' FOR UPDATE")->fetchColumn();
            if ($this->context() !== $context) throw new RuntimeException('MES_CURRENT_PROFILE_CHANGED');
            $profile = Config::profile(self::PROVIDER, $context['profile']);
            Config::assertSecretRevision($profile, $context['revision']);
            $profile['session'] = $session;
            Config::saveProfile(self::PROVIDER, $context['profile'], $profile);
            $this->db->commit();
        } catch (\Throwable $failure) {
            $this->db->rollBack();
            throw $failure;
        }
    }

    private function finish(string $id, string $status, string $code, array $coverage): void
    {
        $this->db->prepare("UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6)
            WHERE id=? AND status='pending'")->execute([$status,$code,Support::json($coverage),$id]);
    }
}
