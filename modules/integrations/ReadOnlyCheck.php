<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** CLI-only auth/metadata command; no document sync, print job, OTP email, send or 1C write. */
final class ReadOnlyCheck {
    public function __construct(private \PDO $db,
        private PrivateStore $store) {
    }
    public function run(string $provider,
        string $requestKey, bool $freshLogin = false, ?int $expectedRevision = null): array {
        if ($freshLogin) FreshLoginAdmission::requireAuthorized($provider);
        if ($provider === 'bank')return $this->reuseBankAuth($requestKey);
        Support::provider($provider);
        if (!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        if (!preg_match('/^[a-f0-9-]{36}$/D',
            $requestKey))throw new \InvalidArgumentException('INVALID_REQUEST_KEY');
        $credentialProvider = $provider === 'bank'?'onec':$provider;
        $q = $this->db->prepare('SELECT * FROM integration_configuration_sources WHERE provider=?');
        $q->execute([$credentialProvider]);
        $source = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$source)throw new \RuntimeException('SELECTED_CONFIG_REQUIRED');
        if ($expectedRevision !== null && (int)$source['source_revision'] !== $expectedRevision)throw new \RuntimeException('SELECTED_CONFIG_CHANGED');
        $lock = 'ipphp-provider:' . $provider;
        $q = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1)throw new \RuntimeException('PROVIDER_BUSY');
        try{
            $q = $this->db->prepare('SELECT id,status,result_code FROM integration_runs WHERE provider=? AND actor_id=0 AND request_key=?');
            $q->execute([$provider,
                    $requestKey]);
            if ($old = $q->fetch(\PDO::FETCH_ASSOC))return $old+['reused' => true];
            $id = Support::uuid();
            $revision = (int)($source['source_revision']??0);
            if ($revision < 1)throw new \RuntimeException('SOURCE_REVISION_UNAVAILABLE');
            if ($freshLogin) FreshLoginAdmission::assertUnused($this->db, $provider, $revision, $source);
            $reservation = [
                'auth_request_mode' => $freshLogin ? 'fresh_login_once' : 'existing_session_or_source_login',
                'source_receipt_sha256' => $source['source_receipt_sha256'],
                'private_profile_reference' => $source['selected_profile'],
                'operative_revision_at_admission' => $revision,
                'auth_raw_body_archive_forbidden' => true,
            ];
            $fingerprint = hash('sha256',
                Support::canonical(['provider' => $provider,
                    'operation' => 'auth-metadata',
                    'source_receipt' => $source['source_receipt_sha256'],
                    'profile' => $source['selected_profile'],
                    'read_only' => true]));
            $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json) VALUES(?,?, 'auth-metadata',?,0,?,?,'pending',?)")->execute([$id,
                    $provider,
                    $requestKey,
                    $revision,
                    $fingerprint, Support::json($reservation)]);
            $status = 'failed';
            $code = 'NOT_CHECKED';
            $metadata = $reservation + ['read_only' => true,
                'sends' => 0,
                'provider_writes' => 0,
                'canonical_document_imports' => 0,
                'source_receipt_sha256' => $source['source_receipt_sha256'],
                'source_variant' => $source['variant']];
            unset($GLOBALS['IPPHP_AUTH_DIAGNOSTIC']);
            try{
                if (in_array($provider,
                    ['email',
                        'max'],
                    true)){
                    $connection = Config::get($this->db,
                        $provider);
                    if (!$connection['enabled'])throw new \RuntimeException('CONNECTION_DISABLED');
                    $config = json_decode($connection['config_json'],
                        true,
                        32,
                        JSON_THROW_ON_ERROR);
                    $secret = Config::secrets($provider);
                    Config::assertSecretRevision($secret, (int)$connection['revision']);
                    $groups = $provider === 'max'?$this->db->query("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN):[];
                    $result = (new MessageTransport())->execute($provider,
                        'check',
                        $config,
                        $secret,
                        '@connection',
                        '',
                        '',
                        $groups);
                    $status = $result['status'] === 'success'?'success':'failed';
                    $code = $result['code'];
                    $metadata['transport_diagnostic'] = array_intersect_key($result, array_flip(['http_status','phase','safe_error_code']));
                    $metadata['enabled_groups_checked'] = $provider === 'max'?count($groups):null;
                    $this->db->prepare('UPDATE integration_connections SET last_status=?,last_checked_at=NOW(6) WHERE provider=? AND revision=?')->execute([$status,
                            $provider,
                            $connection['revision']]);
                }
                else {
                    $reference = Config::reference($source['selected_profile']??'');
                    if (in_array($provider,
                        ['onec', 'mosenergo', 'diadoc',
                            'mosvodokanal',
                            'pik'],
                        true)){
                        $operative = Config::get($this->db,
                            $provider);
                        $public = Support::decode($operative['config_json']);
                        if (!$operative['enabled'] || (int)$operative['revision'] !== $revision || ($public['access_reference']??null) !== $reference)throw new \RuntimeException('SELECTED_CONFIG_CHANGED');
                        $metadata['operative_revision_at_check'] = (int)$operative['revision'];
                        $metadata['private_profile_reference'] = $reference;
                        $metadata['auth_raw_body_archive_forbidden'] = true;
                    }
                    $secret = Config::profile($credentialProvider,
                        $reference);
                    if ($provider === 'mosenergo'){
                        $client = new MesClient($secret['session']??[]);
                        try{
                            $metadata['auth_diagnostic'] = ['auth_mode' => !empty($secret['session']['auth']) ? 'cached_bearer' : (!empty($secret['session']['transactionId']) ? 'pending_human_otp' : 'fresh_login'), 'otp_request_attempted' => false, 'mailbox_read_attempted' => false];
                            if (empty($secret['session']['auth']) && !empty($secret['session']['transactionId'])) {
                                if (!is_numeric($secret['session']['otp_started_at'] ?? null) || microtime(true) - (float)$secret['session']['otp_started_at'] > 600) throw new \RuntimeException('OTP_EXPIRED_RELOGIN_REQUIRED');
                                $result = ['status' => 'awaiting_code', 'code' => 'OTP_REQUIRED'];
                            }
                            elseif (empty($secret['session']['auth']))$result = $client->login($secret);
                            else $result = $client->check();
                            $status = $result['status'];
                            $code = $result['code'];
                            if ($status === 'success'){
                                $metadata['accounts_checked'] = $result['accounts'] ?? count($client->accounts());
                                $code = 'AUTH_METADATA_CHECKED';
                            }
                        }
                        finally{
                            $secret['session'] = $client->session;
                            Config::saveProfile($credentialProvider,
                                $reference,
                                $secret);
                        }
                    }
                    else {
                        $r = (new ProviderService($this->db,
                            $this->store))->native($provider,
                            'authenticate',
                            $secret, $provider === 'onec' ? ['include_metadata' => true] : ($freshLogin ? ['force_login' => true] : []));
                        $secret['session'] = $r['session']??[];
                        // Keep the existing cache until successful metadata and current-revision CAS.
                        $successfulProfile = $secret;
                        $status = 'success';
                        $code = 'AUTH_METADATA_CHECKED';
                        $metadata['accounts_returned'] = is_array($r['data']['items']??null)?count($r['data']['items']):null;
                        $metadata['raw_source_originals'] = $r['source_originals']??[];
                        if ($provider === 'onec') $metadata['metadata_entitysets'] = $r['data']['metadata_entitysets'] ?? null;
                        if($provider==='onec'&&($r['data']['scope']??null)==='organization_read_capability'){
                            $metadata['scope']='organization_read_capability';
                            $metadata['transport']=$r['data']['transport'];
                            $metadata['http']=200;$metadata['organization_count']=$r['data']['organization_count'];
                            $metadata['service_root_checked']=false;
                        }
                    }
                }
            }
            catch (\Throwable $e){
                $safe = ['AUTH_REQUIRED',
                    'PUSH_REQUIRED',
                    'OTP_REQUIRED',
                    'MFA_REQUIRED',
                    'CAPTCHA_REQUIRED',
                    'AUTHENTICATION_REQUIRED',
                    'INVALID_AUTH_RESPONSE',
                    'MFA_FACTOR_UNSUPPORTED',
                    'EXTERNAL_ACCESS_NOT_AUTHORIZED',
                    'CONNECTION_DISABLED',
                    'CA_FILE_UNAVAILABLE',
                    'NETWORK_OR_TLS_FAILED',
                    'TIME_LIMIT',
                    'SOURCE_TIMEOUT',
                    'OTP_EXPIRED_RELOGIN_REQUIRED'];
                $code = in_array($e->getMessage(),
                    $safe,
                    true)?$e->getMessage():(function_exists('IpyaAccess\\errorCode')?\IpyaAccess\errorCode($e):'AUTH_METADATA_CHECK_FAILED');
                $status = 'failed';
            }
            if ($provider === 'onec' && isset($GLOBALS['ONEC_DIAGNOSTIC'])) {
                $metadata['native_diagnostic'] = $GLOBALS['ONEC_DIAGNOSTIC'];
                foreach ($metadata['native_diagnostic']['requests'] ?? [] as $request) {
                    if (($request['curl_errno'] ?? null) === 60) $code = 'TLS_CERTIFICATE_INVALID';
                }
            }
            if (in_array($provider, ['onec', 'diadoc', 'mosvodokanal', 'pik'], true) && isset($GLOBALS['IPPHP_AUTH_DIAGNOSTIC'])) {
                $metadata['auth_diagnostic'] = $GLOBALS['IPPHP_AUTH_DIAGNOSTIC'];
            }
            if (isset($operative)){
                $current = Config::get($this->db,
                    $provider);
                if ((int)$current['revision'] !== (int)$operative['revision'] || $current['config_json'] !== $operative['config_json'] || !$current['enabled']){
                    $status = 'failed';
                    $code = 'REVISION_CONFLICT';
                }
                $metadata['operative_scope_current'] = $code !== 'REVISION_CONFLICT';
            }
            if ($status === 'success' && isset($successfulProfile)) {
                $q = $this->db->prepare('SELECT * FROM integration_configuration_sources WHERE provider=?');
                $q->execute([$credentialProvider]);
                $currentSource = $q->fetch(\PDO::FETCH_ASSOC);
                if (!$currentSource || $currentSource['selected_profile'] !== $source['selected_profile']
                    || $currentSource['source_receipt_sha256'] !== $source['source_receipt_sha256']
                    || (int)$currentSource['source_revision'] !== $revision) {
                    $status = 'failed';
                    $code = 'REVISION_CONFLICT';
                    $metadata['operative_scope_current'] = false;
                } else {
                    Config::saveProfile($credentialProvider, $reference, $successfulProfile);
                    $metadata['session_cache_replaced_after_success'] = true;
                }
            }
            $receipt = $this->store->spool(['run_id' => $id,
                    'provider' => $provider,
                    'operation' => 'auth-metadata',
                    'request_key' => $requestKey],
                Support::json(['status' => $status,
                    'code' => $code,
                    'metadata' => $metadata]));
            $metadata['receipt_path'] = $receipt;
            $this->db->prepare("UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=? AND status='pending'")->execute([$status,
                    $code,
                    Support::json($metadata),
                    $id]);
            return ['provider' => $provider,
                'id' => $id,
                'status' => $status,
                'reason_code' => $code,
                'read_only' => true,
                'sends' => 0,
                'provider_writes' => 0,
                'canonical_document_imports' => 0,
                'receipt_path' => $receipt];
        }
        finally{
            $q = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
        }
    }
    private function reuseBankAuth(string $requestKey): array {
        if (!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        $q = $this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='onec'");
        $source = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$source)throw new \RuntimeException('SELECTED_CONFIG_REQUIRED');
        $effective = Config::bankIdentity($this->db);
        if ($effective['bank_revision'] < 1 || $effective['onec_revision'] < 1 || $effective['onec_revision'] !== $effective['selected_source_revision'] || $effective['effective_reference'] !== $effective['selected_source_reference'])throw new \RuntimeException('SHARED_ONEC_CHECK_REVISION_CHANGED');
        $q = $this->db->query("SELECT * FROM integration_runs WHERE provider='onec' AND operation='auth-metadata' AND finished_at IS NOT NULL ORDER BY finished_at DESC LIMIT 1");
        $parent = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$parent)throw new \RuntimeException('SHARED_ONEC_AUTH_NOT_CHECKED');
        $coverage = Support::decode($parent['coverage_json']??'{}');
        if (($coverage['source_receipt_sha256']??'') !== $source['source_receipt_sha256'])throw new \RuntimeException('SELECTED_CONFIG_CHANGED');
        $id = Support::uuid();
        $metadata = ['read_only' => true,
            'network_calls' => 0,
            'sends' => 0,
            'provider_writes' => 0,
            'canonical_document_imports' => 0,
            'shared_auth_run_id' => $parent['id'],
            'source_receipt_sha256' => $source['source_receipt_sha256'],
            'source_variant' => $source['variant'],
            'bank_collection_check' => 'NOT_PERFORMED_AUTH_METADATA_SCOPE_ONLY',
            'bank_auth_identity' => $effective];
        $status = $parent['status'] === 'success'?'success':'failed';
        $code = $status === 'success'?'SHARED_ONEC_AUTH_CHECKED_BANK_DATA_NOT_READ':'SHARED_ONEC_AUTH_REQUIRED';
        $receipt = $this->store->spool(['run_id' => $id,
                'provider' => 'bank',
                'operation' => 'auth-metadata',
                'request_key' => $requestKey],
            Support::json(['status' => $status,
                'code' => $code,
                'metadata' => $metadata]));
        $metadata['receipt_path'] = $receipt;
        $fp = hash('sha256',
            Support::canonical($metadata));
        $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,result_code,coverage_json,finished_at) VALUES(?,'bank','auth-metadata',?,0,?, ?,?,?,?,NOW(6))")->execute([$id,
                $requestKey,
                $effective['bank_revision'],
                $fp,
                $status,
                $code,
                Support::json($metadata)]);
        return ['provider' => 'bank',
            'id' => $id,
            'status' => $status,
            'reason_code' => $code,
            'read_only' => true,
            'network_calls' => 0,
            'sends' => 0,
            'provider_writes' => 0,
            'canonical_document_imports' => 0,
            'shared_auth_run_id' => $parent['id'],
            'receipt_path' => $receipt];
    }
}
