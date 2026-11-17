<?php
declare(strict_types=1);

namespace Modules\Rental;

use Modules\Integrations\CaptureReader;
use PDO;
use RuntimeException;

/** Reads original approval evidence. It grants no sender access or permission to dispatch. */
final class PacketApprovalRecord
{
    public static function read(PDO $db, string $kind, int $id, array $binding): array
    {
        if (!in_array($kind, ['contract', 'document'], true)
            || !preg_match('/^[a-f0-9]{32}$/D', $binding['binding_id'] ?? '')) {
            throw new RuntimeException('PACKET_APPROVAL_BINDING_INVALID');
        }
        $query = $db->prepare('SELECT * FROM rental_packet_confirmations WHERE id=? AND record_kind=? AND record_id=?');
        $query->execute([$binding['binding_id'], $kind, $id]);
        $saved = $query->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('PACKET_APPROVAL_NOT_FOUND');
        $query = $db->prepare('SELECT * FROM rental_commands WHERE command_key=?');
        $query->execute([$saved['command_key']]);
        $command = $query->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('PACKET_APPROVAL_RECEIPT_MISSING');
        $runtime = $db->query('SELECT * FROM rental_runtime_context WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return self::decode($saved, $command, $runtime ?: [], $binding);
    }

    /** Validates immutable original bytes and their receipt, not current business rows. */
    public static function decode(array $saved, array $command, array $runtime, array $binding): array
    {
        $raw = $saved['snapshot_text'] ?? null;
        $sha = $saved['raw_sha256'] ?? null;
        if (!is_string($raw) || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)
            || !hash_equals($sha, hash('sha256', $raw)) || ($saved['snapshot_sha256'] ?? null) !== $sha
            || ($binding['binding_id'] ?? null) !== ($saved['id'] ?? null)
            || ($binding['sha256'] ?? null) !== $sha
            || (string)($binding['version'] ?? '') !== (string)($saved['record_version'] ?? '')) {
            throw new RuntimeException('PACKET_APPROVAL_RAW_INVALID');
        }
        $snapshot = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        $checks = ['counterparty' => true, 'financial' => true, 'document' => true];
        $pins = json_decode($saved['source_pins_json'] ?? 'null', true, 32, JSON_THROW_ON_ERROR);
        $storedChecks = json_decode($saved['checks_json'] ?? 'null', true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !in_array($saved['record_kind'] ?? null, ['contract', 'document'], true)
            || ($snapshot['record']['kind'] ?? null) !== $saved['record_kind']
            || (string)($snapshot['record']['id'] ?? '') !== (string)($saved['record_id'] ?? '')
            || (string)($snapshot['record']['version'] ?? '') !== (string)$saved['record_version']
            || (string)($snapshot['confirmation']['actor_id'] ?? '') !== (string)($saved['actor_id'] ?? '')
            || !is_string($saved['role_id'] ?? null) || $saved['role_id'] === ''
            || !isset($saved['auth_version']) || (int)$saved['auth_version'] < 1
            || Importer::canonicalJson($storedChecks) !== Importer::canonicalJson($checks)
            || Importer::canonicalJson($snapshot['confirmation']['checks'] ?? null) !== Importer::canonicalJson($checks)
            || ($pins['project_id'] ?? null) !== CaptureReader::PROJECT
            || ($pins['format'] ?? null) !== 'ipya.php.normal-b.v1') {
            throw new RuntimeException('PACKET_APPROVAL_CONTEXT_INVALID');
        }
        if (($runtime['epoch'] ?? null) !== ($saved['workflow_epoch'] ?? null)
            || ($snapshot['workflow']['epoch'] ?? null) !== $saved['workflow_epoch']
            || (string)($snapshot['workflow']['revision'] ?? '') !== (string)($saved['workflow_revision'] ?? '')
            || !isset($runtime['revision']) || bccomp((string)$runtime['revision'], (string)$saved['workflow_revision'], 0) < 0
            || ($runtime['source_pair_sha256'] ?? null) !== ($pins['source_pair_sha256'] ?? null)) {
            throw new RuntimeException('PACKET_APPROVAL_WORKFLOW_CHANGED');
        }
        $confirmation = $snapshot['confirmation'];
        if (!is_string($confirmation['command_fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $confirmation['command_fingerprint'])
            || !is_string($confirmation['preview_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $confirmation['preview_sha256'])
            || ($command['action'] ?? null) !== ($saved['record_kind'] === 'contract' ? 'contract.packet' : 'document.packet')
            || ($command['command_key'] ?? null) !== ($saved['command_key'] ?? null)
            || ($confirmation['command_key'] ?? null) !== $saved['command_key']
            || ($confirmation['command_fingerprint'] ?? null) !== ($command['fingerprint'] ?? null)
            || !is_string($command['result_json'] ?? null)
            || ($confirmation['result_sha256'] ?? null) !== hash('sha256', $command['result_json'])) {
            throw new RuntimeException('PACKET_APPROVAL_RECEIPT_INVALID');
        }
        if (!is_string($saved['originalapproval_signature_text'] ?? null) || $saved['originalapproval_signature_text'] === ''
            || $saved['originalapproval_signature_text'] !== ($snapshot['legal']['baseline_hash'] ?? null)
            || $saved['legal_baseline'] !== $saved['originalapproval_signature_text']
            || $saved['legal_file_id'] !== ($snapshot['file']['id'] ?? null)
            || $saved['legal_file_sha256'] !== ($snapshot['file']['sha256'] ?? null)
            || (string)$saved['file_revision'] !== (string)($snapshot['file']['revision'] ?? '')
            || !is_string($saved['packet_signature_text'] ?? null) || $saved['packet_signature_text'] === ''
            || $saved['packet_signature_text'] !== ($confirmation['preview_sha256'] ?? null)) {
            throw new RuntimeException('PACKET_APPROVAL_SIGNATURE_INVALID');
        }
        return ['snapshot' => $snapshot, 'binding' => $binding,
            'signer' => ['id' => (int)$saved['actor_id'], 'role_id' => $saved['role_id'], 'auth_version' => (int)$saved['auth_version']],
            'raw_sha256' => $sha, 'confirmed_at' => $saved['confirmed_at'] ?? null];
    }
}
