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
        $preceding = [];
        foreach ($contracts as $successor) {
            $predecessor = Importer::decodeExact($successor['source_json'])['archiveContinuity']['predecessor'] ?? null;
            if (isset($contracts[$predecessor]) && $contracts[$predecessor]['status']==='ended') {
                $preceding[$predecessor] = substr($contracts[$predecessor]['end_date'],0,7);
            }
        }
        foreach ($contracts as $id => &$contract) {
            $contract['id'] = (int)$id;
            $contract['party_ref'] = InvoiceContractAssignment::partyRef($contract);
            if ($contract['status'] !== 'active' && !isset($preceding[$id])) continue;
            $terms = BillableTerms::forContract($this->db, (int)$id);
            $sharedRent = $this->sharedMonthlyRent($contract, $terms);
            $previousStart = self::previousReplacementTermStart($contract,$terms);
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
                    $openingDate = $previousStart !== null && substr($contract['start_date'],0,7) === $month
                        ? $previousStart : $contract['start_date'];
                    $planContract = array_replace($contract,['start_date'=>$openingDate]);
                    $entry['planned'][$contract['number']] = $sharedRent !== null
                        ? self::prorateSharedRent($sharedRent, $contract, $first, $last)
                        : BillableMonthlyPlan::calculate($planContract, $terms, [], $month)['amount'];
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
        $customers = MonthlyInvoiceArchive::customers($this->db);
        $verified = [];
        $originals = array_column((new MonthlyInvoiceArchive($this->db,$this->store))->rows(),null,'reference');
        foreach ($originals as $archived) {
            $periods = $archived['periods'];
            if ($archived['status'] !== 'Проведён' || $periods === [] || $archived['document_date'] > $today) continue;
            $ids = $bindings[$archived['contract_ref'] ?? ''] ?? [];
            $assigned = InvoiceContractAssignment::contractId($archived,$manual[$archived['id']] ?? null);
            if ($assigned) $ids = [$assigned];
            $contract = count($ids) === 1 ? ($contracts[$ids[0]] ?? null) : null;
            $sourceParty = $archived['party_ref'];
            $matchesContract = $contract && $sourceParty && $sourceParty === $contract['party_ref'];
            $client = $matchesContract ? $contract['client'] : ($customers[$sourceParty ?? ''] ?? 'Уточнить');
            if ($periods === null) {
                $issuedMonth = substr($archived['document_date'],0,7);
                if (isset($report[$issuedMonth])) $issues[] = ['—', $client, $archived['number'],
                    'Уточнить период и суммы строк оригинала 1С.', $archived['name']];
                continue;
            }
            $selected = array_intersect_key($periods, $report);
            if (!$matchesContract) {
                if ($selected) $issues[] = [implode(', ',array_keys($selected)), $client, $archived['number'],
                    'Подтвердить связь договора и клиента с 1С.', $archived['name']];
                continue;
            }
            $invoiceId = null;
            $linked = array_unique($invoiceLinks[$archived['reference']] ?? []);
            $invoice = count($linked) === 1 ? ($invoices[reset($linked)] ?? null) : null;
            if ($invoice && (int)$invoice['contract_id'] === $contract['id'] && MonthlyInvoiceArchive::matchesInvoice($archived,$invoice)) {
                $invoiceId = $invoice['id'];
                $verified[$invoiceId] = $archived;
            } elseif ($selected) {
                $issues[] = [implode(', ',array_keys($selected)), $contract['client'], $archived['number'],
                    'Нужна точная связь с карточкой счёта ERP и сроком оплаты.', $archived['name']];
            }
            if (!$reviewOnly) {
                foreach ($selected as $month => $amount) {
                    if (!self::includesContract($contract,$month,$preceding)) continue;
                    $entry = &$this->entry($report[$month], $contract);
                    $entry['billed'][$archived['source_id']] = ['number'=>$archived['number'],
                        'contract'=>$contract['number'],'amount'=>$amount,'invoice_id'=>$invoiceId,'name'=>$archived['name'],
                        'reference'=>$archived['reference']];
                    unset($entry);
                }
            }
        }
        $allocations = $reviewOnly ? [] : $this->paymentTotals($today);
        $invoiceFacts = [];
        foreach ($invoices as $id => $invoice) {
            $original = $verified[$id] ?? null;
            if ($original === null) continue;
            $contract = $contracts[$invoice['contract_id']];
            $due = InvoiceDueDate::archivedRent($invoice,$original['periods']);
            $invoice['due'] = $due;
            $rentalMonths = array_intersect_key($original['periods'],$report);
            if (!$rentalMonths) continue;
            if (!$due) $issues[] = [implode(', ',array_keys($rentalMonths)), $contract['client'], $invoice['number'],
                'В карточке ERP отсутствует срок оплаты.', $original['name']];
            if ($invoice['paid'] !== null && MonthlyInvoiceArchive::rentPayment($original,$invoice['paid']) === null) {
                $issues[] = [implode(', ',array_keys($rentalMonths)), $contract['client'], $invoice['number'],
                    'Уточнить, какая часть оплаты относится к аренде.', $original['name']];
            }
            if ($reviewOnly) continue;
            $paid = $allocations[$id]['paid'] ?? self::ZERO;
            $rentPaid = MonthlyInvoiceArchive::rentPayment($original,$paid);
            $rentPaidFromOnec = MonthlyInvoiceArchive::rentPayment($original,$allocations[$id]['paid_from_1c'] ?? self::ZERO);
            $match = $invoice['paid'] !== null && bccomp($invoice['paid'],$paid,2) === 0;
            $overdue = $match && !$invoice['manual_review'] && $rentPaid !== null
                && InvoiceFacts::overdueDays(PaymentReview::attach($this->db,$invoice,$now)) > 0;
            foreach ($rentalMonths as $month=>$amount) {
                $monthlyPaid = self::monthlyPortion($original,$amount,$rentPaid);
                $fromOnec = self::monthlyPortion($original,$amount,$rentPaidFromOnec);
                $balance = $match && $monthlyPaid !== null
                    ? InvoiceFacts::remaining(array_replace($invoice,['amount'=>$amount,'paid'=>$monthlyPaid])) : null;
                $invoiceFacts[$id][$month] = ['due'=>$due,'paid_from_1c'=>$fromOnec,'balance'=>$balance,'allocated'=>$monthlyPaid];
                if (!self::includesContract($contract,$month,$preceding)) continue;
                $entry = &$this->entry($report[$month],$contract);
                $entry['due'][] = ['id'=>$id,'number'=>$invoice['number'],'contract'=>$contract['number'],
                    'due'=>$due,'amount'=>$amount,'paid'=>$monthlyPaid,'paid_from_1c'=>$fromOnec,'balance'=>$balance,
                    'verified'=>true,'overdue'=>$overdue && $balance !== null && bccomp($balance,self::ZERO,2)>0];
                unset($entry);
            }
        }
        if (!$reviewOnly) $this->settledBills($report,$invoiceFacts,$originals,$today);
        return ['months'=>$report, 'issues'=>$issues, 'archive'=>MonthlyInvoiceArchive::lastImports($this->db), 'invoice_facts'=>$invoiceFacts,
            'generated'=>$now->format('d.m.Y H:i:s').' МСК'];
    }

    private function settledBills(array &$report,array $invoiceFacts,array $originals,string $today): void
    {
        $targets=[];
        foreach ($report as $month=>$clients) {
            foreach ($clients as $client) foreach ($client['billed'] as $bill) {
                $balance=$invoiceFacts[$bill['invoice_id']][$month]['balance'] ?? null;
                if ($balance!==self::ZERO) $targets[$bill['reference']]=$originals[$bill['reference']];
            }
        }
        $paid=(new MonthlyBankEvidence($this->db,$this->store))->fullyPaid($targets,$originals,$today);
        foreach ($report as $month=>&$clients) {
            foreach ($clients as &$client) foreach ($client['billed'] as &$bill) {
                $facts=$invoiceFacts[$bill['invoice_id']][$month] ?? [];
                $bill['due']=$facts['due'] ?? null;
                $bill['paid_from_1c']=$facts['paid_from_1c'] ?? null;
                $bill['balance']=$facts['balance'] ?? null;
                $bill['not_allocated']=self::ZERO;
                if (!isset($paid[$bill['reference']])) continue;
                $allocated=$bill['invoice_id'] === null ? self::ZERO : ($facts['allocated'] ?? null);
                $bill['paid_from_1c']=$bill['amount'];
                $bill['balance']=self::ZERO;
                $bill['not_allocated']=$allocated === null ? null
                    : (bccomp($bill['amount'],$allocated,2)>0 ? bcsub($bill['amount'],$allocated,2) : self::ZERO);
            }
            unset($bill,$client);
        }
        unset($clients);
    }

    private static function monthlyPortion(array $original, string $amount, ?string $rentPaid): ?string
    {
        if ($rentPaid === null || count($original['periods']) === 1 || bccomp($rentPaid,self::ZERO,2) === 0)
            return $rentPaid;
        return bccomp($rentPaid,$original['rent_amount'],2) === 0 ? $amount : null;
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

    private static function includesContract(array $contract,string $month,array $preceding): bool
    {
        return $contract['status']==='active' || $month<=($preceding[$contract['id']] ?? '');
    }

    /** Include the old object in the month of a confirmed uninterrupted replacement. */
    private static function previousReplacementTermStart(array $contract, array $terms): ?string
    {
        $replacement = Importer::decodeExact($contract['source_json'])['ownerConfirmedReplacement'] ?? [];
        $boundary = $replacement['date'] ?? null;
        if (!$boundary || $boundary !== $contract['start_date'] || empty($replacement['from']) || empty($replacement['to'])
            || $replacement['from'] === $replacement['to']) return null;

        $previousDay = (new DateTimeImmutable($boundary))->modify('-1 day')->format('Y-m-d');
        $previousStart = null;
        $newTermExists = false;
        foreach ($terms as $term) {
            if ($term['target_kind'] !== 'physical-object' || $term['amount'] === null) continue;
            if ($term['object_id'] === (int)$replacement['to'] && $term['start_date'] === $boundary
                && $term['end_date'] >= $boundary && $term['effective_date'] <= $boundary
                && ($term['superseded_on'] === null || $term['superseded_on'] >= $boundary)) $newTermExists = true;
            if ($term['object_id'] === (int)$replacement['from'] && $term['end_date'] === $previousDay
                && $term['start_date'] !== null && $term['start_date'] <= $previousDay
                && $term['effective_date'] <= $previousDay
                && ($term['superseded_on'] === null || $term['superseded_on'] >= $previousDay)) {
                $previousStart = $previousStart === null ? $term['start_date'] : min($previousStart,$term['start_date']);
            }
        }
        return $newTermExists ? $previousStart : null;
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

    public static function rentLines(array $source): array
    {
        return array_values(array_filter(array_merge($source['Товары'] ?? [], $source['Услуги'] ?? []), self::isRentLine(...)));
    }

    private static function isRentLine(array $line): bool
    {
        $description = (string)($line['Содержание'] ?? '');
        return (YakovlevInvoiceCoverage::hasRentLine(['Услуги'=>[$line]])
                || preg_match('/^\s*услуги\s+по\s+аренде\s+нежилых\s+помещений(?:\s|$)/iu',$description))
            && !preg_match('/(?<!\p{L})переменн\p{L}*\s+част\p{L}*/iu', $description);
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
            if (!self::isRentLine($line)) continue;
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
