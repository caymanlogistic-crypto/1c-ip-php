<?php
declare(strict_types=1);

namespace Modules\Rental;

use DomainException;
use Modules\Integrations\{PrivateStore, Support, YakovlevImport};
use PDO;

/** Reusable display data derived from immutable 1C invoice originals. */
final class MonthlyInvoiceArchive
{
    public function __construct(private PDO $db, private PrivateStore $store) {}

    public function rows(): array
    {
        $query = $this->db->prepare("SELECT id,source_id,number,amount,status,document_date,raw_path,raw_sha256
            FROM external_documents WHERE provider=? AND account_id='invoices' ORDER BY document_date,id");
        $query->execute([YakovlevImport::PROVIDER]);

        $path = APP_ROOT.'/runtime/monthly-rent-invoice-archive.json';
        // Rebuild when the source interpretation changes, including after rollout or rollback.
        $revision = hash_file('sha256', __FILE__)
            .hash_file('sha256', __DIR__.'/MonthlyRentReconciliation.php')
            .hash_file('sha256', __DIR__.'/YakovlevInvoiceCoverage.php')
            .hash_file('sha256', __DIR__.'/YakovlevRentalSync.php')
            .hash_file('sha256', __DIR__.'/InvoicePeriods.php');
        $cached = json_decode(@file_get_contents($path) ?: '', true);
        $previous = ($cached['revision'] ?? null) === $revision && is_array($cached['invoices'] ?? null)
            ? $cached['invoices'] : [];
        $invoices = $rows = [];
        $updated = false;
        foreach ($query as $row) {
            $key = $row['raw_sha256'].':'.$row['document_date'].':'.$row['amount'];
            $summary = $previous[$key] ?? null;
            if ($summary === null) {
                $summary = $this->summarize($row);
                $updated = true;
            }
            $invoices[$key] = $summary;
            $rows[] = $row + $summary;
        }
        if ($updated || count($invoices) !== count($previous)) {
            self::save($path, ['revision'=>$revision,'invoices'=>$invoices]);
        }
        return $rows;
    }

    private function summarize(array $row): array
    {
        $source = Support::decode($this->store->read($row['raw_path'], $row['raw_sha256']))['fields']['record'];
        $periods = [];
        if (YakovlevInvoiceCoverage::hasRentLine($source)) {
            try {
                $periods = MonthlyRentReconciliation::sourceMonths($source, $row['amount'], $row['document_date']);
            } catch (DomainException) {
                $periods = null;
            }
        }
        $name = self::nameFromLines(array_merge($source['Товары'] ?? [], $source['Услуги'] ?? []), 'Содержание');
        return [
            'reference'=>$source['Ref_Key'],
            'party_ref'=>$source['Контрагент_Key'] ?? $source['Контрагент'] ?? null,
            'contract_ref'=>$source['ДоговорКонтрагента_Key'] ?? null,
            'name'=>$name !== '' ? $name : trim((string)($source['Description'] ?? '')),
            'periods'=>$periods,
        ];
    }

    public static function erpName(array $invoice): string
    {
        $print = InvoiceDocument::capturedPrint($invoice);
        return self::nameFromLines($print['lines'] ?? [], 'description') ?: (string)($invoice['kind'] ?? '');
    }

    private static function nameFromLines(array $lines, string $field): string
    {
        $names = [];
        foreach ($lines as $line) {
            $name = trim((string)($line[$field] ?? ''));
            if ($name !== '') $names[$name] = true;
        }
        return implode(' · ', array_keys($names));
    }

    private static function save(string $path, array $data): void
    {
        $temporary = @tempnam(dirname($path), 'rent-invoice-');
        if ($temporary === false) return;
        $json = Support::json($data);
        if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    public static function lastImports(PDO $db): array
    {
        $query = $db->prepare("SELECT MAX(finished_at) FROM integration_runs
            WHERE provider=? AND operation='yakovlev-load' AND status='success'
            AND JSON_UNQUOTE(JSON_EXTRACT(coverage_json,'$.scope.kind'))=?");
        $archive = [];
        foreach (['invoices','bank'] as $tab) {
            $query->execute([YakovlevImport::PROVIDER,'yakovlev-'.$tab]);
            $archive[$tab] = $query->fetchColumn() ?: null;
        }
        return $archive;
    }
}
