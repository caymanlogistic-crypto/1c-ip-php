<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, Http, View, DB};
use RuntimeException;
final class FinanceController {
    public static function paymentActionsAllowed(array $user,bool $financeWrite,int $paymentPageLevel):bool {
        return in_array($user['role']??null,['admin','accountant'],true)&&$financeWrite&&$paymentPageLevel>=2;
    }
    private static function canPaymentWrite():bool {
        $user=Auth::user();
        return $user&&self::paymentActionsAllowed($user,Auth::can('finance.write'),\App\PagePolicy::level('rental.invoices',$user));
    }
    private static function requirePaymentWrite(string $action='payment.create'):void {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('finance.write');
        Service::roles(['accountant']);
        \App\PagePolicy::requireCommand($action);
    }
    public static function standaloneFields(array $parties):string {
        $fields='<label class="field"><span class="field-label">Контрагент</span><select class="field-input field-select" name="party_id" required><option value="">Выберите контрагента</option>';
        foreach($parties as $party)$fields.='<option value="'.(int)$party['id'].'">'.View::e($party['name']).'</option>';
        return $fields.'</select></label>'.Controller::input('payment_date','Дата прихода',date('Y-m-d'),'date',true)
            .Controller::input('amount','Сумма прихода','','text',true)
            .'<span class="registry-ui-modal-help">Приход сохраняется отдельно от счетов. После сохранения откройте нужный счёт и выберите «Распределить приход».</span>';
    }
    public static function allocationFields(array $invoice,array $payments):?string {
        $options='';
        foreach($payments as $payment){
            if($payment['amount']===null||$payment['allocated']===null)continue;
            $available=bcsub($payment['amount'],$payment['allocated'],2);
            if(bccomp($available,'0',2)<=0)continue;
            $options.='<option value="'.(int)$payment['id'].'">'.View::e('#'.$payment['id'].' · '.Documents::dateText($payment['payment_date']).' · доступно '.$available.' ₽').'</option>';
        }
        if($options===''||$invoice['amount']===null||$invoice['paid']===null||bccomp($invoice['amount'],$invoice['paid'],2)<=0||$invoice['status']==='revoked')return null;
        return '<label class="field"><span class="field-label">Нераспределённый приход этого контрагента</span><select class="field-input field-select" name="payment_id" required><option value="">Выберите приход</option>'.$options.'</select></label>'
            .Controller::input('amount','Сумма распределения','','text',true)
            .'<span class="registry-ui-modal-help">Сумма не должна превышать свободный остаток прихода и долг по счёту. Остатки проверяются повторно при сохранении.</span>';
    }
    private static function invoice(int $id):array {
        Access::finance();
        $q = DB::pdo()->prepare('SELECT contract_id FROM invoices WHERE id=?');
        $q->execute([
            $id
        ]);
        $contract = $q->fetchColumn();
        if (!$contract)throw new RuntimeException('Счёт не найден.', 404);
        return Controller::query('SELECT i.*,c.number contract_number,c.party_id,p.name party_name FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id WHERE i.id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Счёт не найден.', 404);
    }
    private static function form(string $action, array $r, string $fields, string $label, bool $file = false, bool $saveAndSend = false):array {
        $fid = 'rental-finance-'
        . bin2hex(random_bytes(6));
        $body = '<form id="'
        . $fid
        . '" method="post" action="/rental/finance-save"'
        . ($file?' enctype="multipart/form-data"':'')
        . '><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="action" value="'
        . View::e($action)
        . '"><input type="hidden" name="id" value="'
        . ($r['id'] ?? 0)
        . '"><input type="hidden" name="version" value="'
        . ($r['version'] ?? 0)
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '">'
        . $fields
        . '</form>';
        $actions=$saveAndSend
            ? '<button class="btn btn-secondary" type="submit" form="'.$fid.'" name="intent" value="save">Сохранить</button>'
                .'<button class="btn btn-primary" type="submit" form="'.$fid.'" name="intent" value="send">Сохранить и отправить клиенту</button>'
            : '<button class="btn btn-primary" type="submit" form="'.$fid.'">'.View::e($label).'</button>';
        return [$body,$actions];
    }
    public static function card():string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $r = self::invoice($id);
        $r['_calculation_review']=InvoiceCalculation::reviewFacts(DB::pdo(),[$id])[$id] ?? null;
        $raw=json_decode($r['source_json'],true) ?: [];
        $r['_delivery']=InvoiceDelivery::presentation(DB::pdo(),$id);
        $delivery=$r['_delivery']['intent'];
        $sendError=InvoiceFacts::view($r)['send_error'];
        $severity='';
        $user=Auth::user();
        foreach (Indicators::tasks(null,null,['invoices']) as $task) {
            if ($task['entity']==='invoice' && $task['id']===$id && ($task['role']===$user['role'] || $user['role']==='admin')) {
                if ($task['severity']==='red' || $severity==='') $severity=$task['severity'];
            }
        }
        $view=InvoiceFacts::view($r,$severity);
        $known = $r['amount'] !== null && $r['paid'] !== null;
        $debt = $known?(bccomp($r['amount'], $r['paid'], 2)>0?bcsub($r['amount'], $r['paid'], 2):'0.00'):null;
        $body = '';
        if (isset($_SESSION['rental_notice'])) {
            $body.=RentalUI::alert($_SESSION['rental_notice']);
            unset($_SESSION['rental_notice']);
        }
        $body.=RentalUI::alert($view['state'],$view['explanation'],match($severity){'red'=>'error','yellow'=>'warning',default=>'info'})
        . RentalUI::pairs([
            [
                'Контрагент',
                $r['party_name']
            ],
            [
                'Договор',
                $r['contract_number']
            ],
            [
                'Наименование',
                $r['title']?:$r['kind']
            ],
            [
                'За что / период',
                $r['purpose']?:$r['kind']
                . ' · '
                . $r['period']
            ],
            [
                'Дата счёта',
                Documents::dateText($r['issued'])
            ],
            [
                'Оплата до',
                Documents::dateText($r['due'])
            ],
            [
                'Сумма с НДС',
                Controller::money($r['amount'])
            ],
            [
                'Оплачено',
                Controller::money($r['paid'])
            ],
            [
                'Остаток',
                Controller::money($debt)
            ]
        ]);
        $review = $r['_calculation_review'];
        if ($review) {
            $x = $review;
            $body.=RentalUI::pairs([
                [
                    'Расчёт по новым условиям',
                    Controller::money($x['expectedAmount'])
                ],
                [
                    'Расхождение начисления',
                    Controller::money($x['difference'])
                ]
            ]);
        }
        $coverage=Controller::query('SELECT ic.*,o.name object_name FROM invoice_coverage ic LEFT JOIN rental_objects o ON o.id=ic.object_id WHERE ic.invoice_id=? ORDER BY ic.object_id,ic.start_date,ic.end_date',[$id]);
        if ($coverage) {
            $rows=[];
            foreach ($coverage as $item) $rows[]=[View::e($item['object_name'] ?? $item['object_id']),Documents::dateText($item['start_date']),Documents::dateText($item['end_date'])];
            $body.=Controller::table(['Помещение','Период с','По'],$rows);
        }
        $body.='<span class="registry-ui-modal-help">Просрочка неоплаченного остатка считается от даты «Оплатить до»: 1–5 календарных дней — жёлтый, с 6-го дня — красный. Контроль после 13:00 по Москве.</span>';
        $payments = [
        ];
        foreach (Controller::query('SELECT p.*,a.amount allocated FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.invoice_id=? ORDER BY p.payment_date,p.id', [
            $id
        ]) as $p)$payments[] = [
            Controller::link('/rental/payment?id='
            . $p['id'],
            Documents::dateText($p['payment_date'])),
            Controller::money($p['allocated']),
            View::e($p['source']),
            View::e($p['source_filename']?:'Без вложения')
        ];
        if ($payments)$body.='<span class="registry-ui-modal-summary">Оплаты по счёту</span>'
        . Controller::table([
            'Дата',
            'Сумма',
            'Источник',
            'Платёжный документ'
        ], $payments);
        elseif($r['paid'] !== null && bccomp($r['paid'], '0', 2)>0)$body.='<span class="registry-ui-modal-help">Ранее учтено '
        . View::e(Controller::money($r['paid']))
        . '. Исходный источник не подтверждает отдельные банковские события.</span>';
        $body.=RentalUI::pairs([
            [
                'Доставка',
                !empty($r['_delivery']['handed']) && !$r['sent']
                    && (int)($r['_delivery']['handed']['invoice_version'] ?? 0)===(int)$r['version']
                    && !in_array($delivery['status'] ?? '',['unknown','claimed'],true)
                    ? 'Передано лично '.Documents::dateText(substr($r['_delivery']['handed']['handed_at'],0,10))
                    : ($delivery ? match($delivery['status']) {
                    'blocked'=>'Отправка не подготовлена',
                    'unknown'=>'Результат отправки не подтверждён; нужна сверка',
                    'failed'=>'Ошибка отправки',
                    'prepared','claimed'=>'Ожидается подтверждение отправки',
                    'accepted'=>'Принято транспортом',
                    default=>$r['sent']?'Принято транспортом':'Ещё не отправлен',
                } : ($raw['handedAt'] ?? null ? 'Передано лично '.Documents::dateText(substr($raw['handedAt'],0,10)) : ($r['sent']?'Отправлено'.(!empty($raw['sentAt'])?' '.Documents::dateText(substr($raw['sentAt'],0,10)):''):'Ещё не отправлен')))
            ],
            [
                'Контроль',
                match ($r['status']) {
                    'frozen' => 'Напоминания приостановлены',
                    'revoked' => 'Счёт отозван',
                    default => 'Активный'
                }
            ]
        ])
        . '<div class="demo-row">'
        . RentalUI::button('Просмотр и печать', '/rental/invoice-preview?id='
        . $id)
        . '<a class="btn btn-secondary" href="/rental/invoice-html?id='.$id.'">Скачать HTML</a>'
        . RentalUI::button('Открыть договор', '/rental/contract?id='
        . $r['contract_id'])
        . RentalUI::button('История договора', '/rental/history?contract_id='
        . $r['contract_id'])
        . '</div>';
        $files=Controller::query("SELECT id FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND kind='invoice-file'",[$id]);
        $file=$files ? Evidence::latest(DB::pdo(),'invoice',$id,'invoice-file') : null;
        if ($file)$body.='<div class="demo-row">'
        . Controller::link('/rental/download?id='
        . $file['id'], 'Скачать '
            . $file['filename'])
        . '</div>';
        if (!empty($raw['onecSnapshot'])) $body.='<div class="demo-row">'.RentalUI::button('Результат сверки с 1С','/rental/invoice-compare?id='.$id).'</div>';
        $role = Auth::user()['role'];
        $actions = '';
        if (in_array($role, [
            'admin',
            'accountant'
        ], true) && Auth::can('finance.write') && \App\PagePolicy::level('rental.invoice-list',$user)>=2) {
            $actions.=RentalUI::button(!empty($raw['issueError'])?'Заполнить реквизиты':'Редактировать', '/rental/edit-invoice?id='.$id,!empty($raw['issueError']));
            if ($debt!==null && bccomp($debt,'0',2)>0 && empty($raw['issueError']) && $r['status']!=='revoked') {
                $actions.=RentalUI::button('Внести оплату','/rental/pay-invoice?id='.$id,true);
            }
            if ($r['manual_review'])$actions.=RentalUI::button('Проверка выполнена', '/rental/invoice-state?id='
            . $id
            . '&action=invoice.confirm-review', true);
        }
        if (in_array($role,['admin','manager','accountant'],true) && empty($raw['issueError']) && $r['status']!=='revoked'
            && Auth::can($role==='manager'?'rental.write':'finance.write') && \App\PagePolicy::level('rental.invoice-list',$user)>=2) {
            [$sendForm,$sendButton]=self::form('invoice.send',$r,'',$sendError?'Повторить отправку':'Отправить клиенту');
            $body.=$sendForm;
            $actions.=str_replace('btn-primary','btn-secondary',$sendButton);
            if ($sendError) {
                [$handedForm,$handedButton]=self::form('invoice.handed',$r,'<input type="hidden" name="confirmed" value="1">','Документы переданы клиенту');
                $body.=$handedForm;
                $actions.=str_replace('btn-primary','btn-secondary',$handedButton);
            }
        }
        if (in_array($role, [
            'admin',
            'manager',
            'accountant'
        ], true) && $r['status'] !== 'revoked')$body.='<div class="demo-row">'
        . RentalUI::button($r['status'] === 'frozen'?'Возобновить напоминания':'Приостановить напоминания', '/rental/invoice-state?id='
        . $id
        . '&action='
        . ($r['status'] === 'frozen'?'invoice.resume':'invoice.freeze'))
        . RentalUI::button('Отозвать счёт', '/rental/invoice-state?id='
        . $id
        . '&action=invoice.revoke')
        . '</div>';
        if(self::canPaymentWrite()&&$debt!==null&&bccomp($debt,'0',2)>0&&$r['status']!=='revoked')
            $actions.=RentalUI::button('Распределить приход','/rental/invoice-state?id='.$id.'&action=payment.allocate');
        return RentalUI::modal('Счёт '
        . $r['number'], $body, $actions, 'invoice-list');
    }
    public static function payment():string {
        Auth::requirePermission('rental.read');
        Access::finance();
        $id = (int)($_GET['id'] ?? 0);
        if(!$id){
            self::requirePaymentWrite();
            $fields=self::standaloneFields(Controller::query('SELECT id,name FROM parties ORDER BY name,id'));
            if(isset($_SESSION['rental_notice'])){$fields=RentalUI::alert($_SESSION['rental_notice']).$fields;unset($_SESSION['rental_notice']);}
            [$body,$actions]=self::form('payment.create',[],$fields,'Сохранить приход');
            return RentalUI::modal('Внести отдельный приход',$body,$actions,'invoices');
        }
        $p = Controller::query('SELECT * FROM payments WHERE id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Платёж не найден.', 404);
        $a = Controller::query('SELECT a.*,i.number FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id WHERE a.payment_id=?', [
            $id
        ]);
        $body = RentalUI::pairs([
            [
                'Дата оплаты',
                Documents::dateText($p['payment_date'])
            ],
            [
                'Сумма',
                Controller::money($p['amount'])
            ],
            [
                'Источник',
                $p['source']?:'—'
            ],
            [
                'Автор',
                $p['entered_by']?:'Нет сведений'
            ],
            [
                'Платёжный документ',
                $p['source_filename']?:'Не загружен'
            ]
        ]);
        $actions = '';
        $allocated='0.00';foreach($a as $allocation)$allocated=bcadd($allocated,$allocation['amount'],2);
        $body.=RentalUI::pairs([['Распределено',Controller::money($allocated)],['Не распределено',$p['amount']===null?'Не указано':Controller::money(bcsub($p['amount'],$allocated,2))]]);
        if(isset($_SESSION['rental_notice'])){$body=RentalUI::alert($_SESSION['rental_notice']).$body;unset($_SESSION['rental_notice']);}
        if(self::canPaymentWrite())$body.='<span class="registry-ui-modal-help">Для распределения откройте счёт этого контрагента и выберите «Распределить приход».</span>';
        foreach (Controller::query("SELECT * FROM rental_files WHERE entity_type='payment' AND entity_id=? ORDER BY created_at DESC", [
            $id
        ]) as $f)$body.='<div class="demo-row">'
        . Controller::link('/rental/download?id='
        . $f['id'], 'Скачать '
        . $f['filename'])
        . '</div>';
        if (count($a) === 1 && in_array(Auth::user()['role'], [
            'admin',
            'accountant'
        ], true))$actions = RentalUI::button('Редактировать оплату', '/rental/pay-invoice?id='
        . $a[0]['invoice_id']
        . '&payment_id='
        . $id, true);
        return RentalUI::modal('Оплата по счёту '
        . ($a[0]['number'] ?? '—'), $body, $actions, 'invoices');
    }
    public static function pay():string {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('finance.write');
        Service::roles([
            'accountant'
        ]);
        $i = self::invoice((int)($_GET['id'] ?? 0));
        $paymentId = (int)($_GET['payment_id'] ?? 0);
        $p = $paymentId?(Controller::query('SELECT * FROM payments WHERE id=?', [
            $paymentId
        ])[0] ?? throw new RuntimeException('Оплата не найдена.', 404)):null;
        $fields = RentalUI::pairs([
            [
                'Контрагент',
                $i['party_name']
            ],
            [
                'Период',
                $i['period']
            ],
            [
                'Остаток счёта',
                $i['amount'] === null || $i['paid'] === null?'Не подтверждено':Controller::money(bcsub($i['amount'],
                $i['paid'],
                2))
            ]
        ])
        . '<input type="hidden" name="payment_id" value="'
        . $paymentId
        . '"><div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . Controller::input('payment_date', 'Дата оплаты', $p['payment_date'] ?? date('Y-m-d'), 'date', true)
        . Controller::input('amount', 'Сумма оплаты', $p['amount'] ?? ($i['amount'] !== null && $i['paid'] !== null?bcsub($i['amount'], $i['paid'], 2):''), 'text', true)
        . '</div>'
        . '<label class="field">'
        . '<span class="field-label">Платёжное поручение при наличии</span>'
        . '<input class="field-input" name="attachment" type="file">'
        . '</label>'
        . '<span class="registry-ui-modal-help">Полная оплата закрывает задолженность; частичная сохраняет остаток.</span>';
        [
            $body,
            $actions
        ] = self::form($p?'invoice.edit-payment':'invoice.pay', $i, $fields, 'Сохранить оплату', true);
        return RentalUI::modal($p?'Редактировать оплату':'Внести оплату по счёту '
        . $i['number'], $body, $actions, 'invoice-list');
    }
    public static function edit():string {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('finance.write');
        Service::roles([
            'accountant'
        ]);
        $id = (int)($_GET['id'] ?? 0);
        $i = $id?self::invoice($id):null;
        $choices = '';
        foreach (Controller::query("SELECT c.id,c.number,p.name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status IN ('approved','sent','active','reconciling') ORDER BY c.number") as $c)$choices.='<option value="'
        . $c['id']
        . '"'
        . ($i && (int)$i['contract_id'] === (int)$c['id']?' selected':'')
        . '>'
        . View::e($c['number']
        . ' · '
        . $c['name'])
        . '</option>';
        $next = (int)DB::pdo()->query('SELECT GREATEST(COALESCE(MAX(id),0),900000)+1 FROM invoices')->fetchColumn();
        $fields = (!$i?'<input type="hidden" name="expected_new_id" value="'
        . $next
        . '">':'')
        . '<label class="field"><span class="field-label">Договор</span><select class="field-input field-select" name="contract_id"'
        . ($i?' disabled':'')
        . '>'
        . $choices
        . '</select></label>'
        . ($i?'<input type="hidden" name="contract_id" value="'
        . $i['contract_id']
        . '">':'')
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . Controller::input('number', 'Номер счёта', $i['number'] ?? '', 'text', true)
        . Controller::input('issued', 'Дата счёта', $i['issued'] ?? date('Y-m-d'), 'date', true)
        . Controller::input('title', 'Наименование платежа', $i['title'] ?? $i['kind'] ?? 'Арендная плата', 'text', true)
        . '<label class="field"><span class="field-label">Вид счёта</span><select class="field-input field-select" name="kind">';
        foreach ([
            'Аренда',
            'Первый период',
            'Обеспечительный платёж'
        ] as $kind)$fields.='<option'
        . (($i['kind'] ?? 'Аренда') === $kind?' selected':'')
        . '>'
        . View::e($kind)
        . '</option>';
        $fields.='</select></label>'
        . Controller::input('amount', 'Сумма с НДС', $i['amount'] ?? '', 'text', true)
        . Controller::input('period', 'Период аренды', ($i['period'] ?? '') === 'Обеспечение'?'':($i['period'] ?? date('Y-m')), 'month', false)
        . Controller::input('due', 'Срок оплаты', $i['due'] ?? date('Y-m')
        . '-01', 'date', true)
        . '</div>'
        . Controller::input('purpose', 'Назначение платежа / за что', $i['purpose'] ?? '', 'text', true)
        . '<label class="field">'
        . '<span class="field-label">Готовый файл счёта при наличии</span>'
        . '<input class="field-input" name="attachment" type="file">'
        . '</label>'
        . '<span class="registry-ui-modal-help">Файл необязателен: счёт формируется по реквизитам.</span>';
        [
            $body,
            $actions
        ] = self::form($i?'invoice.edit':'invoice.issue', $i ?? [
        ], $fields, 'Сохранить', true, true);
        return RentalUI::modal($i?'Редактировать счёт '
        . $i['number']:'Внести счёт', $body, $actions, 'invoice-list');
    }
    public static function state():string {
        Auth::requirePermission('rental.read');
        $i = self::invoice((int)($_GET['id'] ?? 0));
        $action = (string)($_GET['action'] ?? '');
        if (!in_array($action, [
            'invoice.freeze',
            'invoice.resume',
            'invoice.revoke',
            'invoice.confirm-review',
            'invoice.send',
            'invoice.handed',
            'payment.allocate'
        ], true))throw new RuntimeException('Неизвестное действие.', 404);
        if($action==='payment.allocate'){
            self::requirePaymentWrite('payment.allocate');
            $payments=Controller::query('SELECT p.id,p.amount,p.payment_date,(SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.payment_id=p.id) allocated FROM payments p WHERE p.party_id=? ORDER BY p.payment_date,p.id',[$i['party_id']]);
            $fields=self::allocationFields($i,$payments);
            if($fields===null)return RentalUI::modal('Распределить приход',RentalUI::alert('Нет доступного прихода или известного положительного остатка по счёту.').RentalUI::button('Внести приход','/rental/payment'),'', 'invoices');
            [$body,$actions]=self::form('payment.allocate',$i,$fields,'Распределить');
            return RentalUI::modal('Распределить приход на счёт '.$i['number'],$body,$actions,'invoices');
        }
        $fields = RentalUI::pairs([
            [
                'Счёт',
                $i['number']
            ]
        ])
        . (match ($action) {
            'invoice.confirm-review'=>'<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>Финансовые данные проверены</span></label>',
            'invoice.handed'=>'<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>Документы переданы клиенту</span></label>',
            'invoice.send'=>'',
            default=>Controller::input('reason','Причина','','text',true),
        });
        [
            $body,
            $actions
        ] = self::form($action, $i, $fields, 'Подтвердить');
        return RentalUI::modal('Контроль счёта '
        . $i['number'], $body, $actions, 'invoice-list');
    }
    public static function html(): never {
        $invoice=self::invoice((int)($_GET['id'] ?? 0));
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="invoice.html"; filename*=UTF-8\'\''.rawurlencode('Счёт '.$invoice['number'].'.html'));
        header('X-Content-Type-Options: nosniff');
        echo InvoiceDocument::html(DB::pdo(),$invoice);
        exit;
    }

    public static function compare(): string {
        $invoice=self::invoice((int)($_GET['id'] ?? 0));
        $raw=json_decode($invoice['source_json'],true) ?: [];
        $source=$raw['onecSnapshot'] ?? null;
        if (!is_array($source)) throw new RuntimeException('Исходный результат сверки не найден.',404);
        $rows=[
            ['Номер',View::e($invoice['number']),View::e($source['number'] ?? '—')],
            ['Сумма',Controller::money($invoice['amount']),Controller::money($source['amount'] ?? null)],
            ['Оплачено',Controller::money($invoice['paid']),Controller::money($source['paid'] ?? null)],
        ];
        $body=RentalUI::alert(!empty($raw['mismatch'])?'Рассинхронизация данных 1С и ERP':'Данные совпадают',
            !empty($raw['mismatch'])?'Данные ERP сохраняются. Устранение расхождения выполняется вне этого процесса.':'Последняя проверка '.Documents::dateText(isset($raw['reconciledAt'])?substr($raw['reconciledAt'],0,10):null),
            !empty($raw['mismatch'])?'error':'info');
        $body.=Controller::table(['Поле','ERP','1С'],$rows);
        return RentalUI::modal('Сверка счёта '.$invoice['number'],$body,'','invoice-list');
    }

    public static function preview():string {
        Auth::requirePermission('rental.read');
        $i = self::invoice((int)($_GET['id'] ?? 0));
        $body = RentalUI::pairs([
            [
                'Счёт',
                $i['number']
            ],
            [
                'Контрагент',
                $i['party_name']
            ],
            [
                'Договор',
                $i['contract_number']
            ],
            [
                'Назначение',
                $i['purpose']?:$i['title']?:$i['kind']
            ],
            [
                'Период',
                $i['period']
            ],
            [
                'Сумма с НДС',
                Controller::money($i['amount'])
            ],
            [
                'НДС',
                $i['vat'] === null?'НДС не указан':$i['vat']
                . '%'
            ],
            [
                'Оплатить до',
                Documents::dateText($i['due'])
            ]
        ]);
        $fid = 'invoice-print-form';
        $body.='<form id="'
        . $fid
        . '" method="post" action="/rental/invoice-pdf"><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="id" value="'
        . $i['id']
        . '"></form>';
        return RentalUI::modal('Счёт №'
        . $i['number']
        . ' от '
        . Documents::dateText($i['issued']), $body, '<button class="btn btn-primary" type="submit" form="'
        . $fid
        . '">Открыть для печати PDF</button>'
        . RentalUI::button('Вернуться к счёту', '/rental/invoice?id='
        . $i['id']), 'invoice-list');
    }
    public static function persist(array $in,?array $upload=null): array {
        $stored = null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE)$in['attachment_sha256'] = $upload['error'] === UPLOAD_ERR_OK?hash_file('sha256', $upload['tmp_name']):'invalid';
        try {
            return DB::transaction(function()use($in, $upload, &$stored) {
                $r = Service::command($in['action'] ?? '', $in);
                if(($in['action']??'')==='payment.create')$r['payment_id']=$r['id'];
                if(($in['action']??'')==='payment.allocate')$r['invoice_id']=(int)$in['id'];
                if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                    $payment = isset($r['payment_id']);
                    $stored = FinanceFiles::save($upload, $payment?'payment':'invoice', (int)($r['payment_id'] ?? $r['invoice_id']), $payment?'payment-order':'invoice-file');
                }
                if (($in['intent'] ?? '') === 'send' && in_array($in['action'] ?? '',['invoice.issue','invoice.edit'],true)) {
                    $sent=Service::command('invoice.send',[
                        'id'=>$r['invoice_id'],'version'=>$r['version'],
                        'command_key'=>hash('sha256','invoice-save-send:'.$in['command_key']),
                    ]);
                    $r['intent']=$sent['intent'];
                    $r['send_error']=$sent['send_error'];
                    $r['message']=$sent['send_error'] ? 'Счёт сохранён. '.$sent['send_error']
                        : 'Счёт сохранён и поставлен в очередь. Отправка ещё не подтверждена.';
                }
                return $r;
            });
        } catch (\Throwable $error) {
            if ($stored && $stored['created'] && is_file($stored['path'])) unlink($stored['path']);
            throw $error;
        }
    }

    public static function workspaceTarget(?string $value,string $fallback='/rental/invoice-list'): string {
        if (!$value || strlen($value)>4096 || preg_match('/[\x00-\x20\\\\]/',$value)) return $fallback;
        $url=parse_url($value);
        if (!$url || isset($url['scheme']) || isset($url['host']) || isset($url['fragment'])) return $fallback;
        $path=$url['path'] ?? '';
        $keys=[];
        foreach (\App\Menu::GROUPS as $group) $keys=array_merge($keys,array_keys($group));
        $pages=array_map(static fn($page)=>'/rental/'.$page,$keys);
        if (!in_array($path,$pages,true)) return $fallback;
        parse_str($url['query'] ?? '',$input);
        $query=[];
        foreach (['q','flag','party','contract_id','floor','type','inactive','filter','color','hide_rented','employee','role'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && strlen((string)$input[$key])<=1000) $query[$key]=(string)$input[$key];
        }
        return $path.($query ? '?'.http_build_query($query) : '');
    }

    public static function save():never {
        Http::checkCsrf();
        $in=$_POST;
        unset($in['_csrf']);
        try {
            $result=self::persist($in,$_FILES['attachment'] ?? null);
            $_SESSION['rental_notice'] = $result['message'];
            if(($in['action']??'')==='payment.create'){
                if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='ERP-Fetch'){
                    header('Content-Type: text/html; charset=UTF-8');$_GET=['id'=>$result['payment_id']];echo self::payment();exit;
                }
                Http::redirect('/rental/payment?id='.$result['payment_id']);
            }
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                $workspace=self::workspaceTarget($_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? null);
                header('X-Rental-Saved-Invoice: '.(int)$result['invoice_id']);
                header('Content-Type: text/html; charset=UTF-8');
                $saveForm=in_array($in['action'] ?? '',['invoice.issue','invoice.edit','invoice.pay','invoice.edit-payment'],true);
                if ($saveForm && empty($result['send_error'])) {
                    header('X-Rental-Completion-URL: '.$workspace);
                    exit;
                }
                header('X-Rental-Refresh-URL: '.$workspace);
                $_GET=['id'=>$result['invoice_id']];
                echo self::card();
                exit;
            }
            Http::redirect('/rental/invoice?id='
            . $result['invoice_id']);
        }
        catch (\Throwable $e) {
            if (in_array($e->getCode(), [
                401,
                403
            ], true))throw $e;
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
                http_response_code($e->getCode() === 409?409:422);
                echo RentalUI::alert($e instanceof \PDOException?'Не удалось сохранить финансовую запись.':$e->getMessage(), '', 'error');
                exit;
            }
            $_SESSION['rental_notice'] = $e->getMessage();
            if(($in['action']??'')==='payment.create')Http::redirect('/rental/payment');
            Http::redirect('/rental/invoice?id='
            . (int)($in['id'] ?? 0));
        }
    }
}
