<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, DB, Http, View};
use RuntimeException;
final class AddendumController {
    private static function record(int $id):array {
        Access::document($id);
        return Controller::query('SELECT d.*,c.number base_number,p.name party_name FROM rental_documents d JOIN contracts c ON c.id=d.contract_id JOIN parties p ON p.id=c.party_id WHERE d.id=?', [
            $id
        ])[0] ?? throw new RuntimeException('ДС не найдено.', 404);
    }
    public static function listing():string {
        Auth::requirePermission('rental.read');
        Access::pages([
            'contracts',
            'contracts-active',
            'approvals'
        ]);
        $id = (int)($_GET['contract_id'] ?? 0);
        $c = $id?Access::contract($id):null;
        $rows = [
        ];
        foreach (Controller::query('SELECT d.*,c.number base_number FROM rental_documents d JOIN contracts c ON c.id=d.contract_id WHERE (?=0 OR d.contract_id=?) ORDER BY d.id DESC', [
            $id,
            $id
        ]) as $d) {
            try {
                Access::document((int)$d['id']);
            }
            catch (RuntimeException $e) {
                if ($e->getCode() === 403)continue;
                throw $e;
            }
            $rows[] = [
                Controller::link('/rental/document?id='
                . $d['id'],
                'ДС №'
                . $d['number']
                . ' от '
                . Documents::dateText($d['document_date'])),
                View::e(Controller::status($d['status'])
                . ($d['applied']?' · условия применены':'')),
                View::e(Documents::dateText($d['effective_date']))
            ];
        }
        $actions = $c?RentalUI::button('Открыть договор', '/rental/contract?id='
        . $id):'';
        if ($c && $c['status'] === 'active' && in_array(Auth::user()['role'], [
            'admin',
            'manager'
        ], true))$actions.=RentalUI::button('Создать допсоглашение', '/rental/addendum-form?contract_id='
        . $id, true);
        return RentalUI::modal('Допсоглашения'
        . ($c?' · '
        . $c['number']:''), Controller::table([
            'Документ',
            'Стадия',
            'Вступает в силу'
        ], $rows), $actions, 'contracts-active');
    }
    public static function editor():string {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('rental.write');
        Service::roles([
            'manager'
        ]);
        $id = (int)($_GET['id'] ?? 0);
        $d = $id?self::record($id):null;
        $cid = (int)($d['contract_id'] ?? $_GET['contract_id'] ?? 0);
        $c = Access::contract($cid);
        if ($c['status'] !== 'active' || ($d && ($d['applied'] || !in_array($d['status'], [
            'draft',
            'returned'
        ], true) || ($d['status'] === 'returned' && $d['return_to'] !== 'manager'))))throw new RuntimeException('Редактирование ДС не назначено менеджеру.', 403);
        if ($d && $d['base_hash'] !== Evidence::documentBase(DB::pdo(), $cid))throw new RuntimeException('Исходные условия договора изменились.', 409);
        $q = $d?'SELECT t.*,o.name,o.type FROM document_terms t JOIN rental_objects o ON o.id=t.object_id WHERE t.document_id=? ORDER BY o.id':'SELECT o.id object_id,o.name,o.type,COALESCE(t.start_date,c.start_date) start_date,COALESCE(t.end_date,c.end_date) end_date,t.amount FROM contract_objects co JOIN contracts c ON c.id=co.contract_id JOIN rental_objects o ON o.id=co.object_id LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2 WHERE t2.contract_id=co.contract_id AND t2.object_id=co.object_id) WHERE co.contract_id=? ORDER BY o.id';
        $terms = Controller::query($q, [
            $d['id'] ?? $cid
        ]);
        $form = 'rental-addendum-form';
        $body = '<form id="'
        . $form
        . '" data-rental-contract-form method="post" action="/rental/action"><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '"><input type="hidden" name="action" value="'
        . ($d?'document.edit':'document.create')
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . ($d['version'] ?? 0)
        . '"><input type="hidden" name="contract_id" value="'
        . $cid
        . '"><input type="hidden" name="base_version" value="'
        . $c['version']
        . '"><input type="hidden" name="kind" value="renewal">'
        . RentalUI::pairs([
            [
                'Основной договор',
                '№'
                . $c['number']
                . ' от '
                . Documents::dateText($c['document_date'])
            ],
            [
                'Контрагент',
                Controller::query('SELECT name FROM parties WHERE id=?',
                [
                    $c['party_id']
                ])[0]['name']
            ]
        ])
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . Controller::input('number', 'Номер допсоглашения', $d['number'] ?? '', 'text', true)
        . Controller::input('document_date', 'Дата допсоглашения', $d['document_date'] ?? date('Y-m-d'), 'date', true)
        . Controller::input('base_date', 'Дата базового договора', $d['base_date'] ?? $c['document_date'], 'date', false)
        . Controller::input('effective_date', 'Дата вступления в силу', $d['effective_date'] ?? date('Y-m-d'), 'date', true)
        . '</div>'
        . '<span class="registry-ui-modal-help">Укажите полный состав помещений, сроки и месячную стоимость каждого. Новые условия применяются после подписания с даты вступления ДС в силу.</span>'
        . '<div class="table-scroll">'
        . '<table class="table registry-ui-table registry-ui-compact-table">'
        . '<thead>'
        . '<tr>'
        . '<th>Объект</th>'
        . '<th>Начало</th>'
        . '<th>Окончание</th>'
        . '<th>В месяц</th>'
        . '<th>Состав</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody data-rental-terms>';
        $total = '0.00';
        foreach (\App\BillingParts::terms(DB::pdo(), $d?'document':'contract', (int)($d['id'] ?? $cid)) as $part) {
            if ($part['amount'] !== null) $total = bcadd($total, $part['amount'], 2);
        }
        foreach ($terms as $t) {
            $oid = $t['object_id'];
            if ($t['amount'] !== null)$total = bcadd($total, $t['amount'], 2);
            $body.='<tr data-rental-term-id="'
            . $oid
            . '"><td>'
            . View::e($t['name'])
            . '<input type="hidden" name="objects[]" value="'
            . $oid
            . '"></td><td>'
            . Controller::input('term_start['
            . $oid
            . ']', 'Начало', $t['start_date'], 'date', true)
            . '</td><td>'
            . Controller::input('term_end['
            . $oid
            . ']', 'Окончание', $t['end_date'], 'date', true)
            . '</td><td>'
            . Controller::input('term_amount['
            . $oid
            . ']', 'Сумма с НДС', $t['amount'], 'text', true)
            . '</td><td><button type="button" class="registry-ui-reset" data-rental-remove-object>Убрать</button></td></tr>';
        }
        $body.='</tbody><tfoot><tr><td colspan="5" class="registry-ui-number">Итого <input type="hidden" name="amount" value="'
        . $total
        . '"><strong data-rental-contract-total>'
        . View::e(Documents::amountText($total))
        . '</strong>'
        . '</td>'
        . '</tr>'
        . '</tfoot>'
        . '</table>'
        . '</div>'
        . '<div class="registry-ui-modal-address-row">'
        . '<label class="field">'
        . '<span class="field-label">Добавить помещение</span>'
        . '<select class="field-input field-select" data-rental-object-picker>'
        . '<option value="">Выберите помещение</option>';
        foreach (Controller::query('SELECT id,name,type FROM rental_objects WHERE active=1 ORDER BY name') as $o)$body.='<option value="'
        . $o['id']
        . '" data-name="'
        . View::e($o['name'])
        . '" data-type="'
        . View::e($o['type'])
        . '">'
        . View::e($o['name'])
        . '</option>';
        $body.='</select>'
        . '</label>'
        . '<button type="button" class="btn btn-secondary" data-rental-add-object>Добавить</button>'
        . '</div>'
        . '<template data-rental-term-template>'
        . '<tr data-rental-term-id="__ID__">'
        . '<td>'
        . '<span data-rental-term-name>'
        . '</span>'
        . '<input type="hidden" name="objects[]" value="__ID__">'
        . '</td>'
        . '<td>'
        . Controller::input('term_start[__ID__]', 'Начало', $d['effective_date'] ?? date('Y-m-d'), 'date', true)
        . '</td><td>'
        . Controller::input('term_end[__ID__]', 'Окончание', $d['end_date'] ?? $c['end_date'], 'date', true)
        . '</td><td>'
        . Controller::input('term_amount[__ID__]', 'Сумма с НДС', '', 'text', true)
        . '</td><td><button type="button" class="registry-ui-reset" data-rental-remove-object>Убрать</button></td></tr></template>'
        . PartTerms::render(DB::pdo(), $d?'document':'contract', (int)($d['id'] ?? $cid),
            $d['start_date'] ?? $c['start_date'], $d['end_date'] ?? $c['end_date']).'</form>';
        $actions = (!$d || $d['status'] === 'draft'?'<button class="btn btn-secondary" type="submit" form="'
        . $form
        . '" name="intent" value="draft">Сохранить в черновиках</button>':'')
        . '<button class="btn btn-primary" type="submit" form="'
        . $form
        . '" name="intent" value="submit">Отправить на согласование</button>';
        return RentalUI::modal($d?'Изменить допсоглашение':'Допсоглашение к действующему договору', $body, $actions, 'contracts-active', true);
    }
    public static function card():string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $d = self::record($id);
        $c = Controller::query('SELECT id,status FROM contracts WHERE id=?', [
            (int)$d['contract_id']
        ])[0] ?? throw new RuntimeException('Договор не найден.', 404);
        $role = Auth::user()['role'];
        $target = match ($d['status']) {
            'legal' => 'lawyer', 'accounting', 'approved' => 'accountant', 'returned' => $d['return_to'] ?? 'manager', default => 'manager'
        };
        $body = '';
        $delivery=Delivery::presentation(\App\DB::pdo(),'document',$id);
        if ($delivery && $delivery['message']!=='') $body.=RentalUI::alert('Документы и доставка',$delivery['message'],$delivery['error']?'error':'info');
        if (isset($_SESSION['rental_notice'])) {
            $body.=RentalUI::alert($_SESSION['rental_notice']);
            unset($_SESSION['rental_notice']);
        }
        $body.=RentalUI::alert($d['status'] === 'cancelled'?'Проект аннулирован':($d['status'] === 'returned'?'Возврат на доработку':($d['status'] === 'signed'?'Соглашение подписано':'Согласование допсоглашения')), $d['reason']?:($d['status'] === 'draft'?'Черновик допсоглашения. Согласование ещё не начато.':'Текущий исполнитель: '
        . $target), in_array($d['status'], [
            'returned',
            'cancelled'
        ], true)?'error':'info')
        . RentalUI::pairs([
            [
                'Номер ДС',
                $d['number']
            ],
            [
                'Дата ДС',
                Documents::dateText($d['document_date'])
            ],
            [
                'Стадия',
                Controller::status($d['status'])
            ],
            [
                'Начало новых условий',
                Documents::dateText($d['effective_date'])
            ],
            [
                'Полный срок до',
                Documents::dateText($d['end_date'])
            ],
            [
                'Месячная аренда с НДС',
                Controller::money($d['amount'])
            ],
            [
                'Основной договор',
                $d['base_number']
                . ' · '
                . Controller::status($c['status'])
            ]
        ]);
        $rows = [
        ];
        foreach (Controller::query('SELECT t.*,o.name FROM document_terms t JOIN rental_objects o ON o.id=t.object_id WHERE document_id=? ORDER BY o.id', [
            $id
        ]) as $t)$rows[] = [
            View::e($t['name']),
            View::e(Documents::dateText($t['start_date'])),
            View::e(Documents::dateText($t['end_date'])),
            Controller::money($t['amount'])
        ];
        foreach (\App\BillingParts::terms(DB::pdo(), 'document', $id) as $part) $rows[] = [
            View::e(PartTerms::label($part)),
            View::e(Documents::dateText($part['start_date'])), View::e(Documents::dateText($part['end_date'])),
            Controller::money($part['amount']),
        ];
        $body.=Controller::table([
            'Объект',
            'Начало',
            'Окончание',
            'В месяц с НДС'
        ], $rows)
        . '<span class="registry-ui-modal-help">ДС содержит полный новый состав условий. Обеспечение и первый арендный счёт выставляются только по добавленным помещениям и уходят вместе с ДС.</span>'
        . '<div class="demo-row">'
        . RentalUI::button('Сформировать и посмотреть', '/rental/addendum-preview?id='
        . $id)
        . RentalUI::button('Счета', '/rental/invoice-list?contract_id='
        . $c['id'])
        . RentalUI::button('История', '/rental/history?contract_id='
        . $c['id'])
        . RentalUI::button('Основной договор', '/rental/contract?id='
        . $c['id'])
        . '</div>';
        $actions = '';
        $add = function(string $label, string $action, bool $primary = false)use(&$actions, $id) {
            $user=Auth::user();
            $grant=match($user['role']) {'lawyer'=>'rental.approve.legal','accountant'=>'finance.write','director'=>'rental.approve.executive',default=>'rental.write'};
            if (!Auth::can($grant) || \App\PagePolicy::level(\App\PagePolicy::commandPage($action),$user)<2) return;
            $actions.=RentalUI::button($label, '/rental/addendum-action?id='
            . $id
            . '&action='
            . $action, $primary);
        };
        if (($role === $target || $role === 'admin') && !$d['applied'] && !in_array($d['status'], [
            'cancelled'
        ], true)) {
            if ($d['status'] === 'draft' || ($d['status'] === 'returned' && $target === 'manager')) {
                $actions.=RentalUI::button('Редактировать черновик', '/rental/addendum-form?id='
                . $id);
                $add('Отправить на согласование', 'document.submit', true);
            }
            if (in_array($d['status'], [
                'legal',
                'accounting'
            ], true)) {
                $add('Вернуть на доработку', 'document.return');
                $add('Согласовано', $d['status'] === 'legal'?'document.legal':'document.packet', true);
            }
            if ($d['status'] === 'returned' && $target === 'lawyer')$add('Передать менеджеру', 'document.forward', true);
            if ($d['status'] === 'approved')$add('Отправить ДС и счета', 'document.send', true);
            if ($delivery && $delivery['error'] && in_array($role,['admin','manager','accountant'],true)) $add('Документы переданы клиенту','document.handed');
            if ($d['status'] === 'sent')$add('Загрузить подписанное соглашение', 'document.sign', true);
        }
        if (in_array($role, [
            'admin',
            'manager',
            'director'
        ], true) && !$d['applied'] && $d['status'] !== 'cancelled')$add('Аннулировать', 'document.cancel');
        $holds = Controller::query('SELECT h.*,o.name FROM rental_object_holds h JOIN rental_objects o ON o.id=h.object_id WHERE '.\App\ContractOccupancy::physicalPredicate('h').' AND document_id=?', [
            $id
        ]);
        if ($holds) {
            $out = [
            ];
            foreach ($holds as $h)$out[] = [
                View::e($h['name']),
                $h['return_confirmed']?'Возврат подтверждён '
                . Documents::dateText($h['return_date']):'Ожидается подписанный акт возврата'
            ];
            $body.='<span class="registry-ui-modal-summary">Исключённые помещения</span>'
            . Controller::table([
                'Помещение',
                'Возврат'
            ], $out);
            if ($d['applied'] && in_array($role, [
                'admin',
                'manager'
            ], true) && array_filter($holds, fn($h) => !$h['return_confirmed']))$add('Акт возврата исключённых помещений', 'document.return-removed', true);
        }
        return RentalUI::modal('Допсоглашение №'
        . $d['number']
        . ' · '
        . $d['base_number'], $body, $actions, 'approvals');
    }
    public static function action():string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $d = self::record($id);
        $action = (string)($_GET['action'] ?? '');
        \App\PagePolicy::requireCommand($action);
        if ($action==='document.send' && $d['status']==='approved' && PacketForm::requiresConfirmation('document',$id)) $action='document.packet';
        $allowed = [
            'document.submit',
            'document.legal',
            'document.packet',
            'document.return',
            'document.forward',
            'document.handed',
            'document.send',
            'document.sign',
            'document.cancel',
            'document.return-removed'
        ];
        if (!in_array($action, $allowed, true))throw new RuntimeException('Действие не найдено.', 404);
        $fields = RentalUI::pairs([
            [
                'Документ',
                'ДС №'
                . $d['number']
                . ' к договору '
                . $d['base_number']
            ]
        ]);
        $extra = '';
        if (in_array($action, [
            'document.return',
            'document.forward',
            'document.cancel'
        ], true))$fields.=Controller::input('reason', 'Причина', $action === 'document.forward'?$d['reason']:'', 'text', true);
        if ($action === 'document.handed')$fields.='<label class="registry-ui-checkbox">'
        . '<input type="checkbox" name="confirmed" value="1" required>'
        . '<span>Документы переданы клиенту лично; это не подтверждение транспорта</span>'
        . '</label>';
        if (in_array($action, [
            'document.sign',
            'document.return-removed'
        ], true)) {
            $fields.='<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required><span>Подписано обеими сторонами</span></label>';
            $extra = AuxController::uploadForm($d, $action === 'document.sign'?'signed-addendum':'removed-return-act', 'Подписанный файл', 'document');
            if ($action === 'document.return-removed')$fields.=Controller::input('return_date', 'Дата возврата', date('Y-m-d'), 'date', true);
        }
        if ($action === 'document.packet')$fields.=PacketForm::preview('document', $id);
        if ($action === 'document.packet')foreach ([
            'counterparty_confirmed' => 'Контрагент проверен',
            'financial_confirmed' => 'Финансовые условия проверены',
            'document_confirmed' => 'Документ и пакет проверены'
        ] as $key => $label)$fields.='<label class="registry-ui-checkbox"><input type="checkbox" name="'
        . $key
        . '" value="1" required><span>'
        . $label
        . '</span></label>';
        if ($action === 'document.legal')$extra = AuxController::uploadForm($d, 'lawyer-file', 'Актуальный Word или PDF', 'document')
        . self::generateForm($d);
        $fid = 'rental-ds-action';
        $body = '<form id="'
        . $fid
        . '" method="post" action="/rental/action"><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . $d['version']
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '"><input type="hidden" name="action" value="'
        . $action
        . '">'
        . $fields
        . '</form>'
        . $extra;
        return RentalUI::modal('Проверка допсоглашения №'
        . $d['number'], $body, '<button class="btn btn-primary" type="submit" form="'
        . $fid
        . '">Подтвердить</button>', 'approvals');
    }
    private static function generateForm(array $d):string {
        return '<form method="post" action="/rental/generate"><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="entity_type" value="document"><input type="hidden" name="id" value="'
        . $d['id']
        . '"><input type="hidden" name="version" value="'
        . $d['version']
        . '">'
        . '<button class="btn btn-secondary" name="format" value="docx">Сформировать Word ДС</button>'
        . '<button class="btn btn-secondary" name="format" value="pdf">Сформировать PDF</button>'
        . '</form>';
    }
    public static function preview():string {
        Auth::requirePermission('rental.read');
        $d = self::record((int)($_GET['id'] ?? 0));
        $rows = [
        ];
        foreach (Controller::query("SELECT * FROM rental_files WHERE entity_type='document' AND entity_id=? ORDER BY created_at DESC", [
            $d['id']
        ]) as $f)$rows[] = [
            Controller::link('/rental/download?id='
            . $f['id'],
            $f['filename']),
            View::e($f['version']),
            View::e($f['sha256'])
        ];
        $body = RentalUI::pairs([
            [
                'Документ',
                'ДС №'
                . $d['number']
            ],
            [
                'Основной договор',
                $d['base_number']
            ],
            [
                'Дата ДС',
                Documents::dateText($d['document_date'])
            ],
            [
                'Новые условия с',
                Documents::dateText($d['effective_date'])
            ]
        ])
        . Controller::table([
            'Файл',
            'Версия',
            'SHA-256'
        ], $rows);
        if (in_array(Auth::user()['role'], [
            'admin',
            'lawyer'
        ], true) && ($d['status'] === 'legal' || ($d['status'] === 'returned' && $d['return_to'] === 'lawyer')))$body.=self::generateForm($d);
        return RentalUI::modal('Допсоглашение · '
        . $d['base_number'], $body, RentalUI::button('Вернуться к карточке', '/rental/document?id='
        . $d['id']), 'approvals');
    }
}
