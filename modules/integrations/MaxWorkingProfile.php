<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** One explicitly admitted current-profile check, CAS selection and owner test. CLI only. */
final class MaxWorkingProfile
{
    public const ADMISSION = 'working-max-current-20261116-v1';
    private const FILE = 'working-max-current-20261116.profile.json';

    public function __construct(private PDO $db, private PrivateStore $store) {}

    private static function authorize(): void
    {
        if (PHP_SAPI !== 'cli' || getenv('IPPHP_WORKING_MAX_ADMISSION') !== self::ADMISSION) throw new RuntimeException('WORKING_MAX_NOT_AUTHORIZED');
    }

    public static function consumed(PDO $db): ?array
    {
        self::authorize();
        $query = $db->prepare("SELECT id,status,result_code FROM integration_runs WHERE provider='max' AND actor_id=0 AND request_key=?");
        $query->execute([self::ADMISSION]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function selectPublic(PDO $db, array $selected, string $proofSha, array $proof): void
    {
        self::authorize();
        if (!$db->inTransaction()) throw new RuntimeException('WORKING_MAX_TRANSACTION_REQUIRED');
        Config::save($db, 'max', Support::decode($selected['connection']['config_json']), 5, true);
        $query = $db->query("SELECT * FROM integration_configuration_sources WHERE provider='max' FOR UPDATE");
        if ($query->fetch(PDO::FETCH_ASSOC) !== $selected['source']) throw new RuntimeException('WORKING_MAX_SOURCE_CHANGED');
        $db->prepare("UPDATE integration_configuration_sources SET variant='working-current-max-v1',source_receipt_sha256=?,source_revision=6,metadata_json=?,activated_at=NOW(6) WHERE provider='max'")
            ->execute([$proofSha, Support::json($proof)]);
    }

    public static function shape(array $profile): array
    {
        $group = $profile['selectedGroup'] ?? [];
        $token = $profile['token'] ?? null;
        if (($profile['provider'] ?? null) !== 'max' || ($profile['sourceProjectId'] ?? null) !== CaptureReader::PROJECT
            || !is_string($token) || $token === '' || preg_match('/[\r\n\x00]/', $token)
            || ($group['enabled'] ?? null) !== true || (!is_string($group['internalGroupId'] ?? null) && !is_int($group['internalGroupId'] ?? null))
            || (string)$group['internalGroupId'] === '' || !is_string($group['name'] ?? null) || $group['name'] === '') {
            throw new RuntimeException('WORKING_MAX_PROFILE_INVALID');
        }
        $chat = $group['chatId'] ?? null;
        if (is_int($chat)) $chat = (string)$chat;
        $chat = Support::chatId($chat);
        $sourceGroup = (string)$group['internalGroupId'];
        $groups = $profile['groups'] ?? null;
        if (!is_array($groups) || count($groups) !== 1 || $groups[0] !== $group) throw new RuntimeException('WORKING_MAX_GROUP_INVALID');
        $endpoint = $profile['endpoint'] ?? [];
        $authorization = $profile['authorization'] ?? [];
        $body = $profile['requestBody'] ?? [];
        $transport = $profile['transport'] ?? [];
        $acceptance = $profile['acceptance'] ?? [];
        $routing = $profile['routingPolicy'] ?? [];
        if (($endpoint['scheme'] ?? null) !== 'https' || ($endpoint['baseHost'] ?? null) !== 'platform-api2.max.ru'
            || ($endpoint['baseUrl'] ?? null) !== 'https://platform-api2.max.ru' || ($endpoint['method'] ?? null) !== 'POST'
            || ($endpoint['path'] ?? null) !== '/messages' || array_keys($endpoint['query'] ?? []) !== ['chat_id']
            || ($endpoint['query']['chat_id']['source'] ?? null) !== 'selectedGroup.chatId'
            || ($authorization['headerName'] ?? null) !== 'Authorization' || ($authorization['mode'] ?? null) !== 'raw-token'
            || ($authorization['tokenSource'] ?? null) !== 'token' || ($authorization['bearerPrefix'] ?? null) !== false
            || ($authorization['trimToken'] ?? null) !== false || ($body['format'] ?? null) !== 'JSON'
            || ($body['fields'] ?? null) !== ['text'] || ($body['encoding'] ?? null) !== 'UTF-8' || ($body['ensureAscii'] ?? null) !== false
            || ($transport['timeoutSeconds'] ?? null) !== 25 || ($transport['responseMaxBytes'] ?? null) !== 1048576
            || ($transport['tls']['verifyPeer'] ?? null) !== true || ($transport['tls']['verifyHostname'] ?? null) !== true
            || ($transport['proxyEnabled'] ?? null) !== false || ($transport['redirectsEnabled'] ?? null) !== false
            || ($acceptance['requireMessageBodyMidTruthy'] ?? null) !== true
            || ($acceptance['requireRecipientChatIdMatchesSelectedTarget'] ?? null) !== true
            || ($acceptance['deliveredOrReadByHumanClaim'] ?? null) !== false
            || ($routing['enabled'] ?? null) !== true || !is_array($routing['allowedLogicalGroupIds'] ?? null)
            || array_filter($routing['allowedLogicalGroupIds'],static fn($id):bool=>!is_int($id)&&!is_string($id))
            || array_map('strval',$routing['allowedLogicalGroupIds']) !== [$sourceGroup]) {
            throw new RuntimeException('WORKING_MAX_REQUEST_CONTRACT_CHANGED');
        }
        return ['chat' => $chat, 'source_group' => $sourceGroup, 'name' => $group['name']];
    }

    private static function load(): array
    {
        $path = Config::root().'/.local/integration-source/'.self::FILE;
        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) throw new RuntimeException('WORKING_MAX_HANDOFF_REQUIRED');
        $profile = Support::decode(file_get_contents($path));
        self::shape($profile);
        return $profile;
    }

    private function selected(array $shape): array
    {
        $connection = Config::get($this->db, 'max');
        if (!$connection['enabled'] || (int)$connection['revision'] !== 5) throw new RuntimeException('WORKING_MAX_CURRENT_REVISION_CHANGED');
        $secret = Config::secrets('max');
        Config::assertSecretRevision($secret, 5);
        $query = $this->db->prepare("SELECT g.id,g.name FROM messaging_groups g JOIN integration_group_bindings b ON b.local_group_id=g.id AND b.provider='max' WHERE g.provider='max' AND g.enabled=1 AND g.chat_id=? AND b.source_group_id=?");
        $query->execute([$shape['chat'], $shape['source_group']]);
        $groups = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($groups) !== 1 || $groups[0]['name'] !== $shape['name']) throw new RuntimeException('WORKING_MAX_GROUP_CHANGED');
        $query = $this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='max'");
        $source = $query->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('WORKING_MAX_SOURCE_MISSING');
        return ['connection' => $connection, 'secret' => $secret, 'source' => $source, 'group_id' => $groups[0]['id']];
    }

    public function run(): array
    {
        self::authorize();
        if (!Config::liveAllowed() || $this->db->inTransaction()) throw new RuntimeException('WORKING_MAX_NOT_AUTHORIZED');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn() !== 1) throw new RuntimeException('WORKING_MAX_BUSY');
        $id = null;
        $metadata = [];
        try {
            if ($old = self::consumed($this->db)) return $old + ['already_consumed' => true, 'automatic_retry_allowed' => false];
            $profile = self::load();
            $shape = self::shape($profile);
            $selected = $this->selected($shape);
            if (hash_equals((string)$selected['secret']['secret'], $profile['token'])) throw new RuntimeException('WORKING_MAX_PROFILE_NOT_DIFFERENT');
            $id = Support::uuid();
            $metadata = ['read_only_candidate_check' => true, 'whole_profile' => true, 'old_revision' => 5,
                'candidate_file' => self::FILE, 'candidate_token_differs' => true, 'group_binding_matches' => true,
                'auth_raw_body_archives' => 0, 'sends' => 0, 'automatic_retry_allowed' => false,
                'phase' => 'candidate_readonly', 'candidate_auth' => []];
            $fingerprint = hash('sha256', Support::canonical($metadata));
            $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json) VALUES(?,'max','candidate-message-once',?,0,5,?,'pending',?)")
                ->execute([$id, self::ADMISSION, $fingerprint, Support::json($metadata)]);
            $guard = function() use ($profile, $shape, $selected): void {
                if (self::load() !== $profile || $this->selected($shape) !== $selected) throw new RuntimeException('MAX_CONTEXT_CHANGED');
            };
            $auth = (new MessageTransport(null, $guard))->execute('max', 'check', [], ['secret' => $profile['token']], '@connection', '', '', [$shape['chat']]);
            $metadata['candidate_auth'] = array_intersect_key($auth, array_flip(['status','code','http_status','phase','safe_error_code']));
            $receipt = $this->store->spool(['provider' => 'max', 'operation' => 'candidate-auth', 'run_id' => $id], Support::json($metadata));
            if ($auth['status'] !== 'success') return $this->finish($id, $metadata, $receipt, 'failed', $auth['code']);
            $guard();
            $metadata['phase'] = 'select_candidate';
            $revision = $this->activate($profile, $selected, $receipt);
            $metadata['selected_revision'] = $revision;
            $metadata['candidate_auth_proven_before_selection'] = true;
            $transport = function($provider, $operation, $config, $secret, $target, $raw, $remoteId, $groups) use ($profile, $shape, $revision, &$metadata): array {
                $check = function() use ($profile, $shape, $revision): void {
                    $current = Config::get($this->db, 'max');
                    $secret = Config::secrets('max');
                    Config::assertSecretRevision($secret, $revision);
                    if (!$current['enabled'] || (int)$current['revision'] !== $revision || self::load() !== $profile
                        || !hash_equals((string)($secret['secret'] ?? ''), $profile['token'])
                        || ($secret['approved_chat_ids'] ?? null) !== [$shape['chat']]) throw new RuntimeException('MAX_CONTEXT_CHANGED');
                };
                $check();
                if ($target !== $shape['chat']) throw new RuntimeException('WORKING_MAX_GROUP_CHANGED');
                $before = static function($method) use ($check, &$metadata): void {
                    $check();
                    if ($method === 'POST') $metadata['sends']++;
                };
                return (new MessageTransport(null, $before))->execute($provider, $operation, $config, $secret, $target, $raw, $remoteId, $groups);
            };
            $metadata['phase'] = 'send_once';
            $attempts = (new Messaging($this->db, $this->store, $transport))->dispatch('max', 0, self::ADMISSION, 'send', [$selected['group_id']], OwnerMessaging::TEXT, '', []);
            $attempt = $attempts[0];
            $metadata['attempt_id'] = $attempt['id'];
            $metadata['send_status'] = $attempt['status'];
            $metadata['send_code'] = $attempt['result_code'];
            $metadata['inbox_or_human_read_proven'] = false;
            $transportReceipt = $this->store->receiptFor($attempt['id']);
            $metadata['send_receipt_path'] = $transportReceipt['receipt_path'] ?? null;
            if ($transportReceipt !== null) {
                $result = Support::decode(base64_decode($transportReceipt['receipt']['raw_base64'], true));
                $metadata['send_diagnostic'] = array_intersect_key($result, array_flip(['http_status','phase','safe_error_code']));
            }
            $receipt = $this->store->spool(['provider' => 'max', 'operation' => 'working-profile-owner-test', 'run_id' => $id], Support::json($metadata));
            return $this->finish($id, $metadata, $receipt, $attempt['status'], $attempt['result_code']);
        } catch (\Throwable $error) {
            if ($id === null || !$metadata) throw $error;
            $metadata['local_after_admission_unconfirmed'] = true;
            $receipt = $this->store->spool(['provider' => 'max', 'operation' => 'working-profile-stopped', 'run_id' => $id], Support::json($metadata));
            return $this->finish($id, $metadata, $receipt, 'uncertain', 'WORKING_MAX_STOPPED_NO_AUTORETRY');
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
        }
    }

    private function activate(array $profile, array $selected, string $authReceipt): int
    {
        $history = Config::root().'/private/integrations/config-history/max-revision-5.enc';
        if (file_exists($history) || is_link($history)) throw new RuntimeException('WORKING_MAX_HISTORY_EXISTS');
        $currentPath = Config::root().'/private/integrations/max.enc';
        $oldCipher = file_get_contents($currentPath);
        $file = fopen($history, 'xb');
        if (!$file) throw new RuntimeException('WORKING_MAX_HISTORY_FAILED');
        try { if (fwrite($file, $oldCipher) !== strlen($oldCipher)) throw new RuntimeException('WORKING_MAX_HISTORY_FAILED'); fflush($file); if(function_exists('fsync')) fsync($file); }
        finally { fclose($file); }
        chmod($history, 0600);
        $proof = ['whole_profile' => true, 'credential_hashes_stored' => false, 'candidate_file' => self::FILE,
            'source_project_id' => $profile['sourceProjectId'], 'auth_receipt_path' => $authReceipt,
            'predecessor' => $selected['source'], 'old_revision' => 5, 'new_revision' => 6,
            'source_worker_sha256' => $profile['source']['workerCodeSha256'] ?? null,
            'source_policy_sha256' => $profile['source']['policySha256'] ?? null];
        $proofPath = $this->store->put('owner-config-provenance', Support::json($proof));
        $shape = self::shape($profile);
        $this->db->beginTransaction();
        try {
            self::selectPublic($this->db, $selected, basename($proofPath), $proof);
            Config::saveSecrets('max', ['secret' => $profile['token'], 'approved_chat_ids' => [$shape['chat']],
                '_owner_bound_revision' => 6, '_source_receipt_sha256' => basename($proofPath), 'whole_working_profile' => $profile]);
            $this->db->commit();
        } catch (\Throwable $error) {
            // Own same-transaction private-file replacement is reversible while the provider lease is held.
            if ($this->db->inTransaction()) {
                $tmp = $currentPath.'.restore-'.bin2hex(random_bytes(8));
                file_put_contents($tmp, $oldCipher); chmod($tmp, 0600); rename($tmp, $currentPath);
                $this->db->rollBack();
            }
            throw $error;
        }
        return 6;
    }

    private function finish(string $id, array $metadata, string $receipt, string $status, string $code): array
    {
        $metadata['receipt_path'] = $receipt;
        $this->db->prepare('UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=?')
            ->execute([$status, $code, Support::json($metadata), $id]);
        return ['run_id' => $id, 'status' => $status, 'code' => $code, 'receipt_path' => $receipt,
            'selected_revision' => $metadata['selected_revision'] ?? 5, 'candidate_auth' => $metadata['candidate_auth'],
            'phase' => $metadata['phase'], 'send_diagnostic' => $metadata['send_diagnostic'] ?? null,
            'sends' => $metadata['sends'], 'attempt_id' => $metadata['attempt_id'] ?? null, 'inbox_or_human_read_proven' => false,
            'automatic_retry_allowed' => false];
    }
}
