<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\BillableTerms;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Modules\Integrations\{PrivateStore, YakovlevImport};
use PDO;
use RuntimeException;

/** Read-only comparison of contract accruals, posted 1C invoices, and the ERP payment calendar. */
final class MonthlyRentReconciliation
{
    private const ZERO = '0.00';

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public static function range(string $first, string $last): array
    {
        if (!InvoicePeriods::month($first) || !InvoicePeriods::month($last) || $first > $last) {
            throw new RuntimeException('Укажите первый и последний месяц в порядке возрастания.', 422);
        }
        $months = [];
        $end = new DateTimeImmutable($last.'-01');
        for ($month = new DateTimeImmutable($first.'-01'); $month <= $end; $month = $month->modify('+1 month')) {
            if (count($months) === 24) throw new RuntimeException('Выберите период не больше 24 месяцев.', 422);
            $months[] = $month->format('Y-m');
        }
        return $months;
    }

    public function capture(array $months, bool $reviewOnly = false): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->db->beginTransaction();
        try {
            return $this->collect($months, $now, $reviewOnly);
        } finally {
            $this->db->rollBack();
        }
    }

    private function collect(array $months, DateTimeImmutable $now, bool $reviewOnly = false): array
    {
        $report = array_fill_keys($months, []);
        $contracts = $this->db->query('SELECT c.*,p.name client,p.source_json party_source FROM contracts c JOIN parties p ON p.id=c.party_id ORDER BY p.name,c.number')
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $issues = [];
        foreach ($contracts as $id => &$contract) {
            $contract['id'] = (int)$id;
            $contract['party_ref'] = InvoiceContractAssignment::partyRef($contract);
            if ($contract['status'] !== 'active') continue;
            $terms = BillableTerms::forContract($this->db, (int)$id);
            $sharedRent = $this->sharedMonthlyRent($contract, $terms);
            foreach ($months as $month) {
                [$first,$last] = InvoicePeriods::bounds($month);
                if (($contract['start_date'] && $contract['start_date'] > $last)
                    || ($contract['end_date'] && $contract['end_date'] < $first)) continue;
                $entry = &$this->entry($report[$month], $contract);
                try {
                    if (!$terms || !$contract['start_date'] || !$contract['end_date']) {
                        throw new RuntimeException('Нужны подтверждённые сроки и цены аренды.');
                    }
                    HandoverQuestion::assertBillingReady($this->db, (int)$id, $month);
                    $entry['planned'][$contract['number']] = $sharedRent !== null
                        ? self::prorateSharedRent($sharedRent, $contract, $first, $last)
                        : BillableMonthlyPlan::calculate($contract, $terms, [], $month)['amount'];
                } catch (RuntimeException $error) {
                    if ($error instanceof \PDOException) throw $error;
                    $entry['planned'][$contract['number']] = null;
                    $issues[] = [$month, $contract['client'], 'Договор №'.$contract['number'], $error->getMessage()];
                }
                unset($entry);
            }
        }
        unset($contract);

        $bindings = $this->db->query("SELECT DISTINCT source_id,entity_id FROM rental_source_bindings
            WHERE entity_type='contract' AND collection='Catalog_ДоговорыКонтрагентов' AND relation_kind='rental_contract'")
            ->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN);
        $invoices = $this->db->query("SELECT i.* FROM invoices i WHERE i.status='active'
            AND i.kind IN ('Аренда','Первый период') ORDER BY i.due,i.id")
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $invoiceLinks = $this->db->query("SELECT DISTINCT source_id,entity_id FROM rental_source_bindings
            WHERE entity_type='invoice' AND collection='Document_СчетНаОплатуПокупателю' AND relation_kind='invoice_control'")
            ->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN);
        foreach ($invoices as $id => &$invoice) {
            $invoice['id'] = (int)$id;
            $guid = Importer::decodeExact($invoice['source_json'])['Ref_Key'] ?? null;
            if ($guid) $invoiceLinks[$guid][] = (int)$id;
        }
        unset($invoice);

        $manual = InvoiceContractAssignment::index($this->db);
        $today = $now->format('Y-m-d');
        $verified = [];
        foreach ((new MonthlyInvoiceArchive($this->db, $this->store))->rows() as $archived) {
            $periods = $archived['periods'];
            if ($archived['status'] !== 'Проведён' || $archived['document_date'] > $today || $periods === []) continue;
            $ids = $bindings[$archived['contract_ref'] ?? ''] ?? [];
            $assigned = InvoiceContractAssignment::contractId($archived,$manual[$archived['id']] ?? null);
            if ($assigned) $ids = [$assigned];
            $contract = count($ids) === 1 ? ($contracts[$ids[0]] ?? null) : null;
            if ($periods === null) {
                $issues[] = ['—', $contract['client'] ?? '—', $archived['number'],
                    'Уточнить период и суммы строк оригинала 1С.', $archived['name']];
                continue;
            }
            $selected = array_intersect_key($periods, $report);
            $sourceParty = $archived['party_ref'];
            if (!$contract || !$sourceParty || $sourceParty !== $contract['party_ref']) {
                if ($selected) $issues[] = [implode(', ',array_keys($selected)), $contract['client'] ?? '—', $archived['number'],
                    'Подтвердить связь договора и клиента с 1С.', $archived['name']];
                continue;
            }
            $linked = array_unique($invoiceLinks[$archived['reference']] ?? []);
            $invoice = count($linked) === 1 ? ($invoices[reset($linked)] ?? null) : null;
            $invoiceId = null;
            if ($invoice && (int)$invoice['contract_id'] === $contract['id']
                && $invoice['number'] === $archived['number'] && $invoice['issued'] === $archived['document_date']
                && $invoice['amount'] !== null
                && bccomp($invoice['amount'], $archived['amount'], 2) === 0) {
                $invoiceId = $invoice['id'];
                $verified[$invoiceId] = $archived['name'];
            } elseif ($selected) {
                $issues[] = [implode(', ',array_keys($selected)), $contract['client'], $archived['number'],
                    'Нужна точная связь с карточкой счёта ERP и сроком оплаты.', $archived['name']];
            }
            if (!$reviewOnly && $contract['status'] === 'active') {
                foreach ($selected as $month => $amount) {
                    $entry = &$this->entry($report[$month], $contract);
                    $entry['billed'][$archived['source_id']] = ['number'=>$archived['number'],
                        'contract'=>$contract['number'],'amount'=>$amount,'invoice_id'=>$invoiceId];
                    unset($entry);
                }
            }
        }
        $allocations = $reviewOnly ? [] : $this->paymentTotals($today);
        $invoiceFacts = [];
        foreach ($invoices as $id => $invoice) {
            $contract = $contracts[$invoice['contract_id']];
            $due = $invoice['due'];
            if (!$due && (isset($verified[$id]) || isset($report[InvoicePeriods::month($invoice['period'])]))) {
                $issues[] = ['—', $contract['client'], $invoice['number'], 'В карточке ERP отсутствует срок оплаты.',
                    ($verified[$id] ?? '') ?: MonthlyInvoiceArchive::erpName($invoice)];
            }
            if ($reviewOnly) continue;
            $paid = $allocations[$id]['paid'] ?? self::ZERO;
            $paidFromOnec = $allocations[$id]['paid_from_1c'] ?? self::ZERO;
            $balance = $invoice['paid'] !== null && bccomp($invoice['paid'], $paid, 2) === 0
                ? InvoiceFacts::remaining($invoice) : null;
            $invoiceFacts[$id] = ['due'=>$due,'paid_from_1c'=>$paidFromOnec,'balance'=>$balance];
            if (!$due) continue;
            $month = substr($due, 0, 7);
            if (!isset($report[$month])) continue;
            $entry = &$this->entry($report[$month], $contract);
            $invoice['contract'] = $contract['number'];
            $invoice['verified'] = isset($verified[$id]);
            $invoice['balance'] = $balance;
            $invoice['paid_from_1c'] = $paidFromOnec;
            $invoice['overdue'] = $invoice['verified'] && !$invoice['manual_review'] && $balance !== null
                && InvoiceFacts::overdueDays(PaymentReview::attach($this->db, $invoice, $now)) > 0;
            $entry['due'][] = array_intersect_key($invoice,array_flip([
                'id','number','contract','due','amount','paid','paid_from_1c','balance','verified','overdue',
            ]));
            unset($entry);
        }
        return ['months'=>$report, 'issues'=>$issues, 'archive'=>MonthlyInvoiceArchive::lastImports($this->db), 'invoice_facts'=>$invoiceFacts,
            'generated'=>$now->format('d.m.Y H:i:s').' МСК'];
    }

    private function paymentTotals(string $date): array
    {
        $archive = $this->db->prepare("SELECT source_id FROM external_documents
            WHERE provider=? AND account_id='bank_in' AND status='Проведён'");
        $archive->execute([YakovlevImport::PROVIDER]);
        $posted = array_fill_keys(array_map('strtolower', $archive->fetchAll(PDO::FETCH_COLUMN)), true);

        $fromOnec = [];
        $bindings = $this->db->query("SELECT entity_id,source_id FROM rental_source_bindings WHERE entity_type='payment'
            AND collection='Document_ПоступлениеНаРасчетныйСчет' AND relation_kind='allocation_no_cash_replay'");
        foreach ($bindings as $binding) {
            $reference = strstr($binding['source_id'], ':', true);
            if ($reference !== false && isset($posted['bank_in:'.strtolower($reference)])) {
                $fromOnec[$binding['entity_id']] = true;
            }
        }

        $query = $this->db->prepare("SELECT a.invoice_id,a.payment_id,a.amount,
            JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.bank_external_id')) bank_ref,
            JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.Ref_Key')) source_ref
            FROM payment_allocations a JOIN payments p ON p.id=a.payment_id JOIN invoices i ON i.id=a.invoice_id
            WHERE p.payment_date<=? AND i.status='active' AND i.kind IN ('Аренда','Первый период')");
        $query->execute([$date]);
        $totals = [];
        foreach ($query as $allocation) {
            $id = $allocation['invoice_id'];
            $payment = $allocation['payment_id'];
            $amount = $allocation['amount'];
            $fromOnec[$payment] ??= isset($posted['bank_in:'.strtolower($allocation['bank_ref'] ?? '')])
                || isset($posted['bank_in:'.strtolower($allocation['source_ref'] ?? '')]);
            $totals[$id] ??= ['paid'=>self::ZERO,'paid_from_1c'=>self::ZERO];
            $totals[$id]['paid'] = bcadd($totals[$id]['paid'], $amount, 2);
            if ($fromOnec[$payment]) {
                $totals[$id]['paid_from_1c'] = bcadd($totals[$id]['paid_from_1c'], $amount, 2);
            }
        }
        return $totals;
    }

    /** A recorded whole-contract rate can be used for forecasting without pricing its objects. */
    private function sharedMonthlyRent(array $contract, array $terms): ?string
    {
        $amount = $contract['amount'] ?? null;
        if (!$terms || !$contract['start_date'] || !$contract['end_date'] || $amount === null
            || bccomp($amount, '0', 2) <= 0 || array_column($terms, 'amount') !== array_fill(0, count($terms), null)) {
            return null;
        }
        $mapping = Importer::decodeExact($contract['source_json'])['sourceLegalMapping'] ?? [];
        $allocations = $mapping['unresolvedComponentAllocation'] ?? [];
        $conditions = $mapping['sourceConditions'] ?? [];
        if (count($allocations) !== 1 || count($conditions) !== 1) return null;
        $allocation = $allocations[0];
        $condition = $allocation['condition'] ?? [];
        if (empty($condition['condition_id']) || $condition['condition_id'] !== ($conditions[0]['condition_id'] ?? null)
            || ($condition['kind'] ?? null) !== 'RENT' || ($condition['monthly_gross'] ?? null) !== $amount
            || ($condition['start'] ?? null) !== $contract['start_date'] || ($condition['end'] ?? null) !== $contract['end_date']) {
            return null;
        }
        $objects = [];
        foreach ($terms as $term) {
            if ($term['target_kind'] !== 'physical-object' || $term['start_date'] !== $contract['start_date']
                || $term['end_date'] !== $contract['end_date'] || !empty($term['superseded_on'])
                || (!empty($term['effective_date']) && $term['effective_date'] > $contract['start_date'])) {
                return null;
            }
            $objects[] = $term['object_id'];
        }
        $recorded = array_map('intval', $allocation['objects'] ?? []);
        sort($objects, SORT_NUMERIC);
        sort($recorded, SORT_NUMERIC);
        if ($objects !== $recorded) return null;

        $statement = $this->db->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');
        $statement->execute([$contract['id']]);
        $current = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        return $current === $objects ? $amount : null;
    }

    private static function prorateSharedRent(string $amount, array $contract, string $first, string $last): string
    {
        $start = max($first, $contract['start_date'], $contract['servicing_effective_date'] ?? $first);
        $end = min($last, $contract['end_date']);
        if ($start > $end) return self::ZERO;
        $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
        return Billing::round(bcdiv(bcmul($amount, (string)$days, 12), substr($last, -2), 12));
    }

    /** Totals from the priced lines of the original, independent of ERP tariff matching. */
    public static function sourceMonths(array $source, string $total, ?string $issued = null): array
    {
        $periods = [];
        $sum = self::ZERO;
        $included = $source['СуммаВключаетНДС'] ?? null;
        if (!is_bool($included)) throw new DomainException('Нет признака включения НДС.');
        foreach (array_merge($source['Товары'] ?? [], $source['Услуги'] ?? []) as $line) {
            $amount = YakovlevRentalSync::cents($line['Сумма'] ?? null, true);
            if (!$included) {
                $amount = bcadd($amount, YakovlevRentalSync::cents($line['СуммаНДС'] ?? null, true), 2);
            }
            $sum = bcadd($sum, $amount, 2);
            if (!YakovlevInvoiceCoverage::hasRentLine(['Услуги'=>[$line]])) continue;
            [$period] = YakovlevInvoiceCoverage::period(trim((string)$line['Содержание']), $issued);
            $periods[$period] = bcadd($periods[$period] ?? self::ZERO, $amount, 2);
        }
        if (bccomp($sum, $total, 2) !== 0) throw new DomainException('Суммы строк расходятся с итогом.');
        return $periods;
    }

    private function &entry(array &$month, array $contract): array
    {
        $id = $contract['party_id'];
        $month[$id] ??= ['name'=>$contract['client'],'planned'=>[],'billed'=>[],'due'=>[]];
        return $month[$id];
    }
}
