<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Explicit reviewed CLI package. Never consulted by an ordinary UI request. */
final class MainScopeAdmission
{
    public const OWNER = '85eb7c2ee3078fc267a5c3d293d69a9a2d70e3a5460263b0affe6e7d2d8db5e9';
    public const OPERATIONAL = 'e952ab2b1fbf557d387101fed9d98dcab3dea6aa98214099e0d226a3dd048a86';
    public const DATABASE = 'ip_ya_php_local_f6e21e3c';
    public const MIGRATIONS = [
        '048_invoice_scope_correction.sql' => 'ab4ea29f42b3a0ab55ec291c6cdb9e19d26a54fd2a84850f50a878f565759be4',
        '049_contract_object_occupancy.sql' => 'ebe03b11aaf465034c6b7f09520ccb135d334a23d642fcd7ea7510d189454a3b',
    ];
    public const DEPENDENCIES = [
        'app/InvoiceScopeCorrection.php','app/InvoiceScopeArtifact.php','app/MainScopeAdmission.php',
        'app/InvoiceScope.php','app/ContractOccupancy.php','bin/correct-invoice-scope.php','bin/backup.php',
        'app/Migrator.php','app/ScopeMigrationHold.php','app/StorageBudget.php','app/MigrationSource.php','app/DB.php',
        'modules/rental/Availability.php','modules/rental/InvoiceDelivery.php','modules/rental/AddendumBilling.php',
        'modules/rental/AddendumController.php','modules/rental/Addenda.php','modules/rental/MonthlyBilling.php',
        'modules/rental/Indicators.php','modules/rental/FinanceService.php','modules/rental/Evidence.php',
        'modules/rental/ExtraService.php','modules/rental/ContractDelivery.php','modules/rental/ContractHistory.php',
        'modules/rental/ObjectFacts.php','modules/rental/Controller.php','modules/rental/NativePacket.php',
        'modules/rental/Occupancy.php','modules/rental/NativeInvoiceScope.php','modules/rental/Service.php',
    ];

    private function __construct(private array $document) {}

    private static function digest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function readJson(string $path, string $sha256): array
    {
        if (!self::digest($sha256) || !is_file($path) || is_link($path)
            || filesize($path) > 16777216 || !hash_equals($sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('Main admission evidence bytes differ.');
        }
        $value = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Main admission evidence must be an object.');
        return $value;
    }

    /** The expected package SHA is the explicit independently reviewed command pin. */
    public static function load(string $path, string $sha256, PDO $pdo): self
    {
        if (PHP_SAPI !== 'cli' || InvoiceScope::enabled()) throw new RuntimeException('Main admission requires CLI with readers OFF.');
        $document = self::readJson($path, $sha256);
        self::validateContract($document);
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== self::DATABASE) throw new RuntimeException('Main admission database differs.');
        $config = getenv('APP_DB_CONFIG_FILE') ?: APP_ROOT.'/.local/database.php';
        if (!is_file($config) || !hash_equals($document['config_sha256'], hash_file('sha256', $config))) throw new RuntimeException('Main private config binding differs.');
        foreach (['owner' => self::OWNER, 'operational' => self::OPERATIONAL] as $name => $pin) {
            self::readJson($document['inputs'][$name]['path'], $pin);
        }
        $backup = self::readJson($document['backup']['receipt_path'], $document['backup']['receipt_sha256']);
        if (($backup['kind'] ?? null) !== 'PROJECT_DATABASE_BACKUP' || ($backup['database'] ?? null) !== self::DATABASE
            || !isset($backup['path'], $backup['sha256']) || !is_file($backup['path'])
            || ($backup['all_rows_sha256'] ?? null) !== $document['before_rows_sha256']
            || ($backup['all_schema_sha256'] ?? null) !== $document['before_schema_sha256']
            || !hash_equals($backup['sha256'], hash_file('sha256', $backup['path']))) throw new RuntimeException('Main backup bytes differ.');
        $copy = self::readJson($document['copy']['path'], $document['copy']['sha256']);
        if (($copy['kind'] ?? null) !== 'MAIN_BACKUP_SCOPE_COPY_PASS' || ($copy['accepted'] ?? null) !== true
            || ($copy['backup_sha256'] ?? null) !== $backup['sha256']
            || ($copy['owner_sha256'] ?? null) !== self::OWNER
            || ($copy['operational_sha256'] ?? null) !== self::OPERATIONAL
            || ($copy['manifest_sha256'] ?? null) !== InvoiceScope::CANDIDATE_MANIFEST
            || ($copy['before_rows_sha256'] ?? null) !== $document['before_rows_sha256']
            || ($copy['before_schema_sha256'] ?? null) !== $document['before_schema_sha256']
            || ($copy['assertions'] ?? []) !== ['restore_exact' => true, 'cas_all133' => true,
                'project_all109' => true, 'unknown24' => true, 'nonphysical_relation' => true,
                'protected_rows_exact' => true, 'replay_noop' => true, 'rollback_exact' => true,
                'consumers_on' => true, 'external_callbacks_zero' => true]) throw new RuntimeException('Main independent backup-copy proof differs.');
        foreach ($document['dependencies'] as $relative => $expected) {
            if (!preg_match('~^(?:app|bin|modules|database)/[A-Za-z0-9_./-]+$~D', $relative)
                || str_contains($relative, '..') || !is_file(APP_ROOT.'/'.$relative)
                || !self::digest($expected) || !hash_equals($expected, hash_file('sha256', APP_ROOT.'/'.$relative))) throw new RuntimeException('Main source dependency changed.');
        }
        foreach (self::MIGRATIONS as $name => $hash) {
            if (!hash_equals($hash, hash_file('sha256', APP_ROOT.'/database/migrations/'.$name))) throw new RuntimeException('Main migration bytes differ.');
        }
        // Capture enforces committed Git/release-manifest SQL bytes before any DDL.
        $source = MigrationSource::capture();
        foreach (self::MIGRATIONS as $name => $hash) {
            if (($source[$name]['sha256'] ?? null) !== $hash) throw new RuntimeException('Main committed migration differs.');
        }
        if ($document['stage'] === 'apply') {
            $receipts = $pdo->query('SELECT name,sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (self::MIGRATIONS as $name => $hash) {
                if (($receipts[$name] ?? null) !== $hash) throw new RuntimeException('Main target migration receipt missing or changed.');
            }
        }
        return new self($document);
    }

    /** Pure shape checks are also used by the negative contract fixture. */
    public static function validateContract(array $document): void
    {
        if (($document['schema'] ?? null) !== 1 || ($document['kind'] ?? null) !== 'REVIEWED_MAIN_SCOPE_ADMISSION'
            || ($document['project_id'] ?? null) !== InvoiceScopeArtifact::PROJECT
            || ($document['database'] ?? null) !== self::DATABASE
            || ($document['state_sha256'] ?? null) !== InvoiceScopeArtifact::STATE
            || ($document['mapping_sha256'] ?? null) !== InvoiceScopeArtifact::MAPPING
            || ($document['owner_sha256'] ?? null) !== self::OWNER
            || ($document['operational_sha256'] ?? null) !== self::OPERATIONAL
            || ($document['coverage_sha256'] ?? null) !== InvoiceScope::CANDIDATE_MANIFEST
            || ($document['migrations'] ?? []) !== self::MIGRATIONS
            || !in_array($document['stage'] ?? '', ['migrate', 'apply'], true)
            || !self::digest($document['config_sha256'] ?? '')
            || ($document['automatic_billing'] ?? null) !== false
            || ($document['external_callbacks'] ?? null) !== false
            || ($document['operational_membership_activated'] ?? null) !== false) throw new RuntimeException('Main admission contract differs.');
        foreach (['before_rows_sha256','before_schema_sha256','expected_preimage_sha256'] as $field) {
            if (!self::digest($document[$field] ?? '')) throw new RuntimeException('Main admission preimage pin missing.');
        }
        foreach (['owner','operational'] as $input) {
            if (!is_string($document['inputs'][$input]['path'] ?? null)) throw new RuntimeException('Main source evidence path missing.');
        }
        if (!is_string($document['backup']['receipt_path'] ?? null)
            || !self::digest($document['backup']['receipt_sha256'] ?? '')
            || !is_string($document['copy']['path'] ?? null)
            || !self::digest($document['copy']['sha256'] ?? '')) throw new RuntimeException('Main backup/copy evidence binding missing.');
        foreach (self::DEPENDENCIES as $required) {
            if (!self::digest($document['dependencies'][$required] ?? '')) throw new RuntimeException('Main source dependency inventory incomplete.');
        }
    }

    /** Read-only pre-DDL fingerprint: includes owner-created rows and exact counters. */
    public static function databaseFingerprint(PDO $pdo): array
    {
        $schemas = $rows = [];
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected admission table.');
            $schemas[$table] = hash('sha256', $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
            $hashes = [];
            $query = $pdo->query('SELECT * FROM `'.$table.'`');
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) $hashes[] = hash('sha256', Importer::canonicalJson($row));
            sort($hashes, SORT_STRING);
            $rows[$table] = ['rows' => count($hashes), 'sha256' => hash('sha256', Importer::canonicalJson($hashes))];
        }
        return ['rows_sha256' => hash('sha256', Importer::canonicalJson($rows)),
            'schema_sha256' => hash('sha256', Importer::canonicalJson($schemas))];
    }

    public function admitMigrations(PDO $pdo, array $pending): void
    {
        if ($this->document['stage'] !== 'migrate' || $pending !== array_keys(self::MIGRATIONS)) throw new RuntimeException('Only the exact two pending Main migrations are admitted.');
        $actual = self::databaseFingerprint($pdo);
        if (!hash_equals($this->document['before_rows_sha256'], $actual['rows_sha256'])
            || !hash_equals($this->document['before_schema_sha256'], $actual['schema_sha256'])) throw new RuntimeException('Main owner rows/schema changed before DDL.');
    }

    public function admitApply(string $preimage): void
    {
        if ($this->document['stage'] !== 'apply' || !hash_equals($this->document['expected_preimage_sha256'], $preimage)) throw new RuntimeException('Main post-DDL preimage is not admitted.');
    }
}
