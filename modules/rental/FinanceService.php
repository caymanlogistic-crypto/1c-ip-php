<?php
declare(strict_types = 1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
use App\Audit;
final class FinanceService {
    public static function remember(PDO $p, array $invoice, int $actor):void {
        $p->prepare('INSERT IGNORE INTO rental_invoice_versions(invoice_id,version,snapshot_json,actor_id) VALUES(?,?,?,?)')->execute([
            $invoice['id'],
            $invoice['version'],
            Importer::json($invoice),
            $actor
        ]);
    }
    private static function next(PDO $p, string $table):int {
        return \App\BusinessIds::next($p,$table,900000);
    }
    private static function changed(PDO $p, int $contract, int $invoice):void {
        $p->prepare('UPDATE invoices SET manual_review=1,control_closed=0 WHERE id=?')->execute([
            $invoice
        ]);
        $p->prepare('UPDATE contracts SET finance_review=0 WHERE id=?')->execute([
            $contract
        ]);
        $p->prepare('DELETE FROM rental_reviews WHERE contract_id=?')->execute([
            $contract
        ]);
    }
    public static function execute(PDO $p, array $u, string $action, array $in):array {
        $id = (int)($in['id'] ?? 0);
        $i = $action === 'invoice.issue'?null:Service::get($p, 'invoices', $id);
        if ($i)Service::version($i, $in);
        $c = Service::get($p, 'contracts', (int)($i['contract_id'] ?? $in['contract_id'] ?? 0));
        $raw = json_decode($c['source_json'], true);
        if (!empty($raw['imported']) && $c['status'] !== 'active') {
            $allowed=$action==='invoice.pay' && $i && NativeOperatingWriter::historicalPaymentAllowed($p,$i);
            if ($action==='invoice.edit-payment' && $i) {
                Service::roles(['accountant']);
                $allowed=NativeOperatingWriter::historicalCorrectionAllowed($p,$i,(int)$c['party_id'],(int)($in['payment_id'] ?? 0));
            }
            if (!$allowed) throw new RuntimeException('Исторический счёт доступен только для просмотра.');
        }
        if ($action === 'invoice.send') {
            $intent=InvoiceDelivery::prepare($p,$u,$i);
            $error=$intent['send_error'] ?? ($intent['status']==='unknown'
                ? 'Результат предыдущей отправки не подтверждён. Нужна сверка; повторная отправка не создана.' : null);
            $message=$error ?: 'Счёт поставлен в очередь. Отправка ещё не подтверждена.';
            Service::event($p,$u,$action,'Счёт '.$i['number'].': '.$message,(int)$c['id']);
            return ['id'=>$id,'invoice_id'=>$id,'intent'=>$intent,'message'=>$message,'send_error'=>$error];
        }
        if ($action === 'invoice.handed') {
            Service::roles(['manager','accountant']);
            $source=json_decode($i['source_json'],true) ?: [];
            $delivery=InvoiceDelivery::latest($p,$id);
            if ($i['status']==='revoked' || (empty($source['sendError']) && !in_array($delivery['status'] ?? '',['blocked','failed','unknown'],true))) {
                throw new RuntimeException('Личная передача подтверждается после ошибки отправки.');
            }
            if (empty($in['confirmed'])) throw new RuntimeException('Подтвердите передачу документов клиенту.');
            self::remember($p,$i,(int)$u['id']);
            $p->prepare('UPDATE invoices SET version=version+1 WHERE id=?')->execute([$id]);
            $order=$delivery ? (int)(json_decode($delivery['payload_json'],true)['delivery_order'] ?? 0) : 0;
            Audit::record($action,'invoice',$id,null,['invoice_version'=>(int)$i['version']+1,'handed_at'=>date(DATE_ATOM),'actor_id'=>$u['id'],'delivery_order'=>$order]);
            Service::event($p,$u,$action,'Документы по счёту '.$i['number'].' переданы клиенту',(int)$c['id']);
            return ['id'=>$id,'invoice_id'=>$id,'message'=>'Передача документов клиенту подтверждена.'];
        }
        if ($action === 'invoice.edit-due') {
            Service::roles(['accountant']);
            \App\Auth::requirePermission('finance.write');
            if($i['status']==='revoked')throw new RuntimeException('Отозванный счёт доступен только для просмотра.',409);
            $due=Service::date((string)($in['due'] ?? ''));
            $reason=trim((string)($in['reason'] ?? ''));
            if($reason==='')throw new RuntimeException('Укажите основание изменения срока оплаты.',422);
            self::remember($p,$i,(int)$u['id']);
            $p->prepare('UPDATE invoices SET due=?,version=version+1 WHERE id=?')->execute([$due,$id]);
            Audit::record($action,'invoice',$id,['due'=>$i['due'],'version'=>$i['version']],['due'=>$due,'version'=>(int)$i['version']+1,'reason'=>$reason]);
            Service::event($p,$u,$action,'Срок оплаты счёта '.$i['number'].': '.($i['due'] ?? 'не указан').' → '.$due.'; '.$reason,(int)$c['id']);
            return ['id'=>$id,'invoice_id'=>$id,'message'=>'Срок оплаты сохранён.'];
        }
        if (in_array($action, [
            'invoice.issue',
            'invoice.edit'
        ], true)) {
            Service::roles([
                'accountant'
            ]);
            if (!$i && !in_array($c['status'], [
                'approved',
                'sent',
                'active',
                'reconciling'
            ], true))throw new RuntimeException('Новый счёт доступен после согласования договора.');
            $number = trim($in['number'] ?? '');
            $title = trim($in['title'] ?? '');
            $purpose = trim($in['purpose'] ?? '');
            if (!$number || !$title || !$purpose)throw new RuntimeException('Заполните номер, наименование и назначение счёта.');
            $amount = Service::money($in['amount'] ?? '');
            if (bccomp($amount, '0', 2) <= 0)throw new RuntimeException('Сумма счёта должна быть больше нуля.');
            if ($i && $i['paid'] !== null && bccomp($amount, $i['paid'], 2)<0)throw new RuntimeException('Сумма счёта меньше уже внесённой оплаты.');
            $issued = Service::date($in['issued'] ?? '');
            $kind = trim($in['kind'] ?? $i['kind'] ?? 'Аренда');
            $initial = in_array($kind, [
                'Обеспечительный платёж',
                'Первый период'
            ], true);
            $period = $kind === 'Обеспечительный платёж'?'Обеспечение':($in['period'] ?? substr($issued, 0, 7));
            if ($kind !== 'Обеспечительный платёж')Service::date($period
            . '-01');
            if (!$i && in_array($kind, ['Аренда', 'Первый период'], true)) {
                HandoverQuestion::assertBillingReady($p, (int)$c['id'], $period);
            }
            $due = Service::date($in['due'] ?? InvoiceDueDate::automatic($issued,$period,$initial));
            if ($i) {
                self::remember($p, $i, (int)$u['id']);
                $write=static function() use($p,$number,$title,$purpose,$issued,$amount,$kind,$initial,$period,$due,$id): void {
                    $p->prepare('UPDATE invoices SET number=?,title=?,purpose=?,issued=?,amount=?,kind=?,initial_invoice=?,period=?,due=?,version=version+1 WHERE id=?')->execute([
                    $number,
                    $title,
                    $purpose,
                    $issued,
                    $amount,
                    $kind,
                    (int)$initial,
                    $period,
                    $due,
                    $id
                ]);
                };
                if ($issued!==$i['issued']) NativeOperatingWriter::changeDate($p,'invoice',$i,$issued,
                    ['number'=>$number,'title'=>$title,'purpose'=>$purpose,'issued'=>$issued,'amount'=>$amount,
                        'kind'=>$kind,'initial_invoice'=>(int)$initial,'period'=>$period,'due'=>$due],
                    (string)($in['command_key'] ?? 'invoice:'.$id.':'.$i['version']),(int)$u['id'],$write);
                else $write();
                if ($i['amount'] === null || bccomp($amount, $i['amount'], 2) !== 0)self::changed($p, (int)$c['id'], $id);
            }
            else {
                $expected = (int)($in['expected_new_id'] ?? $in['expectedNewId'] ?? 0);
                if (!$expected)throw new RuntimeException('Откройте новую форму счёта: отсутствует ожидаемый идентификатор.', 409);
                $q = $p->prepare('SELECT id FROM invoices WHERE coverage_key=? OR id=? LIMIT 1 FOR UPDATE');
                $q->execute([
                    'manual:'
                    . $expected,
                    $expected
                ]);
                if ($q->fetchColumn() || $expected !== self::next($p, 'invoices'))throw new RuntimeException('Список счетов изменился. Откройте новую форму. Повторный счёт не создан.', 409);
                $id = $expected;
                $creation=NativeOperatingWriter::begin($p,'invoice',$id,'native-c-manual','finance.invoice.create',
                    $issued,(string)($in['command_key'] ?? 'manual:'.$id),(int)$u['id']);
                $p->prepare("INSERT INTO invoices(id,number,contract_id,title,purpose,kind,period,amount,paid,issued,due,status,initial_invoice,coverage_key,source_json) VALUES(?,?,?,?,?,?,?,?,0,?,?,'active',?,?,'{}')")->execute([
                    $id,
                    $number,
                    $c['id'],
                    $title,
                    $purpose,
                    $kind,
                    $period,
                    $amount,
                    $issued,
                    $due,
                    (int)$initial,
                    'manual:'
                    . $id
                ]);
                Billing::snapshotInvoice($p, $id);
                NativeInvoiceScope::record($p,$id,'native-c-manual',$in['command_key'] ?? null);
                NativeOperatingWriter::finish($p,'invoice',$id,'native-c-manual',$creation);
            }
            Service::event($p, $u, $action, ($i?'Изменён':'Вручную создан')
            . ' счёт '
            . $number, (int)$c['id']);
            Audit::record($action, 'invoice', $id, $i, [
                'amount' => $amount,
                'number' => $number
            ]);
            return [
                'id' => $id,
                'invoice_id' => $id,
                'version' => $i ? (int)$i['version']+1 : 1,
                'message' => 'Счёт сохранён. Отправка — отдельная операция.'
            ];
        }
        if ($action === 'invoice.pay' || $action === 'invoice.edit-payment') {
            Service::roles([
                'accountant'
            ]);
            NativeOperatingWriter::requireObligation($p,$i,$action==='invoice.pay');
            if ($i['amount'] === null || $i['paid'] === null)throw new RuntimeException('Сумма или итог оплаты не подтверждены.');
            if ($i['status'] === 'revoked')throw new RuntimeException('Отозванный счёт доступен только для просмотра.');
            $a = Service::money($in['amount'] ?? '');
            if (bccomp($a, '0', 2) <= 0)throw new RuntimeException('Укажите положительную сумму оплаты.');
            $date = Service::date($in['payment_date'] ?? '');
            $payment = null;
            $allocation = '0.00';
            if ($action === 'invoice.edit-payment') {
                $payment = Service::get($p, 'payments', (int)$in['payment_id']);
                $q = $p->prepare('SELECT amount FROM payment_allocations WHERE payment_id=? AND invoice_id=? FOR UPDATE');
                $q->execute([
                    $payment['id'],
                    $id
                ]);
                $allocation = $q->fetchColumn();
                if ($allocation === false)throw new RuntimeException('Оплата не относится к этому счёту.');
                $q = $p->prepare('SELECT COUNT(*) FROM payment_allocations WHERE payment_id=?');
                $q->execute([
                    $payment['id']
                ]);
                if ((int)$q->fetchColumn() !== 1)throw new RuntimeException('Разделённый приход редактируется через отдельные распределения.');
            }
            $others = bcsub($i['paid'], $allocation, 2);
            if (bccomp(bcadd($others, $a, 2), $i['amount'], 2)>0)throw new RuntimeException('Сумма оплаты превышает остаток счёта.');
            if ($payment) {
                $paymentId = (int)$payment['id'];
                NativeOperatingWriter::requirePayment($p,$paymentId);
                $p->prepare('INSERT INTO rental_payment_versions(payment_id,version,amount,payment_date,actor_id) VALUES(?,?,?,?,?)')->execute([
                    $paymentId,
                    $payment['version'],
                    $payment['amount'],
                    $payment['payment_date'],
                    $u['id']
                ]);
                $write=static function() use($p,$a,$date,$u,$paymentId): void {
                    $p->prepare('UPDATE payments SET amount=?,payment_date=?,entered_by=?,version=version+1 WHERE id=?')->execute([
                    $a,
                    $date,
                    $u['name'],
                    $paymentId
                ]);
                };
                if ($date!==$payment['payment_date']) NativeOperatingWriter::changeDate($p,'payment',$payment,$date,
                    ['amount'=>$a,'payment_date'=>$date,'entered_by'=>$u['name']],
                    (string)($in['command_key'] ?? 'payment:'.$paymentId.':'.$payment['version']),(int)$u['id'],$write);
                else $write();
                NativeOperatingWriter::requirePayment($p,$paymentId);
                $p->prepare('UPDATE payment_allocations SET amount=? WHERE payment_id=? AND invoice_id=?')->execute([
                    $a,
                    $paymentId,
                    $id
                ]);
            }
            else {
                $paymentId = self::next($p, 'payments');
                $creation=NativeOperatingWriter::begin($p,'payment',$paymentId,'native-c-manual','finance.payment.create',
                    $date,(string)($in['command_key'] ?? 'payment:'.$paymentId),(int)$u['id']);
                $p->prepare("INSERT INTO payments(id,party_id,amount,payment_date,source,entered_by,source_json) VALUES(?,?,?,?,'Внесено бухгалтером',?,'{}')")->execute([
                    $paymentId,
                    $c['party_id'],
                    $a,
                    $date,
                    $u['name']
                ]);
                NativeOperatingWriter::finish($p,'payment',$paymentId,'native-c-manual',$creation);
                NativeOperatingWriter::requirePayment($p,$paymentId);
                $p->prepare('INSERT INTO payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)')->execute([
                    $paymentId,
                    $id,
                    $a
                ]);
            }
            $paid = bcadd($others, $a, 2);
            self::remember($p, $i, (int)$u['id']);
            $p->prepare('UPDATE invoices SET paid=?,version=version+1 WHERE id=?')->execute([
                $paid,
                $id
            ]);
            if ($payment && bccomp($paid, $i['paid'], 2) !== 0)self::changed($p, (int)$c['id'], $id);
            Evidence::activation($p, (int)$c['id']);
            Service::event($p, $u, $action, ($payment?'Исправлена':'Внесена')
            . ' оплата счёта '
            . $i['number']
            . ': '
            . $a
            . ' ₽', (int)$c['id']);
            return [
                'id' => $id,
                'invoice_id' => $id,
                'payment_id' => $paymentId,
                'message' => 'Оплата сохранена.'
            ];
        }
        if (in_array($action, [
            'invoice.freeze',
            'invoice.resume',
            'invoice.revoke'
        ], true)) {
            Service::roles([
                'manager',
                'accountant'
            ]);
            $reason = trim($in['reason'] ?? '');
            if (!$reason)throw new RuntimeException('Укажите причину.');
            if ($i['status'] === 'revoked')throw new RuntimeException('Отозванный счёт доступен только для просмотра.');
            $status = match ($action) {
                'invoice.freeze' => 'frozen', 'invoice.revoke' => 'revoked', default => 'active'
            };
            if ($i['status'] === $status)return [
                'id' => $id,
                'invoice_id' => $id,
                'message' => 'Состояние уже установлено.'
            ];
            self::remember($p, $i, (int)$u['id']);
            $p->prepare('UPDATE invoices SET status=?,version=version+1 WHERE id=?')->execute([
                $status,
                $id
            ]);
            Service::event($p, $u, $action, 'Счёт '
            . $i['number']
            . ': '
            . $status
            . '; '
            . $reason, (int)$c['id']);
            return [
                'id' => $id,
                'invoice_id' => $id,
                'message' => 'Состояние контроля сохранено.'
            ];
        }
        if ($action === 'invoice.confirm-review') {
            Service::roles([
                'accountant'
            ]);
            if (empty($in['confirmed']))throw new RuntimeException('Подтвердите проверку финансовых данных счёта.');
            self::remember($p, $i, (int)$u['id']);
            $p->prepare('UPDATE invoices SET manual_review=0,version=version+1 WHERE id=?')->execute([
                $id
            ]);
            $p->prepare("UPDATE rental_facts SET fact_json=JSON_SET(fact_json,'$.reviewRequired',false,'$.reviewedAt',?) WHERE namespace='calculation_review' AND source_key=?")->execute([
                date(DATE_ATOM),
                (string)$id
            ]);
            Service::event($p, $u, $action, 'Финансовые данные счёта '
            . $i['number']
            . ' проверены', (int)$c['id']);
            return [
                'id' => $id,
                'invoice_id' => $id,
                'message' => 'Результат проверки сохранён.'
            ];
        }
        throw new RuntimeException('Неизвестное действие со счётом.');
    }
}
