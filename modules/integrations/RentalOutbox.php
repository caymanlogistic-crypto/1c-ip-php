<?php
declare(strict_types = 1);
namespace Modules\Integrations;
use Modules\Rental\{ContractDelivery, DeliveryRetry, InvoiceDelivery};
/** Consumes an already authorized typed rental intent. Does not create a business command. */
final class RentalOutbox {
    public function __construct(private \PDO $db,
        private PrivateStore $store,
        private mixed $sink = null) {
    }
    public function run(string $intentId): array {
        return $this->runBound($intentId,null);
    }
    /** HTTP action dispatches only its committed intent, with the actual current user. */
    public function runForCurrentActor(string $intentId):array {
        if($this->db->inTransaction())throw new \RuntimeException('DISPATCH_REQUIRES_COMMITTED_INTENT');
        $actor=\App\Auth::user()??throw new \RuntimeException('Требуется вход.',401);
        return $this->runBound($intentId,['id'=>(int)$actor['id'],'role_id'=>$actor['role_id'],
            'auth_version'=>(int)$actor['auth_version']]);
    }
    private function runBound(string $intentId,?array $currentActor):array {
        if (!preg_match('/^[a-f0-9]{32}$/D',
            $intentId))throw new \InvalidArgumentException('INVALID_INTENT_ID');
        $lock = 'ipphp-rental-send:' . $intentId;
        $q = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1)throw new \RuntimeException('INTENT_BUSY');
        try{
            return $this->runLocked($intentId,$currentActor);
        }
        finally{
            $q = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
        }
    }
    private function runLocked(string $intentId,?array $currentActor): array {
        $this->db->beginTransaction();
        try{
            $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $q = $this->db->prepare('SELECT * FROM rental_delivery_intents WHERE id=? FOR UPDATE');
            $q->execute([$intentId]);
            $intent = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$intent)throw new \RuntimeException('INTENT_NOT_FOUND');
            if ($intent['provider'] !== 'email')throw new \RuntimeException('UNSUPPORTED_TYPED_INTENT');
            [$handler, $requiredPolicy, $page] = match ([$intent['entity_type'], $intent['operation']]) {
                ['contract', 'contract-packet'] => [ContractDelivery::class, 'rental-approved-packet-v1', 'rental.approvals'],
                ['document', 'addendum-packet'] => [ContractDelivery::class, 'rental-approved-addendum-packet-v1', 'rental.approvals'],
                ['invoice', 'invoice'] => [InvoiceDelivery::class, 'rental-approved-invoice-v1', 'rental.invoice-list'],
                default => throw new \RuntimeException('UNSUPPORTED_TYPED_INTENT'),
            };
            $invoice = $handler === InvoiceDelivery::class;
            $payload=Support::decode($intent['payload_json']);
            $retry=!$invoice&&array_key_exists('retry_of',$payload);
            if($currentActor!==null){
                if($invoice)throw new \RuntimeException('OPS_PACKET_DISPATCH_REQUIRED',403);
                $bound=$payload[$retry?'sender_context':'actor_context']??null;
                if(!is_array($bound)||Support::canonical($bound)!==Support::canonical($currentActor))throw new \RuntimeException('CURRENT_SENDER_INTENT_MISMATCH',403);
                $query=$this->db->prepare('SELECT active,role,auth_version FROM users WHERE id=? FOR UPDATE');
                $query->execute([$currentActor['id']]);$storedActor=$query->fetch(\PDO::FETCH_ASSOC);
                if(!$storedActor||!$storedActor['active']||$storedActor['role']!==$currentActor['role_id']
                    ||(int)$storedActor['auth_version']!==$currentActor['auth_version'])throw new \RuntimeException('ACTOR_AUTHORIZATION_REVOKED',403);
            }
            if($retry){
                $handler=DeliveryRetry::class;
                // Current sender's actual registered role/page/auth-version checks,
                // independent of the immutable original accountant signature.
                DeliveryRetry::authorizeStored($this->db,$intent);
            }else{
                $this->authorizeInitial($intent,$invoice,$page);
            }
            $storedReceipt=Support::decode($intent['receipt_json']??'{}');
            if(array_key_exists('manual_completion',$storedReceipt)){
                $this->db->commit();
                return ['id'=>$intentId,'status'=>$intent['status'],'reused'=>true,'manual_annotation'=>true,
                    'reconciliation_required'=>in_array($intent['status'],['unknown','claimed'],true)];
            }
            if ($intent['status'] !== 'prepared'){
                $this->db->commit();
                return ['id' => $intentId,
                    'status' => $intent['status'],
                    'reused' => true];
            }
            $handler::validate($this->db,
                $intent);
            if (($payload['policy']??'') !== $requiredPolicy)throw new \RuntimeException('UNAPPROVED_RENTAL_POLICY');
            if (isset($payload['catalog_binding']) || isset($payload['catalog_values'])){
                if (!is_array($payload['catalog_binding']??null) || !is_array($payload['catalog_values']??null))throw new \RuntimeException('CATALOG_BINDING_REQUIRED');
                (new TemplateCatalog($this->db,
                    $this->store))->verifyEvent($payload['catalog_binding'],
                    $payload['catalog_values'],
                    $payload['subject'],
                    $payload['text']);
            }
            $fingerprint = hash('sha256',
                Support::canonical($payload));
            $claimPath = $this->store->spool(['intent_id' => $intentId,
                    'entity_version' => $intent['entity_version'],
                    'baseline_hash' => $intent['baseline_hash'],
                    'kind' => 'rental-claim'],
                Support::canonical($payload));
            $this->db->prepare("UPDATE rental_delivery_intents SET status='claimed',receipt_json=? WHERE id=? AND status='prepared'")->execute([Support::json(['claim_path' => $claimPath,
                    'payload_sha256' => $fingerprint]),
                    $intentId]);
            $this->db->commit();
        }
        catch (\Throwable $e){
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        $attempts = [];
        $dispatchStarted = false;
        $receipt = ['intent_id' => $intentId,
            'claim_path' => $claimPath,
            'baseline_hash' => $intent['baseline_hash'],
            'payload_sha256' => $fingerprint,
            'accepted' => false];
        $status = 'failed';
        $code = 'NOT_DISPATCHED';
        try{
            if (!is_callable($this->sink))$receipt['policy_binding'] = $this->policy($payload);
            $attachments = [];
            $root = realpath(Config::root() . '/.local/documents');
            if (!$root)throw new \RuntimeException('ATTACHMENT_ROOT_MISSING');
            foreach ($payload['attachments']??[] as $file){
                $path = realpath($file['path']??'');
                if (!$path || is_link($file['path']) || !str_starts_with(strtolower(str_replace('\\',
                    '/',
                    $path)),
                    strtolower(str_replace('\\',
                    '/',
                    $root)) . '/') || !is_file($path) || filesize($path) > 10485760)throw new \RuntimeException('ATTACHMENT_BINDING_FAILED');
                $bytes = file_get_contents($path);
                if (!is_string($bytes) || !hash_equals($file['sha256']??'',
                    hash('sha256',
                    $bytes)))throw new \RuntimeException('ATTACHMENT_HASH_FAILED');
                $attachments[] = ['name' => $file['filename'],
                    'bytes' => $bytes];
            }
            $this->store->verifySpool($claimPath);
            // Persist the dispatch boundary before control enters the sender. A thrown return does
            // not prove that no target accepted DATA: receipts/SQL may fail after provider ACK.
            $receipt['dispatch_boundary_path'] = $this->store->spool(['intent_id' => $intentId,
                    'kind' => 'rental-dispatch-boundary'],
                Support::json(['request_key' => 'rental-intent-' . $intentId,
                    'payload_sha256' => $fingerprint]));
            $this->db->beginTransaction();
            try{
                $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
                $query=$this->db->prepare('SELECT * FROM rental_delivery_intents WHERE id=? FOR UPDATE');
                $query->execute([$intentId]);
                $current=$query->fetch(\PDO::FETCH_ASSOC);
                if(!$current||$current['status']!=='claimed')throw new \RuntimeException('INTENT_CLAIM_CHANGED');
                $receipt=$this->preserveManualAnnotation($intentId,$receipt);
                if(isset($receipt['manual_completion']))throw new \RuntimeException('MANUAL_COMPLETION_BEFORE_DISPATCH');
                // Recheck current sender and original business/file proof immediately before
                // the durable boundary. The transaction ends before entering the transport.
                if(!$retry)$this->authorizeInitial($current,$invoice,$page);
                $handler::validate($this->db,$current);
                $this->db->prepare("UPDATE rental_delivery_intents SET receipt_json=? WHERE id=? AND status='claimed'")
                    ->execute([Support::json($receipt+['dispatch_boundary_started'=>true]),$intentId]);
                $this->db->commit();
            }catch(\Throwable $error){
                if($this->db->inTransaction())$this->db->rollBack();
                throw $error;
            }
            $dispatchStarted = true;
            $attempts = (new Messaging($this->db,
                $this->store,
                $this->sink))->dispatch('email',
                (int)$intent['actor_id'],
                'rental-intent-' . $intentId,
                'send',
                $payload['recipients'],
                $payload['text'],
                $payload['subject'],
                $attachments);
            $states = array_column($attempts,
                'status');
            $accepted = count(array_filter($states,
                static fn($s) => $s === 'accepted'));
            $uncertain = array_intersect($states,
                ['pending',
                    'uncertain']);
            $status = $accepted === count($states) && $states?'accepted':($accepted > 0 || $uncertain?'unknown':'failed');
            $code = $status === 'accepted'?'ALL_TARGETS_ACCEPTED':($status === 'unknown'?'PARTIAL_OR_UNKNOWN_NO_REPLAY':'CONFIRMED_NOT_ACCEPTED');
            $receipt['accepted'] = $status === 'accepted';
            $receipt['attempts'] = array_map(static fn($a) => ['id' => $a['id'],
                    'target' => $a['target'],
                    'status' => $a['status'],
                    'remote_id' => $a['remote_id'],
                    'result_code' => $a['result_code']],
                $attempts);
        }
        catch (\Throwable $e){
            $code = in_array($e->getMessage(),
                ['EXTERNAL_ACCESS_NOT_AUTHORIZED',
                    'TARGET_NOT_APPROVED',
                    'UNAPPROVED_RENTAL_POLICY',
                    'POLICY_RELEASE_BINDING_REQUIRED',
                    'POLICY_DIGEST_MISMATCH',
                    'POLICY_DISABLED',
                    'ATTACHMENT_BINDING_FAILED',
                    'ATTACHMENT_HASH_FAILED',
                    'ACTOR_AUTHORIZATION_REVOKED',
                    'CONNECTION_DISABLED'],
                true)?$e->getMessage():'OUTBOX_DISPATCH_FAILED';
            // A manual annotation on our claimed row is not permission to release its
            // uncertainty fence, even when this invocation stopped before transport.
            $manualClaim=isset($receipt['manual_completion']);
            $status = $dispatchStarted||$manualClaim?'unknown':'failed';
            if($manualClaim&&!$dispatchStarted){
                $receipt['reconciliation_required']=true;
                $code='MANUAL_CLAIM_REQUIRES_RECONCILIATION_NO_REPLAY';
            }
            if ($dispatchStarted){
                $receipt['reconciliation_required'] = true;
                $receipt['dispatch_boundary_started'] = true;
                $code = 'DISPATCH_BOUNDARY_RESULT_UNKNOWN_NO_REPLAY';
                try{
                    $q = $this->db->prepare('SELECT id,target,status,remote_id,result_code FROM messaging_attempts WHERE provider=\'email\' AND actor_id=? AND request_key=?');
                    $q->execute([$intent['actor_id'],
                            'rental-intent-' . $intentId]);
                    $receipt['attempts'] = $q->fetchAll(\PDO::FETCH_ASSOC);
                    $attempts = $receipt['attempts'];
                    foreach ($attempts as $attempt){
                        $durable = $this->store->receiptFor($attempt['id']);
                        if ($durable !== null)$receipt['durable_transport_receipts'][] = ['attempt_id' => $attempt['id'],
                            'receipt_path' => $durable['receipt_path']];
                    }
                }
                catch (\Throwable){
                    $receipt['attempts_readable'] = false;
                }
            }
        }
        $receipt['code'] = $code;
        $receipt['status'] = $status;
        $receiptPath = $this->store->spool(['intent_id' => $intentId,
                'kind' => 'rental-transport-receipt'],
            Support::json($receipt));
        $receipt['receipt_path'] = $receiptPath;
        $this->db->beginTransaction();
        try{
            $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $receipt=$this->settlementFence($intentId,$receipt,$status,$dispatchStarted);
            if(!empty($receipt['manual_claim_reconciliation'])){
                $receiptPath=$this->store->spool(['intent_id'=>$intentId,'kind'=>'rental-manual-reconciliation-receipt'],Support::json($receipt));
                $receipt['receipt_path']=$receiptPath;
            }
            $handler::settle($this->db,
                $intentId,
                $status,
                $receipt);
            $this->db->commit();
        }
        catch (\Throwable $e){
            if ($this->db->inTransaction())$this->db->rollBack();
            $receipt['accepted_transport'] = $receipt['accepted'];
            $receipt['accepted'] = false;
            $receipt['reconciliation_required'] = true;
            $receipt['status'] = 'unknown';
            $receipt['code'] = 'TRANSPORT_LOCAL_SETTLEMENT_REQUIRES_RECONCILIATION';
            $this->db->beginTransaction();
            try{
                $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
                $receipt=$this->preserveManualAnnotation($intentId,$receipt);
                $handler::settle($this->db,
                    $intentId,
                    'unknown',
                    $receipt);
                $this->db->commit();
            }
            catch (\Throwable){
                if ($this->db->inTransaction())$this->db->rollBack();
                throw new \RuntimeException('DURABLE_ACK_EXISTS_LOCAL_RECONCILIATION_REQUIRED');
            }
            $status = 'unknown';
        }
        return ['id' => $intentId,
            'status' => $status,
            'receipt_path' => $receiptPath,
            'attempt_ids' => array_column($attempts,
                'id')];
    }
    private function authorizeInitial(array $intent,bool $invoice,string $page):void
    {
        $q = $this->db->prepare("SELECT u.active,u.role,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END AS workflow_role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=? FOR UPDATE");
        $q->execute([$intent['actor_id']]);
        $actor = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$actor || !$actor['active'] || !in_array($actor['workflow_role'],
            $invoice ? ['manager', 'accountant', 'admin'] : ['accountant', 'admin'],
            true))throw new \RuntimeException('ACTOR_AUTHORIZATION_REVOKED');
        $grant = $invoice && $actor['workflow_role'] === 'manager' ? 'rental.write' : 'finance.write';
        foreach (['rental.read', $grant] as $permission) {
            $q = $this->db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*') LIMIT 1 FOR UPDATE");
            $q->execute([$actor['role'], $permission]);
            if (!$q->fetchColumn())throw new \RuntimeException('ACTOR_AUTHORIZATION_REVOKED');
        }
        if ($actor['role'] !== 'admin' && $actor['grants_configured']) {
            $q = $this->db->prepare('SELECT level FROM role_page_grants WHERE role_id=? AND page_key=? FOR UPDATE');
            $q->execute([$actor['role'], $page]); $level = (int)($q->fetchColumn() ?: 0);
        } else $level = \App\PagePolicy::levelForRole($actor['role'], $actor['workflow_role'], (bool)$actor['grants_configured'], $page);
        if ($level < 2)throw new \RuntimeException('ACTOR_AUTHORIZATION_REVOKED');
    }
    private function preserveManualAnnotation(string $id,array $receipt):array
    {
        $query=$this->db->prepare('SELECT receipt_json FROM rental_delivery_intents WHERE id=? FOR UPDATE');$query->execute([$id]);
        $stored=$query->fetchColumn();$prior=$stored===false||$stored===null?[]:Support::decode($stored);
        if(isset($prior['manual_completion']))$receipt['manual_completion']=$prior['manual_completion'];
        return $receipt;
    }
    /** Final status is decided only after refreshing the claimed row under its lock. */
    private function settlementFence(string $id,array $receipt,string &$status,bool $dispatchStarted):array
    {
        $query=$this->db->prepare('SELECT status,receipt_json FROM rental_delivery_intents WHERE id=? FOR UPDATE');
        $query->execute([$id]);$stored=$query->fetch(\PDO::FETCH_ASSOC);
        if(!$stored)throw new \RuntimeException('INTENT_NOT_FOUND');
        $prior=Support::decode($stored['receipt_json']??'{}');
        if(isset($prior['manual_completion']))$receipt['manual_completion']=$prior['manual_completion'];
        if($stored['status']==='claimed'&&isset($prior['manual_completion'])){
            $receipt['accepted_transport']=!empty($receipt['accepted_transport'])||!empty($receipt['accepted']);
            $receipt['accepted']=false;
            $receipt['reconciliation_required']=true;
            $receipt['manual_claim_reconciliation']=true;
            $receipt['transport_dispatch_started']=$dispatchStarted;
            $receipt['status']=$status='unknown';
            $receipt['code']='MANUAL_CLAIM_REQUIRES_RECONCILIATION_NO_REPLAY';
        }
        return $receipt;
    }
    private function policy(array $payload): array {
        if (!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        $secret = Config::secrets('email');
        $policy = $secret['rental_policies'][$payload['policy']] ?? $secret['rental_policy'] ?? null;
        if (!is_array($policy) || ($policy['project_id']??'') !== 'f6e21e3c-d974-4604-82fb-333da66559d2' || ($policy['policy']??'') !== $payload['policy'] || ($policy['enabled']??false) !== true)throw new \RuntimeException('POLICY_DISABLED');
        $version = getenv('IPPHP_RELEASE_SHA');
        if (!is_string($version) || !preg_match('/^[a-f0-9]{40}$/D',
            $version) || ($policy['source_version']??'') !== $version)throw new \RuntimeException('POLICY_RELEASE_BINDING_REQUIRED');
        $digest = $policy['digest']??'';
        unset($policy['digest']);
        if (!is_string($digest) || !hash_equals(hash('sha256',
            Support::canonical($policy)),
            $digest))throw new \RuntimeException('POLICY_DIGEST_MISMATCH');
        $allowed = array_map('strtolower',
            $secret['approved_recipients']??[]);
        foreach ($payload['recipients'] as $target)if (!in_array(strtolower($target),
            $allowed,
            true))throw new \RuntimeException('TARGET_NOT_APPROVED');
        return ['source_version' => $version,
            'digest' => $digest,
            'policy' => $policy['policy']];
    }
}
