<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Temporary owner hold at the CLI upgrade boundary; never invoked by UI reads. */
final class ScopeMigrationHold
{
    public static function validate(array $pendingNames, string $database, ?string $config, ?string $copyMode, ?MainScopeAdmission $mainAdmission = null, ?\PDO $pdo = null): void
    {
        $held = ['048_invoice_scope_correction.sql', '049_contract_object_occupancy.sql'];
        if (array_intersect($pendingNames, $held) === []) return;
        if ($mainAdmission !== null && $pdo !== null && $database === MainScopeAdmission::DATABASE) {
            $mainAdmission->admitMigrations($pdo, $pendingNames);
            return;
        }
        if ($copyMode === 'explicit-disposable-copy' && $config !== null && $config !== ''
            && preg_match('/^ip_ya_php_scratch_[a-z0-9_]+$/D', $database)) return;
        throw new RuntimeException('Operational scope migrations are held for Main; only an explicit disposable scratch copy is admitted.');
    }
}
