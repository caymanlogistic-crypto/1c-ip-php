<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;
use Throwable;

/** CREATE-only journal. An uncertain dispatch permanently switches to GET reconciliation. */
final class OnecNativeService
{
    public function __construct(
        private PDO $db,
        private PrivateStore $store,
        private OnecPreparedOwner $owner,
        private mixed $transport = null,
    ) {}

    public static function project(PDO $db, PrivateStore $store): self
    {
        return new self($db, $store, new OnecOwnerFacade($db, $store));
    }

    private function row(string $sql, array $args): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('ONEC_JOURNAL_ROW_MISSING');
    }

    private function transaction(callable $call): mixed
    {
        if ($this->db->inTransaction()) throw new RuntimeException('ONEC_JOURNAL_OWNS_TRANSACTION');
        $this->db->beginTransaction();
        try {
            // Same lock order as normal B commands, before reading or inserting
            // packet children. It also avoids shared-FK lock upgrade races.
            $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $result = $call();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private static function bindingHash(array $source): string
    {
        unset($source['profile']);
        return hash('sha256', OnecMapping::json($source));
    }

    private function exchange(array $source, string $method, string $collection, ?string $key, string $body = '', array $query = []): array
    {
        $response = is_callable($this->transport)
            ? ($this->transport)($source, $method, $collection, $key, $body, $query)
            : (new OnecNativeTransport($source, $source['profile_ref']))->exchange($method, $collection, $key, $body, $query);
        if (!is_array($response) || !is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
            || !is_string($response['raw'] ?? null) || strlen($response['raw']) > 2000000
            || ($response['source_instance'] ?? null) !== $source['source_instance'] || ($response['provenance'] ?? null) !== 'native') {
            throw new RuntimeException('ONEC_NATIVE_RESPONSE_UNVERIFIED');
        }
        return $response;
    }

    private function immutable(array $intent, array $current): array
    {
        if (!hash_equals($intent['profile_sha256'], OnecMapping::profileHash($current['source']['profile']))
            || !hash_equals($intent['binding_sha256'], self::bindingHash($current['source']))
            || !hash_equals($intent['prepared_sha256'], $current['prepared_sha256'])) throw new RuntimeException('ONEC_JOURNAL_SCOPE_CHANGED');
        $payload = Support::decode($this->store->read($intent['payload_path']));
        $body = OnecMapping::build($intent['kind'], $payload, $intent['native_key'], $current['source']['profile']);
        $raw = OnecMapping::json($body);
        if (!hash_equals($intent['body_sha256'], hash('sha256', $raw)) || $raw !== $this->store->read($intent['body_path'], $intent['body_sha256'])) {
            throw new RuntimeException('ONEC_JOURNAL_BODY_CHANGED');
        }
        return ['payload' => $payload, 'body' => $body, 'raw' => $raw];
    }

    public function prepare(int $actor, string $kind, array $identity): array
    {
        return $this->transaction(function () use ($actor, $kind, $identity): array {
            $context = $this->owner->prepare($actor, $kind, $identity);
            $source = $context['source'];
            $packet = $context['packet'];
            if (($packet['project_id'] ?? null) !== CaptureReader::PROJECT || ($packet['native_source_instance'] ?? null) !== $source['source_instance']
                || !isset(OnecMapping::COLLECTIONS[$kind])) throw new RuntimeException('ONEC_PREPARED_PROJECT_MISMATCH');
            $this->row('SELECT source_instance FROM integration_native_sources WHERE source_instance=? AND enabled=1 FOR UPDATE', [$source['source_instance']]);
            $subjectId = Support::uuid();
            $this->db->prepare('INSERT INTO integration_native_subjects(id,project_id,workflow_namespace,source_instance,entity,logical_subject) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')->execute([
                $subjectId, CaptureReader::PROJECT, $source['workflow_namespace'], $source['source_instance'], $kind, $packet['logical_document_id'],
            ]);
            $subject = $this->row('SELECT * FROM integration_native_subjects WHERE project_id=? AND workflow_namespace=? AND source_instance=? AND entity=? AND logical_subject=? FOR UPDATE', [
                CaptureReader::PROJECT, $source['workflow_namespace'], $source['source_instance'], $kind, $packet['logical_document_id'],
            ]);
            if ($subject['prepared_id']) {
                $existing = $this->row('SELECT * FROM integration_native_intents WHERE prepared_id=? FOR UPDATE', [$subject['prepared_id']]);
                $body = OnecMapping::json(OnecMapping::build($kind, $packet['payload'], $existing['native_key'], $source['profile']));
                if ((int)$existing['actor_id'] !== $actor || !hash_equals($existing['body_sha256'], hash('sha256', $body))) throw new RuntimeException('ONEC_GLOBAL_SUBJECT_CONFLICT');
                // A new B group or epoch cannot allocate another Ref_Key, including UNKNOWN.
                return $existing;
            }
            $collection = $kind === 'counterparty' ? 'parties' : 'invoices';
            $logicalId = preg_replace('/^(?:party|invoice):/', '', $packet['logical_document_id']);
            $q = $this->db->prepare('SELECT * FROM integration_native_bindings WHERE source_instance=? AND collection=? AND logical_id=? AND purpose=\'\' FOR UPDATE');
            $q->execute([$source['source_instance'], $collection, $logicalId]);
            $mapping = $q->fetch(PDO::FETCH_ASSOC);
            if ($mapping && $mapping['native_type'] !== OnecMapping::COLLECTIONS[$kind]) throw new RuntimeException('ONEC_EXISTING_MAPPING_TYPE_CHANGED');
            $nativeKey = $mapping ? OnecMapping::guid($mapping['native_key']) : Support::uuid();
            $body = OnecMapping::json(OnecMapping::build($kind, $packet['payload'], $nativeKey, $source['profile']));
            $id = Support::uuid();
            $payloadPath = $this->store->put('onec-payloads', OnecMapping::json($packet['payload']));
            $bodyPath = $this->store->put('onec-bodies', $body);
            $profileSha = OnecMapping::profileHash($source['profile']);
            $bindingSha = self::bindingHash($source);
            $commandSha = hash('sha256', OnecMapping::json([$actor, $kind, $context['request_key'], $context['prepared_sha256'], $profileSha, $bindingSha]));
            $this->db->prepare('INSERT INTO integration_native_intents(id,project_id,source_instance,prepared_id,request_key,actor_id,kind,native_key,command_sha256,profile_sha256,binding_sha256,prepared_sha256,payload_path,body_path,body_sha256,prepared_path,state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $id, CaptureReader::PROJECT, $source['source_instance'], $context['prepared']['id'], $context['request_key'], $actor, $kind, $nativeKey,
                $commandSha, $profileSha, $bindingSha, $context['prepared_sha256'], $payloadPath, $bodyPath, hash('sha256', $body), $context['prepared']['packet_path'],
                $mapping ? 'existing_reference' : 'prepared',
            ]);
            $this->db->prepare('UPDATE integration_native_subjects SET prepared_id=?,mapping_id=?,claimed_confirmation_id=?,body_sha256=?,revision=revision+1 WHERE id=?')->execute([
                $context['prepared']['id'], $mapping ? $mapping['id'] : null, $packet['b_packet']['binding_id'], hash('sha256', $body), $subject['id'],
            ]);
            return $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$id]);
        });
    }

    private function receipt(array $intent, string $stage, array $response, bool $dispatch = false): array
    {
        $rawPath = $this->store->put('onec-responses', $response['raw']);
        $context = ['kind' => $dispatch ? 'transport_receipt' : 'native-read-receipt', 'attempt_id' => $intent['id'],
            'stage' => $stage, 'source_instance' => $intent['source_instance'], 'native_key' => $intent['native_key'],
            'request_key' => $intent['request_key'], 'body_sha256' => $intent['body_sha256'],
            'post_fence' => $intent['post_fence'], 'http_status' => $response['status']];
        $path = $this->store->spool($context, $response['raw']);
        if ($dispatch) $this->store->linkReceipt($intent['id'], $path);
        // Filesystem HMAC/index is durable before SQL can fail.
        $this->db->prepare('INSERT INTO integration_native_receipts(id,intent_id,stage,http_status,sha256,raw_path,hmac_path) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')->execute([
            Support::uuid(), $intent['id'], $stage, $response['status'], hash('sha256', $response['raw']), $rawPath, $path,
        ]);
        return ['raw_path' => $rawPath, 'hmac_path' => $path];
    }

    private function freshReferences(array $intent, array $source, array $payload): void
    {
        if ($intent['kind'] === 'counterparty') {
            $filter = "DeletionMark eq false and ИНН eq '".$payload['inn']."'";
            if ($payload['kpp'] !== '') $filter .= " and КПП eq '".$payload['kpp']."'";
            $r = $this->exchange($source, 'GET', OnecMapping::REFS['counterparty'], null, '', [
                '$filter' => $filter, '$format' => 'json', '$select' => 'Ref_Key,ИНН,КПП,DeletionMark', '$top' => '2',
            ]);
            $this->receipt($intent, 'duplicate-identity', $r);
            $data = Support::decode($r['raw']);
            $rows = $data['value'] ?? $data['d']['results'] ?? null;
            if ($r['status'] !== 200 || !is_array($rows) || !array_is_list($rows) || $rows) throw new RuntimeException('ONEC_COUNTERPARTY_DUPLICATE_OR_UNVERIFIED');
            return;
        }
        $refs = ['organization' => $payload['organization'], 'counterparty' => $payload['counterparty'], 'contract' => $payload['contract'], 'currency' => $payload['currency']];
        foreach ($payload['lines'] as $index => $line) $refs['item-'.$index] = $line['item'];
        foreach ($refs as $name => $ref) {
            $r = $this->exchange($source, 'GET', $ref['type'], $ref['key']);
            $this->receipt($intent, 'reference-'.$name, $r);
            $facts = Support::decode($r['raw']); $facts = $facts['d'] ?? $facts;
            if ($r['status'] !== 200 || ($facts['Ref_Key'] ?? null) !== $ref['key'] || ($facts['DataVersion'] ?? null) !== $ref['version']
                || !OnecMapping::referenceFlags($ref['type'],$facts)) throw new RuntimeException('ONEC_NATIVE_REFERENCE_CHANGED');
            if ($name === 'organization' && ($facts['ИНН'] ?? null) !== $source['organization_inn']) throw new RuntimeException('ONEC_NATIVE_ORGANIZATION_CHANGED');
            if ($name === 'currency' && (string)($facts['Code'] ?? '') !== '643') throw new RuntimeException('ONEC_NATIVE_CURRENCY_CHANGED');
            if ($name === 'contract' && !OnecMapping::customerContract($facts,$payload['organization']['key'],$payload['counterparty']['key'],$payload['currency']['key'])) throw new RuntimeException('ONEC_NATIVE_CONTRACT_CHANGED');
        }
    }

    public function dispatch(string $id, int $actor): array
    {
        $claim = $this->transaction(function () use ($id, $actor): array {
            $intent = $this->row('SELECT * FROM integration_native_intents WHERE id=? FOR UPDATE', [$id]);
            if ((int)$intent['actor_id'] !== $actor) throw new RuntimeException('ONEC_ACTOR_SCOPE_CHANGED');
            // Dispatch requires the current mutation grant even for a replay.
            // Explicit reconcile/read commands have their own narrower scope.
            $current = $this->owner->current($intent, 'create');
            $this->immutable($intent, $current);
            if ($intent['post_started'] || in_array($intent['state'], ['saved', 'existing_reference', 'existing_verified', 'unconfirmed', 'unknown', 'ack_received', 'posting'], true)) return ['intent' => $intent, 'reconcile' => true];
            if ($intent['lease_until'] !== null && strtotime($intent['lease_until']) > time()) throw new RuntimeException('ONEC_INTENT_BUSY');
            $fence = bin2hex(random_bytes(32));
            $this->db->prepare("UPDATE integration_native_intents SET state='preflight',fence=?,lease_until=DATE_ADD(NOW(6),INTERVAL 180 SECOND),revision=revision+1,updated_at=NOW(6) WHERE id=? AND post_started=0")->execute([$fence, $id]);
            $intent['fence'] = $fence;
            return ['intent' => $intent, 'current' => $current, 'reconcile' => false];
        });
        if ($claim['reconcile']) return $this->reconcile($id, $actor);
        $intent = $claim['intent'];
        $current = $claim['current'];
        try {
            $data = $this->immutable($intent, $current);
            $preflight = $this->exchange($current['source'], 'GET', OnecMapping::COLLECTIONS[$intent['kind']], $intent['native_key']);
            $this->receipt($intent, 'preflight-'.$intent['fence'], $preflight);
            if ($preflight['status'] !== 404) {
                $this->db->prepare("UPDATE integration_native_intents SET state='unconfirmed',reason_code='NATIVE_REF_NOT_PROVEN_ABSENT',lease_until=NULL WHERE id=? AND fence=? AND post_started=0")->execute([$id, $intent['fence']]);
                return $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$id]);
            }
            $this->freshReferences($intent, $current['source'], $data['payload']);
            $intent = $this->transaction(function () use ($intent): array {
                $locked = $this->row('SELECT * FROM integration_native_intents WHERE id=? FOR UPDATE', [$intent['id']]);
                if ($locked['fence'] !== $intent['fence'] || $locked['post_started']) throw new RuntimeException('ONEC_DISPATCH_FENCE_CHANGED');
                $fresh = $this->owner->current($locked, 'create');
                $this->immutable($locked, $fresh);
                $confirmation = $this->owner->confirmation($locked);
                if (($confirmation['native_key'] ?? null) !== $locked['native_key'] || ($confirmation['body_sha256'] ?? null) !== $locked['body_sha256']
                    || ($confirmation['request_key'] ?? null) !== $locked['request_key'] || (string)($confirmation['actor_native_id'] ?? '') !== (string)$locked['actor_id']) throw new RuntimeException('ONEC_NORMAL_CONFIRMATION_CHANGED');
                $path = $this->store->put('onec-confirmations', OnecMapping::json($confirmation));
                $this->db->prepare("UPDATE integration_native_intents SET post_started=1,preflight_absent=1,post_fence=fence,state='posting',confirmation_path=?,confirmation_ref=?,revision=revision+1,updated_at=NOW(6) WHERE id=? AND post_started=0 AND fence=?")->execute([$path, $confirmation['audit_ref'], $locked['id'], $locked['fence']]);
                return $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$locked['id']]);
            });
            $response = $this->exchange($current['source'], 'POST', OnecMapping::COLLECTIONS[$intent['kind']], null, $data['raw']);
            $receipt = $this->receipt($intent, 'create-response', $response, true);
            $this->db->prepare("UPDATE integration_native_intents SET ack_path=?,state='ack_received',lease_until=NULL,revision=revision+1,updated_at=NOW(6) WHERE id=? AND post_fence=? AND state<>'saved'")->execute([$receipt['hmac_path'], $id, $intent['post_fence']]);
            return $this->reconcileIntent($id, $actor, true);
        } catch (Throwable $error) {
            // Even an ACK SQL insert failure cannot erase the durable dispatch boundary.
            $this->db->prepare("UPDATE integration_native_intents SET state=IF(post_started=1,'unknown','failed_preflight'),reason_code=IF(post_started=1,'NATIVE_OUTCOME_UNKNOWN','PREFLIGHT_NOT_COMPLETED'),lease_until=NULL,updated_at=NOW(6) WHERE id=? AND (fence=? OR (post_started=1 AND post_fence=?)) AND state NOT IN ('saved','existing_verified')")->execute([$id, $intent['fence'], $intent['fence']]);
            return $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$id]);
        }
    }

    public function reconcile(string $id, int $actor): array
    {
        return $this->reconcileIntent($id, $actor, false);
    }

    public function readExisting(string $id, int $actor): array
    {
        $intent = $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$id]);
        if ((int)$intent['actor_id'] !== $actor) throw new RuntimeException('ONEC_ACTOR_SCOPE_CHANGED');
        $current = $this->owner->current($intent, 'read_existing');
        $this->immutable($intent, $current);
        return $intent;
    }

    /** Historical evidence survives business-data resets; it never authorizes another write. */
    public function readHistory(string $id,int $actor): array
    {
        (new OnecOwnerFacade($this->db,$this->store))->actor($actor,'read_existing');
        $intent=$this->row('SELECT * FROM integration_native_intents WHERE id=?',[$id]);
        if((int)$intent['actor_id']!==$actor)throw new RuntimeException('ONEC_ACTOR_SCOPE_CHANGED');
        $intent['_proof_verified']=false;
        if(in_array($intent['state'],['saved','existing_verified'],true)){
            OnecNativeEvidence::readSaved($this->store,$intent);
            $intent['_proof_verified']=true;
        }
        try{$current=$this->owner->current($intent,'read_existing');$this->immutable($intent,$current);$intent['_source_current']=true;}
        catch(\RuntimeException){$intent['_source_current']=false;}
        return $intent;
    }

    private function reconcileIntent(string $id, int $actor, bool $recordedDispatch): array
    {
        $intent = $this->row('SELECT * FROM integration_native_intents WHERE id=?', [$id]);
        if ((int)$intent['actor_id'] !== $actor) throw new RuntimeException('ONEC_ACTOR_SCOPE_CHANGED');
        $action = $recordedDispatch ? 'read_existing' : 'reconcile';
        $current = $this->owner->current($intent, $action);
        $data = $this->immutable($intent, $current);
        if (in_array($intent['state'], ['saved', 'existing_verified'], true)) return $this->transaction(function()use($intent,$action):array{
            $row=$this->row('SELECT * FROM integration_native_intents WHERE id=? FOR UPDATE',[$intent['id']]);
            $this->owner->current($row,$action);
            (new OnecBindings($this->db,$this->store))->recordSavedReference($row['id']);
            return $row;
        });
        $ack = $this->store->receiptFor($id);
        $ackValid = false;
        if ($ack !== null) {
            $proof = $ack['receipt']; $c = $proof['context'];
            if (($c['post_fence'] ?? null) !== $intent['post_fence'] || ($c['source_instance'] ?? null) !== $intent['source_instance']
                || ($c['body_sha256'] ?? null) !== $intent['body_sha256'] || ($c['native_key'] ?? null) !== $intent['native_key']) throw new RuntimeException('ONEC_ACK_SCOPE_CHANGED');
            if (($c['http_status'] ?? null) === 201) {
                $created = Support::decode(base64_decode($proof['raw_base64'], true)); $created = $created['d'] ?? $created;
                $ackValid = ($created['Ref_Key'] ?? null) === $intent['native_key'];
            }
        }
        $response = $this->exchange($current['source'], 'GET', OnecMapping::COLLECTIONS[$intent['kind']], $intent['native_key']);
        $receipt = $this->receipt($intent, 'readback-'.Support::uuid(), $response);
        $facts = $response['status'] === 200 ? Support::decode($response['raw']) : [];
        $facts = $facts['d'] ?? $facts;
        $matches = $response['status'] === 200 && !OnecMapping::compare($intent['kind'], $data['body'], $facts);
        $state = $matches && $ackValid ? 'saved' : ($matches && $intent['state'] === 'existing_reference' ? 'existing_verified' : 'unconfirmed');
        return $this->transaction(function () use ($intent, $state, $facts, $receipt, $ack, $action): array {
            $row = $this->row('SELECT * FROM integration_native_intents WHERE id=? FOR UPDATE', [$intent['id']]);
            $this->owner->current($row, $action);
            if (in_array($row['state'], ['saved', 'existing_verified'], true)) return $row;
            $number = $state === 'saved' || $state === 'existing_verified' ? $facts[$intent['kind'] === 'customer_invoice' ? 'Number' : 'Code'] : null;
            $version = $number !== null ? $facts['DataVersion'] : null;
            $this->db->prepare('UPDATE integration_native_intents SET state=?,ack_path=?,readback_path=?,native_number=?,native_version=?,reason_code=?,lease_until=NULL,revision=revision+1,updated_at=NOW(6) WHERE id=?')->execute([
                $state, $ack['receipt_path'] ?? null, $receipt['hmac_path'], $number, $version,
                $state === 'saved' ? 'NATIVE_201_AND_READBACK_VERIFIED' : ($state === 'existing_verified' ? 'EXISTING_NATIVE_REFERENCE_VERIFIED' : 'GET_WITHOUT_VERIFIED_CREATE_ACK'), $intent['id'],
            ]);
            $saved=$this->row('SELECT * FROM integration_native_intents WHERE id=?', [$intent['id']]);
            if(in_array($saved['state'],['saved','existing_verified'],true))(new OnecBindings($this->db,$this->store))->recordSavedReference($saved['id']);
            return $saved;
        });
    }
}
