<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, Http, View};
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Modules\Integrations\Config;
use RuntimeException;

final class MonthlyRentReconciliationPage
{
    public const MENU_KEY = 'monthly-reconciliation';
    public const ROUTE = '/rental/'.self::MENU_KEY;
    private const TITLE = 'Помесячная сверка аренды';
    private const PLAN_COLUMN = 'Расчёт по договорам';

    public static function available(array $user): bool
    {
        return in_array($user['role'] ?? '', ['accountant', 'admin'], true);
    }

    public static function page(): string
    {
        Access::pages([\App\Menu::permissionPage(self::MENU_KEY)]);
        if (!self::available(Auth::user() ?? [])) throw new RuntimeException('Сверка доступна бухгалтеру и администратору.', 403);
        $current = new DateTimeImmutable('first day of this month', new DateTimeZone('Europe/Moscow'));
        $first = $_GET['first'] ?? $current->modify('-1 month')->format('Y-m');
        $last = $_GET['last'] ?? $current->modify('+1 month')->format('Y-m');
        $first = is_string($first) ? $first : '';
        $last = is_string($last) ? $last : '';
        $requested = $_GET['flag'] ?? '';
        $tab = in_array($requested,['review','bills'],true) ? $requested : 'main';
        $unlinked = ($_GET['unlinked'] ?? '') === '1';
        $body = Controller::heading(self::TITLE, 'Расчёт по договорам, выставленные счета 1С и сроки оплаты.',
            '<a class="btn btn-secondary" href="/rental/invoice-list">Счета и оплаты</a>');
        try {
            $months = MonthlyRentReconciliation::range($first, $last);
        } catch (RuntimeException $error) {
            return View::page(self::TITLE, $body.self::tabs($tab,$first,$last,$unlinked,0,0)
                .'<section class="table-card registry-ui-card">'.self::filters($tab,$first,$last,$unlinked)
                .'<section class="panel-section">'
                .RentalUI::alert($error->getMessage(), '', 'error').'</section></section>', self::MENU_KEY);
        }
        $url = self::ROUTE.'?'.http_build_query(['flag'=>'bills','first'=>$first,'last'=>$last,'unlinked'=>(int)$unlinked]);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($tab !== 'bills') throw new RuntimeException('Откройте вкладку «Счета и разнесения».',405);
            Http::checkCsrf();
            $saved = Service::command(InvoiceContractAssignment::SAVE,$_POST);
            $payment = $saved['payment'];
            $_SESSION['rental_notice'] = match (true) {
                $payment['created'] > 0 && $payment['review'] > 0 => 'Привязка сохранена. Подтверждённые оплаты распределены. Остальные на сверке.',
                $payment['review'] > 0, $payment['state'] === 'review' => 'Привязка сохранена. Откройте сверку счетов и оплат 1С.',
                $payment['created'] > 0 => 'Привязка сохранена. Связанная оплата распределена.',
                $payment['existing'] > 0 => 'Привязка сохранена. Связанная оплата уже учтена.',
                $payment['state'] === 'accounting-disabled' => 'Привязка сохранена. Автоматическое разнесение выключено.',
                in_array($payment['state'],['created','existing'],true) => 'Привязка сохранена. В загруженных платежах нет прямой ссылки на этот счёт.',
                default => 'Привязка счёта сохранена.',
            };
            Http::redirect($url);
        }
        $report = (new MonthlyRentReconciliation(DB::pdo(), Config::store()))->capture($months);
        $bills = $tab === 'bills' ? MonthlyInvoiceAssignments::listing(DB::pdo(),$months) : null;
        $body .= self::tabs($tab,$first,$last,$unlinked,count($months),count($report['issues']),$bills === null ? null : count($bills))
            .'<section class="table-card registry-ui-card">'.self::filters($tab,$first,$last,$unlinked,$report['archive']);
        if ($tab === 'bills') {
            $document = $_GET['document'] ?? '';
            $body .= RentalUI::notice();
            if (is_string($document) && $document !== '') $body .= MonthlyInvoiceAssignments::editor($document,$url);
            $body .= MonthlyInvoiceAssignments::table($bills,$url,$unlinked);
        } else {
            $body .= $tab === 'review' ? self::reviewTable($report['issues'])
                : self::monthlyTable($report['months'], $report['invoice_facts']);
        }
        return View::page(self::TITLE, $body.'</section>', self::MENU_KEY);
    }

    private static function tabs(string $tab, string $first, string $last, bool $unlinked, int $months, int $issues, ?int $bills = null): string
    {
        return Controller::tabs(['main'=>['Основное',$months], 'review'=>['Требует сверки',$issues], 'bills'=>['Счета и разнесения',$bills]],
            $tab, context:['first'=>$first,'last'=>$last,'unlinked'=>(int)$unlinked]);
    }

    private static function filters(string $tab, string $first, string $last, bool $unlinked, array $archive = []): string
    {
        $summary = $archive ? '<div class="found-label registry-ui-toolbar-summary">'
            .'Загрузка 1С (МСК) · счета '.self::archiveTime($archive['invoices'], 'ожидаются')
            .' · банк '.self::archiveTime($archive['bank'], 'ожидается').'</div>' : '';
        return '<form class="table-toolbar registry-ui-toolbar" method="get" action="'.self::ROUTE.'">'
            .'<input type="hidden" name="flag" value="'.View::e($tab).'">'.$summary
            .'<div class="toolbar-right">'.($tab === 'bills' ? DirectoryUi::checkbox('unlinked','Показать непривязанные',$unlinked)
                : '<input type="hidden" name="unlinked" value="'.(int)$unlinked.'">')
            .Controller::input('first', 'Первый месяц', $first, 'month')
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
        return Controller::table(['Месяц',self::PLAN_COLUMN,'Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['text','money','money','money','money','money'], $details, columnWidths:[1=>'text']);
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
        return Controller::table(['Клиент',self::PLAN_COLUMN,'Счета 1С','Разница','Счета к сроку','Остаток'],
            $rows, ['flex','money','money','money','money','money'], $details, columnWidths:[1=>'text']);
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
        return Controller::table(['Договор','Номер счёта',self::PLAN_COLUMN,'Счета 1С','Разница','Срок оплаты','Оплата из 1С','Остаток'],
            $rows, ['short','flex','money','money','money','code','money','money'], $details, columnWidths:[2=>'text']);
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

    public static function monthLabel(string $month): string
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
