<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;
use Throwable;

/** One admitted source batch. It neither imports a state nor invokes business commands. */
final class InvoiceScopeCorrection
{
    private const MUTABLE_TABLES = [
        'invoice_objects', 'invoice_coverage', 'invoice_scope_availability',
        'rental_scope_corrections', 'rental_scope_correction_rows',
    ];

    private static function json(mixed $value): string
    {
        return Importer::canonicalJson($value);
    }

    private static function sha(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    private static function rows(PDO $pdo, string $sql, array $arguments = []): array
    {
        $query = $pdo->prepare($sql);
        $query->execute($arguments);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Every table is protected; only the documented technical columns are excluded. */
    public static function protectedRows(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);
        $result = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table identifier.');
            if (in_array($table, self::MUTABLE_TABLES, true)) continue;
            $query = $pdo->query('SELECT * FROM `'.$table.'`');
            $hashes = [];
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($table === 'invoices') unset($row['version']);
                if ($table === 'contract_objects') unset($row['physical_occupancy'], $row['occupancy_evidence_sha256']);
                $hashes[] = self::sha($row);
            }
            sort($hashes, SORT_STRING);
            $result[$table] = ['rows' => count($hashes), 'sha256' => self::sha($hashes)];
        }
        return $result;
    }

    private static function storedScopes(PDO $pdo): array
    {
        $objects = $periods = [];
        foreach (self::rows($pdo, 'SELECT invoice_id,object_id FROM invoice_objects ORDER BY invoice_id,object_id') as $row) {
            $objects[(int)$row['invoice_id']][] = (int)$row['object_id'];
        }
        foreach (self::rows($pdo, 'SELECT invoice_id,object_id,start_date,end_date FROM invoice_coverage ORDER BY invoice_id,object_id,start_date,end_date') as $row) {
            $periods[(int)$row['invoice_id']][] = [
                'object_id' => (int)$row['object_id'], 'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
            ];
        }
        return ['objects' => $objects, 'periods' => $periods];
    }

    private static function snapshot(PDO $pdo): array
    {
        return [
            'invoices' => self::rows($pdo, 'SELECT * FROM invoices ORDER BY id'),
            'scopes' => self::storedScopes($pdo),
            'metadata' => self::rows($pdo, 'SELECT * FROM invoice_scope_availability ORDER BY invoice_id'),
            'contract_objects' => self::rows($pdo, 'SELECT * FROM contract_objects ORDER BY contract_id,object_id'),
            'protected' => self::protectedRows($pdo),
        ];
    }

    private static function normalizedMetadata(array $row): array
    {
        unset($row['created_at'], $row['updated_at']);
        foreach (['invoice_id', 'invoice_version', 'revision'] as $field) $row[$field] = (int)$row[$field];
        return $row;
    }

    private static function projection(PDO $pdo, array $ids): array
    {
        $all = self::snapshot($pdo);
        $metadata = array_column($all['metadata'], null, 'invoice_id');
        $invoices = array_column($all['invoices'], null, 'id');
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = [
                'invoice' => $invoices[$id],
                'objects' => $all['scopes']['objects'][$id] ?? [],
                'periods' => $all['scopes']['periods'][$id] ?? [],
                'metadata' => isset($metadata[$id]) ? self::normalizedMetadata($metadata[$id]) : null,
            ];
        }
        return ['rows' => $rows, 'contract_objects' => $all['contract_objects'], 'protected' => $all['protected']];
    }

    private static function receipt(PDO $pdo): ?array
    {
        $row = self::rows($pdo, 'SELECT * FROM rental_scope_corrections WHERE manifest_sha256=?', [InvoiceScope::CANDIDATE_MANIFEST])[0] ?? null;
        if ($row === null) return null;
        if ($row['project_id'] !== InvoiceScopeArtifact::PROJECT
            || $row['artifact_sha256'] !== InvoiceScopeArtifact::PINS['coverage-delta.json']
            || $row['audit_sha256'] !== InvoiceScopeArtifact::PINS['invoice-coverage-audit.json']
            || $row['state_sha256'] !== InvoiceScopeArtifact::STATE
            || $row['mapping_sha256'] !== InvoiceScopeArtifact::MAPPING
            || (int)$row['invoice_count'] !== 133 || (int)$row['verified_count'] !== 109 || (int)$row['unknown_count'] !== 24) {
            throw new RuntimeException('Existing correction receipt differs from this source batch.');
        }
        foreach (['before_path' => 'preimage_sha256', 'after_path' => 'after_sha256', 'artifact_path' => 'artifact_sha256'] as $path => $hash) {
            if (!is_file($row[$path]) || is_link($row[$path]) || !hash_equals($row[$hash], (string)hash_file('sha256', $row[$path]))) throw new RuntimeException('Committed correction evidence is missing or altered.');
        }
        return $row;
    }

    /** Read-only, strict all-133 preimage. No source-value normalization or guessed scope. */
    public static function plan(PDO $pdo, array $artifact): array
    {
        if ($receipt = self::receipt($pdo)) return ['already_applied' => true, 'receipt' => $receipt];
        $before = self::snapshot($pdo);
        $imports = self::rows($pdo, 'SELECT state_hash,mapping_hash FROM rental_imports WHERE state_hash=?', [InvoiceScopeArtifact::STATE]);
        if (count($imports) !== 1 || $imports[0]['mapping_hash'] !== InvoiceScopeArtifact::MAPPING) throw new RuntimeException('Original source pair receipt differs.');
        if (count($before['invoices']) !== 133 || $before['metadata'] !== []) throw new RuntimeException('Expected untouched imported-133 scope baseline.');
        if ($before['scopes']['objects'] !== [] || $before['scopes']['periods'] !== []) throw new RuntimeException('Operational scope preimage is not empty.');
        foreach ($before['invoices'] as $row) {
            $id = (int)$row['id'];
            $source = $artifact['original'][$id] ?? null;
            if ($source === null || self::json(Importer::decodeExact($row['source_json'])) !== self::json($source)) throw new RuntimeException('All-133 source preimage CAS failed.');
            if ((int)$row['contract_id'] !== (int)$source['contract'] || (int)$row['version'] !== (int)($source['version'] ?? 1)) throw new RuntimeException('Invoice binding/version preimage differs.');
            foreach (['amount', 'paid'] as $money) {
                $original = $source[$money] ?? null;
                if (($row[$money] === null) !== ($original === null)
                    || ($original !== null && bccomp((string)$row[$money], (string)$original, 2) !== 0)) throw new RuntimeException('Imported financial amount/NULL preimage differs.');
            }
            foreach (['number', 'kind', 'period', 'status', 'issued', 'due'] as $field) {
                if ($row[$field] !== ($source[$field] ?? null)) throw new RuntimeException('Imported financial identity/status/date preimage differs.');
            }
            if ((bool)$row['sent'] !== (bool)($source['sent'] ?? false)
                || (bool)$row['initial_invoice'] !== (bool)($source['initial'] ?? false)) throw new RuntimeException('Imported financial flag preimage differs.');
        }
        $relations = array_values(array_filter($before['contract_objects'], fn($row) => (int)$row['contract_id'] === 970415 && (int)$row['object_id'] === 1105));
        if (count($relations) !== 1 || $relations[0]['physical_occupancy'] !== null || $relations[0]['occupancy_evidence_sha256'] !== null) throw new RuntimeException('Nonphysical relation preimage differs.');
        if (($artifact['audit'][971525]['contractId'] ?? null) !== 970415 || !in_array(1105, $artifact['changes'][971525]['replaceWith']['objectIds'] ?? [], true)) throw new RuntimeException('Legal-address billing evidence is not bound to the exact relation.');
        return ['already_applied' => false, 'before' => $before, 'preimage_sha256' => self::sha($before)];
    }

    private static function putImmutable(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Immutable correction evidence path already exists.');
        $file = fopen($path, 'xb');
        if ($file === false) throw new RuntimeException('Cannot create correction evidence.');
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($file, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Correction evidence write failed.');
                $offset += $written;
            }
            if (!fflush($file)) throw new RuntimeException('Correction evidence flush failed.');
        } finally {
            fclose($file);
        }
        if (!hash_equals(hash('sha256', $bytes), (string)hash_file('sha256', $path))) throw new RuntimeException('Correction evidence reread hash failed.');
    }

    private static function target(array $artifact, array $before, string $receiptId): array
    {
        $rows = [];
        foreach ($before['invoices'] as $invoice) {
            $id = (int)$invoice['id'];
            $verified = isset($artifact['changes'][$id]);
            if ($verified) $invoice['version'] = (int)$invoice['version'] + 1;
            $fields = $verified ? $artifact['changes'][$id]['replaceWith'] : ['objectIds' => [], 'coverage' => []];
            $objects = $fields['objectIds'];
            sort($objects, SORT_NUMERIC);
            $periods = array_map(fn($period) => [
                'object_id' => $period['object'], 'start_date' => $period['start'], 'end_date' => $period['end'],
            ], $fields['coverage']);
            usort($periods, fn($a, $b) => [$a['object_id'], $a['start_date'], $a['end_date']] <=> [$b['object_id'], $b['start_date'], $b['end_date']]);
            $evidence = self::sha([
                'audit' => $artifact['audit'][$id],
                'physical_line_sha256' => $artifact['raw_lines'][$id]['physical_sha256'],
                'producer_trimmed_sha256' => $artifact['raw_lines'][$id]['producer_trimmed_sha256'],
            ]);
            $rows[$id] = [
                'invoice' => $invoice, 'objects' => $objects, 'periods' => $periods,
                'metadata' => [
                    'invoice_id' => $id, 'origin' => 'source-correction-v3',
                    'objects_state' => $verified ? 'KNOWN' : 'UNKNOWN',
                    'periods_state' => $verified ? 'KNOWN' : 'UNKNOWN',
                    'availability' => $verified ? 'KNOWN' : 'UNKNOWN',
                    'classification' => $artifact['audit'][$id]['classification'],
                    'runtime_hold' => $verified ? null : 'SOURCE_AMBIGUOUS',
                    'evidence_sha256' => $evidence, 'admission_sha256' => InvoiceScope::CANDIDATE_MANIFEST,
                    'correction_id' => $receiptId, 'source_sha256' => $artifact['raw_lines'][$id]['file_sha256'],
                    'command_key' => null, 'invoice_version' => (int)$invoice['version'], 'revision' => 1,
                ],
            ];
        }
        $relations = $before['contract_objects'];
        $relationProof = self::sha([
            'project_id' => InvoiceScopeArtifact::PROJECT,
            'contract' => array_values(array_filter($artifact['state']['domain']['contracts'], fn($row) => (int)$row['id'] === 970415)),
            'audit' => $artifact['audit'][971525],
            'physical_line_sha256' => $artifact['raw_lines'][971525]['physical_sha256'],
            'meaning' => 'EXPLICIT_SOURCE_LEGAL_ADDRESS_BILLABLE_NONPHYSICAL_RELATION_970415_1105',
        ]);
        foreach ($relations as &$relation) {
            if ((int)$relation['contract_id'] === 970415 && (int)$relation['object_id'] === 1105) {
                $relation['physical_occupancy'] = 0;
                $relation['occupancy_evidence_sha256'] = $relationProof;
            }
        }
        unset($relation);
        return ['rows' => $rows, 'contract_objects' => $relations, 'protected' => $before['protected']];
    }

    /** Caller must prove a disposable copy or independently admitted Main execution. */
    public static function apply(PDO $pdo, array $artifact, string $directory, string $expectedPreimage, ?callable $fault = null): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Correction owns its complete transaction.');
        $plan = self::plan($pdo, $artifact);
        if ($plan['already_applied']) return $plan;
        if (!hash_equals($plan['preimage_sha256'], $expectedPreimage)) throw new RuntimeException('Approved database preimage differs.');
        $hex = bin2hex(random_bytes(16));
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
        $target = self::target($artifact, $plan['before'], $id);
        $beforeBytes = self::json($plan['before']);
        $afterBytes = self::json($target);
        $budget = strlen($beforeBytes) + strlen($afterBytes) + strlen($artifact['state_raw']) + strlen($artifact['mapping_raw']);
        foreach ($artifact['files'] as $bytes) $budget += strlen($bytes);
        foreach ($artifact['raw_lines'] as $line) $budget += strlen($line['physical']);
        StorageBudget::target($directory, $budget);
        if (file_exists($directory) || !mkdir($directory, 0700, true)) throw new RuntimeException('Unique correction evidence directory required.');
        foreach ($artifact['files'] as $name => $bytes) self::putImmutable($directory.'/'.$name, $bytes);
        self::putImmutable($directory.'/state.original.json', $artifact['state_raw']);
        self::putImmutable($directory.'/mapping.original.json', $artifact['mapping_raw']);
        mkdir($directory.'/raw-lines', 0700);
        foreach ($artifact['raw_lines'] as $invoiceId => $line) self::putImmutable($directory.'/raw-lines/'.$invoiceId.'.jsonl', $line['physical']);
        self::putImmutable($directory.'/before.json', $beforeBytes);
        self::putImmutable($directory.'/candidate-after.json', $afterBytes);
        self::putImmutable($directory.'/convention.json', self::json([
            'state' => 'EXPECTED_POSTIMAGE_NOT_APPLIED',
            'physical_line_hash' => 'sha256 of exact archived physical bytes including any newline',
            'producer_line_hash' => 'sha256(rtrim(exact physical line, CR LF)) as explicitly declared by run-v3 producer',
            'archive_authority' => 'SQL committed receipt, never the existence of candidate files',
        ]));
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetch();
            $pdo->query('SELECT id FROM invoices ORDER BY id FOR UPDATE')->fetchAll();
            $pdo->query('SELECT contract_id,object_id FROM contract_objects ORDER BY contract_id,object_id FOR UPDATE')->fetchAll();
            $locked = self::plan($pdo, $artifact);
            if ($locked['already_applied']) {
                $pdo->rollBack();
                return $locked;
            }
            if (!hash_equals($expectedPreimage, $locked['preimage_sha256'])) throw new RuntimeException('All-133 locked preimage CAS failed.');
            if ($fault) $fault('after-cas', $pdo);
            $pdo->prepare('INSERT INTO rental_scope_corrections(id,project_id,manifest_sha256,artifact_sha256,audit_sha256,state_sha256,mapping_sha256,preimage_sha256,after_sha256,artifact_path,before_path,after_path,invoice_count,verified_count,unknown_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,133,109,24)')->execute([
                $id, InvoiceScopeArtifact::PROJECT, InvoiceScope::CANDIDATE_MANIFEST,
                InvoiceScopeArtifact::PINS['coverage-delta.json'], InvoiceScopeArtifact::PINS['invoice-coverage-audit.json'],
                InvoiceScopeArtifact::STATE, InvoiceScopeArtifact::MAPPING, $expectedPreimage, hash('sha256', $afterBytes),
                $directory.'/coverage-delta.json', $directory.'/before.json', $directory.'/candidate-after.json',
            ]);
            $objectInsert = $pdo->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)');
            $periodInsert = $pdo->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)');
            foreach ($target['rows'] as $invoiceId => $row) {
                $oldVersion = (int)($artifact['original'][$invoiceId]['version'] ?? 1);
                $verified = isset($artifact['changes'][$invoiceId]);
                if ($verified) {
                    $update = $pdo->prepare('UPDATE invoices SET version=version+1 WHERE id=? AND version=?');
                    $update->execute([$invoiceId, $oldVersion]);
                    if ($update->rowCount() !== 1) throw new RuntimeException('Invoice version CAS failed.');
                    foreach ($row['objects'] as $objectId) $objectInsert->execute([$invoiceId, $objectId]);
                    foreach ($row['periods'] as $period) $periodInsert->execute([$invoiceId, $period['object_id'], $period['start_date'], $period['end_date']]);
                }
                $metadata = $row['metadata'];
                $columns = array_keys($metadata);
                $pdo->prepare('INSERT INTO invoice_scope_availability (`'.implode('`,`', $columns).'`) VALUES('.implode(',', array_fill(0, count($columns), '?')).')')->execute(array_values($metadata));
                $pdo->prepare('INSERT INTO rental_scope_correction_rows(correction_id,invoice_id,verified,old_version,new_version,before_sha256,after_sha256,evidence_sha256) VALUES(?,?,?,?,?,?,?,?)')->execute([
                    $id, $invoiceId, (int)$verified, $oldVersion, $row['invoice']['version'],
                    self::sha($locked['before']['invoices'][array_search($invoiceId, array_column($locked['before']['invoices'], 'id'))]),
                    self::sha($row), $metadata['evidence_sha256'],
                ]);
                if ($fault) $fault('after-invoice-'.$invoiceId, $pdo);
            }
            $relation = array_values(array_filter($target['contract_objects'], fn($row) => (int)$row['contract_id'] === 970415 && (int)$row['object_id'] === 1105))[0];
            $update = $pdo->prepare('UPDATE contract_objects SET physical_occupancy=0,occupancy_evidence_sha256=? WHERE contract_id=970415 AND object_id=1105 AND physical_occupancy IS NULL AND occupancy_evidence_sha256 IS NULL');
            $update->execute([$relation['occupancy_evidence_sha256']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Exact nonphysical relation CAS failed.');
            $actual = self::projection($pdo, array_keys($artifact['original']));
            if (self::json($actual) !== $afterBytes) throw new RuntimeException('Actual postimage or full protected row-content invariant failed.');
            if ($fault) $fault('before-commit', $pdo);
            $pdo->commit();
            return ['already_applied' => false, 'receipt_id' => $id, 'preimage_sha256' => $expectedPreimage, 'after_sha256' => hash('sha256', $afterBytes), 'verified' => 109, 'unknown' => 24];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
