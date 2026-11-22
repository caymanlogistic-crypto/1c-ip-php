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
        $requestedMonth = $_GET['month'] ?? 'all';
        $search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $tokens = preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $context = ['first'=>$first,'last'=>$last,'unlinked'=>(int)$unlinked,'month'=>'all','q'=>$search];
        $body = Controller::heading(self::TITLE, 'Все объекты аренды из реестра. Расчёт по договорам, счета 1С и оплаты.',
            '<a class="btn btn-secondary" href="/rental/invoice-list">Счета и оплаты</a>');
        try {
            $months = MonthlyRentReconciliation::range($first, $last);
        } catch (RuntimeException $error) {
            return View::page(self::TITLE, $body.self::tabs($tab,$context,0,0,0)
                .'<section class="table-card registry-ui-card">'.self::filters($tab,$context,[])
                .'<section class="panel-section">'
                .RentalUI::alert($error->getMessage(), '', 'error').'</section></section>', self::MENU_KEY);
        }
        $month = in_array($requestedMonth,$months,true) ? $requestedMonth : 'all';
        $context['month'] = $month;
        $selectedMonths = $month === 'all' ? $months : [$month];
        $url = self::ROUTE.'?'.http_build_query(['flag'=>$tab]+$context);
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
        $db = DB::pdo();
        $billRows = MonthlyInvoiceAssignments::listing($db, $selectedMonths);
        if ($unlinked || $tokens) {
            $billRows = array_values(array_filter($billRows, static fn(array $row): bool=>(!$unlinked || $row['contract'] === null)
                && (!$tokens || self::matches($tokens,[$row['number'],$row['name'],$row['client'],$row['contract'],
                    $row['contract'] === null ? 'Не привязан' : '№'.$row['contract'],
                    ...array_map(self::monthLabel(...),$row['months']),Documents::dateText($row['issued']),
                    $row['amount'],$row['paid'],$row['status'],$row['origin']]))));
        }
        $report = null;
        $issues = $monthRows = [];
        if ($tab !== 'bills') {
            $report = (new MonthlyRentReconciliation($db, Config::store()))->capture($selectedMonths, $tab === 'review');
            $issues = array_values(array_filter($report['issues'], static function(array $issue) use ($month,$tokens): bool {
                $periods = explode(', ', $issue[0]);
                return ($month === 'all' || in_array($month,$periods,true))
                    && self::matches($tokens, [...$issue,...array_map(self::monthLabel(...),$periods)]);
            }));
            if ($tab === 'review') {
                usort($issues, static fn(array $left, array $right): int=>($left[0] === '—') <=> ($right[0] === '—'));
            } else {
                $monthRows = self::filterClients($report['months'],$tokens);
            }
        }
        $archive = $report['archive'] ?? MonthlyInvoiceArchive::lastImports($db);
        $issueCount = $report === null ? null : count($issues);
        $body .= self::tabs($tab,$context,$tab === 'main' ? count($monthRows) : count($selectedMonths),
                $issueCount,count($billRows))
            .'<section class="table-card registry-ui-card">'.self::filters($tab,$context,$months,$archive);
        if ($tab === 'bills') {
            $document = $_GET['document'] ?? '';
            $body .= RentalUI::notice();
            if (is_string($document) && $document !== '') $body .= MonthlyInvoiceAssignments::editor($document,$url);
            $body .= MonthlyInvoiceAssignments::table($billRows,$url);
        } else {
            $body .= $tab === 'review' ? self::reviewTable($issues)
                : self::monthlyTable($monthRows);
        }
        return View::page(self::TITLE, $body.'</section>', self::MENU_KEY);
    }

    private static function tabs(string $tab, array $context, int $months, ?int $issues, int $bills): string
    {
        return Controller::tabs(['main'=>['Основное',$months], 'review'=>['Требует сверки',$issues], 'bills'=>['Счета и разнесения',$bills]],
            $tab, context:$context);
    }

    private static function filters(string $tab, array $context, array $months, array $archive = []): string
    {
        $summary = $archive ? '<div class="found-label registry-ui-toolbar-summary">'
            .'Загрузка 1С (МСК) · счета '.self::archiveTime($archive['invoices'], 'ожидаются')
            .' · банк '.self::archiveTime($archive['bank'], 'ожидается').'</div>' : '';
        $selector = '<select class="toolbar-select" name="month" aria-label="Месяц аренды"><option value="all">Все месяцы</option>';
        foreach ($months as $month) {
            $selector .= '<option value="'.View::e($month).'"'.($context['month'] === $month ? ' selected' : '').'>'
                .View::e(self::monthLabel($month)).'</option>';
        }
        return '<form class="table-toolbar registry-ui-toolbar" method="get" action="'.self::ROUTE.'" data-rental-registry-filter>'
            .'<input type="hidden" name="flag" value="'.View::e($tab).'">'
            .'<input type="hidden" name="first" value="'.View::e($context['first']).'">'
            .'<input type="hidden" name="last" value="'.View::e($context['last']).'">'.$summary
            .'<div class="toolbar-right">'.($tab === 'bills' ? DirectoryUi::checkbox('unlinked','Показать непривязанные',(bool)$context['unlinked'])
                : '<input type="hidden" name="unlinked" value="'.$context['unlinked'].'">')
            .$selector.'</select>'.DirectoryUi::search($context['q'],true).'</div></form>';
    }

    private static function matches(array $tokens, array $fields): bool
    {
        if (!$tokens) return true;
        $text = mb_strtolower(implode(' ',$fields));
        foreach ($tokens as $token) {
            if (!str_contains($text,$token)) return false;
        }
        return true;
    }

    private static function filterClients(array $months, array $tokens): array
    {
        if (!$tokens) return $months;
        $filtered = [];
        foreach ($months as $month=>$clients) {
            $label = self::monthLabel($month);
            if (self::matches($tokens,[$month,$label])) {
                $filtered[$month] = $clients;
                continue;
            }
            foreach ($clients as $id=>$client) {
                $contracts = array_keys($client['planned']);
                $fields = [$client['name'],$month,$label,...$contracts];
                foreach (array_merge($client['billed'],$client['due']) as $invoice) {
                    $fields[] = $invoice['number'];
                    $fields[] = $invoice['name'] ?? '';
                    $contracts[] = $invoice['contract'];
                }
                foreach ($contracts as $number) $fields[] = '№'.$number;
                if (self::matches($tokens,$fields)) $filtered[$month][$id] = $client;
            }
        }
        return $filtered;
    }

    private static function reviewTable(array $issues): string
    {
        if (!$issues) return '<section class="panel-section">'.RentalUI::alert('За выбранный период вопросов на сверку нет.').'</section>';
        $rows = $details = [];
        foreach ($issues as $index=>$issue) {
            [$periods,$client,$document,$reason] = $issue;
            $name = $issue[4] ?? '';
            $months = array_map(self::monthLabel(...), explode(', ', $periods));
            $rows[] = [View::e(implode(', ', $months)),View::e($client),View::e($document),self::invoiceNameCell($name),View::e($reason)];
            if ($name !== '') $details[$index] = self::invoiceNameDetails('review-name-'.$index, $document, $name);
        }
        return Controller::table(['Месяц аренды','Клиент','Документ','Наименование счёта','Что уточнить'],
            $rows, ['text','text','code','flex','flex'], $details);
    }

    public static function invoiceNameCell(string $name): string
    {
        return $name === '' ? '—' : '<span class="registry-ui-name">'.View::e(mb_strimwidth($name,0,100,'…')).'</span>';
    }

    public static function invoiceNameDetails(string $id, string $number, string $name): array
    {
        return self::details($id, 'Полное наименование · '.$number,
            '<section class="panel-section"><p class="registry-ui-primary text-prewrap">'.View::e($name).'</p></section>');
    }

    private static function monthlyTable(array $months): string
    {
        $rows = $details = [];
        foreach ($months as $month => $clients) {
            $plans = $billed = $due = [];
            foreach ($clients as $client) {
                array_push($plans, ...array_values($client['planned']));
                array_push($billed, ...array_values($client['billed']));
                array_push($due, ...$client['due']);
            }
            $index = count($rows);
            $label = self::monthLabel($month);
            $rows[] = ['<strong>'.View::e($label).'</strong>', ...self::summary($plans, $billed, $due)];
            if ($clients) {
                $details[$index] = self::details('reconciliation-'.$month, 'Клиенты · '.$label, self::clients($month, $clients));
            }
        }
        return Controller::table(['Месяц',self::PLAN_COLUMN,'Счета 1С','Разница','Разнесено в ERP','Оплачено, не разнесено','Выставлено, но не оплачено'],
            $rows, ['text','money','money','money','money','money','money'], $details, columnWidths:[1=>'text',5=>'long',6=>'long']);
    }

    private static function clients(string $month, array $clients): string
    {
        $rows = $details = [];
        foreach ($clients as $id => $client) {
            $index = count($rows);
            $rows[] = [View::e($client['name']), ...self::summary($client['planned'],
                $client['billed'], $client['due'])];
            $key = 'reconciliation-'.$month.'-'.$id;
            $details[$index] = self::details($key, 'Счета · '.$client['name'], self::clientDetails($key, $client));
        }
        return Controller::table(['Клиент',self::PLAN_COLUMN,'Счета 1С','Разница','Разнесено в ERP','Оплачено, не разнесено','Выставлено, но не оплачено'],
            $rows, ['flex','money','money','money','money','money','money'], $details, columnWidths:[1=>'text',5=>'long',6=>'long']);
    }

    private static function clientDetails(string $key, array $client): string
    {
        $body = '';
        if ($client['planned'] || $client['billed']) {
            $body .= self::heading('Аренда за месяц').self::contracts($key, $client);
        }
        if ($client['due']) {
            $rows = [];
            foreach ($client['due'] as $invoice) {
                $state = $invoice['overdue'] ? 'Просрочка подтверждена' : ($invoice['verified'] ? 'Связан с 1С' : 'Сверить связь с 1С');
                if ($invoice['advance']!=='0.00') $state .= '. Зачёт аванса '.self::money($invoice['advance']);
                $rows[] = [Controller::link('/rental/invoice?id='.$invoice['id'], $invoice['number']), '№'.View::e($invoice['contract']),
                    View::e($invoice['due'] ?? 'Уточнить'), self::money($invoice['amount']), self::money($invoice['paid']),
                    self::money($invoice['paid_from_1c']), self::money($invoice['balance']), $state];
            }
            $body .= self::heading('Разнесения за месяц аренды').Controller::table(
                ['Счёт','Договор','Срок оплаты','Сумма','Оплачено','Из 1С','Остаток','Состояние'],
                $rows, ['code','short','code','money','money','money','money','text']);
        }
        return $body;
    }

    private static function contracts(string $key, array $client): string
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
                $facts = $bill;
                $paymentFacts[$id ?? $bill['number']] = $facts;
                $invoiceRows[] = [$id ? Controller::link('/rental/invoice?id='.$id, $bill['number']) : View::e($bill['number']),
                    View::e($bill['name']), self::money($bill['amount']), View::e($facts['due'] ?? 'Уточнить'),
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
            $name = $count === 1 ? $bills[0]['name'] : '';
            if ($count > 1) {
                $details[count($rows)] = self::details($key.'-contract-'.count($rows), 'Счета по договору №'.$contract,
                    Controller::table(['Счёт','Наименование счёта','Сумма за месяц','Срок оплаты','Оплата из 1С','Остаток'],
                        $invoiceRows, ['code','long','money','code','money','money']));
            } elseif ($name !== '') {
                $details[count($rows)] = self::invoiceNameDetails($key.'-invoice-'.count($rows),$bills[0]['number'],$name);
            }
            $rows[] = ['№'.View::e($contract), $label, self::invoiceNameCell($name), self::money($plan), self::money($fact), self::money($difference),
                View::e($deadline), $bills ? self::money(self::sum(array_column($paymentFacts,'paid_from_1c'))) : '—',
                $bills ? self::money(self::sum(array_column($paymentFacts,'balance'))) : '—'];
        }
        return Controller::table(['Договор','Номер счёта','Наименование счёта',self::PLAN_COLUMN,'Счета 1С','Разница','Срок оплаты','Оплата из 1С','Остаток'],
            $rows, ['short','code','long','money','money','money','code','money','money'], $details, columnWidths:[3=>'text']);
    }

    private static function summary(array $plans, array $billed, array $due): array
    {
        $plan = self::sum($plans);
        $invoiced = self::sum(array_column($billed,'amount'));
        $difference = $plan !== null ? bcsub($plan, $invoiced, 2) : null;
        $balance = self::sum(array_column($billed,'balance'));
        return array_map(self::money(...), [
            $plan, $invoiced, $difference, self::sum(array_column($due,'paid')),
            self::sum(array_column($billed,'not_allocated')), $balance,
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
        static $labels = [];
        if (!InvoicePeriods::month($month)) return $month;
        if (!isset($labels[$month])) {
            $label = IntlDateFormatter::formatObject(new DateTimeImmutable($month.'-01'), 'LLLL yyyy', 'ru_RU');
            $labels[$month] = $label ? mb_strtoupper(mb_substr($label,0,1)).mb_substr($label,1) : $month;
        }
        return $labels[$month];
    }

    private static function details(string $id, string $label, string $body): array
    {
        return compact('id','label','body');
    }
}
