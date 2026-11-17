<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Typed project-local observations. Logical bindings never derive from INN, names or file hashes. */
final class OnecBindings implements OnecReferenceSource {
    public function __construct(private \PDO $db,
        private PrivateStore $store) {
    }
    private static function gap(string ...$fields): never {
        throw new OnecSetupGap($fields);
    }
    public function source(): array {
        $locking = $this->db->inTransaction() ? ' FOR UPDATE' : '';
        $rows = $this->db->query("SELECT * FROM integration_native_sources WHERE enabled=1 ORDER BY source_instance LIMIT 2".$locking)->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== 1)self::gap('connection_binding.single_enabled_native_profile');
        $r = $rows[0];
        if ($r['project_id'] !== CaptureReader::PROJECT || $r['organization_key'] !== OnecMapping::ORGANIZATION || $r['organization_inn'] !== '390613939405' || !preg_match('/^[a-f0-9]{40}$/D',
            $r['source_version']) || !preg_match('/^[a-f0-9]{64}$/D',
            $r['freeze_sha256']) || trim($r['admission_ref']) === '' || trim($r['workflow_namespace']) === '')self::gap('connection_binding.approved_project_source_scope');
        Config::reference($r['profile_ref']);
        $q = $this->db->prepare("SELECT selected_profile,source_receipt_sha256 FROM integration_configuration_sources WHERE provider='onec'".$locking);
        $q->execute();
        $selected = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$selected || $selected['selected_profile'] !== $r['profile_ref'] || !hash_equals($selected['source_receipt_sha256'],
            $r['config_receipt_sha256']))self::gap('connection_binding.selected_whole_profile');
        $connection = $this->db->query("SELECT enabled,revision,config_json FROM integration_connections WHERE provider='onec'".$locking)->fetch(\PDO::FETCH_ASSOC);
        $public = $connection ? Support::decode($connection['config_json']) : [];
        if (!$connection || !$connection['enabled'] || ($public['access_reference'] ?? null) !== $r['profile_ref']) {
            self::gap('connection_binding.enabled_current_onec_profile');
        }
        $r['operative_connection_revision'] = (int)$connection['revision'];
        $raw = $this->store->read($r['profile_path'],
            $r['profile_file_sha256']);
        $profile = Support::decode($raw);
        if (!is_array($profile))self::gap('connection_binding.profile');
        OnecMapping::assertProfile($profile);
        if (!hash_equals($r['profile_sha256'],
            OnecMapping::profileHash($profile)))self::gap('connection_binding.profile_digest');
        $r['profile'] = $profile;
        return $r;
    }
    public function reference(array $source,
        string $collection,
        string $logicalId,
        string $field,
        string $purpose = ''): array {
        return $this->observation($source,
            $collection,
            $logicalId,
            $field,
            $purpose)['reference'];
    }
    public function observation(array $source,
        string $collection,
        string $logicalId,
        string $field,
        string $purpose = ''): array {
        if (!isset(OnecMapping::REFS[$field]) || $logicalId === '' || strlen($logicalId) > 180 || strlen($purpose) > 100)self::gap('native_refs.typed_identity');
        $q = $this->db->prepare('SELECT * FROM integration_native_bindings WHERE source_instance=? AND collection=? AND logical_id=? AND purpose=?');
        $q->execute([$source['source_instance'],
                $collection,
                $logicalId,
                $purpose]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$r)self::gap('native_refs.' . $field . '.verified_binding');
        if ($r['native_type'] !== OnecMapping::REFS[$field] || !is_string($r['native_version']) || $r['native_version'] === '' || trim($r['evidence_ref']) === '')self::gap('native_refs.' . $field . '.type_version_evidence');
        $proof = $this->store->verifySpool($r['evidence_hmac_path']);
        $c = $proof['context']??[];
        if (($c['kind']??'') !== 'native-reference-get' || ($c['method']??'') !== 'GET' || ($c['http_status']??null) !== 200 || ($c['source_instance']??'') !== $source['source_instance'] || ($c['native_type']??'') !== $r['native_type'] || ($c['native_key']??'') !== $r['native_key'] || !hash_equals($r['evidence_sha256'],
            $proof['sha256']))self::gap('native_refs.' . $field . '.native_read_provenance');
        $raw = $this->store->read($r['evidence_path'],
            $r['evidence_sha256']);
        $facts = Support::decode($raw);
        $facts = $facts['d']??$facts;
        if (!is_array($facts) || ($facts['Ref_Key']??null) !== $r['native_key'] || ($facts['DataVersion']??null) !== $r['native_version'] || !OnecMapping::referenceFlags($r['native_type'],$facts))self::gap('native_refs.' . $field . '.observed_flags_version');
        OnecMapping::guid($r['native_key']);
        $result = ['type' => $r['native_type'],
            'key' => $r['native_key'],
            'version' => $r['native_version']];
        if ($field === 'contract'){
            $org = $facts['Организация_Key']??null;
            $buyer = $facts['Owner_Key']??null;
            if ($org !== $r['organization_key'] || $buyer !== $r['counterparty_key'])self::gap('native_refs.contract.owner_relationship');
            $result['organization_key'] = OnecMapping::guid($org);
            $result['counterparty_key'] = OnecMapping::guid($buyer);
        }
        return ['reference' => $result,
            'facts' => $facts,
            'binding_id' => $r['id']];
    }
    public function partyIdentity(array $source,
        array $party): array {
        $q = $this->db->prepare('SELECT * FROM integration_native_party_identities WHERE source_instance=? AND logical_party_id=?');
        $q->execute([$source['source_instance'],
                (string)$party['id']]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$r)self::gap('native_party_identity.entity_type',
            'native_party_identity.full_name',
            'native_party_identity.kpp');
        if (!in_array($r['entity_type'],
            ['individual',
                'legal_entity',
                'entrepreneur'],
            true) || $r['source_name'] !== $party['name'] || $r['inn'] !== ($party['inn']??'') || trim($r['full_name']) === '' || trim($r['evidence_ref']) === '')self::gap('native_party_identity.current_source_provenance');
        $proof = $this->store->verifySpool($r['evidence_hmac_path']);
        $c = $proof['context']??[];
        if (($c['kind']??'') !== 'approved-party-identity' || ($c['source_instance']??'') !== $source['source_instance'] || ($c['logical_party_id']??'') !== (string)$party['id'] || !hash_equals($r['evidence_sha256'],
            $proof['sha256']))self::gap('native_party_identity.approved_source_evidence');
        $raw = $this->store->read($r['evidence_path'],
            $r['evidence_sha256']);
        $facts = Support::decode($raw);
        foreach (['entity_type',
                'source_name',
                'full_name',
                'inn',
                'kpp'] as $key)if (($facts[$key]??null) !== $r[$key])self::gap('native_party_identity.immutable_evidence');
        return $r;
    }
    public function invoicePolicy(array $source,
        string $kind): array {
        $q = $this->db->prepare('SELECT * FROM integration_native_invoice_policies WHERE source_instance=? AND invoice_kind=?');
        $q->execute([$source['source_instance'],
                $kind]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$r || $r['line_policy'] !== 'approved_aggregate' || $r['contract_purpose'] === '' || $r['invoice_service_logical_id'] === '' || $r['currency_logical_id'] === '' || $r['admission_ref'] === '')self::gap('native_policy.invoice_kind.purpose_and_approved_aggregate');
        return $r;
    }
}
