<?php
declare(strict_types=1);

namespace Mosvodokanal;

use Closure;
use RuntimeException;

/** Native pageSize=0 is verified on this portal. Offset requests can be malformed. */
final class ReportReader
{
    private Closure $fetch;
    public array $checks = [];

    public function __construct(callable $fetch)
    {
        $this->fetch = Closure::fromCallable($fetch);
    }

    public function all(string $report, array $parameters): array
    {
        if ($report !== 'NATURAL_PERSON_USER_ACCOUNT_REGISTER' && $parameters === []) {
            throw new RuntimeException('UNSCOPED_REPORT_FORBIDDEN');
        }
        foreach ($parameters as $value) {
            if ($value === null || $value === '') {
                throw new RuntimeException('EMPTY_SCOPE_FORBIDDEN');
            }
        }
        $response = ($this->fetch)($report, $parameters, 0, 0);
        $rows = $response['list'] ?? null;
        $total = $response['totalCount'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('REPORT_LIST_REQUIRED');
        }
        if (!(is_int($total) || (is_string($total) && ctype_digit($total))) || (int)$total < 0) {
            throw new RuntimeException('REPORT_TOTAL_REQUIRED');
        }
        if (count($rows) !== (int)$total) {
            throw new RuntimeException('REPORT_INCOMPLETE');
        }
        if (count($rows) > 100000) {
            throw new RuntimeException('REPORT_ROW_BUDGET_EXCEEDED');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                throw new RuntimeException('REPORT_ROW_NOT_OBJECT');
            }
        }
        $this->checks[] = ['report' => $report, 'rows' => count($rows), 'declared_total' => (int)$total, 'complete' => true];
        return $rows;
    }
}

function requireId(array $row, string $field): string
{
    $value = $row[$field] ?? null;
    if (!(is_int($value) || is_string($value)) || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', (string)$value)) {
        throw new RuntimeException('MISSING_OR_INVALID_' . $field);
    }
    return (string)$value;
}

function uniqueRows(array $rows, array $fields): void
{
    $seen = [];
    foreach ($rows as $row) {
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = requireId($row, $field);
        }
        $key = json_encode($parts, JSON_THROW_ON_ERROR);
        if (isset($seen[$key])) {
            throw new RuntimeException('DUPLICATE_SOURCE_KEY');
        }
        $seen[$key] = true;
    }
}

function contextualize(array $rows, array $context): array
{
    return array_map(static function (array $row) use ($context): array {
        if (array_intersect_key($row, $context) !== []) {
            throw new RuntimeException('SOURCE_CONTEXT_COLLISION');
        }
        return $context + $row;
    }, $rows);
}

final class Exporter
{
    public const TABLES = ['accounts', 'receipts', 'payments', 'bill_payments', 'pending_payments', 'advances', 'meters', 'readings', 'reading_requests', 'reading_request_details'];
    public array $tables;
    public array $coverage = [];
    public array $errors = [];
    public string $phase = 'accounts';

    public function __construct(private readonly ReportReader $reader)
    {
        $this->tables = array_fill_keys(self::TABLES, []);
    }

    public function accounts(int $expected): array
    {
        $accounts = $this->reader->all('NATURAL_PERSON_USER_ACCOUNT_REGISTER', []);
        uniqueRows($accounts, ['ID']);
        uniqueRows($accounts, ['DOG_TAG']);
        if (count($accounts) !== $expected) {
            throw new RuntimeException('ACCOUNT_COUNT_MISMATCH');
        }
        $this->tables['accounts'] = $accounts;
        return $accounts;
    }

    /** Keep the account transaction separate: partial rows never enter completed aggregates. */
    public function account(array $account, int $index): array
    {
        $accountId = requireId($account, 'ID');
        $number = requireId($account, 'DOG_TAG');
        $context = ['account_id' => $accountId, 'account_number' => $number, 'account_address' => $account['ADR_NAME_FULL'] ?? null];
        $data = array_fill_keys(array_diff(self::TABLES, ['accounts']), []);
        $reports = [
            'receipts' => 'NATURAL_PERSON_ACCOUNT_BILL_REGISTER',
            'payments' => 'MOBILE_ACCOUNT_PAYMENT_REESTR',
            'pending_payments' => 'NATURAL_PERSON_ACCOUNT_ACQ_ORDER_REGISTER',
            'advances' => 'NATURAL_PERSON_ACCOUNT_PREPAYMENT_REGISTER',
            'meters' => 'ACCOUNT_METER_REGISTER',
            'reading_requests' => 'NATURAL_PERSON_METER_READ_REQUEST_HISTORY',
        ];
        foreach ($reports as $table => $report) {
            $this->phase = $report;
            $rows = $this->reader->all($report, ['ACCOUNT_ID' => $accountId]);
            foreach ($rows as $row) {
                if (isset($row['ACCOUNT_ID']) && (string)$row['ACCOUNT_ID'] !== $accountId) {
                    throw new RuntimeException('ACCOUNT_SCOPE_MISMATCH');
                }
            }
            $data[$table] = contextualize($rows, $context);
        }
        uniqueRows($data['receipts'], ['ID', 'FL_DOC']);
        uniqueRows($data['meters'], ['MM_ID']);
        uniqueRows($data['payments'], ['PAY_ID']);
        uniqueRows($data['reading_requests'], ['REQUEST_ID']);
        foreach ($data['receipts'] as $receipt) {
            $this->phase = 'BILL_PAYMENTS_REESTR';
            $receiptId = requireId($receipt, 'ID');
            $rows = $this->reader->all($this->phase, ['BILL_ID' => $receiptId]);
            array_push($data['bill_payments'], ...contextualize($rows, $context + ['receipt_id' => $receiptId, 'receipt_type' => $receipt['FL_DOC']]));
        }
        $meterIds = [];
        foreach ($data['meters'] as $meter) {
            $meterIds[requireId($meter, 'MM_ID')] = $meter['PRODUCTION_TAG'] ?? null;
        }
        foreach ($data['reading_requests'] as $request) {
            $this->phase = 'METER_READ_REQUEST_HISTORY_DATA';
            $requestId = requireId($request, 'REQUEST_ID');
            $rows = $this->reader->all($this->phase, ['REQUEST_ID' => $requestId]);
            foreach ($rows as $row) {
                if (isset($row['ACCOUNT_ID']) && (string)$row['ACCOUNT_ID'] !== $accountId) {
                    throw new RuntimeException('REQUEST_ACCOUNT_SCOPE_MISMATCH');
                }
                if (isset($row['MOUNTED_METER_ID']) && (string)$row['MOUNTED_METER_ID'] !== '') {
                    $meterIds[requireId($row, 'MOUNTED_METER_ID')] ??= $row['PRODUCTION_TAG'] ?? null;
                }
            }
            array_push($data['reading_request_details'], ...contextualize($rows, $context + ['request_id' => $requestId]));
        }
        foreach ($meterIds as $meterId => $serial) {
            $this->phase = 'METER_READING_REESTR';
            $rows = $this->reader->all($this->phase, ['MOM_ID' => (string)$meterId]);
            uniqueRows($rows, ['ID']);
            array_push($data['readings'], ...contextualize($rows, $context + ['meter_id' => (string)$meterId, 'meter_serial' => $serial]));
        }
        $coverage = ['index' => $index, 'status' => 'complete', 'counts' => array_map('count', $data), 'meter_histories' => count($meterIds)];
        return ['coverage' => $coverage, 'tables' => $data];
    }

    public function accept(array $snapshot): void
    {
        foreach ($this->coverage as $item) {
            if ($item['index'] === $snapshot['coverage']['index']) {
                throw new RuntimeException('ACCOUNT_ALREADY_COMMITTED');
            }
        }
        foreach ($snapshot['tables'] as $table => $rows) {
            array_push($this->tables[$table], ...$rows);
        }
        $this->coverage[] = $snapshot['coverage'];
    }
}

/** Raw JSON is authoritative. CSV preserves null as \N and escapes spreadsheet formulas. */
function writeCsv(string $path, array $rows, array $fallbackHeaders = []): void
{
    $headers = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $key) {
            $headers[$key] = true;
        }
    }
    $headers = $headers === [] ? $fallbackHeaders : array_keys($headers);
    $handle = fopen($path, 'xb');
    if ($handle === false) { throw new RuntimeException('CSV_CREATE_FAILED'); }
    try {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ';', '"', '');
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $key) {
                $value = $row[$key] ?? null;
                $text = $value === null ? '\\N' : (is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value));
                if (preg_match('/^[\s]*[=+@-]/u', $text) && !preg_match('/^-?\d+(?:[.,]\d+)?$/D', $text)) {
                    $text = "'" . $text;
                }
                $values[] = $text;
            }
            if (fputcsv($handle, $values, ';', '"', '') === false) { throw new RuntimeException('CSV_WRITE_FAILED'); }
        }
    } finally { fclose($handle); }
    if (PHP_OS_FAMILY !== 'Windows') { chmod($path, 0600); }
}
