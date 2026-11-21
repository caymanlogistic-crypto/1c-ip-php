<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, View};
use DateTimeImmutable;
use DateTimeZone;
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
        $body = Controller::heading(self::TITLE, 'План по договорам, счета из 1С и сроки оплаты.',
            '<a class="btn btn-secondary" href="/rental/invoice-list">Счета и оплаты</a>');
        $body .= '<section class="table-card registry-ui-card"><form class="table-toolbar registry-ui-toolbar" method="get" action="'.self::ROUTE.'">'
            .'<div class="toolbar-right">'.Controller::input('first', 'Первый месяц', $first, 'month')
            .Controller::input('last', 'Последний месяц', $last, 'month')
            .'<button class="btn btn-primary" type="submit">Показать</button></div></form>';
        try {
            $months = MonthlyRentReconciliation::range($first, $last);
        } catch (RuntimeException $error) {
            return View::page(self::TITLE, $body.'<section class="panel-section">'
                .RentalUI::alert($error->getMessage(), '', 'error').'</section></section>', 'invoice-list');
        }
        $report = (new MonthlyRentReconciliation(DB::pdo(), Config::store()))->capture($months);
        $body .= '<section class="panel-section"><p class="registry-ui-secondary">'
            .'Загрузка 1С (МСК) · счета '.self::archiveTime($report['archive']['invoices'], 'ожидаются')
            .' · банк '.self::archiveTime($report['archive']['bank'], 'ожидается').'</p></section>'
            .self::monthlyTable($report['months'], $report['invoice_facts']).'</section>';
        if ($report['issues']) {
            $body .= '<section class="table-card registry-ui-card"><details class="registry-ui-form-disclosure" data-form-disclosure>'
                .'<summary class="btn btn-secondary">Требуют сверки · '.count($report['issues']).'</summary>'
                .Controller::table(['Месяц аренды','Клиент','Документ','Что уточнить'],
                    array_map(static fn(array $row): array => array_map(View::e(...), $row), $report['issues']),
                    ['code','flex','code','flex']).'</details></section>';
        }
        return View::page(self::TITLE, $body, 'invoice-list');
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
            $rows[] = ['<strong>'.View::e($month).'</strong>', ...self::summary($plans, $billed, $due)];
            if ($clients) {
                $details[$index] = self::details('reconciliation-'.$month, 'Клиенты за '.$month, self::clients($month, $clients, $invoiceFacts));
            }
        }
        return Controller::table(['Месяц','По договорам','Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['short','money','money','money','money','money'], $details);
    }

    private static function clients(string $month, array $clients, array $invoiceFacts): string
    {
        $rows = $details = [];
        foreach ($clients as $id => $client) {
            $index = count($rows);
            $rows[] = [View::e($client['name']), ...self::summary($client['planned'],
                array_column($client['billed'], 'amount'), $client['due'])];
            $details[$index] = self::details('reconciliation-'.$month.'-'.$id, 'Счета · '.$client['name'],
                self::clientDetails($client, $invoiceFacts));
        }
        return Controller::table(['Клиент','По договорам','Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['flex','money','money','money','money','money'], $details);
    }

    private static function clientDetails(array $client, array $invoiceFacts): string
    {
        $body = '';
        if ($client['planned'] || $client['billed']) {
            $rows = [];
            foreach ($client['planned'] as $contract => $amount) {
                $billed = array_filter($client['billed'], static fn(array $bill): bool => $bill['contract'] === (string)$contract);
                $difference = $amount === null ? null : bcsub($amount, self::sum(array_column($billed,'amount')), 2);
                $rows[] = ['Расчёт по договору','№'.View::e($contract),self::money($amount),self::money($difference),'—','—','—'];
            }
            foreach ($client['billed'] as $invoice) {
                $facts = $invoiceFacts[$invoice['invoice_id']] ?? [];
                $rows[] = [$invoice['invoice_id']
                    ? Controller::link('/rental/invoice?id='.$invoice['invoice_id'], $invoice['number'])
                    : View::e($invoice['number']), '№'.View::e($invoice['contract']), self::money($invoice['amount']), '—',
                    View::e($facts['due'] ?? 'Уточнить'), self::money($facts['paid_from_1c'] ?? null),
                    self::money($facts['balance'] ?? null)];
            }
            $body .= self::heading('Аренда за месяц').Controller::table(
                ['Основание','Договор','Сумма с НДС','Разница','Срок оплаты','Из 1С','Остаток'],
                $rows, ['flex','short','money','money','code','money','money']);
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

    private static function details(string $id, string $label, string $body): array
    {
        return compact('id','label','body');
    }
}
