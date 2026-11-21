<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BusinessIds, DB, OperatingMembership};
use Modules\Integrations\{Config, Support};
use PDO;
use RuntimeException;

final class SavedReceipt408
{
    public const ACTION = 'payment.reconcile-saved-408';
    private const MARKER = 'saved_yakovlev_408';
    private const CONTRACT = 1037;
    private const OBJECT = 1076;
    private const INVOICE = 971474;
    private const PARTY = 1009;
    private const PAYMENT_DATE = '2026-10-30';
    private const INVOICE_DATE = '2026-10-15';
    private const AMOUNT = '263876.76';
    private const BANK_NUMBER = 'АЯИП-001573';
    private const INVOICE_NUMBER = 'АЯИП-001147';

    public static function requireOperator(): void
    {
        if (PHP_SAPI !== 'cli' || (Auth::user()['role'] ?? null) !== 'admin') {
            throw new RuntimeException('Сверка сохранённого прихода доступна администратору через CLI.', 403);
        }
        if (OperatingMembership::selected() !== null) {
            throw new RuntimeException('Для текущего режима учёта нужен отдельный допуск к переносу сохранённого прихода.', 409);
        }
    }

    public static function managed(array $payment): bool
    {
        $source = json_decode($payment['source_json'] ?? '{}', true);
        return is_array($source) && isset($source[self::MARKER]);
    }

    public static function rejectManualChange(array $payment): void
    {
        if (self::managed($payment)) {
            throw new RuntimeException('Приход из сверенной выписки меняется только отдельной командой сверки.', 409);
        }
    }

    private static function rows(PDO $db, string $sql, array $args = []): array
    {
        $query = $db->prepare($sql);
        $query->execute($args);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function digest(array $value): string
    {
        return hash('sha256', Importer::canonicalJson($value));
    }

    private static function document(PDO $db, string $account, string $date, string $number, string $currency): array
    {
        $rows = self::rows($db, 'SELECT * FROM external_documents WHERE provider=? AND account_id=? AND document_date=?
            AND number=? FOR UPDATE', ['onec-yakovlev',$account,$date,$number]);
        $row = $rows[0] ?? [];
        if (count($rows) !== 1 || $row['status'] !== 'Проведён'
            || Support::money($row['amount']) !== self::AMOUNT.'000000') {
            throw new RuntimeException('Сохранённый документ '.$number.' требует сверки.', 409);
        }
        $exact = Config::store()->read($row['raw_path'], $row['raw_sha256']);
        $original = Support::decode($exact);
        $record = $original['fields']['record'] ?? [];
        $ref = (string)($record['Ref_Key'] ?? '');
        if (($original['kind'] ?? null) !== $account || !self::guid($ref) || $row['source_id'] !== $account.':'.$ref
            || ($record['Number'] ?? null) !== $number || substr((string)($record['Date'] ?? ''), 0, 10) !== $date
            || ($record['Posted'] ?? null) !== true || ($record['DeletionMark'] ?? null) !== false
            || ($record['ВалютаДокумента_Key'] ?? null) !== $currency
            || Support::money($record['СуммаДокумента'] ?? null) !== self::AMOUNT.'000000') {
            throw new RuntimeException('Оригинал '.$number.' не подтверждает дату, валюту, проведение и сумму.', 409);
        }
        return [$row['id'], $row['raw_sha256'], $record];
    }

    private static function guid(string $value): bool
    {
        return $value !== '00000000-0000-0000-0000-000000000000'
            && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    private static function source(PDO $db, string $owner): array
    {
        // Use the already reconciled October installment of the same ruble lease to identify the currency in 1C.
        $prior = self::rows($db, "SELECT JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.sourceCurrencyRef')) currency
            FROM payments p JOIN payment_allocations a ON a.payment_id=p.id JOIN invoices i ON i.id=a.invoice_id
            WHERE i.number='АЯИП-001044' AND i.contract_id=? AND i.period='2026-10' AND i.amount=a.amount
            AND i.paid=a.amount AND p.party_id=? FOR UPDATE", [self::CONTRACT,self::PARTY]);
        $currency = (string)($prior[0]['currency'] ?? '');
        if (count($prior) !== 1 || !self::guid($currency)) throw new RuntimeException('Валюта договора не подтверждена.', 409);
        [$bankId, $bankSha, $bank] = self::document($db, 'bank_in', self::PAYMENT_DATE, self::BANK_NUMBER, $currency);
        [$invoiceId, $invoiceSha, $invoice] = self::document($db, 'invoices', self::INVOICE_DATE, self::INVOICE_NUMBER, $currency);
        $bankRef = $bank['Ref_Key'];
        $invoiceRef = $invoice['Ref_Key'];
        $legal = $invoice['ДоговорКонтрагента_Key'] ?? '';
        $line = $bank['РасшифровкаПлатежа'][0] ?? [];
        $bankOrder = trim((string)($bank['НомерВходящегоДокумента'] ?? ''));
        $purpose = $bank['НазначениеПлатежа'] ?? '';
        $description = $invoice['Товары'][0]['Содержание'] ?? '';
        $basis = '/Договору аренды нежилых помещений №128 от 24\.11\.2025г?\.?\s+за период с 01 по 28 ноября 2026/u';
        $links = self::rows($db, "SELECT entity_type,entity_id,relation_kind FROM rental_source_bindings
            WHERE source_id IN (?,?) AND relation_kind IN ('rental_contract','invoice_control') ORDER BY entity_type,entity_id FOR UPDATE",
            [$legal, $invoiceRef]);
        if ($owner === '' || ($bank['Контрагент'] ?? null) !== $owner || ($invoice['Контрагент_Key'] ?? null) !== $owner
            || $legal === '' || ($bank['ДоговорКонтрагента_Key'] ?? null) !== $legal
            || empty($invoice['Организация_Key']) || ($bank['Организация_Key'] ?? null) !== $invoice['Организация_Key']
            || empty($bank['СчетОрганизации_Key']) || ($bank['ВидОперации'] ?? null) !== 'ОплатаПокупателя'
            || $bankOrder === ''
            || substr((string)($bank['ДатаВходящегоДокумента'] ?? ''), 0, 10) !== self::PAYMENT_DATE
            || count($bank['РасшифровкаПлатежа'] ?? []) !== 1 || count($invoice['Товары'] ?? []) !== 1
            || ($line['СчетНаОплату_Key'] ?? null) !== $invoiceRef || ($line['ДоговорКонтрагента_Key'] ?? null) !== $legal
            || Support::money($line['СуммаПлатежа'] ?? null) !== self::AMOUNT.'000000'
            || Support::money($line['СуммаВзаиморасчетов'] ?? null) !== self::AMOUNT.'000000'
            || Support::money($line['СуммаВозврата'] ?? null) !== '0.00000000'
            || !is_string($purpose) || !preg_match($basis, $purpose) || !is_string($description) || !preg_match($basis, $description)
            || array_map(static fn(array $v): array=>[$v['entity_type'],(int)$v['entity_id'],$v['relation_kind']], $links)
                !== [['contract',self::CONTRACT,'rental_contract'],['invoice',self::INVOICE,'invoice_control']]) {
            throw new RuntimeException('Оригиналы и учётные связи не подтверждают поступление по №128 и счёту АЯИП-001147.', 409);
        }
        return ['bank_document' => $bankId, 'bank_number' => self::BANK_NUMBER, 'bank_sha256' => $bankSha, 'bank_ref' => $bankRef,
            'invoice_document' => $invoiceId, 'invoice_sha256' => $invoiceSha, 'invoice_ref' => $invoiceRef, 'bank_purpose' => $purpose,
            'line' => (string)$line['LineNumber'], 'bank_order_number' => $bankOrder, 'issued' => self::INVOICE_DATE, 'payment_date' => self::PAYMENT_DATE,
            'currency' => 'RUB', 'amount' => self::AMOUNT, 'period_start' => '2026-11-01', 'period_end' => '2026-11-28'];
    }

    private static function state(PDO $db, string $undoOf): array
    {
        $contract = Service::get($db, 'contracts', self::CONTRACT);
        $invoice = Service::get($db, 'invoices', self::INVOICE);
        $party = Service::get($db, 'parties', self::PARTY);
        $ownerRef = Importer::decodeExact($party['source_json'])['source_party_guid'] ?? null;
        $objects = self::rows($db, 'SELECT object_id FROM contract_objects WHERE contract_id=? FOR UPDATE', [self::CONTRACT]);
        $scope = self::rows($db, 'SELECT io.object_id,o.name,o.floor,ic.start_date,ic.end_date FROM invoice_objects io
            JOIN rental_objects o ON o.id=io.object_id LEFT JOIN invoice_coverage ic ON ic.invoice_id=io.invoice_id
            AND ic.object_id=o.id WHERE io.invoice_id=? FOR UPDATE', [self::INVOICE]);
        $period = $scope[0] ?? [];
        if ($contract['number'] !== '128' || $contract['document_date'] !== '2025-11-24' || $contract['status'] !== 'active'
            || (int)$contract['party_id'] !== self::PARTY || array_map('intval', array_column($objects,'object_id')) !== [self::OBJECT]
            || empty($ownerRef) || $ownerRef !== (Importer::decodeExact($contract['source_json'])['sourceLegalMapping']['party1CRef_Key'] ?? null)
            || $invoice['number'] !== self::INVOICE_NUMBER || (int)$invoice['contract_id'] !== self::CONTRACT
            || $invoice['issued'] !== self::INVOICE_DATE || $invoice['status'] !== 'active' || $invoice['kind'] !== 'Аренда'
            || $invoice['period'] !== '2026-11' || $invoice['amount'] !== self::AMOUNT || count($scope) !== 1
            || (int)$period['object_id'] !== self::OBJECT || $period['name'] !== '408' || $period['floor'] !== '4'
            || $period['start_date'] !== '2026-11-01' || $period['end_date'] !== '2026-11-28') {
            throw new RuntimeException('Договор, объект или счёт отличаются от согласованного основания.', 409);
        }

        $evidence = self::source($db, $ownerRef);
        $bankRef = $evidence['bank_ref'];
        $like = '%'.$bankRef.'%';
        $payments = self::rows($db, "SELECT * FROM payments WHERE CAST(source_json AS CHAR) LIKE ?
            OR (payment_date=? AND amount=?) ORDER BY id FOR UPDATE", [$like,self::PAYMENT_DATE,self::AMOUNT]);
        $bindings = self::rows($db, "SELECT entity_id FROM rental_source_bindings WHERE entity_type='payment' AND source_id LIKE ? FOR UPDATE", [$like]);
        $imports = self::rows($db, "SELECT id FROM rental_facts WHERE namespace='rental_bank_payment_identity' AND CAST(fact_json AS CHAR) LIKE ? FOR UPDATE", [$like]);
        $native = self::rows($db, 'SELECT id FROM integration_native_bindings WHERE native_key=? FOR UPDATE', [$bankRef]);
        $history = self::rows($db, 'SELECT payment_id FROM rental_payment_versions WHERE payment_date=? AND amount=? FOR UPDATE',
            [self::PAYMENT_DATE,self::AMOUNT]);
        if ($bindings || $imports || $native || $history) throw new RuntimeException('Есть привязка или прежняя версия похожего прихода. Нужна сверка.', 409);
        $payment = count($payments) === 1 && self::managed($payments[0]) ? $payments[0] : null;
        if ($payments && !$payment) throw new RuntimeException('Найден существующий либо похожий приход. Требуется сверка источника и остатка.', 409);
        $book = self::rows($db, "SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE", [self::MARKER,$bankRef]);
        $recorded = Importer::decodeExact($book[0]['fact_json'] ?? '{}');
        if (($recorded['payment_id'] ?? null) !== ($payment ? (int)$payment['id'] : null)
            || ($payment && (self::digest(Importer::decodeExact($payment['source_json'])[self::MARKER]) !== self::digest($evidence)
                || $payment['amount'] !== self::AMOUNT || $payment['payment_date'] !== self::PAYMENT_DATE || (int)$payment['party_id'] !== self::PARTY))) {
            throw new RuntimeException('Существующий приход или его источник изменились.', 409);
        }

        $allocations = self::rows($db, 'SELECT * FROM payment_allocations WHERE invoice_id=? OR payment_id=?
            ORDER BY payment_id,invoice_id FOR UPDATE', [self::INVOICE,$payment['id'] ?? 0]);
        if ($undoOf === '') {
            NativeOperatingWriter::requireObligation($db,$invoice);
            if ($allocations || $invoice['paid'] !== '0.00' || SecurityDeposit::payable($db,$invoice,'0.00') !== self::AMOUNT) {
                throw new RuntimeException('Сумма к оплате или распределение изменились. Нужна сверка.', 409);
            }
        } else {
            $receipt = self::rows($db, 'SELECT result_json FROM rental_commands WHERE action=? AND command_key=?', [self::ACTION,$undoOf]);
            $prior = Importer::decodeExact($receipt[0]['result_json'] ?? '{}');
            $a = $allocations[0] ?? [];
            if (!$payment || ($prior['undo_of'] ?? null) !== '' || ($recorded['last_command'] ?? null) !== $undoOf
                || ($prior['invoice_after'] ?? null) !== self::digest($invoice)
                || ($prior['payment_after'] ?? null) !== self::digest($payment)
                || count($allocations) !== 1 || (int)$a['payment_id'] !== (int)$payment['id']
                || (int)$a['invoice_id'] !== self::INVOICE || $a['amount'] !== self::AMOUNT || $invoice['paid'] !== self::AMOUNT) {
                throw new RuntimeException('Нельзя снять это распределение: исходная квитанция или текущие записи изменились.', 409);
            }
        }
        $epoch = $db->query('SELECT epoch FROM rental_runtime_context WHERE id=1 FOR UPDATE')->fetchColumn();
        return ['epoch'=>$epoch,'contract'=>$contract,'invoice'=>$invoice,'owner_ref'=>$ownerRef,'scope'=>$scope,'payment'=>$payment,
            'evidence'=>$evidence,'booking'=>$recorded,'allocations'=>$allocations];
    }

    private static function preview(string $key, string $undoOf, array $state): array
    {
        $evidence = $state['evidence'];
        unset($evidence['bank_purpose']);
        return ['command_key'=>$key,'undo_of'=>$undoOf,'state_sha256'=>self::digest($state),
            'invoice_id'=>self::INVOICE,'invoice_number'=>self::INVOICE_NUMBER,'invoice_version'=>(int)$state['invoice']['version'],
            'contract_number'=>'128','object'=>'408','evidence'=>$evidence,'existing_payment_id'=>$state['payment']['id'] ?? null,
            'paid_before'=>$state['invoice']['paid'],'paid_after'=>$undoOf === '' ? self::AMOUNT : '0.00'];
    }

    public static function plan(string $key, string $undoOf = ''): array
    {
        self::requireOperator();
        return DB::transaction(static function(PDO $db) use($key,$undoOf): array {
            $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            return self::preview($key,$undoOf,self::state($db,$undoOf));
        });
    }

    public static function execute(PDO $db, array $actor, array $plan): array
    {
        self::requireOperator();
        $key = (string)$plan['command_key'];
        $undoOf = (string)($plan['undo_of'] ?? '');
        $state = self::state($db,$undoOf);
        if (Importer::canonicalJson(self::preview($key,$undoOf,$state)) !== Importer::canonicalJson($plan)) {
            throw new RuntimeException('Данные изменились после предпросмотра. Подготовьте новый план.', 409);
        }
        $paymentId = (int)($state['payment']['id'] ?? 0);
        $bankRef = $state['evidence']['bank_ref'];
        if (!$paymentId) {
            $paymentId = BusinessIds::next($db,'payments',9000000,false);
            $source = [self::MARKER=>$state['evidence'],'bank_source'=>'onec-yakovlev','bank_external_id'=>$bankRef,
                'bankPurpose'=>$state['evidence']['bank_purpose']];
            $db->prepare('INSERT INTO payments(id,party_id,amount,payment_date,source,entered_by,source_json) VALUES(?,?,?,?,?,?,?)')
                ->execute([$paymentId,self::PARTY,self::AMOUNT,self::PAYMENT_DATE,'1С: банковская выписка, '.self::BANK_NUMBER,$actor['name'],Importer::json($source)]);
        }
        NativeOperatingWriter::requirePayment($db,$paymentId);
        if ($undoOf === '') {
            $db->prepare('INSERT INTO payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)')->execute([$paymentId,self::INVOICE,self::AMOUNT]);
        } else {
            $db->prepare('DELETE FROM payment_allocations WHERE payment_id=? AND invoice_id=?')->execute([$paymentId,self::INVOICE]);
        }
        FinanceService::remember($db,$state['invoice'],(int)$actor['id']);
        $db->prepare('UPDATE invoices SET paid=?,version=version+1 WHERE id=?')->execute([$plan['paid_after'],self::INVOICE]);
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute([self::MARKER,$bankRef,Importer::json(['payment_id'=>$paymentId,'last_command'=>$key])]);
        $result = $plan+['payment_id'=>$paymentId,'invoice_after'=>self::digest(Service::get($db,'invoices',self::INVOICE)),
            'payment_after'=>self::digest(Service::get($db,'payments',$paymentId))];
        Service::event($db,$actor,self::ACTION,($undoOf === '' ? 'Учтён и распределён приход ' : 'Снято распределение прихода ')
            .self::BANK_NUMBER.' на счёт '.self::INVOICE_NUMBER, self::CONTRACT,$result);
        return $result;
    }
}
