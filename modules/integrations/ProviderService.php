<?php
declare(strict_types = 1);
namespace Modules\Integrations;
final class ProviderService {
    private static function devFailureOutcome(\Throwable $error,array $record=[]):?array
    {
        $diagnostic=OnecReaderFactory::devFailure($error,$record);
        if($diagnostic===null)return null;
        $code=$diagnostic['safe_error_code'];
        return ['code'=>$code,'status'=>in_array($code,['ONEC_DEV_TRANSPORT_UNKNOWN','ONEC_DEV_HTTP_UNKNOWN'],true)?'uncertain':'failed',
            'diagnostic'=>$diagnostic];
    }

    private static function checkReceiptCoverage(array $coverage):array
    {
        // These request fields are separately appended to the run journal. The
        // existing verified projection removes them; never put them in its body.
        unset($coverage['command_context'],$coverage['bank_auth_identity']);return $coverage;
    }
    public function __construct(private \PDO $db,
        private PrivateStore $store,
        private mixed $native = null) {
    }
    public function native(string $provider,
        string $operation,
        array $secret,
        array $params = [], bool $scheduled = false): array {
        if (is_callable($this->native))return ($this->native)($provider,
            $operation,
            $secret,
            $params);
        \App\NativePlatform::requireOwnerChecks();
        if ($scheduled) {
            if (!CabinetSyncAdmission::allows($this->db,$provider,$operation,$secret['_reference']??null)) {
                throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            }
            $params['cabinet_profile'] = $secret['_reference'];
        } elseif (!Config::liveAllowed() && !OnecReadAdmission::allows($this->db,$provider,$operation,$secret)) {
            throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        }
        $devContext=null;
        if(in_array($provider,['onec','bank'],true)&&($devSettings=OnecDevSshTransport::settings())!==null){
            $devContext=OnecReaderFactory::selectedContext($this->db,$secret,$devSettings);
        }
        $state = Config::root() . '/private/integrations/state/' . $provider . '/' . Config::reference($secret['_reference']??'');
        if (!is_dir($state) && !mkdir($state,
            0700,
            true))throw new \RuntimeException('STATE_DIRECTORY_FAILED');
        $state = (string)realpath($state);
        require_once __DIR__ . '/worker.php';
        $GLOBALS['IPPHP_EMBEDDED_NATIVE'] = true;
        $originals = [];
        $hadCapture = array_key_exists('IPPHP_CAPTURE_RAW',
            $GLOBALS);
        $oldCapture = $GLOBALS['IPPHP_CAPTURE_RAW']??null;
        $ephemeral = $operation === 'authenticate' || ($params['check_probe']??false) === true;
        $hadEphemeral = array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL',
            $GLOBALS);
        $oldEphemeral = $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']??null;
        if ($ephemeral){
            unset($GLOBALS['IPPHP_CAPTURE_RAW']);
            $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = true;
        }
        else $GLOBALS['IPPHP_CAPTURE_RAW'] = function(string $raw)use(&$originals){
            $originals[] = ['private_path' => $this->store->put('provider-responses',
                    $raw),
                'sha256' => hash('sha256',
                    $raw),
                'bytes' => strlen($raw)];
        }
        ;
        try{
            if ($provider === 'bank'){
                $GLOBALS['ONLINE_DEADLINE'] = hrtime(true)/1e9+300;
                $dev=OnecDevSshTransport::settings()!==null;
                $r = OnecReaderFactory::create($secret,$dev?60:30,$operation==='authenticate'?'connection':'bank_read',Support::caFile());
                if ($operation === 'authenticate'){
                    $capability=OnecReaderFactory::connection($r);
                    $result = ['data' => $capability,
                        'session' => []];
                }
                else $result = ['data' => $r->archiveBank($params['from'],
                        $params['to'],
                        $params['cursor']??0),
                    'session' => []];
                if($dev)$result['transport']=OnecDevSshTransport::identity();
            }
            else $result = \IpyaAccess\run(['provider' => $provider,
                    'operation' => $operation,
                    'state' => $state,
                    'credentials' => $scheduled ? [] : ['username' => $secret['username']??'',
                    'password' => $secret['password']??''],
                    'session' => $secret['session']??[],
                    'remaining_ms' => ($params['check_probe']??false)===true?min(300000,max(1,(int)($params['remaining_ms']??300000))):300000]+($provider==='onec'?['profile_reference'=>$devContext['profile_reference']??$secret['_reference']??'',
                    'profile_revision'=>$devContext['profile_revision']??$secret['_owner_bound_revision']??null]:[])+($provider === 'diadoc'?['box_id' => $secret['box_id']??'']:[])+$params);
            $result['source_originals'] = $originals;
            return $result;
        }
        finally{
            if ($hadCapture)$GLOBALS['IPPHP_CAPTURE_RAW'] = $oldCapture;
            else unset($GLOBALS['IPPHP_CAPTURE_RAW']);
            if ($hadEphemeral)$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = $oldEphemeral;
            else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);
        }
    }
    public function execute(string $provider,
        string $operation,
        int $actor,
        string $requestKey,
        array $params = []): array {
        $lock = 'ipphp-provider:' . Support::provider($provider);
        $q = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1)throw new \RuntimeException('PROVIDER_BUSY');
        try{
            return $this->executeLocked($provider,
                $operation,
                $actor,
                $requestKey,
                $params);
        }
        finally{
            $q = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
        }
    }
    public function resume(string $runId,
        int $actor): array {
        $q = $this->db->prepare('SELECT * FROM integration_runs WHERE id=?');
        $q->execute([$runId]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$r || $r['operation'] !== 'sync' || !in_array($r['status'],
            ['pending',
                'failed'],
            true))throw new \RuntimeException('RUN_NOT_RESUMABLE');
        $context = json_decode($r['coverage_json']??'{}',
            true)['command_context']??null;
        if (!is_array($context))throw new \RuntimeException('RESUME_CONTEXT_UNAVAILABLE');
        return $this->execute($r['provider'],
            'sync',
            (int)$r['actor_id'],
            $r['request_key'],
            $context+['_resume_run' => $runId]);
    }
    public function inspectBounded(string $provider,
        array &$secret,
        array $auth,
        string $from,
        string $to): array {
        $effective=$provider==='bank'?'onec':$provider;
        $expected=Config::get($this->db,$effective);
        $call=function(string $operation,
            array $params)use($provider,
            &$secret,$effective,$expected):array {
                $current=Config::get($this->db,$effective);
                if(!$current['enabled']||(int)$current['revision']!==(int)$expected['revision']||$current['config_json']!==$expected['config_json'])throw new \RuntimeException('REVISION_CONFLICT');
                if ($provider !== 'bank')unset($params['from'],
                $params['to']);
                else $params['cursor'] = $params['cursor']??0;
                $response = $this->native($provider,
                $operation,
                $secret,
                $params);
                $secret['session'] = $response['session']??$secret['session']??[];
                if (isset($response['data']['rows']) && !isset($response['data']['items']))$response['data']['items'] = $response['data']['rows'];
                return $response;
            };
        return (new BoundedCheck($call,new SourceFileProbe($call)))->inspect($provider,
            $auth,
            $from,
            $to);
    }
    private function executeLocked(string $provider,
        string $operation,
        int $actor,
        string $requestKey,
        array $params = []): array {
        Support::provider($provider);
        $scheduled = ($params['trigger']??null)==='hourly';
        if ($scheduled && ($actor!==0 || $operation!=='sync' || !CabinetSyncAdmission::allows($this->db,$provider,$operation))) {
            throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        }
        if(in_array($provider,['onec','bank'],true))unset($GLOBALS['ONEC_DIAGNOSTIC'],$GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']);
        if (!in_array($operation,
            ['check',
                'sync',
                'login',
                'send_code',
                'confirm'],
            true) || in_array($provider,
            ['email',
                'max'],
            true))throw new \InvalidArgumentException('INVALID_PROVIDER_COMMAND');
        if (!preg_match('/^[A-Za-z0-9-]{16,100}$/D',
            $requestKey))throw new \InvalidArgumentException('INVALID_REQUEST_KEY');
        $bankIdentity = null;
        $safeParams = $params;
        unset($safeParams['code'],
            $safeParams['_resume_run']);
        $fingerprint = hash('sha256',
            Support::canonical([$provider,
                $operation,
                $safeParams]));
        $id = Support::uuid();
        $this->db->beginTransaction();
        try{
            if ($provider === 'bank'){
                $lockOne = $this->db->query("SELECT provider FROM integration_connections WHERE provider='onec' FOR UPDATE");
                $lockOne->fetchColumn();
                $bankIdentity = Config::bankIdentity($this->db);
                $fingerprint = hash('sha256',
                    Support::canonical([$provider,
                        $operation,
                        $safeParams,
                        $bankIdentity]));
            }
            $q = $this->db->prepare('SELECT * FROM integration_connections WHERE provider=? FOR UPDATE');
            $q->execute([$provider]);
            $connection = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$connection || !$connection['enabled'])throw new \RuntimeException('CONNECTION_DISABLED');
            $q = $this->db->prepare('SELECT * FROM integration_runs WHERE provider=? AND actor_id=? AND request_key=? FOR UPDATE');
            $q->execute([$provider,
                    $actor,
                    $requestKey]);
            $existing = $q->fetch(\PDO::FETCH_ASSOC);
            if ($existing){
                if (!hash_equals($existing['fingerprint'],
                    $fingerprint))throw new \RuntimeException('IDEMPOTENCY_CONFLICT');
                if (($params['_resume_run']??'') !== $existing['id']){
                    $this->db->commit();
                    return $existing;
                }
                if ((int)$existing['revision'] !== (int)$connection['revision'] || !in_array($existing['status'],
                    ['pending',
                        'failed'],
                    true) || $operation !== 'sync')throw new \RuntimeException('RESUME_REVISION_CONFLICT');
                $id = $existing['id'];
                $this->db->prepare("UPDATE integration_runs SET status='pending',result_code=NULL,finished_at=NULL WHERE id=?")->execute([$id]);
            }
            $q = $this->db->prepare("SELECT COUNT(*) FROM integration_runs WHERE provider=? AND status='pending' AND id<>?");
            $q->execute([$provider,
                    $id]);
            if ($q->fetchColumn() > 0)throw new \RuntimeException('PROVIDER_BUSY_RECONCILE_PENDING');
            $revision = (int)$connection['revision'];
            if (!$existing)$this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json) VALUES(?,?,?,?,?,?,?,'pending',?)")->execute([$id,
                    $provider,
                    $operation,
                    $requestKey,
                    $actor,
                    $revision,
                    $fingerprint,
                    Support::json(['command_context' => $safeParams]+($bankIdentity === null?[]:['bank_auth_identity' => $bankIdentity]))]);
            $this->db->commit();
        }
        catch (\Throwable $e){
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        $public = json_decode($connection['config_json'],
            true);
        $credentialProvider = $provider === 'bank'?'onec':$provider;
        if ($provider === 'bank'){
            $one = Config::get($this->db,
                'onec');
            if (!$one['enabled']){
                $this->db->prepare("UPDATE integration_runs SET status='failed',result_code='ONEC_CONNECTION_DISABLED',finished_at=NOW(6) WHERE id=?")->execute([$id]);
                return ['id' => $id,
                    'status' => 'failed',
                    'result_code' => 'ONEC_CONNECTION_DISABLED'];
            }
            $public = json_decode($one['config_json'],
                true);
        }
        $reference = Config::reference((string)($public['access_reference']??''));
        $status = 'failed';
        $code = 'NOT_RUN';
        $coverage = ['command_context' => $safeParams]+($bankIdentity === null?[]:['bank_auth_identity' => $bankIdentity]);
        if(in_array($provider,['onec','bank'],true)&&OnecDevSshTransport::settings()!==null)$coverage['transport']=OnecDevSshTransport::identity();
        try{
            $secret = Config::profile($credentialProvider,$reference);
            if ($scheduled && !CabinetSyncAdmission::allows($this->db,$provider,$operation,$reference)) {
                throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            }
            if (!$scheduled && !is_callable($this->native) && !Config::liveAllowed() && !OnecReadAdmission::allows($this->db,$provider,$operation))throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            if ($provider === 'mosenergo'){
                $client = new MesClient($secret['session']??[],
                    is_callable($this->native)?$this->native:($scheduled?CabinetNetwork::mesTransport():null));
                try{
                    if ($operation === 'login' && isset($secret['mail']) && getenv('IPPHP_ALLOW_MES_MAIL') === 'owner-authorized-assigned-mailbox'){
                        $mail = new MesMail($secret['mail']);
                        $flow = new MesAutoOtp(Config::root() . '/private/integrations/mes-mail',
                            $mail);
                        $result = $flow->authenticate($client,
                            $secret,
                            function(array $session)use(&$secret,
                            $credentialProvider,
                            $reference){
                                $secret['session'] = $session;
                                Config::saveProfile($credentialProvider,
                                $reference,
                                $secret);
                            }
                        );
                    }
                    elseif($operation === 'login')$result = $client->login($secret);
                    elseif($operation === 'send_code'){
                        $secret['session'] = $client->session;
                        Config::saveProfile($credentialProvider,
                            $reference,
                            $secret);
                        $result = $client->requestCode();
                    }
                    elseif($operation === 'confirm')$result = $client->confirm((string)($params['code']??''));
                    elseif($operation === 'check'){
                        $result = $client->check();
                        if (($result['status']??'') === 'success'){
                            $from = Support::day($params['from']??date('Y-m-d',
                                strtotime('-30 days')));
                            $to = Support::day($params['to']??date('Y-m-d'));
                            $probeCall=static function(string $op,
                                array $probe)use($client):array{
                                    return ['data' => ['items' => $client->documents($probe['account'],
                                        $probe['from'],
                                        $probe['to']),
                                        'next' => null,
                                        'total' => null]];
                                };
                            $coverage = (new BoundedCheck($probeCall,new SourceFileProbe($probeCall)))->inspect($provider,
                                ['data' => ['items' => $client->accounts()]],
                                $from,
                                $to);
                            $result['code'] = 'BOUNDED_METADATA_CHECKED';
                        }
                    }
                    else {
                        $from = Support::day($params['from']??'2026-10-01');
                        $to = Support::day($params['to']??date('Y-m-d'));
                        $rows = [];
                        foreach ($client->accounts() as $account)$rows = array_merge($rows,
                            $client->documents((string)$account['id'],
                            $from,
                            $to));
                        if ($scheduled) {
                            $rows = self::cabinetPeriodRows($provider,$rows,$from,$to);
                            $this->checkCabinetRevision($provider,$reference);
                        }
                        $coverage = ['scope' => 'period_all_records',
                            'complete' => !$scheduled,
                            'scope_limited' => $scheduled,
                            'pagination_exhausted' => true,
                            'period_from' => $from,
                            'period_to' => $to,
                            'records_read' => count($rows)];
                        $result = (new Registry($this->db,
                            $this->store))->import($provider,
                            Support::json(['provider' => $provider,
                                'records' => $rows,
                                'coverage' => $coverage]),
                            $actor,
                            $requestKey . '-import');
                        $result['code'] = 'IMPORTED';
                        $coverage = $result['coverage'];
                    }
                }
                finally{
                    $secret['session'] = $client->session;
                    if (!$scheduled && !is_callable($this->native))Config::saveProfile($credentialProvider,
                        $reference,
                        $secret);
                }
                $status = $result['status']??'failed';
                $code = $result['code']??'READ_COMPLETE';
            }
            else {
                if (in_array($operation,
                    ['send_code',
                        'confirm'],
                    true))throw new \InvalidArgumentException('OTP_PROVIDER_REQUIRED');
                $auth = $scheduled && $provider==='diadoc'
                    ? []
                    : $this->native($provider,$scheduled?'accounts':'authenticate',$secret,[],$scheduled);
                $secret['session'] = $auth['session']??[];
                if (!$scheduled && !is_callable($this->native))Config::saveProfile($credentialProvider,
                    $reference,
                    $secret);
                if ($operation === 'check'){
                    if($provider==='onec'&&($auth['data']['scope']??null)==='organization_read_capability'){
                        $coverage=$auth['data']+['complete'=>false,'scope_limited'=>true,'native_write_operations'=>0];
                    }else $coverage = $this->inspectBounded($provider,
                        $secret,
                        $auth,
                        Support::day($params['from']??date('Y-m-d',
                        strtotime('-30 days'))),
                        Support::day($params['to']??date('Y-m-d')));
                    if(isset($auth['transport']))$coverage['transport']=$auth['transport'];
                    $status = 'success';
                    $code = 'BOUNDED_METADATA_CHECKED';
                }
                elseif($operation === 'login'){
                    $status = 'success';
                    $code = 'AUTH_METADATA_CHECKED';
                }
                else {
                    $from = Support::day($params['from']??($provider === 'onec'?'2026-09-01':'2026-10-01'));
                    $to = Support::day($params['to']??date('Y-m-d'));
                    if ($to < $from)throw new \InvalidArgumentException('INVALID_PERIOD');
                    $captured = $this->collectNative($provider,
                        $id,
                        $secret,
                        $from,
                        $to,
                        $auth,$scheduled);
                    $rows = $captured['items'];
                    if ($scheduled) {
                        $rows = self::cabinetPeriodRows($provider,$rows,$from,$to);
                        $this->checkCabinetRevision($provider,$reference);
                    }
                    $scopeLimited = $captured['scope_limited'];
                    $allTerminal = true;
                    if (!$scheduled && !is_callable($this->native))Config::saveProfile($credentialProvider,
                        $reference,
                        $secret);
                    $coverage = ['scope' => 'period_all_records',
                        'complete' => !$scopeLimited,
                        'scope_limited' => $scopeLimited,
                        'pagination_exhausted' => $allTerminal,
                        'period_from' => $from,
                        'period_to' => $to,
                        'records_read' => count($rows)];
                    $import = (new Registry($this->db,
                        $this->store))->import($provider,
                        Support::json(['provider' => $provider,
                            'records' => $rows,
                            'coverage' => $coverage]),
                        $actor,
                        $requestKey . '-import');
                    $coverage = $import['coverage'];
                    $status = 'success';
                    $code = 'IMPORTED';
                }
            }
        }
        catch (\Throwable $e){
            $allowed = ['EXTERNAL_ACCESS_NOT_AUTHORIZED',
                'AUTH_REQUIRED',
                'SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN',
                'PROFILE_NOT_CONFIGURED',
                'PUSH_REQUIRED',
                'OTP_REQUIRED',
                'OTP_EXPIRED_RELOGIN_REQUIRED',
                'OTP_REQUEST_NOT_REPLAYABLE',
                'MFA_FACTOR_UNSUPPORTED',
                'OTP_SENT',
                'ACCOUNT_NOT_AVAILABLE',
                'PROVIDER_BUSY_RECONCILE_PENDING',
                'SOURCE_SCHEMA_CHANGED',
                'SOURCE_TOTAL_CHANGED',
                'INCOMPLETE_SOURCE_COUNT',
                'REPEATED_CURSOR',
                'INVALID_PERIOD',
                'INVALID_OTP',
                'ACCOUNT_LIST_NOT_CONFIRMED',
                'MAIL_WAITING_FRESH_MESSAGE',
                'MAIL_RECOVERY_REQUIRED',
                'MAIL_TLS_OR_NETWORK',
                'MAIL_ACCESS_NOT_AUTHORIZED',
                'MAIL_FLAGS_CHANGED',
                'MAIL_UIDVALIDITY_CHANGED',
                'MAIL_REPLAY',
                'MAIL_AMBIGUOUS_CODE',
                'MAIL_REQUEST_EXPIRED',
                'MAIL_PROFILE_INVALID',
                'CONFLICTING_SOURCE_ID'];
            $code = in_array($e->getMessage(),
                $allowed,
                true)?$e->getMessage():'PROVIDER_OPERATION_FAILED';
            if ($scheduled && $provider==='pik' && $e instanceof \PikComfort\AuthenticationError
                && $e->getMessage()==='HTTP 401: sign in again') {
                $code='AUTH_REQUIRED';
            }
            $status = $operation === 'send_code'?'uncertain':'failed';
            if(in_array($provider,['onec','bank'],true)){
                $record=$GLOBALS['ONEC_DIAGNOSTIC']['dev_transport']??$GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']??[];
                $failure=self::devFailureOutcome($e,is_array($record)?$record:[]);
                if($failure!==null){
                    $code=$failure['code'];$status=$failure['status'];
                    $coverage['native_diagnostic']=['dev_transport'=>$failure['diagnostic']];
                }
            }
        }
        if ($operation === 'check'){
            $current = Config::get($this->db,
                $provider);
            if (!$current['enabled'] || (int)$current['revision'] !== $revision || $current['config_json'] !== $connection['config_json'] || $bankIdentity !== null && Config::bankIdentity($this->db) !== $bankIdentity
                ||isset($coverage['transport'])&&OnecDevSshTransport::identity()!==$coverage['transport']){
                $status = 'failed';
                $code = 'REVISION_CONFLICT';
                $coverage['revision_current'] = false;
                $coverage['complete'] = false;
            }
            else $coverage['revision_current'] = true;
            $coverage['receipt_path'] = $this->store->spool(['run_id' => $id,
                    'provider' => $provider,
                    'operation' => 'check',
                    'revision' => $revision],
                Support::json(['status' => $status,
                    'code' => $code,
                    'coverage' => self::checkReceiptCoverage($coverage)]));
        }
        $this->db->prepare("UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=? AND status='pending'")->execute([$status,
                $code,
                Support::json($coverage+['command_context' => $safeParams]+($bankIdentity === null?[]:['bank_auth_identity' => $bankIdentity])),
                $id]);
        if ($operation === 'check')$this->db->prepare('UPDATE integration_connections SET last_status=?,last_checked_at=NOW(6) WHERE provider=? AND revision=?')->execute([$status,
                $provider,
                $revision]);
        return ['id' => $id,
            'status' => $status,
            'result_code' => $code,
            'coverage' => $coverage];
    }
    private function collectNative(string $provider,
        string $runId,
        array &$secret,
        string $from,
        string $to,
        array $auth, bool $scheduled = false): array {
        $accounts = ['' => null];
        if (in_array($provider,
            ['pik',
                'mosvodokanal'],
            true)){
            $source = $auth['data']['items']??null;
            if (!is_array($source))throw new \RuntimeException('ACCOUNT_LIST_NOT_CONFIRMED');
            $accounts = [];
            foreach ($source as $account){
                $key = $provider === 'pik'?($account['_uid']??null):($account['ID']??$account['ACCOUNT_ID']??$account['id']??null);
                $key = Support::identity($key);
                if (isset($accounts[$key]))throw new \RuntimeException('DUPLICATE_ACCOUNT');
                $accounts[$key] = $account;
            }
        }
        $rows = [];
        $limited = false;
        $collector = new Collector($this->db,
            $this->store);
        foreach ($accounts as $accountId => $account){
            $result = $collector->collect($runId,
                (string)$accountId,
                function($cursor)use($provider,
                &$secret,
                $from,
                $to,
                $scheduled,
                $accountId,
                $account){
                    $operation = 'records';
                    $extra = ['cursor' => $cursor,
                        'account' => $account === null?null:(string)$accountId];
                    if ($provider === 'onec'){
                        $operation = 'archive_invoices';
                        $extra['archive_period'] = ['from' => $from,
                            'to' => $to];
                    }
                    elseif($provider === 'bank')$extra = ['from' => $from,
                        'to' => $to,
                        'cursor' => $cursor??0];
                    $r = $this->native($provider,
                    $operation,
                    $secret,
                        $extra,$scheduled);
                    $secret['session'] = $r['session']??$secret['session']??[];
                    $data = $r['data']??[];
                    $items = $data['items']??$data['rows']??null;
                    if (!is_array($items))throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
                    if ($account !== null)$items = array_map(static fn($row) => ['fields' => ['record' => $row,
                        'account' => $account],
                        'account_id' => (string)$accountId],
                    $items);
                    return ['items' => $items,
                        'next' => $data['next']??$data['next_cursor']??null,
                        'total' => $data['total']??null,
                        'source_originals' => $r['source_originals']??[]];
                }
            );
            if ($result['total'] === null)$limited = true;
            $rows = array_merge($rows,
                $result['items']);
        }
        return ['items' => $rows,
            'scope_limited' => $limited];
    }

    private function checkCabinetRevision(string $provider,string $reference): void
    {
        if (!CabinetSyncAdmission::allows($this->db,$provider,'sync',$reference)) {
            throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        }
    }

    private static function cabinetPeriodRows(string $provider,array $rows,string $from,string $to): array
    {
        return array_values(array_filter($rows,static function(array $row) use ($provider,$from,$to): bool {
            $date = Registry::normalize($provider,$row)['document_date'];
            return $date>=$from&&$date<=$to;
        }));
    }
}
