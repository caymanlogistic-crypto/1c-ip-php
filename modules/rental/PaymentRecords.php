<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,View};
use RuntimeException;

final class PaymentRecords
{
    public static function purpose(array $payment): ?string
    {
        $source = Importer::decodeExact($payment['source_json']);
        foreach (['bankPurpose','bank_purpose','НазначениеПлатежа','НазначениеПлатежаСтрокой'] as $field) {
            $value = $source[$field] ?? null;
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }

    public static function allocations(int $id): array
    {
        return Controller::query('SELECT a.invoice_id,a.amount,i.number,i.issued,i.period,c.number contract_number
            FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id JOIN contracts c ON c.id=i.contract_id
            WHERE a.payment_id=? ORDER BY i.issued,i.id',[$id]);
    }

    public static function history(int $id): string
    {
        $versions = Controller::query('SELECT v.version,v.amount,v.payment_date,v.recorded_at,
            u.name actor_name FROM rental_payment_versions v LEFT JOIN users u ON u.id=v.actor_id
            WHERE v.payment_id=? ORDER BY v.version DESC',[$id]);
        $body = '<h3 class="registry-ui-modal-summary">История оплаты</h3>';
        if (!$versions) return $body.'<p class="registry-ui-secondary">Предыдущих версий оплаты нет в сохранённых данных.</p>';
        $rows = [];
        foreach ($versions as $version) {
            $rows[] = [View::e($version['version']),Documents::dateText($version['payment_date']),
                Controller::money($version['amount']),View::e($version['actor_name'] ?? 'Не указан'),View::e($version['recorded_at'])];
        }
        return $body.Controller::table(['Версия','Дата оплаты','Сумма','Кто изменил','Изменено'], $rows,
            ['short','date','money','text','text']);
    }

    public static function orders(int $id,bool $allocated): string
    {
        $actor = Auth::user();
        if (!$allocated && (!Auth::can('finance.write') || !in_array($actor['role'],['admin','accountant'],true)))
            return '<p class="registry-ui-secondary">Платёжное поручение нераспределённого прихода доступно бухгалтеру.</p>';
        $files = Controller::query("SELECT * FROM rental_files WHERE entity_type='payment' AND entity_id=? AND kind='payment-order'
            ORDER BY upload_order DESC,created_at DESC,id",[$id]);
        if (!$files) return '<p class="registry-ui-secondary">Файл платёжного поручения отсутствует.</p>';
        $body = '';
        foreach ($files as $file) {
            try {
                Evidence::integrity($file);
                $body .= '<div class="demo-row">'.Controller::link('/rental/download?id='.$file['id'],
                    'Скачать платёжное поручение · '.$file['filename']).'</div>';
            } catch (RuntimeException $error) {
                $body .= RentalUI::alert('Платёжное поручение недоступно',
                    $file['filename'].' · файл отсутствует или не совпадает с сохранённым оригиналом.','warning');
            }
        }
        return $body;
    }

    public static function listing(string $party, string $month, string $query, int $contract, ?array $onlyInvoices): string
    {
        $params = [];
        $where = [];
        if ($party !== 'all') {
            $where[] = 'p.party_id=?';
            $params[] = $party;
        }
        if ($month !== '') {
            [$from,$until] = InvoicePeriods::bounds($month);
            $where[] = 'p.payment_date BETWEEN ? AND ?';
            array_push($params,$from,$until);
        }
        if ($contract) {
            $where[] = 'EXISTS (SELECT 1 FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id
                WHERE a.payment_id=p.id AND i.contract_id=?)';
            $params[] = $contract;
        }
        $payments = Controller::query('SELECT p.*,b.name party_name,COALESCE(a.allocated,0) allocated
            FROM payments p LEFT JOIN parties b ON b.id=p.party_id
            LEFT JOIN (SELECT payment_id,SUM(amount) allocated FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id'
            .($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY p.payment_date DESC,p.id DESC',$params);
        $allocationRows = Controller::query('SELECT a.payment_id,a.invoice_id,i.number FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id
            ORDER BY a.payment_id,i.number,i.id');
        $byPayment = [];
        foreach ($allocationRows as $row) $byPayment[$row['payment_id']][] = $row;
        $tokens = preg_split('/\s+/u',mb_strtolower(trim($query)),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $rows = [];
        foreach ($payments as $payment) {
            $allocations = $byPayment[$payment['id']] ?? [];
            if ($onlyInvoices !== null && !array_intersect(array_column($allocations,'invoice_id'),$onlyInvoices)) continue;
            $source = self::purpose($payment);
            $text = mb_strtolower(implode(' ',[$payment['id'],$payment['party_name'],$payment['payment_date'],
                Documents::dateText($payment['payment_date']),$payment['source'],$source,implode(' ',array_column($allocations,'number'))]));
            foreach ($tokens as $token) if (!str_contains($text,$token)) continue 2;
            $remaining = $payment['amount'] === null ? null : bcsub($payment['amount'],$payment['allocated'],2);
            $rows[] = [
                RentalUI::button(Documents::dateText($payment['payment_date']).' · № '.$payment['id'],'/rental/payment?id='.$payment['id']),
                View::e($payment['party_name'] ?? 'Контрагент не указан'),Controller::money($payment['amount']),
                Controller::money($payment['allocated']),Controller::money($remaining),
                View::e(implode(', ',array_column($allocations,'number')) ?: 'Без распределения'),
            ];
        }
        return '<h3 class="registry-ui-modal-summary">Поступления</h3>'.($rows
            ? Controller::table(['Оплата','Контрагент','Поступило','Распределено','Остаток','Счета'],$rows,
                ['text','text','money','money','money','text'])
            : '<p class="registry-ui-secondary">По заданным условиям поступления не найдены.</p>');
    }
}
