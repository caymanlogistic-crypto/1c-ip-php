<?php
declare(strict_types = 1);
namespace Modules\Integrations;
use Modules\Rental\NativePacket;
/** Concrete normalized-row counterpart of source owner.py; no caller approval flags or per-invoice callbacks. */
final class OnecOwnerFacade implements OnecPreparedOwner {
    private OnecBindings $bindings;
    private OnecPayloadBuilder $builder;
    public function __construct(private \PDO $db,
        private PrivateStore $store) {
        $this->bindings = new OnecBindings($db,
            $store);
        $this->builder = new OnecPayloadBuilder($this->bindings);
    }
    public function actor(int $id,
        string $action):array {
        $locking = $this->db->inTransaction() ? ' FOR UPDATE' : '';
        $q = $this->db->prepare("SELECT u.id,u.active,u.role AS role_id,u.auth_version,u.name,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END AS workflow_role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?".$locking);
        $q->execute([$id]);
        $u = $q->fetch(\PDO::FETCH_ASSOC);
        $write = in_array($action,
            ['prepare',
                'validate',
                'create',
                'confirm'],
            true);
        if (!$u || !$u['active'] || !in_array($u['workflow_role'],
            $write?['accountant',
                'admin']:['manager',
                'lawyer',
                'accountant',
                'director',
                'admin'],
            true))throw new \RuntimeException('ONEC_ACTOR_DENIED');
        foreach ($write?['rental.read',
                'finance.write']:['rental.read'] as $grant){
            $q = $this->db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*') LIMIT 1".$locking);
            $q->execute([$u['role_id'],
                    $grant]);
            if (!$q->fetchColumn())throw new \RuntimeException('ONEC_ACTOR_DENIED');
        }
        $required = $action === 'read_existing'?1:2;
        if ($u['role_id'] !== 'admin' && $u['grants_configured']) {
            $q = $this->db->prepare("SELECT level FROM role_page_grants WHERE role_id=? AND page_key='rental.approvals'".$locking);
            $q->execute([$u['role_id']]);
            $level = (int)($q->fetchColumn() ?: 0);
        } else $level = \App\PagePolicy::levelForRole($u['role_id'],
            $u['workflow_role'],
            (bool)$u['grants_configured'],
            'rental.approvals');
        if ($level < $required)throw new \RuntimeException('ONEC_ACTOR_DENIED');
        $u['role'] = $u['workflow_role'];
        return $u;
    }
    private static function identity(array $v):array {
        $keys = array_keys($v);
        sort($keys);
        if ($keys !== ['record_id',
                'record_kind',
                'subject_id'] || !in_array($v['record_kind']??'',
            ['contract',
                'document'],
            true) || !is_int($v['record_id']??null) || $v['record_id'] < 1 || !is_string($v['subject_id']??null) || !preg_match('/^(?:party|invoice):[1-9][0-9]*$/D',
            $v['subject_id']))throw new \InvalidArgumentException('ONEC_BUSINESS_IDENTITY_INVALID');
        return $v;
    }
    public static function requestKey(array $envelope):string {
        $identity = ['project_id' => CaptureReader::PROJECT,
            'source_namespace' => $envelope['source_namespace'],
            'logical_document_id' => $envelope['logical_document_id'],
            'step_id' => $envelope['step_id'],
            'kind' => $envelope['kind']];
        $namespace = hex2bin(str_replace('-',
            '',
            CaptureReader::PROJECT));
        $hash = sha1($namespace . OnecMapping::json($identity));
        $bytes = hex2bin(substr($hash,
            0,
            32));
        $bytes[6] = chr((ord($bytes[6])&15)|80);
        $bytes[8] = chr((ord($bytes[8])&63)|128);
        $hex = bin2hex($bytes);
        return substr($hex,
            0,
            8) . '-' . substr($hex,
            8,
            4) . '-' . substr($hex,
            12,
            4) . '-' . substr($hex,
            16,
            4) . '-' . substr($hex,
            20);
    }
    private function resolve(int $actor,
        string $kind,
        array $identity,
        string $phase):array {
        $identity = self::identity($identity);
        $u = $this->actor($actor,
            $phase);
        // Getter validates real persisted normal confirmation and current rows; it never approves on read.
        $packet = NativePacket::current($this->db,
            $identity['record_kind'],
            $identity['record_id'],
            $u,
            $phase);
        if (($packet['state']??'') === 'normal_confirmation_required')throw new OnecNormalConfirmationRequired($packet['missing']??['confirmation.normal_accountant_packet']);
        $original = $packet['source_original'] ?? null;
        if (!is_array($original)
            || !is_string($original['snapshot_text'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $original['raw_sha256'] ?? '')
            || !hash_equals($original['raw_sha256'], hash('sha256', $original['snapshot_text']))
            || !is_string($original['approval_signature_text'] ?? null)
            || $original['approval_signature_text'] === ''
            || !is_string($original['packet_signature_text'] ?? null)
            || $original['packet_signature_text'] === '') {
            throw new \RuntimeException('ONEC_B_ORIGINAL_EVIDENCE_INVALID');
        }
        $source = $this->bindings->source();
        $envelope = $this->builder->build($source,
            $packet,
            $kind,
            $identity['subject_id']);
        if ((int)($packet['confirmation']['actor_id']??0) !== $actor)throw new \RuntimeException('ONEC_CONFIRMATION_ACTOR_CHANGED');
        $raw = OnecMapping::json($envelope);
        if (strlen($raw) > 1048576)throw new \RuntimeException('ONEC_PREPARED_PACKET_LIMIT');
        return ['source' => $source,
            'packet' => $envelope,
            'normalized' => $packet,
            'packet_raw' => $raw,
            'prepared_sha256' => hash('sha256',
                $raw),
            'request_key' => self::requestKey($envelope),
            'business_identity' => $identity];
    }
    public function preview(int $actor,
        string $kind,
        array $identity):array {
        try{
            $r = $this->resolve($actor,
                $kind,
                $identity,
                'prepare');
            return ['state' => 'ready_for_prepare',
                'written' => false,
                'native_fill_check' => false,
                'packet' => $r['packet'],
                'packet_sha256' => $r['prepared_sha256'],
                'request_key' => $r['request_key']];
        }
        catch (OnecNormalConfirmationRequired $e){
            return ['state' => 'awaiting_confirmation',
                'code' => 'NORMAL_B_CONFIRMATION_REQUIRED',
                'missing' => $e->fields,
                'written' => false];
        }
        catch (OnecSetupGap $e){
            return ['state' => 'setup_mapping_gap',
                'code' => 'SETUP_MAPPING_GAP',
                'missing' => $e->fields,
                'written' => false];
        }
    }
    public function prepare(int $actor,
        string $kind,
        array $identity):array {
        if ($this->db->inTransaction())return $this->prepareLocked($actor,
            $kind,
            $identity);
        $this->db->beginTransaction();
        try{
            $r = $this->prepareLocked($actor,
                $kind,
                $identity);
            $this->db->commit();
            return $r;
        }
        catch (\Throwable $e){
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
    private function prepareLocked(int $actor,
        string $kind,
        array $identity):array {
        $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
        $r = $this->resolve($actor,
            $kind,
            $identity,
            'prepare');
        $p = $r['packet'];
        $n = $r['normalized'];
        $path = $this->store->put('onec-prepared',
            $r['packet_raw']);
        $context = $this->store->put('onec-owner-context',
            Support::canonical(['business_identity' => $identity,
                'confirmation' => $n['confirmation'],
                'source_original' => $n['source_original'],
                'original_private_paths' => [
                    'snapshot' => $this->store->put('onec-source-originals', $n['source_original']['snapshot_text']),
                    'approval_signature' => $this->store->put('onec-source-originals', $n['source_original']['approval_signature_text']),
                    'packet_signature' => $this->store->put('onec-source-originals', $n['source_original']['packet_signature_text']),
                ]]));
        $id = Support::uuid();
        $fence = Support::uuid();
        $this->db->prepare("INSERT INTO integration_native_prepared(id,source_instance,source_namespace,logical_document_id,step_id,kind,request_key,actor_id,workflow_epoch,workflow_revision,record_kind,record_id,record_version,attempt_fence,packet_path,packet_sha256,context_path,confirmation_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id")->execute([$id,
                $r['source']['source_instance'],
                $p['source_namespace'],
                $p['logical_document_id'],
                $p['step_id'],
                $kind,
                $r['request_key'],
                $actor,
                $p['workflow']['epoch'],
                $p['workflow']['revision'],
                $identity['record_kind'],
                $identity['record_id'],
                $n['record']['version'],
                $fence,
                $path,
                $r['prepared_sha256'],
                $context,
                $n['confirmation']['audit_ref']]);
        $q = $this->db->prepare('SELECT * FROM integration_native_prepared WHERE source_namespace=? AND logical_document_id=? AND step_id=? AND kind=? FOR UPDATE');
        $q->execute([$p['source_namespace'],
                $p['logical_document_id'],
                $p['step_id'],
                $kind]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['source_instance'] !== $r['source']['source_instance'] || (int)$row['actor_id'] !== $actor || $row['request_key'] !== $r['request_key'] || !hash_equals($row['packet_sha256'],
            $r['prepared_sha256']) || $row['confirmation_ref'] !== $n['confirmation']['audit_ref'])throw new \RuntimeException('ONEC_PREPARED_SCOPE_CHANGED');
        $r['prepared'] = $row;
        return $r;
    }
    public function current(array $intent,
        string $action):array {
        $actor = (int)$intent['actor_id'];
        $this->actor($actor,
            $action);
        $q = $this->db->prepare('SELECT * FROM integration_native_prepared WHERE id=?' . ($this->db->inTransaction()?' FOR UPDATE':''));
        $q->execute([$intent['prepared_id']]);
        $prepared = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$prepared || (int)$prepared['actor_id'] !== $actor || $prepared['request_key'] !== $intent['request_key'] || !hash_equals($prepared['packet_sha256'],
            $intent['prepared_sha256']))throw new \RuntimeException('ONEC_PREPARED_SCOPE_CHANGED');
        $raw = $this->store->read($prepared['packet_path'],
            $prepared['packet_sha256']);
        $packet = Support::decode($raw);
        $context = Support::decode($this->store->read($prepared['context_path']));
        $source = $this->bindings->source();
        if ($source['source_instance'] !== $intent['source_instance'] || $packet['native_source_instance'] !== $source['source_instance'])throw new \RuntimeException('ONEC_SOURCE_SCOPE_CHANGED');
        $r = ['source' => $source,
            'prepared' => $prepared,
            'packet' => $packet,
            'packet_raw' => $raw,
            'prepared_sha256' => $prepared['packet_sha256'],
            'business_identity' => $context['business_identity'],
            'confirmation' => $context['confirmation']];
        if (!in_array($action,
            ['read_existing',
                'reconcile'],
            true)){
            $fresh = $this->resolve($actor,
                $intent['kind'],
                $context['business_identity'],
                $action);
            if (!hash_equals($prepared['packet_sha256'],
                $fresh['prepared_sha256']) || $fresh['normalized']['confirmation']['audit_ref'] !== $prepared['confirmation_ref'])throw new \RuntimeException('ONEC_CURRENT_B_CONFIRMATION_CHANGED');
            $r['normalized'] = $fresh['normalized'];
        }
        return $r;
    }
    public function confirmation(array $intent):array {
        $r = $this->current($intent,
            'confirm');
        $expected = OnecMapping::json(OnecMapping::build($intent['kind'],
            $r['packet']['payload'],
            $intent['native_key'],
            $r['source']['profile']));
        if (!hash_equals($intent['body_sha256'],
            hash('sha256',
            $expected)) || $expected !== $this->store->read($intent['body_path'],
            $intent['body_sha256']))throw new \RuntimeException('ONEC_CONFIRMATION_BODY_CHANGED');
        $p = $r['packet'];
        return ['schema' => 'ipya.rental.onec.confirmation.v1',
            'project_id' => CaptureReader::PROJECT,
            'source_namespace' => $p['source_namespace'],
            'native_source_instance' => $p['native_source_instance'],
            'logical_document_id' => $p['logical_document_id'],
            'step_id' => $p['step_id'],
            'request_key' => $intent['request_key'],
            'actor_native_id' => (string)$intent['actor_id'],
            'native_key' => $intent['native_key'],
            'body_sha256' => $intent['body_sha256'],
            'prepared_packet_sha256' => $intent['prepared_sha256'],
            'attempt_fence' => $r['prepared']['attempt_fence'],
            'b_packet_binding_id' => $p['b_packet']['binding_id'],
            'b_packet_version' => $p['b_packet']['version'],
            'b_packet_sha256' => $p['b_packet']['sha256'],
            'audit_ref' => $r['prepared']['confirmation_ref']];
    }
}
