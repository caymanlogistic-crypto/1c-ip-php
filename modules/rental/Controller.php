<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{DB, Auth, Http, View};
use PDO;
final class Controller {
    private const TITLES = [
        'contracts' => 'Договоры в работе',
        'contracts-active' => 'Действующие договоры',
        'contracts-completed' => 'Завершённые договоры',
        'approvals' => 'Согласование документов',
        'templates' => 'Шаблоны документов',
        'payments' => 'Оплаты контрагентов',
        'invoices' => 'Оплаты контрагентов',
        'invoice-list' => 'Счета и оплаты',
        'reconciliation' => 'Финальная сверка',
        'parties' => 'Контрагенты',
        'organization' => 'Наша организация',
        'cadastre' => 'Кадастровые объекты',
        'objects' => 'Объекты аренды',
        'operations' => 'Операции и отправки',
        'errors' => 'Контроль ошибок',
        'journal' => 'Журнал',
        'summary' => 'Обзор документов',
        'thresholds' => 'Пороги и календарь'
    ];

    public static function pages(): array {
        return array_keys(self::TITLES);
    }

    private static function e(mixed $v): string {
        return View::e($v);
    }

    public static function objectNames(int $id): string {
        static $groups = null;
        if ($groups === null) {
            $groups = [
            ];
            foreach (self::query('SELECT co.contract_id,o.type,o.name FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id ORDER BY co.contract_id,o.id') as $r)$groups[$r['contract_id']][$r['type'] ?? 'Объекты'][] = $r['name'];
        }
        $h = '';
        foreach ($groups[$id] ?? [
        ] as $type => $names) {
            $prefix = match ($type) {
                'Офис' => 'Офисы', 'Машиноместо' => 'Машиноместа', default => $type
            };
            $h.='<div class="registry-ui-primary"><span class="registry-executor-secondary">'
            . self::e($prefix)
            . ':</span> '
            . self::e(implode(', ', $names))
            . '</div>';
        }
        return $h?:'—';
    }

    public static function tabs(array $items, string $current, bool $preserveQuery=false,array $context=[],string $countPage=''): string {
        $h = '<form method="get" class="registry-ui-tabs" role="group" aria-label="Разделы списка">';
        if ($preserveQuery && !empty($_GET['q'])) $context['q']=(string)$_GET['q'];
        foreach ($context as $key=>$value) $h.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
        foreach ($items as $key => $spec) {
        [$label,$count]=$spec;
        $h.='<button type="submit" name="flag" value="'
        . self::e($key)
        . '" class="'
        . ($key === $current?'is-active':'')
        . '" aria-pressed="'
        . ($key === $current?'true':'false')
        . '">'
        . self::e($label)
        . ($count === null ? '' : ' <b>'.$count.'</b>')
        . (isset($spec[2])?DirectoryUi::counts($spec[2],$countPage):'').'</button>';
        }
        return $h
        . '</form>';
    }

    public static function heading(string $title, string $sub = '', string $actions = ''): string {
        return '<div class="page-head registry-ui-head">'
        . '<div class="page-head-left">'
        . '<span class="rp-identity-icon">'
        . '<svg class="page-head-tabler-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M14 3v4a1 1 0 0 0 1 1h4"/>'
        . '<path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2"/>'
        . '<path d="M9 9h1M9 13h6M9 17h6"/>'
        . '</svg>'
        . '</span>'
        . '<span class="rp-head-text">'
        . '<h1 class="page-title">'
        . self::e($title)
        . '</h1><span class="page-summary">'
        . self::e($sub)
        . '</span></span></div><div class="page-head-actions">'
        . $actions
        . '</div></div>';
    }

    public static function registry(array $headers, array $rows, ?array $columns=null): string {
        $query = (string)($_GET['q'] ?? '');
        return '<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-scroll">'
        . '<div class="table-toolbar registry-ui-toolbar">'
        . '<div class="found-label">Найдено: <b>'
        . count($rows)
        . '</b></div><div class="toolbar-right"><form method="get"><input class="registry-ui-search" name="q" type="search" value="'
        . self::e($query)
        . '" placeholder="Поиск по всем столбцам" aria-label="Поиск по реестру"></form></div></div>'
        . self::table($headers, $rows,$columns)
        . '</section>';
    }

    public static function query(string $sql, array $params = [
    ]):array {
        $q = DB::pdo()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function table(array $headers, array $rows, ?array $columnOverride = null, array $details = [], array $columnWidths = []): string {
        $profile = $headers[0] === 'Кадастровый номер'?'rental-cadastre':'rental-contracts';
        $columns = $headers[0] === 'Кадастровый номер'?[
            'flex',
            'text',
            'short',
            'money',
            'text'
        ]:(in_array($headers[0], [
            'Договор',
            'Документ'
        ], true)?[
            'short',
            'flex',
            'short',
            'code',
            'flex',
            'text',
            'flex',
            'money',
            'text'
        ]:($headers[0] === 'Счёт / дата'?[
            'code',
            'flex',
            'short',
            'text',
            'code',
            'money',
            'money',
            'money',
            'flex'
        ]:[
        ]));
        if ($columnOverride !== null)$columns = $columnOverride;
        $h = '<div class="table-scroll"><table class="table registry-ui-table registry-ui-data" data-column-profile="'
        . $profile
        . '">';
        if ($columns || $details) {
            $h.='<colgroup>';
            foreach ($columns as $index=>$size)$h.='<col class="registry-column-'
            . ($columnWidths[$index] ?? $size)
            . '">';
            if ($details) $h.='<col class="registry-ui-disclosure-column">';
            $h.='</colgroup>';
        }
        $h.='<thead><tr>';
        foreach ($headers as $i => $v)$h.='<th'
        . (($columns[$i] ?? '') === 'money'?' class="registry-ui-number"':'')
        . '>'
        . self::e($v)
        . '</th>';
        if ($details) $h.='<th aria-label="Детали"></th>';
        $h.='</tr></thead><tbody>';
        foreach ($rows as $index=>$row) {
            $attrs = $row['_attrs'] ?? '';
            unset($row['_attrs']);
            $url = '';
            if (isset($row[0]) && preg_match('~href="(/rental/(?:contract|invoice|edit-party|edit-object|edit-cadastre|document|template|payment|party)\?[^"]+)"~', $row[0], $match))$url = html_entity_decode($match[1]);
            $h.='<tr'
            . ($attrs?' '
            . $attrs:'')
            . ($url?' data-rental-url="'
            . self::e($url)
            . '" tabindex="0"':'')
            . '>';
            foreach ($row as $i => $v) {
                if ($url)$v = preg_replace('~<a\b[^>]*>(.*?)</a>~s', '<span class="registry-ui-name">$1</span>', $v);
                $h.='<td'
                . (($columns[$i] ?? '') === 'money'?' class="registry-ui-number"':(in_array($columns[$i] ?? '', [
                    'text',
                    'long',
                    'flex'
                ], true)?' class="registry-column-text"':''))
                . '>'
                . $v
                . '</td>';
            }
            if ($details) $h.=RegistryDisclosure::cell($details[$index] ?? null);
            $h.='</tr>';
            if (isset($details[$index])) $h.=RegistryDisclosure::row($details[$index], count($headers)+1);
        }
        if (!$rows)$h.='<tr><td colspan="'
        . count($headers)
        . '">Нет записей по выбранным условиям.</td></tr>';
        return $h
        . '</tbody></table></div>';
    }

    public static function money(mixed $v): string {
        if ($v === null)return 'Не подтверждено';
        $parts = explode('.', bcadd((string)$v, '0', 2));
        return preg_replace('/\B(?=(\d{3})+(?!\d))/u', ' ', $parts[0])
        . ','
        . $parts[1]
        . ' ₽';
    }

    public static function link(string $path, mixed $label): string {
        return '<a href="'
        . self::e($path)
        . '"'
        . (preg_match('~^/rental/(contract|invoice|new-contract|edit-contract|edit-party|edit-object|edit-cadastre|edit-organization|document|template|reservations|payment|party)(?:\?|$)~', $path)?' data-rental-open':'')
        . '>'
        . self::e($label)
        . '</a>';
    }

    public static function form(string $action, int $id, int $version, string $body, string $button): string {
        return '<form method="post" action="/rental/action"><input type="hidden" name="_csrf" value="'
        . self::e(Http::csrf())
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '"><input type="hidden" name="action" value="'
        . self::e($action)
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . $version
        . '">'
        . $body
        . '<button class="btn btn-primary" type="submit">'
        . self::e($button)
        . '</button></form>';
    }

    public static function input(string $key, string $label, mixed $v = '', string $type = 'text', bool $required = true): string {
        return '<label class="field"><span class="field-label">'
        . self::e($label)
        . '</span><input class="field-input" type="'
        . $type
        . '" name="'
        . self::e($key)
        . '" value="'
        . self::e($v)
        . '"'
        . ($required?' required':'')
        . '></label>';
    }

    public static function page(string $page): string {
        Auth::requirePermission('rental.read');
        $u = Auth::user();
        $bookingOnly=$page === 'contracts' && !\App\Menu::allowed('contracts',$u);
        if (($page === 'new-contract' && !in_array($u['role'], [
            'admin',
            'manager'
        ], true)) || ($page === 'templates' && !in_array($u['role'], [
            'admin',
            'director',
            'lawyer'
        ], true))) {
            http_response_code(403);
            throw new \RuntimeException('Страница недоступна для этой роли.', 403);
        }
        if (in_array($page, [
            'errors',
            'journal',
            'summary',
            'thresholds'
        ], true) && !in_array($u['role'], [
            'admin',
            'director'
        ], true))throw new \RuntimeException('Страница доступна руководителю.', 403);
        if ($page === 'contract')return self::contract();
        if ($page === 'invoice')return self::invoice();
        if ($page === 'new-contract')return self::newContract();
        $title = self::TITLES[
            $page
        ] ?? 'Аренда';
        $headActions = '';
        if (in_array($u['role'], [
            'admin',
            'manager'
        ], true) && !$bookingOnly && in_array($page, [
            'contracts',
            'contracts-active',
            'approvals'
        ], true)) {
            $headActions = RentalUI::button('Создать договор', '/rental/document-creator', true);
            if ($page === 'contracts')$headActions.=RentalUI::button('Резервирование помещения', '/rental/reservations');
        }
        if (in_array($u['role'], [
            'admin',
            'manager'
        ], true) && in_array($page, [
            'objects',
            'cadastre'
        ], true))$headActions = RentalUI::button('Добавить объект', '/rental/edit-'
        . ($page === 'objects'?'object':'cadastre'), true);
        if (in_array($u['role'], [
            'admin',
            'accountant'
        ], true) && in_array($page, [
            'invoices',
            'invoice-list'
        ], true) && Auth::can('finance.write') && \App\PagePolicy::level('rental.invoice-list',$u)>=2)
            $headActions = RentalUI::button('Создать счёт',RegularInvoiceCard::route((int)($_GET['contract_id'] ?? 0)),true)
                .RentalUI::button('Сформировать за месяц',InvoiceBatchCard::ROUTE)
                .RentalUI::button('УПД',UpdCard::ROUTE);
        if ($page === 'invoice-list' && in_array($u['role'],['accountant','admin'],true))
            $headActions .= RentalUI::button('Сверка банковских оплат',DebtFollowUpCard::route());
        if ($page === 'invoice-list') {
            $reportMonth = InvoicePeriods::month($_GET['rental_month'] ?? null);
            $headActions .= RentalUI::button('Отчёт по начислениям',InvoiceReport::ROUTE
                .($reportMonth ? '?rental_month='.$reportMonth : ''));
            if (MonthlyRentReconciliationPage::available($u)) {
                $headActions .= '<a class="btn btn-secondary" href="'.MonthlyRentReconciliationPage::ROUTE
                    .($reportMonth ? '?first='.$reportMonth.'&amp;last='.$reportMonth : '').'">Помесячная сверка</a>';
            }
        }
        if(in_array($page,['invoices','invoice-list'],true)&&FinanceController::paymentActionsAllowed($u,Auth::can('finance.write'),\App\PagePolicy::level('rental.invoices',$u)))
            $headActions.=RentalUI::button('Внести приход','/rental/payment');
        if (in_array($u['role'], [
            'admin',
            'manager',
            'accountant'
        ], true) && $page === 'parties')$headActions = RentalUI::button('Создать контрагента', '/rental/edit-party', true);
        if ($page==='templates' && in_array($u['role'],['admin','lawyer'],true) && Auth::can('rental.approve.legal')
            && \App\PagePolicy::level('rental.templates',$u)>=2) $headActions=RentalUI::button('Создать шаблон','/rental/template-editor',true);
        $sub = match ($page) {
            'contracts' => $bookingOnly?'Двойной щелчок открывает карточку резервирования.':'Двойной щелчок открывает редактор черновика или карточку резервирования.', 'contracts-active' => 'Действующие условия аренды, сроки и назначенные действия.', 'contracts-completed' => 'История завершённой аренды и подтверждений расчётов.', 'approvals' => 'Назначенные проверки, ожидание подписания и история решений.', 'invoices', 'payments' => 'Поступления и связанные счета. Раскройте строку контрагента, чтобы открыть счёт.', 'invoice-list' => 'Счета, оплаты и остатки по каждому документу.', 'templates'=>'Юрист готовит новую версию, руководитель утверждает.', 'objects' => 'Помещения и машиноместа: занятость, резервирование и контроль простоя', 'cadastre' => 'Кадастровая площадь и доступность для сдачи.', default => ''
        };
        $h = self::heading($title, $sub, $headActions);
        if (isset($_SESSION['rental_notice'])) {
            $h.='<p role="status" class="notice">'
            . self::e($_SESSION['rental_notice'])
            . '</p>';
            unset($_SESSION['rental_notice']);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($page === 'contracts') {
            $h.=WorkRegistry::render($bookingOnly);
        }
        elseif(in_array($page, [
            'contracts-active',
            'contracts-completed',
            'approvals',
            'reconciliation'
        ], true)) {
            $where = match ($page) {
                'contracts' => "c.status='draft'", 'contracts-active' => "c.status IN ('active','reconciling')", 'contracts-completed' => "c.status IN ('ended','closed')", 'approvals' => "c.status IN ('legal','accounting','returned','approved','sent','cancelled')", default => "c.status IN ('reconciling','ended')"
            };
            $rows = self::query("SELECT c.*,p.name party_name,(SELECT GROUP_CONCAT(o.name SEPARATOR ', ') FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=c.id) object_names FROM contracts c JOIN parties p ON p.id=c.party_id WHERE $where AND (c.number LIKE ? OR p.name LIKE ?) ORDER BY c.id DESC", [
                '%'
                . $q
                . '%',
                '%'
                . $q
                . '%'
            ]);
            $flag = (string)($_GET['flag'] ?? 'all');
            $tabs = [
                'all' => [
                    'Все',
                    count($rows)
                ]
            ];
            if ($page === 'contracts-active') {
                $tabs['ending'] = [
                    'Заканчиваются',
                    count(array_filter($rows,
                    fn($r) => $r['end_date'] && $r['end_date'] <= date('Y-m-d',
                    strtotime('+1 month'))))
                ];
                $tabs['review'] = [
                    'На завершении',
                    count(array_filter($rows,
                    fn($r) => $r['status'] === 'reconciling'))
                ];
            }
            elseif($page === 'contracts-completed') {
                $tabs['ended'] = [
                    'Аренда завершена',
                    count(array_filter($rows,
                    fn($r) => $r['status'] === 'ended'))
                ];
                $tabs['closed'] = [
                    'Расчёты закрыты',
                    count(array_filter($rows,
                    fn($r) => $r['status'] === 'closed'))
                ];
            }
            elseif($page === 'approvals') {
                $tabs['mine'] = [
                    'Моё действие',
                    count(array_filter($rows,
                    fn($r) => match ($r['status']) {
                        'legal' => $u['role'] === 'lawyer',
                        'accounting',
                        'approved' => $u['role'] === 'accountant',
                        'returned' => $u['role'] === ($r['return_to'] ?? 'manager'),
                        'sent' => $u['role'] === 'manager',
                        default => false
                    }))
                ];
                $tabs['returned'] = [
                    'Возвращённые',
                    count(array_filter($rows,
                    fn($r) => $r['status'] === 'returned'))
                ];
                $tabs['cancelled'] = [
                    'Аннулированные',
                    count(array_filter($rows,
                    fn($r) => $r['status'] === 'cancelled'))
                ];
            }
            $h.=self::tabs($tabs, $flag,true);
            $rows = array_values(array_filter($rows, fn($r) => match ($flag) {
                'ending' => $r['end_date'] && $r['end_date'] <= date('Y-m-d', strtotime('+1 month')), 'review' => $r['status'] === 'reconciling', 'returned', 'cancelled', 'ended', 'closed' => $r['status'] === $flag, 'mine' => match ($r['status']) {
                    'legal' => $u['role'] === 'lawyer', 'accounting', 'approved' => $u['role'] === 'accountant', 'returned' => $u['role'] === ($r['return_to'] ?? 'manager'), 'sent' => $u['role'] === 'manager', default => false
                }, default => true
            }));
            $out = [
            ];
            foreach ($rows as $r)$out[] = [
                self::link('/rental/contract?id='
                . $r['id'],
                $r['number']),
                self::e($r['party_name']),
                self::e(Documents::dateText($r['start_date'])),
                self::e(Documents::dateText($r['end_date'])),
                self::objectNames((int)$r['id']),
                self::e(self::status($r['status'])),
                self::e($r['reason'] ?? ''),
                self::money($r['amount']),
                self::e(match ($r['status']) {
                    'legal' => 'Юрист',
                    'accounting',
                    'approved' => 'Бухгалтер',
                    default => 'Менеджер'
                })
            ];
            $h.=self::registry([
                'Договор',
                'Контрагент',
                'Начало',
                'Окончание',
                'Объекты аренды',
                'Стадия',
                'Комментарий',
                'Аренда с НДС',
                'Ответственный'
            ], $out);
        }
        elseif(in_array($page, [
            'invoices',
            'payments',
            'invoice-list'
        ], true)) {
            $h.=InvoiceRegistry::render($page !== 'invoice-list');
        }
        elseif($page === 'parties') {
            $rows = self::query('SELECT p.*,b.bank,b.account,b.bik FROM parties p LEFT JOIN party_bank_accounts b ON b.party_id=p.id AND b.operational=1 WHERE p.name LIKE ? OR p.inn LIKE ? ORDER BY p.name', [
                '%'
                . $q
                . '%',
                '%'
                . $q
                . '%'
            ]);
            $out = [
            ];
            foreach ($rows as $r) {
                $controls = '';
                if (in_array($u['role'], [
                    'admin',
                    'director',
                    'manager',
                    'lawyer',
                    'accountant'
                ], true))$controls = self::form($r['blocked']?'party.unblock':'party.block', (int)$r['id'], (int)$r['version'], self::input('reason', 'Причина'), 'Заблокировать / разблокировать');
                $out[] = [
                    self::link('/rental/party?id='
                    . $r['id'],
                    $r['name']),
                    self::e($r['inn']),
                    self::e($r['email']),
                    self::e($r['bank']
                    . ' · '
                    . $r['account']),
                    self::e($r['blocked']?'Заблокирован: '
                    . $r['block_reason']:'Доступен'),
                    $controls
                ];
            }
            $h.=self::registry([
                'Контрагент',
                'ИНН',
                'Email',
                'Оперативный банковский счёт',
                'Статус',
                'Действия'
            ], $out);
        }
        elseif($page === 'cadastre') {
            $rows = self::query('SELECT c.*,(SELECT SUM(o.area) FROM rental_objects o WHERE o.cadastre_id=c.id) allocated FROM cadastre c WHERE c.number LIKE ? ORDER BY c.id', [
                '%'
                . $q
                . '%'
            ]);
            $out = [
            ];
            foreach ($rows as $r)$out[] = [
                self::link('/rental/edit-cadastre?id='
                . $r['id'],
                $r['number']),
                self::e($r['type']),
                self::e($r['floor']),
                self::e($r['area']),
                self::e($r['rentable']?'Доступен для сдачи':'Недоступен для сдачи')
            ];
            $h.=self::registry([
                'Кадастровый номер',
                'Тип',
                'Этаж',
                'Площадь, м²',
                'Доступность'
            ], $out);
        }
        elseif($page === 'objects') {
            $floor = (string)($_GET['floor'] ?? '');
            $show = isset($_GET['inactive']);
            $h.='<form><label>Этаж <input class="field-input" name="floor" value="'
            . self::e($floor)
            . '"></label><label><input type="checkbox" name="inactive"'
            . ($show?' checked':'')
            . '> Показывать неактивные</label><button class="btn">Применить</button></form>';
            $rows = self::query("SELECT o.*,c.number cadastral,(SELECT MAX(ct.end_date) FROM contract_objects co JOIN contracts ct ON ct.id=co.contract_id WHERE ".\App\ContractOccupancy::contractPredicate('co')." AND co.object_id=o.id AND ct.status IN ('active','sent','approved')) due_end,(SELECT GROUP_CONCAT(co.contract_id) FROM contract_objects co JOIN contracts ct ON ct.id=co.contract_id WHERE ".\App\ContractOccupancy::contractPredicate('co')." AND co.object_id=o.id AND ct.status IN ('active','sent','approved')) occupied FROM rental_objects o LEFT JOIN cadastre c ON c.id=o.cadastre_id WHERE o.name LIKE ? AND (?=1 OR o.active=1) AND (?='' OR o.floor=?) ORDER BY o.name", [
                '%'
                . $q
                . '%',
                (int)$show,
                $floor,
                $floor
            ]);
            $out = [
            ];
            foreach ($rows as $r)$out[] = [
                self::link('/rental/edit-object?id='
                . $r['id'],
                $r['name']),
                self::e($r['type']),
                self::e($r['floor']),
                self::e($r['area']),
                self::e(!$r['active']?'Неактивен':($r['occupied']?'Занят':'Свободен')),
                self::e($r['due_end'] ?? '—'),
                self::e($r['free_since']?'Простой с '
                . $r['free_since']:'Дата простоя неизвестна')
            ];
            $h.=self::registry([
                'Объект аренды',
                'Тип',
                'Этаж',
                'Площадь, м²',
                'Занятость',
                'Договор до',
                'Комментарий'
            ], $out);
        }
        elseif($page === 'organization') {
            $r = self::query('SELECT * FROM organization WHERE id=1')[0] ?? [
            ];
            $h.='<dl>';
            foreach ([
                'name' => 'Наименование',
                'inn' => 'ИНН',
                'address' => 'Адрес',
                'email' => 'Email',
                'vat' => 'НДС, %',
                'vat_from' => 'С даты'
            ] as $key => $label)$h.='<dt>'
            . self::e($label)
            . '</dt><dd>'
            . self::e($r[$key] ?? 'Не подтверждено')
            . '</dd>';
            $h.='</dl>';
            $out = [
            ];
            foreach (self::query('SELECT * FROM organization_bank_accounts ORDER BY is_primary DESC,id') as $a)$out[] = [
                self::link('/rental/edit-organization?id=1&bank_id='
                . urlencode($a['id']),
                $a['bank']),
                self::e($a['account']),
                self::e($a['bik']),
                self::e($a['correspondent_account']),
                $a['is_primary']?'Основной':'Дополнительный'
            ];
            $h.=self::link('/rental/edit-organization?id=1', 'Редактировать организацию')
            . self::registry([
                'Банк',
                'Счёт',
                'БИК',
                'Корреспондентский счёт',
                'Назначение'
            ], $out);
        }
        elseif($page === 'templates') {
            $out = [
            ];
            foreach (self::query('SELECT * FROM rental_templates ORDER BY id') as $r) {
                if (!WorkRegistry::matches($q,[$r['name'],$r['status'],$r['version']])) continue;
                $out[]=[
                    '_attrs'=>'data-rental-url="/rental/template?id='.(int)$r['id'].'" tabindex="0"',
                    self::e($r['name']),$r['version'] ?: 'Нет утверждённой',
                    $r['pending_version'] ? 'Версия '.(int)$r['pending_version'] : '—',
                    self::e(match($r['status']) {
                        'pending'=>'Ожидает решения руководителя',
                        'returned'=>'Вернуть юристу: '.$r['reason'],
                        default=>'Утвердил: '.($r['approved_by'] ?: \App\People::workflowName('director')),
                    }),
                ];
            }
            $h.=self::registry([
                'Шаблон',
                'Версия',
                'Новая версия',
                'Решение'
            ], $out,['flex','code','code','flex']);
        }
        elseif($page === 'journal') {
            $out = [
            ];
            foreach (self::query('SELECT * FROM rental_events WHERE description LIKE ? ORDER BY id DESC LIMIT 500', [
                '%'
                . $q
                . '%'
            ]) as $r)$out[] = [
                self::e($r['created_at']),
                self::e($r['actor_name']?:$r['actor_role']),
                self::e($r['description']),
                $r['contract_id']?self::link('/rental/contract?id='
                . $r['contract_id'],
                $r['contract_id']):'—'
            ];
            $h.=self::registry([
                'Время',
                'Роль',
                'Событие',
                'Договор'
            ], $out);
        }
        elseif($page === 'errors') {
            $h.=self::errors();
        }
        elseif($page === 'summary') {
            $out = [
            ];
            foreach (self::query('SELECT c.status,COUNT(*) count,SUM(c.amount) amount FROM contracts c GROUP BY c.status') as $r)$out[] = [
                self::e(self::status($r['status'])),
                self::e($r['count']),
                self::money($r['amount'])
            ];
            $h.=self::registry([
                'Состояние документов',
                'Количество',
                'Арендная плата'
            ], $out);
            $h.='<h2>Файлы документов</h2>';
            $out = [
            ];
            foreach (self::query('SELECT * FROM rental_files ORDER BY created_at DESC') as $r)$out[] = [
                self::link('/rental/download?id='
                . $r['id'],
                $r['filename']),
                self::e($r['entity_type']
                . ' #'
                . $r['entity_id']),
                self::e($r['version']),
                self::e($r['sha256'])
            ];
            $h.=self::registry([
                'Файл',
                'Документ',
                'Версия',
                'SHA-256'
            ], $out);
        }
        elseif($page === 'thresholds') {
            $out = [
            ];
            foreach (self::query("SELECT source_key,fact_json FROM rental_facts WHERE namespace='state' AND source_key IN ('thresholds','calendar','demoTime')") as $r)$out[] = [
                self::e($r['source_key']),
                self::e($r['fact_json'])
            ];
            $h.='<p>Информационное представление сохранённых правил. Рабочий календарь источника не объявляется подтверждённым производственным календарём.</p>'
            . self::registry([
                'Правило',
                'Источник'
            ], $out);
        }
        elseif($page === 'operations') {
            $out = [
            ];
            foreach (self::query("SELECT source_key,fact_json FROM rental_facts WHERE namespace='state' AND source_key IN ('operationGroups','workflowOutbox')") as $r) {
                $items = json_decode($r['fact_json'], true);
                foreach ($items as $item)$out[] = [
                    self::e($item['id'] ?? $item['key'] ?? ''),
                    self::e($item['description'] ?? $item['subject'] ?? ''),
                    self::e($item['status'] ?? 'Состояние источника'),
                    self::e(Importer::json($item))
                ];
            }
            $h.='<p>Исторические операции не воспроизводятся. UNKNOWN требует сверки результата. Ручная передача не считается принятием транспортом.</p>'
            . self::registry([
                'ID',
                'Операция',
                'Статус',
                'Evidence'
            ], $out);
            $h.=self::link('/email/history', 'История Email')
            . ' · '
            . self::link('/max/history', 'История MAX');
        }
        return View::page($title, $h, $page);
    }

    public static function status(string $v): string {
        return [
            'draft' => 'Черновик',
            'legal' => 'У юриста',
            'accounting' => 'В бухгалтерии',
            'returned' => 'Возвращён',
            'approved' => 'Подготовка отправки',
            'sent' => 'Ожидает подписи / оплаты',
            'active' => 'Действует',
            'reconciling' => 'Финальная сверка',
            'ended' => 'Аренда завершена',
            'closed' => 'Расчёты закрыты',
            'cancelled' => 'Не прошёл согласование'
        ][$v] ?? $v;
    }

    public static function partySelect(): string {
        $h = '<label>Контрагент<select class="field-input" name="party_id" required>';
        foreach (self::query('SELECT id,name FROM parties WHERE blocked=0 ORDER BY name') as $p)$h.='<option value="'
        . $p['id']
        . '">'
        . self::e($p['name'])
        . '</option>';
        return $h
        . '</select></label>';
    }

    private static function newContract(): string {
        $body = View::heading('Новый договор')
        . self::partySelect()
        . self::input('number', 'Номер договора (пусто — следующий номер)', '', 'text', false)
        . self::input('start_date', 'Начало аренды', date('Y-m-d'), 'date')
        . self::input('end_date', 'Окончание аренды', '', 'date')
        . self::input('amount', 'Сумма в месяц');
        $body.='<fieldset><legend>Объекты</legend>';
        foreach (self::query('SELECT id,name FROM rental_objects WHERE active=1 ORDER BY name') as $o)$body.='<label><input type="checkbox" name="objects[]" value="'
        . $o['id']
        . '">'
        . self::e($o['name'])
        . '</label>';
        $body.='</fieldset>';
        return View::page('Новый договор', self::form('contract.create', 0, 0, $body, 'Сохранить черновик'), 'contracts');
    }

    private static function contract(): string {
        $id = (int)($_GET['id'] ?? 0);
        $r = self::query('SELECT c.*,p.name party_name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?', [
            $id
        ])[0] ?? throw new \RuntimeException('Договор не найден.', 404);
        $role = Auth::user()['role'];
        $h = View::heading('Договор №'
        . $r['number'], $r['party_name']
        . ' · '
        . self::status($r['status']));
        $h.='<dl><dt>Срок</dt><dd>'
        . self::e($r['start_date']
        . ' — '
        . $r['end_date'])
        . '</dd><dt>Арендная плата</dt><dd>'
        . self::money($r['amount'])
        . '</dd><dt>Версия</dt><dd>'
        . $r['version']
        . '</dd><dt>Причина возврата</dt><dd>'
        . self::e($r['reason'])
        . '</dd></dl>';
        $out = [
        ];
        foreach (self::query('SELECT o.* FROM rental_objects o JOIN contract_objects co ON co.object_id=o.id WHERE co.contract_id=?', [
            $id
        ]) as $o)$out[] = [
            self::e($o['name']),
            self::e($o['area']),
            self::e($o['floor'])
        ];
        $h.=self::table([
            'Объект',
            'Площадь',
            'Этаж'
        ], $out);
        if (in_array($role, [
            'admin',
            'manager'
        ], true) && empty(json_decode($r['source_json'], true)['imported']) && in_array($r['status'], [
            'draft',
            'returned'
        ], true)) {
            $fields = '<input type="hidden" name="number" value="'
            . self::e($r['number'])
            . '"><p>Номер: '
            . self::e($r['number'])
            . '</p>';
            if ($r['status'] !== 'active')$fields.=self::input('start_date', 'Начало', $r['start_date'], 'date')
            . self::input('end_date', 'Окончание', $r['end_date'], 'date')
            . self::input('amount', 'Арендная плата', $r['amount']);
            $h.=self::form('contract.edit', $id, (int)$r['version'], $fields, 'Сохранить');
        }
        $checks = '<label>'
        . '<input type="checkbox" name="counterparty_confirmed" value="1"> Контрагент проверен</label>'
        . '<label>'
        . '<input type="checkbox" name="financial_confirmed" value="1"> Финансовые условия проверены</label>';
        foreach ([
            'submit' => [
                'manager',
                [
                    'draft',
                    'returned'
                ],
                'Передать юристу',
                ''
            ],
            'legal' => [
                'lawyer',
                [
                    'legal'
                ],
                'Согласовать и передать бухгалтеру',
                $checks
            ],
            'packet' => [
                'accountant',
                [
                    'accounting'
                ],
                'Подтвердить пакет',
                $checks
                . '<label><input type="checkbox" name="document_confirmed" value="1"> Документ проверен</label>'
            ],
            'return' => [
                $r['status'] === 'accounting'?'accountant':'lawyer',
                [
                    'legal',
                    'accounting'
                ],
                'Вернуть',
                self::input('reason',
                'Причина')
            ],
            'forward' => [
                'lawyer',
                [
                    'returned'
                ],
                'Вернуть менеджеру',
                self::input('reason',
                'Причина')
            ],
            'sign' => [
                'manager',
                [
                    'sent',
                    'active'
                ],
                'Подтвердить подписи обеих сторон',
                '<label><input type="checkbox" name="both_signed" value="1"> Подписан обеими сторонами</label>'
            ],
            'reconcile' => [
                'manager',
                [
                    'active'
                ],
                'Начать финальную сверку',
                self::input('reason',
                'Причина')
                . self::input('finish_date',
                'Дата завершения',
                $r['end_date'],
                'date')
            ],
            'return-act' => [
                'manager',
                [
                    'reconciling'
                ],
                'Подтвердить фактический возврат',
                self::input('return_date',
                'Дата возврата',
                date('Y-m-d'),
                'date')
                . '<label><input type="checkbox" name="both_signed" value="1"> Подписи обеих сторон</label>'
            ],
            'close' => [
                'accountant',
                [
                    'ended'
                ],
                'Закрыть расчёты',
                ''
            ],
            'handed' => [
                'manager',
                [
                    'approved'
                ],
                'Подтвердить личную передачу',
                '<label><input type="checkbox" name="confirmed" value="1"> Пакет передан лично (не подтверждение транспорта)</label>'
            ],
            'send' => [
                'accountant',
                [
                    'approved'
                ],
                'Подготовить отправку пакета',
                ''
            ],
            'cancel' => [
                'manager',
                [
                    'draft',
                    'returned',
                    'legal',
                    'accounting'
                ],
                'Прекратить согласование',
                self::input('reason',
                'Причина')
            ]
        ] as $action => $cfg)if (($role === 'admin' || $role === $cfg[0]) && in_array($r['status'], $cfg[1], true))$h.=self::form('contract.'
        . $action, $id, (int)$r['version'], $cfg[3], $cfg[2]);
        if (in_array($role, [
            'admin',
            'lawyer'
        ], true))$h.='<form action="/rental/generate" method="post"><input type="hidden" name="_csrf" value="'
        . self::e(Http::csrf())
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . $r['version']
        . '"><button class="btn" name="format" value="docx">Сформировать Word</button></form>';
        $h.=AuxController::contractActions($r);
        $h.='<h2>Документы</h2>';
        $out = [
        ];
        foreach (self::query("SELECT * FROM rental_files WHERE entity_type='contract' AND entity_id=? ORDER BY created_at DESC", [
            $id
        ]) as $f)$out[] = [
            self::link('/rental/download?id='
            . $f['id'],
            $f['filename']),
            self::e($f['version']),
            self::e($f['created_at'])
        ];
        $h.=self::table([
            'Файл',
            'Версия',
            'Создан'
        ], $out);
        $h.='<h2>Счета</h2>';
        $out = [
        ];
        foreach (self::query('SELECT * FROM invoices WHERE contract_id=? ORDER BY issued DESC', [
            $id
        ]) as $i)$out[] = [
            self::link('/rental/invoice?id='
            . $i['id'],
            $i['number']),
            self::e($i['kind']
            . ' · '
            . $i['period']),
            self::money($i['amount']),
            self::money($i['paid'])
        ];
        $h.=self::table([
            'Номер',
            'Назначение',
            'Сумма',
            'Оплата'
        ], $out);
        $h.=self::link('/rental/documents?contract_id='
        . $id, 'Дополнительные соглашения и будущие условия');
        return View::page('Договор №'
        . $r['number'], $h, 'contracts');
    }

    private static function invoice(): string {
        $id = (int)($_GET['id'] ?? 0);
        $r = self::query('SELECT * FROM invoices WHERE id=?', [
            $id
        ])[0] ?? throw new \RuntimeException('Счёт не найден.', 404);
        $h = View::heading('Счёт '
        . $r['number'], $r['kind']
        . ' · '
        . $r['period'])
        . '<p>Начислено: '
        . self::money($r['amount'])
        . ' · Оплачено: '
        . self::money($r['paid'])
        . '</p>'
        . self::link('/rental/contract?id='
        . $r['contract_id'], 'Договор');
        $h.='<form method="post" action="/rental/invoice-pdf"><input type="hidden" name="_csrf" value="'
        . self::e(Http::csrf())
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><button class="btn">Сформировать PDF счёта</button></form>';
        if (in_array(Auth::user()['role'], [
            'admin',
            'accountant'
        ], true))$h.=self::form('payment.allocate', $id, (int)$r['version'], self::input('payment_id', 'ID прихода', '', 'number')
        . self::input('amount', 'Сумма распределения'), 'Распределить оплату');
        $out = [
        ];
        foreach (self::query('SELECT p.*,a.amount allocated FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.invoice_id=?', [
            $id
        ]) as $a)$out[] = [
            self::e($a['id']),
            self::e($a['payment_date']),
            self::money($a['allocated']),
            self::e($a['source'])
        ];
        $h.=self::table([
            'Приход',
            'Дата',
            'Распределено',
            'Источник'
        ], $out);
        return View::page('Счёт '
        . $r['number'], $h, 'invoices');
    }

    private static function errors(): string {
        $employee = (string)($_GET['employee'] ?? '');
        $tasks = Indicators::tasks();
        $summary = [
        ];
        $counts = [
        ];
        $out = [
        ];
        $idle = [
        ];
        foreach ($tasks as $task) {
            if ($task['severity'] !== 'red')continue;
            if ($task['entity'] === 'object') {
                if ($task['role'] === 'manager')$idle[] = [
                    self::link('/rental/edit-object?id='
                    . $task['id'],
                    'Объект #'
                    . $task['id']),
                    self::e($task['text'])
                ];
                continue;
            }
            $counts[$task['role']] = ($counts[$task['role']] ?? 0)+1;
            if (!$employee || $employee === $task['role'])$out[] = [
                self::e($task['role']),
                self::link($task['entity'] === 'document'?'/rental/document?id='
                . $task['id']:$task['url'],
                $task['entity']
                . ' #'
                . $task['id']),
                self::e($task['text'])
            ];
        }
        foreach ($counts as $role => $count)$summary[] = [
            self::link('/rental/errors?employee='
            . $role,
            $role),
            self::e($count)
        ];
        return '<h2>Красные задачи · сводка по сотрудникам</h2>'
        . self::table([
            'Ответственный',
            'Задач'
        ], $summary)
        . '<h2>Задачи контроля</h2>'
        . self::table([
            'Ответственный',
            'Документ',
            'Причина'
        ], $out)
        . '<h2>Простой активных объектов 30 дней и более</h2>'
        . self::table([
            'Объект',
            'Причина'
        ], $idle);
    }

    public static function completionTarget(string $action, array $input): ?string {
        if (in_array($action, ['contract.create', 'contract.edit', 'document.create', 'document.edit'], true)) {
            return ($input['intent'] ?? 'draft') === 'submit' ? '/rental/approvals' : '/rental/contracts';
        }
        if ($action === 'contract.delete') return '/rental/contracts';
        if ($action === 'contract.submit') return '/rental/approvals';
        if (str_starts_with($action, 'reservation.')) return '/rental/contracts?flag=booking';
        if (str_starts_with($action,'party.')) return '/rental/parties';
        if (str_starts_with($action,'organization.')) return '/rental/organization';
        if ($action==='cadastre.save') return '/rental/cadastre';
        if ($action==='object.save') return '/rental/objects';
        return null;
    }

    public static function workflowMaxNotice(?int $freshEventId,string $commandKey,string $action):string {
        if($freshEventId===null)return '';
        try{
            $actor=Auth::user();
            if(!$actor)return '';
            if (StaffHandoff::managed($action) && \Modules\Integrations\ServerWorkflowMax::selectedStaffEvent(DB::pdo(),$freshEventId)) {
                $delivery=(new \Modules\Integrations\ServerWorkflowMax(DB::pdo(),true))->afterCommittedCommand($freshEventId,$commandKey,$actor);
            } else {
                $delivery=(new \Modules\Integrations\ObjectActivityMax(DB::pdo()))->afterCommittedCommand($freshEventId,$commandKey,$actor);
            }
            if ($delivery===null) {
                if(\Modules\Integrations\ServerWorkflowMax::settings()!==null){
                    $delivery=(new \Modules\Integrations\ServerWorkflowMax(DB::pdo()))->afterCommittedCommand($freshEventId,$commandKey,$actor);
                }elseif(\Modules\Integrations\ContractWorkflowRelay::configured()){
                    $delivery=(new \Modules\Integrations\ContractWorkflowRelay(DB::pdo()))->afterCommittedCommand($freshEventId,$commandKey,$actor);
                }elseif(\Modules\Integrations\LocalWorkflowMax::configured()){
                    $delivery=(new \Modules\Integrations\LocalWorkflowMax(DB::pdo()))->afterCommittedCommand($freshEventId,$commandKey,$actor);
                }else{
                    if(!\Modules\Integrations\WorkflowMaxEvent::postcommitModeAllowed(PHP_SAPI,getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')))return '';
                    $delivery=(new \Modules\Integrations\WorkflowMaxEvent(DB::pdo()))->afterCommittedCommand($freshEventId,$commandKey,$actor);
                }
            }
            return match($delivery['status']??'uncertain'){
                'disabled','skipped'=>'',
                'accepted'=>' MAX принял уведомление; прочтение не подтверждено.',
                'unavailable'=>' Уведомление MAX не отправлено: в этой среде отправка не разрешена.',
                default=>' Результат уведомления MAX не подтверждён; автоматического повтора нет.',
            };
        }catch(\Throwable $error){
            // The domain transaction is already committed. Delivery failure is never a command failure.
            return self::workflowMaxFailure($error);
        }
    }

    private static function workflowMaxFailure(\Throwable $error):string
    {
        if ($error->getMessage()===\Modules\Integrations\ServerWorkflowMax::RECONCILIATION_REQUIRED)
            return ' Уведомление MAX не отправлено. Сверьте результат предыдущей попытки. Это событие автоматически повторено не будет.';
        if ($error->getMessage()==='STAFF_HANDOFF_PROBE_ALREADY_CLAIMED')
            return ' Разовый допуск MAX уже использован. Повтора не было.';
        if (str_starts_with($error->getMessage(),'STAFF_HANDOFF_PROBE_')
            || str_starts_with($error->getMessage(),'STAFF_HANDOFF_OBSERVATION_'))
            return ' Данные сохранены. Уведомление MAX не отправлено. Условия разовой пробы требуют сверки.';
        return ' Данные сохранены. Уведомление MAX не подтверждено; автоматического повтора нет.';
    }

    public static function action(): never {
        Http::checkCsrf();
        $in = $_POST;
        unset($in['_csrf']);
        $action = (string)($in['action'] ?? '');
        $target = match (true) {
            in_array($action,OnecRequest::ACTIONS,true) => OnecRequest::route((int)($in['id']??0)),
            in_array($action,InitialInvoice::ACTIONS,true) => InitialInvoiceCard::route((int)($in['id']??0)),
            $action===LeasePaymentTerms::SAVE => LeasePaymentTermsCard::route((int)($in['id']??0),(string)($in['month']??'')),
            $action===DebtFollowUp::SAVE => DebtFollowUpCard::route((int)($in['invoice_id']??0)),
            str_starts_with($action,'closing.') => '/rental/contract?id='.(int)($in['id'] ?? 0),
            in_array($action,RentalServices::ACTIONS,true) => '/rental/services?contract_id='.(int)($in['id']??0),
            in_array($action,[PremisesInventory::SAVE,PremisesInventory::MOVE],true) => '/rental/object-inventory?id='.(int)($in['id']??0),
            in_array($action,[PremisesActs::COUNT,PremisesActs::CONFIRM],true) => '/rental/acts?id='.(int)($in['id']??0),
            str_starts_with($action, 'contract.'), str_starts_with($action, 'reconcile.') => '/rental/contract?id='
            . (int)($in['id'] ?? 0), str_starts_with($action, 'document.') => '/rental/document?id='
            . (int)($in['id'] ?? 0), str_starts_with($action, 'party.') => '/rental/parties', str_starts_with($action, 'object.') => '/rental/objects', str_starts_with($action, 'cadastre.') => '/rental/cadastre', str_starts_with($action, 'organization.') => '/rental/organization', str_starts_with($action, 'reservation.') => '/rental/reservations', str_starts_with($action, 'template.') => '/rental/templates', default => '/rental/invoices'
        };
        try {
            unset($in['object_plan']);
            if ($action==='object.save') {
                $plan=ObjectPlan::upload($_FILES['object_plan'] ?? null);
                if ($plan!==null) $in['object_plan']=$plan;
            }
            $freshCommittedEventId=null;
            $result = Service::command($action, $in, $freshCommittedEventId);
            if (in_array($action,RentalServices::ACTIONS,true) && !empty($result['service_id'])) $target='/rental/service?id='.$result['service_id'];
            if ($action===PremisesInventory::MOVE) $target='/rental/object-inventory?id='.(int)$result['id'];
            $maxNotice=self::workflowMaxNotice($freshCommittedEventId,(string)($in['command_key']??''),$action);
            if ($action === 'party.save' && empty($in['id']) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
                $party = self::query('SELECT id,name,inn FROM parties WHERE id=?', [
                    $result['id']
                ])[0];
                Http::json([
                    'createdParty' => [
                        'id' => (int)$party['id'],
                        'name' => $party['name'],
                        'description' => $party['inn']?'ИНН: '
                        . $party['inn']:''
                    ]
                ]);
            }
            if (str_starts_with($action, 'reservation.') && !empty($result['id']))$target = '/rental/booking?id='
            . $result['id'];
            if (str_starts_with($action,'party.review.')) $target='/rental/party?id='.(int)$result['id'];
            $_SESSION['rental_notice'] = ($result['message'] ?? 'Сохранено.').$maxNotice;
            if(is_string($in['command_key']??null))$_SESSION['rental_top_command']=$in['command_key'];
            $completion = self::completionTarget($action, $in);
            if ($completion !== null) {
                $workspace=FinanceController::workspaceTarget($_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? null);
                if (parse_url($workspace,PHP_URL_PATH)===$completion) $completion=$workspace;
                $target = $completion;
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
                    header('X-Rental-Completion-URL: '.$completion);
                    header('Content-Type: text/html; charset=UTF-8');
                    exit;
                }
            }
            if (isset($result['id']) && in_array($action, [
                'contract.create',
                'document.create'
            ], true) && $completion === null)$target = '/rental/'
            . ($action === 'contract.create'?'contract':'document')
            . '?id='
            . $result['id'];
            if ($completion===null && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch'
                && preg_match('~^/rental/(contract|document)\?id=(\d+)$~D',$target,$recordTarget)) {
                $kind=$recordTarget[1];$recordId=(int)$recordTarget[2];
                $state=self::query('SELECT status FROM '.($kind==='contract'?'contracts':'rental_documents').' WHERE id=?',[$recordId])[0]['status'] ?? '';
                $default='/rental/'.($kind==='document' && in_array($state,['signed','applied'],true)?'contracts-active':Access::statusPages($state)[0]);
                $requested=$_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? null;
                $workspace=FinanceController::workspaceTarget($requested,$default);
                $previous=$_GET;$_GET=['id'=>$recordId];
                try {
                    $body=$kind==='contract'?SourceCards::show():AddendumController::card();
                } catch (\RuntimeException $error) {
                    if ($error->getCode()!==403) throw $error;
                    header('X-Rental-Completion-URL: '.$workspace);
                    exit;
                } finally {$_GET=$previous;}
                header('X-Rental-Refresh-URL: '.$workspace);
                header('Content-Type: text/html; charset=UTF-8');
                echo $body;
                exit;
            }
        }
        catch (\Throwable $e) {
            if (in_array($e->getCode(), [
                401,
                403
            ], true))throw $e;
            $previewChanged = $e->getCode() === 409 && $e->getMessage() === NativePacket::PREVIEW_CHANGED;
            $message = match ($e->getMessage()) {
                NativePacket::PREVIEW_CHANGED => PacketForm::CHANGED_MESSAGE,
                'NATIVE_PACKET_LEGAL_FILE_SUPERSEDED', 'NATIVE_PACKET_LEGAL_BASELINE_CHANGED',
                'NATIVE_PACKET_DOCUMENT_BASE_CHANGED' => 'Документ или условия изменились. Попросите юриста проверить актуальный комплект.',
                'NATIVE_PACKET_NOT_AT_ACCOUNTANT' => 'Этап согласования изменился. Откройте карточку заново.',
                default => $e->getMessage(),
            };
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
                http_response_code($e->getCode() === 409?409:422);
                if ($previewChanged) {
                    $previousGet = $_GET;
                    $_GET = ['id' => (int)$in['id'], 'action' => $action];
                    try {
                        $body = $action === 'contract.packet' ? SourceCards::action($in) : AddendumController::action($in);
                        header('X-Rental-Form-Refreshed: 1');
                        echo $body;
                    } catch (\RuntimeException) {
                        echo RentalUI::alert('Пакет изменился. Закройте окно и откройте карточку заново.', '', 'error');
                    } finally {
                        $_GET = $previousGet;
                    }
                    exit;
                }
                echo RentalUI::alert($e instanceof \PDOException?'Не удалось сохранить: проверьте связанные записи и уникальность номера.':$message, '', 'error');
                exit;
            }
            $_SESSION['rental_notice'] = $e instanceof \PDOException?'Не удалось сохранить: проверьте уникальность номера и связанные записи.':$message;
            if (empty($in['id']) && $action === 'contract.create')$target = '/rental/new-contract';
        }
        Http::redirect($target);
    }
}
