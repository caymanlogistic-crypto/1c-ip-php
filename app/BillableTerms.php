<?php
declare(strict_types=1);
namespace App;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Ordered billable read DTO. It is not an occupancy query or an authorization gate. */
final class BillableTerms
{
    private static function admission(): void
    {
        // OFF preserves the physical branch. ON still needs a recorded source receipt.
        BillingPartAdmission::selected();
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) return null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Billable term date is invalid.');
        return $value;
    }

    private static function amount(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || !preg_match('/^-?[0-9]{1,18}(?:\.[0-9]{1,12})?$/D', $value)) throw new RuntimeException('Billable term amount must preserve a decimal string or NULL.');
        return $value;
    }

    private static function identity(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value)) throw new RuntimeException('Billable source identity is invalid.');
        return (int)$value;
    }

    /** Existing physical terms remain ordered by their existing numeric term ID. */
    private static function physical(array $row, string $recordKind): array
    {
        $object = self::identity($row['object_id']);
        return [
            'target_kind' => 'physical-object', 'target_key' => 'object:'.$object,
            'source_term_key' => $recordKind.'-term:'.self::identity($row['id']),
            'object_id' => $object, 'part_id' => null, 'parent_object_id' => $object,
            'physical_occupancy' => $row['physical_occupancy'] === null ? null : (int)$row['physical_occupancy'],
            'name' => $row['target_name'], 'type' => $row['target_type'],
            'area' => $row['target_area'], 'floor' => $row['target_floor'], 'address' => null,
            'start_date' => self::date($row['start_date']), 'end_date' => self::date($row['end_date']),
            'amount' => self::amount($row['amount']),
            'effective_date' => self::date($row['effective_date'] ?? null),
            'superseded_on' => self::date($row['superseded_on'] ?? null),
            'record_version' => (int)$row['record_version'], 'object_version' => (int)$row['object_version'],
            'part_version' => null, 'evidence_sha256' => $row['occupancy_evidence_sha256'],
            'admission_sha256' => null,
        ];
    }

    public static function forContract(PDO $pdo, int $contractId): array
    {
        self::admission();
        $query = $pdo->prepare('SELECT t.*,o.name target_name,o.type target_type,o.area target_area,o.floor target_floor,o.version object_version,c.version record_version,'.ContractOccupancy::selectColumns('co').' FROM contract_terms t JOIN contracts c ON c.id=t.contract_id JOIN rental_objects o ON o.id=t.object_id LEFT JOIN contract_objects co ON co.contract_id=t.contract_id AND co.object_id=t.object_id WHERE t.contract_id=? ORDER BY t.id');
        $query->execute([$contractId]);
        return [...array_map(fn($row) => self::physical($row, 'contract'), $query->fetchAll(PDO::FETCH_ASSOC)),...BillingParts::terms($pdo,'contract',$contractId)];
    }

    public static function forDocument(PDO $pdo, int $documentId): array
    {
        self::admission();
        $query = $pdo->prepare('SELECT t.*,o.name target_name,o.type target_type,o.area target_area,o.floor target_floor,o.version object_version,d.version record_version,'.ContractOccupancy::selectColumns('t').' FROM document_terms t JOIN rental_documents d ON d.id=t.document_id JOIN rental_objects o ON o.id=t.object_id WHERE t.document_id=? ORDER BY t.id');
        $query->execute([$documentId]);
        return [...array_map(fn($row) => self::physical($row, 'document'), $query->fetchAll(PDO::FETCH_ASSOC)),...BillingParts::terms($pdo,'document',$documentId)];
    }

    /** Pure typed mapper for reviewed part rows; does not admit or store those rows. */
    public static function part(array $term, array $part, string $recordKind, int $recordVersion): array
    {
        if (!in_array($recordKind, ['contract', 'document'], true) || $recordVersion < 1
            || ($part['use_type'] ?? null) !== 'LEGAL_ADDRESS'
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $part['id'] ?? '')
            || !is_string($part['number'] ?? null) || trim($part['number']) === ''
            || !is_string($part['source_namespace'] ?? null) || !is_string($part['source_key'] ?? null)
            || (int)($part['version'] ?? 0) < 1) throw new RuntimeException('Billing part lacks explicit reviewed identity/type/version.');
        foreach (['source_namespace' => 100, 'source_key' => 180, 'number' => 100] as $field => $limit) {
            if (trim($part[$field]) === '' || mb_strlen($part[$field]) > $limit) throw new RuntimeException('Billing part source identity exceeds its explicit bound.');
        }
        $partVersion = self::identity($part['version']);
        foreach (['evidence_sha256', 'admission_sha256'] as $key) if (!preg_match('/^[a-f0-9]{64}$/D', $part[$key] ?? '')) throw new RuntimeException('Billing part lacks source/admission proof.');
        if (($term['part_id'] ?? null) !== $part['id']) throw new RuntimeException('Part term identity differs.');
        $parent = $part['parent_object_id'] ?? null;
        if ($parent !== null) $parent = self::identity($parent);
        foreach (['area', 'floor', 'address'] as $field) if (($part[$field] ?? null) !== null && !is_string($part[$field])) throw new RuntimeException('Billing part display fields must preserve source strings/NULL.');
        return [
            'target_kind' => 'billing-part', 'target_key' => 'part:'.$part['id'],
            'source_term_key' => $recordKind.'-part-term:'.self::identity($term['id']),
            'object_id' => null, 'part_id' => $part['id'], 'parent_object_id' => $parent,
            'physical_occupancy' => 0, 'name' => $part['number'], 'type' => $part['use_type'],
            'area' => $part['area'] ?? null, 'floor' => $part['floor'] ?? null, 'address' => $part['address'] ?? null,
            'start_date' => self::date($term['start_date'] ?? null), 'end_date' => self::date($term['end_date'] ?? null),
            'amount' => self::amount($term['amount'] ?? null),
            'effective_date' => self::date($term['effective_date'] ?? null),
            'superseded_on' => self::date($term['superseded_on'] ?? null),
            'record_version' => $recordVersion, 'object_version' => null, 'part_version' => $partVersion,
            'evidence_sha256' => $part['evidence_sha256'], 'admission_sha256' => $part['admission_sha256'],
            'source_namespace' => $part['source_namespace'], 'source_key' => $part['source_key'],
        ];
    }
}
