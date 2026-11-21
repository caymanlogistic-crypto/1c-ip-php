<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\BillableTerms;
use DateTimeImmutable;
use DomainException;
use Modules\Integrations\{PrivateStore, Support, YakovlevImport};
use PDO;
use RuntimeException;

/** Read-only comparison of contract accruals, posted 1C invoices, and the ERP payment calendar. */
final class MonthlyRentReconciliation
{
    private const ZERO = '0.00';

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public function collect(array $months, DateTimeImmutable $now): array
    {
        $report = array_fill_keys($months, []);
        $contracts = $this->db->query('SELECT c.*,p.name client,p.source_json party_source FROM contracts c JOIN parties p ON p.id=c.party_id ORDER BY p.name,c.number')
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $issues = [];
        foreach ($contracts as $id => &$contract) {
            $contract['id'] = (int)$id;
            if ($contract['status'] !== 'active') continue;
            $terms = BillableTerms::forContract($this->db, (int)$id);
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
                    $entry['planned'][$contract['number']] = BillableMonthlyPlan::calculate($contract, $terms, [], $month)['amount'];
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

        $query = $this->db->prepare("SELECT number,amount,source_id,document_date,raw_path,raw_sha256 FROM external_documents
            WHERE provider=? AND account_id='invoices' AND status='Проведён' AND document_date<=? ORDER BY document_date,id");
        $query->execute([YakovlevImport::PROVIDER, $now->format('Y-m-d')]);
        $verified = [];
        foreach ($query->fetchAll() as $archived) {
            $source = Support::decode($this->store->read($archived['raw_path'], $archived['raw_sha256']))['fields']['record'];
            if (!YakovlevInvoiceCoverage::hasRentLine($source)) continue;
            $ids = $bindings[$source['ДоговорКонтрагента_Key'] ?? ''] ?? [];
            $contract = count($ids) === 1 ? ($contracts[$ids[0]] ?? null) : null;
            try {
                $periods = self::sourceMonths($source, $archived['amount']);
            } catch (DomainException) {
                $issues[] = ['—', $contract['client'] ?? '—', $archived['number'], 'Уточнить период и суммы строк оригинала 1С.'];
                continue;
            }
            $selected = array_intersect_key($periods, $report);
            $party = $contract ? Importer::decodeExact($contract['party_source']) : [];
            $sourceParty = $source['Контрагент_Key'] ?? $source['Контрагент'] ?? null;
            if (!$contract || !$sourceParty || $sourceParty !== ($party['source_party_guid'] ?? $party['Ref_Key'] ?? null)) {
                if ($selected) $issues[] = [implode(', ',array_keys($selected)), $contract['client'] ?? '—', $archived['number'],
                    'Подтвердить связь договора и клиента с 1С.'];
                continue;
            }
            $linked = array_unique($invoiceLinks[$source['Ref_Key']] ?? []);
            $invoice = count($linked) === 1 ? ($invoices[reset($linked)] ?? null) : null;
            $invoiceId = null;
            if ($invoice && (int)$invoice['contract_id'] === $contract['id']
                && $invoice['number'] === $archived['number'] && $invoice['issued'] === $archived['document_date']
                && $invoice['amount'] !== null
                && bccomp($invoice['amount'], $archived['amount'], 2) === 0) {
                $invoiceId = $invoice['id'];
                $verified[$invoiceId] = true;
            } elseif ($selected) {
                $issues[] = [implode(', ',array_keys($selected)), $contract['client'], $archived['number'],
                    'Нужна точная связь с карточкой счёта ERP и сроком оплаты.'];
            }
            if ($contract['status'] === 'active') {
                foreach ($selected as $month => $amount) {
                    $entry = &$this->entry($report[$month], $contract);
                    $entry['billed'][$archived['source_id']] = ['number'=>$archived['number'],
                        'contract'=>$contract['number'],'amount'=>$amount,'invoice_id'=>$invoiceId];
                    unset($entry);
                }
            }
        }
        $paymentQuery = $this->db->prepare("SELECT a.invoice_id,SUM(a.amount) paid,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM external_documents e WHERE e.provider=? AND e.account_id='bank_in'
                AND e.status='Проведён' AND (e.source_id IN (
                    CONCAT('bank_in:',JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.bank_external_id'))),
                    CONCAT('bank_in:',JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.Ref_Key')))
                ) OR EXISTS (
                    SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='payment' AND b.entity_id=p.id
                    AND b.collection='Document_ПоступлениеНаРасчетныйСчет' AND b.relation_kind='allocation_no_cash_replay'
                    AND b.source_id LIKE CONCAT(SUBSTRING(e.source_id,9),':%')
                ))) THEN a.amount ELSE 0 END) paid_from_1c
            FROM payment_allocations a JOIN payments p ON p.id=a.payment_id
            WHERE p.payment_date<=? GROUP BY a.invoice_id");
        $paymentQuery->execute([YakovlevImport::PROVIDER, $now->format('Y-m-d')]);
        $allocations = $paymentQuery->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $invoiceFacts = [];
        foreach ($invoices as $id => $invoice) {
            $contract = $contracts[$invoice['contract_id']];
            $due = $invoice['due'];
            $paid = $allocations[$id]['paid'] ?? self::ZERO;
            $paidFromOnec = $allocations[$id]['paid_from_1c'] ?? self::ZERO;
            $balance = $invoice['paid'] !== null && bccomp($invoice['paid'], $paid, 2) === 0
                ? InvoiceFacts::remaining($invoice) : null;
            $invoiceFacts[$id] = ['due'=>$due,'paid_from_1c'=>$paidFromOnec,'balance'=>$balance];
            if (!$due) {
                if (isset($verified[$id]) || isset($report[InvoicePeriods::month($invoice['period'])])) {
                    $issues[] = ['—', $contract['client'], $invoice['number'], 'В карточке ERP отсутствует срок оплаты.'];
                }
                continue;
            }
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
        $lastArchive = $this->db->prepare("SELECT MAX(finished_at) FROM integration_runs
            WHERE provider=? AND operation='yakovlev-load' AND status='success'
            AND JSON_UNQUOTE(JSON_EXTRACT(coverage_json,'$.scope.kind'))=?");
        $archive = [];
        foreach (['invoices','bank'] as $tab) {
            $lastArchive->execute([YakovlevImport::PROVIDER,'yakovlev-'.$tab]);
            $archive[$tab] = $lastArchive->fetchColumn() ?: null;
        }
        return ['months'=>$report, 'issues'=>$issues, 'archive'=>$archive, 'invoice_facts'=>$invoiceFacts,
            'generated'=>$now->format('d.m.Y H:i:s').' МСК'];
    }

    /** Totals from the priced lines of the original, independent of ERP tariff matching. */
    public static function sourceMonths(array $source, string $total): array
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
            [$period] = YakovlevInvoiceCoverage::period(trim((string)$line['Содержание']));
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
