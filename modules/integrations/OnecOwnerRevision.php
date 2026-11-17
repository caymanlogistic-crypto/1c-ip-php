<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** A separate owner-provided Basic pair, retaining the fixed project endpoint and mode. */
final class OnecOwnerRevision
{
    private const PROJECT = 'f6e21e3c-d974-4604-82fb-333da66559d2';
    private const REFERENCE = 'owner-20261116-v1';
    private const MOUNTED_REFERENCE = 'working-mounted-main-20261116-v1';
    private const MOUNTED_ADMISSION = 'working-mounted-onec-20261116-v1';
    private const NATIVE_PROBE_SHA = 'b5ed012d75f5f0b402a2e6772fbdbfdff7e5779497a58e1cc671ae66af1611ba';

    private static function authorized(bool $mounted = false): void
    {
        $scope = $mounted ? self::MOUNTED_ADMISSION : 'owner-current-pair-20261116-v1';
        if (PHP_SAPI !== 'cli' || getenv('IPPHP_ONEC_OWNER_ADMISSION') !== $scope) {
            throw new RuntimeException('OWNER_ONEC_REVISION_NOT_AUTHORIZED');
        }
    }

    public static function mountedCandidate(array $base, array $entry): array
    {
        if (count($entry)!==3 || array_diff(array_keys($entry),['provider','reference','credentials'])
            || ($entry['provider']??null)!=='onec' || ($entry['reference']??null)!=='main' || !is_array($entry['credentials']??null)) {
            throw new RuntimeException('MOUNTED_ONEC_PROFILE_INVALID');
        }
        $candidate = self::candidate($base,$entry['credentials']);
        $candidate['_source_mounted_profile'] = $entry;
        return $candidate;
    }

    public static function candidate(array $base, array $pair): array
    {
        if (array_diff(array_keys($base), ['username','password','_source_receipt_sha256','_reference'])
            || count($pair) !== 2 || array_diff(array_keys($pair), ['username','password'])) throw new RuntimeException('OWNER_PAIR_SHAPE_REJECTED');
        foreach (['username'=>320,'password'=>1024] as $key=>$limit) {
            if (!is_string($pair[$key] ?? null) || $pair[$key] === '' || strlen($pair[$key]) > $limit
                || preg_match('/[\x00-\x1f\x7f]/', $pair[$key])) throw new RuntimeException('OWNER_PAIR_SHAPE_REJECTED');
        }
        if (str_contains($pair['username'], ':')) throw new RuntimeException('OWNER_PAIR_SHAPE_REJECTED');
        if (hash_equals((string)($base['username'] ?? ''), $pair['username'])
            && hash_equals((string)($base['password'] ?? ''), $pair['password'])) throw new RuntimeException('OWNER_PAIR_NOT_CHANGED');
        // Fixed endpoint/root/mode live in the approved native reader, not another account.
        // No auth session, cookie, token or field from a different source profile is carried.
        return ['username'=>$pair['username'], 'password'=>$pair['password']];
    }

    public static function publish(PDO $db, int $expected, string $oldReference, string $newReference, string $receiptSha, array $metadata, bool $mounted = false): int
    {
        self::authorized($mounted);
        Config::reference($newReference);
        if (!$db->inTransaction() || !preg_match('/^[a-f0-9]{64}$/D', $receiptSha)) throw new RuntimeException('OWNER_PUBLICATION_TRANSACTION_REQUIRED');
        $query = $db->query("SELECT * FROM integration_connections WHERE provider='onec' FOR UPDATE");
        $connection = $query->fetch(PDO::FETCH_ASSOC);
        $query = $db->query("SELECT * FROM integration_configuration_sources WHERE provider='onec' FOR UPDATE");
        $source = $query->fetch(PDO::FETCH_ASSOC);
        $public = $connection ? Support::decode($connection['config_json']) : [];
        if (!$connection || !$source || !$connection['enabled'] || (int)$connection['revision'] !== $expected
            || ($public['access_reference'] ?? null) !== $oldReference || $source['selected_profile'] !== $oldReference) throw new RuntimeException('OWNER_CONFIG_CAS_CONFLICT');
        $metadata['predecessor'] = $source;
        $metadata['source_revision_kind'] = $mounted ? 'new-PHP-current-mounted-profile' : 'new-PHP-owner-configuration';
        $metadata['upstream_revision'] = null;
        $public['access_reference'] = $newReference;
        Config::save($db, 'onec', $public, $expected, true);
        $revision = $expected+1;
        $db->prepare("UPDATE integration_configuration_sources SET variant=?,source_receipt_sha256=?,source_revision=?,selected_profile=?,enabled_in_source=1,metadata_json=?,activated_at=NOW(6) WHERE provider='onec'")
            ->execute([$mounted?'mounted-current-onec-v1':'owner-current-onec-v1',$receiptSha, $revision, $newReference, Support::json($metadata)]);
        return $revision;
    }

    public function activateMounted(): array
    {
        self::authorized(true);
        if ($this->db->inTransaction()) throw new RuntimeException('OWNER_CONFIG_REQUIRES_NO_OPEN_TRANSACTION');
        if (!hash_equals(self::NATIVE_PROBE_SHA,hash_file('sha256',__DIR__.'/native/php/onec/OnecProbe.php'))) throw new RuntimeException('MOUNTED_ONEC_ENDPOINT_PIN_CHANGED');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-owner-onec-revision',0)")->fetchColumn()!==1) throw new RuntimeException('OWNER_CONFIG_BUSY');
        try {
            $connection = Config::get($this->db,'onec');
            $public = Support::decode($connection['config_json']);
            if ((int)$connection['revision']!==3 || !$connection['enabled']) throw new RuntimeException('OWNER_CONFIG_CAS_CONFLICT');
            $oldReference = Config::reference($public['access_reference']??'');
            $base = Config::profile('onec',$oldReference);
            $file = Config::root().'/.local/integration-source/working-onec-current-server-20261116.profile.json';
            if (!is_file($file)||is_link($file)||filesize($file)>65536) throw new RuntimeException('OWNER_HANDOFF_REQUIRED');
            $entry = Support::decode(file_get_contents($file));
            $candidate = self::mountedCandidate($base,$entry);
            unset($base,$entry);
            $current = Config::secrets('onec');
            if (isset($current['profiles'][self::MOUNTED_REFERENCE])) throw new RuntimeException('OWNER_CANDIDATE_ALREADY_EXISTS');
            unset($current);
            $proof = ['project_id'=>self::PROJECT,'source_kind'=>'current-runtime-LoadCredential-mounted-onec-main',
                'handoff_basename'=>basename($file),'source_reference'=>'main','base_operative_revision'=>3,'new_operative_revision'=>4,
                'endpoint_mode'=>'source-pinned-fixed-native-basic','native_probe_sha256'=>self::NATIVE_PROBE_SHA,
                'source_authority_report_sha256'=>'097ca31dae538c89cf8edbc8f21ec8d70af265c713434807e4fad2bf20fa25b7',
                'credentials_hashed'=>false,'sessions_copied'=>false,'other_profiles_merged'=>false,'upstream_revision'=>null];
            $path = $this->store->put('owner-config-provenance',Support::json($proof));
            $candidate['_source_receipt_sha256']=basename($path);
            Config::saveProfile('onec',self::MOUNTED_REFERENCE,$candidate);
            unset($candidate);
            $this->db->beginTransaction();
            try {
                $revision = self::publish($this->db,3,$oldReference,self::MOUNTED_REFERENCE,basename($path),$proof+['proof_path'=>$path,'candidate_encrypted'=>true],true);
                $this->db->commit();
            } catch (\Throwable $error) { if($this->db->inTransaction())$this->db->rollBack(); throw $error; }
            return ['candidate_present'=>true,'revision'=>$revision,'revision_status'=>'mounted-profile-selected-unverified','proof_path'=>$path];
        } finally { $this->db->query("SELECT RELEASE_LOCK('ipphp-owner-onec-revision')"); }
    }

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public function activate(): array
    {
        self::authorized();
        if ($this->db->inTransaction()) throw new RuntimeException('OWNER_CONFIG_REQUIRES_NO_OPEN_TRANSACTION');
        $query = $this->db->query("SELECT GET_LOCK('ipphp-owner-onec-revision',0)");
        if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('OWNER_CONFIG_BUSY');
        try {
            $connection = Config::get($this->db, 'onec');
            $public = Support::decode($connection['config_json']);
            if ((int)$connection['revision'] !== 2 || !$connection['enabled']) throw new RuntimeException('OWNER_CONFIG_CAS_CONFLICT');
            $oldReference = Config::reference((string)($public['access_reference'] ?? ''));
            $base = Config::profile('onec', $oldReference);
            $file = Config::root().'/.local/integration-source/owner-onec-20261116.credentials.json';
            if (!is_file($file) || is_link($file)) throw new RuntimeException('OWNER_HANDOFF_REQUIRED');
            $pair = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($pair)) throw new RuntimeException('OWNER_PAIR_SHAPE_REJECTED');
            $candidate = self::candidate($base, $pair);
            unset($base, $pair);
            $existing = Config::secrets('onec');
            if (isset($existing['profiles'][self::REFERENCE])) throw new RuntimeException('OWNER_CANDIDATE_ALREADY_EXISTS');
            unset($existing);
            $proof = ['project_id'=>self::PROJECT, 'source_kind'=>'current-explicit-owner-message',
                'handoff_basename'=>basename($file), 'base_operative_revision'=>2, 'new_operative_revision'=>3,
                'endpoint_mode'=>'unchanged-fixed-native-basic', 'credentials_hashed'=>false,
                'sessions_copied'=>false, 'other_profiles_merged'=>false, 'upstream_revision'=>null];
            $proofPath = $this->store->put('owner-config-provenance', Support::json($proof));
            $proofSha = basename($proofPath);
            $candidate['_source_receipt_sha256'] = $proofSha;
            Config::saveProfile('onec', self::REFERENCE, $candidate);
            unset($candidate);
            $this->db->beginTransaction();
            try {
                $revision = self::publish($this->db, 2, $oldReference, self::REFERENCE, $proofSha,
                    $proof+['proof_path'=>$proofPath, 'candidate_encrypted'=>true]);
                $this->db->commit();
            } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
            return ['candidate_present'=>true, 'revision'=>$revision, 'revision_status'=>'owner-profile-selected-unverified', 'proof_path'=>$proofPath];
        } finally { $this->db->query("SELECT RELEASE_LOCK('ipphp-owner-onec-revision')"); }
    }

    public function probeOnce(bool $mounted = false): array
    {
        self::authorized($mounted);
        if ($mounted) return OnecCapabilityDiagnostic::run($this->db,$this->store,4,true);
        $query = $this->db->query("SELECT GET_LOCK('ipphp-owner-onec-revision',0)");
        if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('OWNER_CONFIG_BUSY');
        try {
            $connection = Config::get($this->db, 'onec');
            $public = Support::decode($connection['config_json']);
            $revision = 3;
            $reference = self::REFERENCE;
            if ((int)$connection['revision'] !== $revision || !$connection['enabled'] || ($public['access_reference'] ?? null) !== $reference) throw new RuntimeException('OWNER_CONFIG_CAS_CONFLICT');
            $query = $this->db->prepare("SELECT id FROM integration_runs WHERE provider='onec' AND operation='auth-metadata' AND revision=? LIMIT 1");
            $query->execute([$revision]);
            if ($query->fetchColumn()) throw new RuntimeException('OWNER_ONEC_PROBE_ALREADY_CONSUMED');
            // ReadOnlyCheck records pending before its one ROOT GET. Only a valid ROOT
            // response permits the same source reader's bounded metadata GET.
            return (new ReadOnlyCheck($this->db, $this->store))->run('onec', Support::uuid(),false,$revision);
        } finally { $this->db->query("SELECT RELEASE_LOCK('ipphp-owner-onec-revision')"); }
    }
}
