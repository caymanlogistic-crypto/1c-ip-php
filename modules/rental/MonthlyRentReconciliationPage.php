<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, View};
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Modules\Integrations\Config;
use RuntimeException;

final class MonthlyRentReconciliationPage
{
    public const ROUTE = '/rental/monthly-reconciliation';
    private const TITLE = 'Помесячная сверка аренды';

    public static function available(array $user): bool
    {
        return in_array($user['role'] ?? '', ['accountant', 'admin'], true);
    }

    public static function page(): string
    {
        Access::pages(['invoice-list']);
        if (!self::available(Auth::user() ?? [])) throw new RuntimeException('Сверка доступна бухгалтеру и администратору.', 403);
        $current = new DateTimeImmutable('first day of this month', new DateTimeZone('Europe/Moscow'));
        $first = $_GET['first'] ?? $current->modify('-1 month')->format('Y-m');
        $last = $_GET['last'] ?? $current->modify('+1 month')->format('Y-m');
        $first = is_string($first) ? $first : '';
        $last = is_string($last) ? $last : '';
        $tab = ($_GET['flag'] ?? '') === 'review' ? 'review' : 'main';
        $body = Controller::heading(self::TITLE, 'План по договорам, счета из 1С и сроки оплаты.',
            '<a class="btn btn-secondary" href="/rental/invoice-list">Счета и оплаты</a>');
        try {
            $months = MonthlyRentReconciliation::range($first, $last);
        } catch (RuntimeException $error) {
            return View::page(self::TITLE, $body.self::tabs($tab,$first,$last,0,0)
                .'<section class="table-card registry-ui-card">'.self::filters($tab,$first,$last)
                .'<section class="panel-section">'
                .RentalUI::alert($error->getMessage(), '', 'error').'</section></section>', 'invoice-list');
        }
        $report = (new MonthlyRentReconciliation(DB::pdo(), Config::store()))->capture($months);
        $body .= self::tabs($tab,$first,$last,count($months),count($report['issues']))
            .'<section class="table-card registry-ui-card">'.self::filters($tab,$first,$last,$report['archive']);
        $body .= $tab === 'review' ? self::reviewTable($report['issues'])
            : self::monthlyTable($report['months'], $report['invoice_facts']);
        return View::page(self::TITLE, $body.'</section>', 'invoice-list');
    }

    private static function tabs(string $tab, string $first, string $last, int $months, int $issues): string
    {
        return Controller::tabs(['main'=>['Основное',$months], 'review'=>['Требует сверки',$issues]],
            $tab, context:['first'=>$first,'last'=>$last]);
    }

    private static function filters(string $tab, string $first, string $last, array $archive = []): string
    {
        $summary = $archive ? '<div class="found-label registry-ui-toolbar-summary">'
            .'Загрузка 1С (МСК) · счета '.self::archiveTime($archive['invoices'], 'ожидаются')
            .' · банк '.self::archiveTime($archive['bank'], 'ожидается').'</div>' : '';
        return '<form class="table-toolbar registry-ui-toolbar" method="get" action="'.self::ROUTE.'">'
            .'<input type="hidden" name="flag" value="'.View::e($tab).'">'.$summary
            .'<div class="toolbar-right">'.Controller::input('first', 'Первый месяц', $first, 'month')
            .Controller::input('last', 'Последний месяц', $last, 'month')
            .'<button class="btn btn-primary" type="submit">Показать</button></div></form>';
    }

    private static function reviewTable(array $issues): string
    {
        if (!$issues) return '<section class="panel-section">'.RentalUI::alert('За выбранный период вопросов на сверку нет.').'</section>';
        $rows = [];
        foreach ($issues as [$periods,$client,$document,$reason]) {
            $months = array_map(self::monthLabel(...), explode(', ', $periods));
            $rows[] = array_map(View::e(...), [implode(', ', $months),$client,$document,$reason]);
        }
        return Controller::table(['Месяц аренды','Клиент','Документ','Что уточнить'],
            $rows, ['text','flex','code','flex']);
    }

    private static function monthlyTable(array $months, array $invoiceFacts): string
    {
        $rows = $details = [];
        foreach ($months as $month => $clients) {
            $plans = $billed = $due = [];
            foreach ($clients as $client) {
                array_push($plans, ...array_values($client['planned']));
                array_push($billed, ...array_column($client['billed'], 'amount'));
                array_push($due, ...$client['due']);
            }
            $index = count($rows);
            $label = self::monthLabel($month);
            $rows[] = ['<strong>'.View::e($label).'</strong>', ...self::summary($plans, $billed, $due)];
            if ($clients) {
                $details[$index] = self::details('reconciliation-'.$month, 'Клиенты · '.$label, self::clients($month, $clients, $invoiceFacts));
            }
        }
        return Controller::table(['Месяц','По договорам','Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['text','money','money','money','money','money'], $details);
    }

    private static function clients(string $month, array $clients, array $invoiceFacts): string
    {
        $rows = $details = [];
        foreach ($clients as $id => $client) {
            $index = count($rows);
            $rows[] = [View::e($client['name']), ...self::summary($client['planned'],
                array_column($client['billed'], 'amount'), $client['due'])];
            $key = 'reconciliation-'.$month.'-'.$id;
            $details[$index] = self::details($key, 'Счета · '.$client['name'], self::clientDetails($key, $client, $invoiceFacts));
        }
        return Controller::table(['Клиент','По договорам','Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['flex','money','money','money','money','money'], $details);
    }

    private static function clientDetails(string $key, array $client, array $invoiceFacts): string
    {
        $body = '';
        if ($client['planned'] || $client['billed']) {
            $body .= self::heading('Аренда за месяц').self::contracts($key, $client, $invoiceFacts);
        }
        if ($client['due']) {
            $rows = [];
            foreach ($client['due'] as $invoice) {
                $state = $invoice['overdue'] ? 'Просрочка подтверждена' : ($invoice['verified'] ? 'Связан с 1С' : 'Сверить связь с 1С');
                $rows[] = [Controller::link('/rental/invoice?id='.$invoice['id'], $invoice['number']), '№'.View::e($invoice['contract']),
                    View::e($invoice['due']), self::money($invoice['amount']), self::money($invoice['paid']),
                    self::money($invoice['paid_from_1c']), self::money($invoice['balance']), $state];
            }
            $body .= self::heading('Счета со сроком оплаты в этом месяце').Controller::table(
                ['Счёт','Договор','Срок оплаты','Сумма','Оплачено','Из 1С','Остаток','Состояние'],
                $rows, ['code','short','code','money','money','money','money','text']);
        }
        return $body;
    }

    private static function contracts(string $key, array $client, array $invoiceFacts): string
    {
        $contracts = array_fill_keys(array_keys($client['planned']), []);
        foreach ($client['billed'] as $invoice) $contracts[$invoice['contract']][] = $invoice;
        $rows = $details = [];
        foreach ($contracts as $contract => $bills) {
            $plan = $client['planned'][$contract] ?? null;
            $fact = self::sum(array_column($bills, 'amount'));
            $difference = $plan !== null ? bcsub($plan, $fact, 2) : null;
            $invoiceRows = $paymentFacts = [];
            foreach ($bills as $bill) {
                $id = $bill['invoice_id'];
                $facts = $invoiceFacts[$id] ?? ['due'=>null,'paid_from_1c'=>null,'balance'=>null];
                $paymentFacts[$id ?? $bill['number']] = $facts;
                $invoiceRows[] = [$id ? Controller::link('/rental/invoice?id='.$id, $bill['number']) : View::e($bill['number']),
                    self::money($bill['amount']), View::e($facts['due'] ?? 'Уточнить'),
                    self::money($facts['paid_from_1c']), self::money($facts['balance'])];
            }
            $count = count($invoiceRows);
            $label = match ($count) {
                0 => '—',
                1 => $invoiceRows[0][0],
                default => 'Счета ('.$count.')',
            };
            $dates = array_unique(array_column($paymentFacts, 'due'));
            $deadline = match (true) {
                !$bills => '—',
                in_array(null, $dates, true) => 'Уточнить',
                count($dates) === 1 => reset($dates),
                default => 'Разные сроки',
            };
            if ($count > 1) {
                $details[count($rows)] = self::details($key.'-contract-'.count($rows), 'Счета по договору №'.$contract,
                    Controller::table(['Счёт','Сумма за месяц','Срок оплаты','Оплата из 1С','Остаток'],
                        $invoiceRows, ['flex','money','code','money','money']));
            }
            $rows[] = ['№'.View::e($contract), $label, self::money($plan), self::money($fact), self::money($difference),
                View::e($deadline), $bills ? self::money(self::sum(array_column($paymentFacts,'paid_from_1c'))) : '—',
                $bills ? self::money(self::sum(array_column($paymentFacts,'balance'))) : '—'];
        }
        return Controller::table(['Договор','Счёт 1С','По договору','Выставлено','Разница','Срок оплаты','Оплата из 1С','Остаток'],
            $rows, ['short','flex','money','money','money','code','money','money'], $details);
    }

    private static function summary(array $plans, array $billed, array $due): array
    {
        $plan = self::sum($plans);
        $invoiced = self::sum($billed);
        $difference = $plan !== null ? bcsub($plan, $invoiced, 2) : null;
        return array_map(self::money(...), [
            $plan, $invoiced, $difference, self::sum(array_column($due,'amount')), self::sum(array_column($due,'balance')),
        ]);
    }

    private static function sum(array $amounts): ?string
    {
        $sum = '0.00';
        foreach ($amounts as $amount) {
            if ($amount === null) return null;
            $sum = bcadd($sum, $amount, 2);
        }
        return $sum;
    }

    private static function money(?string $amount): string
    {
        return $amount === null ? 'На сверке' : Controller::money($amount);
    }

    private static function heading(string $label): string
    {
        return '<section class="panel-section"><h2 class="section-title">'.View::e($label).'</h2></section>';
    }

    private static function archiveTime(?string $value, string $empty): string
    {
        return $value ? (new DateTimeImmutable($value))->format('d.m.Y H:i') : $empty;
    }

    private static function monthLabel(string $month): string
    {
        if (!InvoicePeriods::month($month)) return $month;
        $label = IntlDateFormatter::formatObject(new DateTimeImmutable($month.'-01'), 'LLLL yyyy', 'ru_RU');
        return $label ? mb_strtoupper(mb_substr($label,0,1)).mb_substr($label,1) : $month;
    }

    private static function details(string $id, string $label, string $body): array
    {
        return compact('id','label','body');
    }
}
