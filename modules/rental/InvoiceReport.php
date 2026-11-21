<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\View;
use PDO;

final class InvoiceReport
{
    public const ROUTE = '/rental/invoice-report';
    private const LABELS = ['office'=>'Офисы','apartment'=>'Апартаменты','parking'=>'Машино-места'];
    private const ZERO = ['net'=>'0.00','tax'=>'0.00','gross'=>'0.00'];

    public static function capture(PDO $db,int $id,array $charges): void
    {
        $invoice = Service::get($db,'invoices',$id);
        $proof = self::releaseProof($invoice,$charges);
        $db->prepare("UPDATE invoices SET source_json=JSON_SET(source_json,'$.regular_invoice.reportDetails',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json($proof),$id]);
    }

    private static function releaseProof(array $invoice,array $charges): array
    {
        $combined = InvoiceDocument::nativeBreakdown((string)$invoice['amount'],$invoice['vat']);
        if (!$combined) return ['reason'=>'В счёте при выпуске не зафиксирована подтверждённая ставка НДС.'];
        $lines = [];
        $tax = '0.00';
        $grossTotal = '0.00';
        foreach ($charges as $charge) {
            $gross = $charge['gross'];
            $amounts = InvoiceDocument::nativeBreakdown($gross,$invoice['vat']);
            $vat = $charge['tax'] ?? $amounts['tax'] ?? null;
            if ($vat === null || bccomp($vat,$gross,2)>0 || bccomp($vat,'0',2)<0)
                return ['reason'=>'Сверьте сумму НДС по подтверждённым месяцам выпуска.'];
            $net = bcsub($gross,$vat,2);
            $lines[] = [
                'description'=>'Аренда за '.$charge['period'],'unit'=>'мес','quantity'=>'1',
                'netPrice'=>$net,'netAmount'=>$net,'vatRate'=>$combined['vatRate'],'vatAmount'=>$vat,
                'rentalCategory'=>$charge['category'],'rentalPeriod'=>$charge['period'],
            ];
            $tax = bcadd($tax,$vat,2);
            $grossTotal = bcadd($grossTotal,$gross,2);
        }
        if (bccomp($grossTotal,$invoice['amount'],2)!==0 || bccomp($tax,$combined['tax'],2)!==0)
            return ['reason'=>'Разбивка округления по месяцам расходится с итогом счёта. Требуется сверить суммы строк.'];
        return ['number'=>$invoice['number'],'issued'=>$invoice['issued'],'lines'=>$lines];
    }

    public static function evidence(array $invoice): array
    {
        $stored = Importer::decodeExact($invoice['source_json'] ?? '{}');
        $native = $stored['regular_invoice'] ?? null;
        if (is_array($native)) {
            $print = $native['reportDetails'] ?? null;
            if (!is_array($print))
                return ['reason'=>'В прежнем снимке выпуска нет подтверждённых строк с предметом, месяцем и НДС.'];
            if (isset($print['reason'])) return ['reason'=>$print['reason']];
            if (($print['number'] ?? null) !== $invoice['number'] || ($print['issued'] ?? null) !== $invoice['issued'])
                return ['reason'=>'Номер или дата счёта расходятся со снимком выпуска.'];
        } elseif (!empty($stored['manual_regular_invoice'])) {
            return ['reason'=>'В ручном основании сохранена сумма, но нет подтверждённой разбивки по предмету и НДС.'];
        } else {
            $print = InvoiceDocument::capturedPrint($invoice);
        }
        if (!$print || empty($print['lines']))
            return ['reason'=>'Нужны оригинал счёта и подтверждённые строки начислений.'];
        foreach ((array)$print['lines'] as $raw) {
            if (!is_array($raw)) return ['reason'=>'Сверьте состав строк с оригиналом счёта.'];
            if (!isset($raw['vatRate'],$raw['vatAmount']))
                return ['reason'=>'Подтвердите ставку и сумму НДС по строкам оригинала.'];
        }
        $lines = InvoiceDocument::capturedLines($print);
        if (!$lines) return ['reason'=>'Сверьте строки, суммы и НДС с оригиналом счёта.'];
        $sum = '0.00';
        $rates = [];
        foreach ($lines as $line) {
            $sum = bcadd($sum,bcadd($line['net'],$line['tax'],2),2);
            $rates[$line['vatRate']] = true;
        }
        if ($invoice['amount'] === null || bccomp($sum,$invoice['amount'],2) !== 0)
            return ['reason'=>'Итог строк с НДС не совпадает с суммой счёта. Сверьте его с оригиналом.'];
        if ($invoice['vat'] !== null && count($rates) === 1 && bccomp($invoice['vat'],(string)key($rates),4) !== 0)
            return ['reason'=>'Ставка НДС в счёте расходится со строками оригинала.'];
        foreach ($lines as $index=>&$line) {
            $raw = $print['lines'][$index];
            $kind = $raw['rentalCategory'] ?? null;
            if (!is_string($kind) || !isset(self::LABELS[$kind]))
                return ['reason'=>'Подтвердите предмет аренды каждой строки по счёту и подписанному договору или ДС.'];
            $period = InvoicePeriods::month($raw['rentalPeriod'] ?? $print['rentalPeriod'] ?? null);
            if (!$period)
                return ['reason'=>'Подтвердите месяц начисления по каждой строке исходного счёта.'];
            $line += ['category'=>$kind,'period'=>$period,'gross'=>bcadd($line['net'],$line['tax'],2)];
        }
        unset($line);
        return ['lines'=>$lines];
    }

    public static function summarize(array $invoices,string $month): array
    {
        $groups = array_fill_keys(array_keys(self::LABELS),self::ZERO);
        $total = self::ZERO;
        $included = [];
        $excluded = [];
        $seen = [];
        foreach ($invoices as $invoice) {
            $id = (int)$invoice['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $proof = self::evidence($invoice);
            if (isset($proof['reason'])) {
                $excluded[] = ['invoice'=>$invoice,'reason'=>$proof['reason']];
                continue;
            }
            $selected = array_filter($proof['lines'],static fn(array $line): bool=>$line['period'] === $month);
            if (!$selected) {
                $excluded[] = ['invoice'=>$invoice,'reason'=>'В подтверждённых строках нет начислений за выбранный месяц.'];
                continue;
            }
            foreach ($selected as $line) {
                $category = $line['category'];
                foreach (array_keys(self::ZERO) as $key) {
                    $groups[$category][$key] = bcadd($groups[$category][$key],$line[$key],2);
                    $total[$key] = bcadd($total[$key],$line[$key],2);
                }
            }
            $included[] = ['invoice'=>$invoice,'lines'=>$selected];
        }
        return compact('groups','total','included','excluded');
    }

    public static function invoiceEvidence(array $invoice): string
    {
        $proof = self::evidence($invoice);
        if (empty($proof['lines'])) return '';
        $rows = [];
        foreach ($proof['lines'] as $line) {
            $rate = rtrim(rtrim($line['vatRate'],'0'),'.');
            $rows[] = [View::e($line['description']),View::e($line['period']),View::e(self::LABELS[$line['category']]),
                Controller::money($line['net']),Controller::money($line['tax']).' · '.View::e($rate).'%',
                Controller::money($line['gross'])];
        }
        return '<h3 class="registry-ui-modal-summary">Подтверждённые строки счёта</h3>'
            .Controller::table(['Начисление','Период аренды','Предмет аренды','Без НДС','НДС','С НДС'],$rows,
                ['text','code','text','money','money','money']);
    }

    public static function page(): string
    {
        Access::pages(['invoice-list']);
        $month = InvoicePeriods::month($_GET['rental_month'] ?? date('Y-m'));
        if ($month === '') $month = date('Y-m');
        $invoices = Controller::query("SELECT i.* FROM invoices i WHERE i.status<>'revoked' AND "
            .InvoicePeriods::rentalPredicate().' ORDER BY i.issued,i.id',InvoicePeriods::rentalParameters($month));
        $report = self::summarize($invoices,$month);
        $body = '<form class="registry-ui-modal-grid" method="get" action="'.self::ROUTE.'">'
            .Controller::input('rental_month','Период аренды',$month,'month',true)
            .'<button type="submit" class="btn btn-secondary">Показать отчёт</button></form>';
        $totals = [];
        foreach ($report['groups'] as $category=>$values) $totals[] = [View::e(self::LABELS[$category]),...self::money($values)];
        $totals[] = ['<strong>Итого по подтверждённым строкам</strong>',...self::money($report['total'])];
        $body .= Controller::table(['Предмет аренды','Без НДС','НДС','С НДС'],$totals,['text','money','money','money']);
        if ($report['included']) {
            $rows = [];
            foreach ($report['included'] as $item) {
                foreach ($item['lines'] as $line) $rows[] = [self::link($item['invoice']),View::e(self::LABELS[$line['category']]),
                    ...self::money($line)];
            }
            $body .= '<h3 class="registry-ui-modal-summary">Строки в отчёте</h3>'
                .Controller::table(['Счёт','Предмет аренды','Без НДС','НДС','С НДС'],$rows,['text','text','money','money','money']);
        }
        if ($report['excluded']) {
            $rows = [];
            foreach ($report['excluded'] as $item) $rows[] = [self::link($item['invoice']),View::e($item['reason'])];
            $body .= '<h3 class="registry-ui-modal-summary">Требуют сверки · '.count($rows).'</h3>'
                .Controller::table(['Счёт','Что требуется'],$rows,['text','text']);
        }
        return RentalUI::modal('Отчёт по начислениям за аренду',$body,'','invoice-list',wide:true,extractForms:false);
    }

    private static function money(array $amounts): array
    {
        return [Controller::money($amounts['net']),Controller::money($amounts['tax']),Controller::money($amounts['gross'])];
    }

    private static function link(array $invoice): string
    {
        return RentalUI::button($invoice['number'],'/rental/invoice?id='.$invoice['id']);
    }
}
