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
            self::saveCache($path, ['revision'=>$revision,'invoices'=>$invoices]);
        }
        return $rows;
    }

    private function summarize(array $row): array
    {
        $source = Support::decode($this->store->read($row['raw_path'], $row['raw_sha256']))['fields']['record'];
        $rentLines = MonthlyRentReconciliation::rentLines($source);
        $wholeInvoice = count($rentLines) === count($source['Товары'] ?? []) + count($source['Услуги'] ?? []);
        $periods = [];
        $rentAmount = null;
        if ($rentLines) {
            if ($wholeInvoice) $rentAmount = $row['amount'];
            try {
                $periods = MonthlyRentReconciliation::sourceMonths($source, $row['amount'], $row['document_date']);
                $rentAmount = array_reduce($periods, static fn(string $sum, string $amount): string=>bcadd($sum,$amount,2), '0.00');
            } catch (DomainException) {
                $periods = null;
            }
        }
        $partyRef = $source['Контрагент_Key'] ?? $source['Контрагент'] ?? null;
        return [
            'reference'=>$source['Ref_Key'],
            'party_ref'=>is_string($partyRef) ? $partyRef : null,
            'contract_ref'=>$source['ДоговорКонтрагента_Key'] ?? null,
            'currency_ref'=>$source['ВалютаДокумента_Key'] ?? null,
            'name'=>self::nameFromLines($rentLines, 'Содержание'),
            'rent_amount'=>$rentAmount,
            'periods'=>$periods,
        ];
    }

    public static function customers(PDO $db): array
    {
        $query = $db->prepare("SELECT SUBSTRING(source_id,9) ref,title FROM external_documents
            WHERE provider=? AND account_id='parties' AND source_id LIKE 'parties:%' AND title<>'Без наименования'");
        $query->execute([YakovlevImport::PROVIDER]);
        return $query->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function rentPayment(array $archive, ?string $payment): ?string
    {
        $rent = $archive['rent_amount'];
        if ($rent === null || $payment === null) return null;
        if (bccomp($rent,$archive['amount'],2) === 0 || bccomp($payment,'0',2) === 0) return $payment;
        return bccomp($payment,$archive['amount'],2) === 0 ? $rent : null;
    }

    public static function matchesInvoice(array $archive, array $invoice): bool
    {
        return $invoice['number'] === $archive['number']
            && $invoice['issued'] === $archive['document_date']
            && $invoice['amount'] !== null
            && bccomp($invoice['amount'],$archive['amount'],2) === 0;
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

    public static function saveCache(string $path, array $data): void
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
