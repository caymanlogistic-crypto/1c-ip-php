<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, Http, View};
use RuntimeException;
final class SourceCards {

    public static function party(): string {
        return PartyDirectory::card();
    }

    public static function draft(): string {
        return ContractForm::render();
    }

    private static function contract(int $id): array {
        Access::contract($id);
        return Controller::query('SELECT c.*,COALESCE(p.name,\'—\') party_name FROM contracts c LEFT JOIN parties p ON p.id=c.party_id WHERE c.id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Договор не найден.', 404);
    }

    private static function date(?string $v): string {
        return $v?(new \DateTimeImmutable($v))->format('d.m.Y'):'—';
    }

    public static function show(): string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $c = self::contract($id);
        $role = Auth::user()['role'];
        if(in_array($role,['admin','manager'],true) && Auth::can('rental.write') && \App\PagePolicy::level('rental.contracts',Auth::user())>=2 && empty(json_decode($c['source_json'],true)['imported']) && in_array($c['status'],['draft','returned'],true) && $c['return_to']!=='lawyer')return ContractForm::render();
        $managed = empty(json_decode($c['source_json'], true)['imported']);
        $rows = Controller::query('SELECT o.* FROM rental_objects o JOIN contract_objects co ON co.object_id=o.id WHERE co.contract_id=? ORDER BY o.id', [
            $id
        ]);
        if (($draft=ContractDraft::input($c)) !== null) $rows=ContractDraft::objects(\App\DB::pdo(),$draft);
        $objects = implode(', ', array_column($rows, 'name'));
        foreach (\App\BillingParts::terms(\App\DB::pdo(), 'contract', $id) as $part) {
            $objects .= ($objects!==''?', ':'').PartTerms::label($part);
        }
        $responsible = match ($c['status']) {
            'legal' => 'Юрист', 'accounting', 'approved' => 'Бухгалтер', 'returned' => $c['return_to'] === 'lawyer'?'Юрист':'Менеджер', default => $managed?'Менеджер':'Не назначен'
        };
        $pairs = [
            [
                'Стадия',
                Controller::status($c['status'])
            ],
            [
                'Ответственный',
                $responsible
            ],
            [
                'Контрагент',
                $c['party_name']
            ],
            [
                'Объекты',
                $objects
            ],
            [
                'Период аренды',
                self::date($c['start_date'])
                . ' — '
                . self::date($c['end_date'])
            ],
            [
                'Аренда с НДС',
                Controller::money($c['amount'])
                . ' / месяц'
            ],
            [
                'Подписание',
                $c['signed']?'Подписи обеих сторон подтверждены':($managed?'Ожидается подписанный экземпляр':'По исходному реестру')
            ]

        ];
        $body = '';
        $delivery=Delivery::presentation(\App\DB::pdo(),'contract',$id);
        if ($delivery && $delivery['message']!=='') $body.=RentalUI::alert('Документы и доставка',$delivery['message'],$delivery['error']?'error':'info');
        $actions = '';
        if (isset($_SESSION['rental_notice'])) {
            $body.=RentalUI::alert($_SESSION['rental_notice']);
            unset($_SESSION['rental_notice']);
        }
        if ($c['status'] === 'returned')$body.=RentalUI::alert(Controller::status($c['status']), $c['reason'] ?? '', 'error');
        if (in_array($c['status'], [
            'reconciling',
            'ended',
            'closed'
        ], true)) {
            $body.=RentalUI::pairs([
                [
                    'Причина завершения',
                    $c['finish_reason'] ?? 'Нет сведений'
                ],
                [
                    'Дата завершения',
                    self::date($c['finish_date'])
                ]
            ]);
            $check = [
            ];
            foreach ([
                [
                    'Бухгалтерия',
                    'Взаиморасчёты и остаток',
                    'finance_review'
                ],
                [
                    'Юрист',
                    'Юридическая проверка',
                    'legal_review'
                ],
                [
                    'Менеджер',
                    'Акт возврата помещения',
                    'return_confirmed'
                ]
            ] as $r)$check[] = [
                View::e($r[0]),
                View::e($r[1]),
                $c[$r[2]]?'Подтверждено':'Ожидается подтверждение'
            ];
            $body.='<span class="registry-ui-modal-summary">Условия завершения</span>'
            . Controller::table([
                'Участник',
                'Проверка',
                'Состояние'
            ], $check);
        }
        $body.=RentalUI::pairs($pairs);
        $add = function(string $label, string $action, bool $primary = false)use(&$actions, $id) {
            $user=Auth::user();
            $grant=match($user['role']) {'lawyer'=>'rental.approve.legal','accountant'=>'finance.write','director'=>'rental.approve.executive',default=>'rental.write'};
            if (!Auth::can($grant) || \App\PagePolicy::level(\App\PagePolicy::commandPage($action),$user)<2) return;
            $actions.=RentalUI::button($label, '/rental/action-form?id='
            . $id
            . '&action='
            . urlencode($action), $primary);
        };
        if (in_array($role, [
            'admin',
            'manager'
        ], true)) {
            if(ContractDraft::deletable(\App\DB::pdo(),$c))$add('Удалить черновик','contract.delete');
            if ($managed && in_array($c['status'], [
                'draft',
                'returned'
            ], true) && $c['return_to'] !== 'lawyer') {
                $actions.=RentalUI::button('Редактировать проект', '/rental/edit-contract?id='
                . $id);
                $add('Отправить на согласование', 'contract.submit', true);
            }
            if ($managed && in_array($c['status'], [
                'sent',
                'active'
            ], true) && !$c['signed'])$add('Загрузить подписанный договор', 'contract.sign', true);
            if ($c['status'] === 'active') {
                $actions.=RentalUI::button($managed?'Пролонгация и бронь':'Создать допсоглашение', '/rental/addendum-form?contract_id='
                . $id, true);
                $add('Завершение договора', 'contract.reconcile');
            }
            if ($c['status'] === 'reconciling')$add('Загрузить акт возврата', 'contract.return-act', true);
            if ($managed && in_array($c['status'], [
                'returned',
                'legal',
                'accounting',
                'approved',
                'sent'
            ], true))$add('Аннулировать', 'contract.cancel');
        }
        if (in_array($role, [
            'admin',
            'lawyer'
        ], true)) {
            if ($c['status'] === 'legal') {
                $add('Вернуть на доработку', 'contract.return');
                $add('Согласовано', 'contract.legal', true);
            }
            if ($c['status'] === 'returned' && $c['return_to'] === 'lawyer')$add('Передать замечания менеджеру', 'contract.forward');
            if (in_array($c['status'], [
                'reconciling',
                'ended'
            ], true) && !$c['legal_review'])$add('Претензий нет', 'reconcile.legal', true);
        }
        if (in_array($role, [
            'admin',
            'accountant'
        ], true)) {
            if ($c['status'] === 'accounting') {
                $add('Вернуть на доработку', 'contract.return');
                $add('Согласовано', 'contract.packet', true);
            }
            if ($c['status'] === 'approved')$add('Пакет и отправка', 'contract.send', true);
            if (in_array($c['status'], [
                'reconciling',
                'ended'
            ], true) && !$c['finance_review'])$add('Взаиморасчёты согласованы', 'reconcile.finance', true);
        }
        return RentalUI::modal('Договор '
        . $c['number'], $body, $actions, 'contracts-active');
    }

    public static function conditions(): string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $c = self::contract($id);
        $out = [];
        $draft=ContractDraft::input($c);
        if ($draft !== null) {
            foreach (ContractDraft::objects(\App\DB::pdo(),$draft) as $object) {
                $price=trim((string)($object['amount'] ?? ''));
                $out[]=[View::e($object['name']),View::e($object['area']),View::e(self::date($c['start_date'])),View::e(self::date($c['end_date'])),View::e($price!==''?$price:'Не подтверждено'),'—'];
            }
            foreach (PartTerms::candidates(\App\DB::pdo(),'contract',$id) as $slot=>$candidate) {
                $price=trim((string)($draft['term_amount'][$slot] ?? ''));
                if ($price==='') continue;
                $part=$candidate['part']['part'];
                $out[]=[View::e('Часть '.$part['number'].' · юридический адрес'),'—',View::e($draft['part_terms'][$slot]['start_date'] ?? ''),View::e($draft['part_terms'][$slot]['end_date'] ?? ''),View::e($price),'—'];
            }
        } else foreach (Controller::query('SELECT o.name,o.area,t.start_date,t.end_date,t.amount,t.effective_date FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id LEFT JOIN contract_terms t ON t.contract_id=co.contract_id AND t.object_id=o.id WHERE co.contract_id=? ORDER BY o.id,t.effective_date,t.id', [
            $id
        ]) as $t)$out[] = [
            View::e($t['name']),
            View::e($t['area']),
            View::e(self::date($t['start_date'] ?? $c['start_date'])),
            View::e(self::date($t['end_date'] ?? $c['end_date'])),
            Controller::money($t['amount']),
            View::e(self::date($t['effective_date']))
        ];
        foreach ($draft===null?\App\BillingParts::terms(\App\DB::pdo(), 'contract', $id):[] as $part) $out[] = [
            View::e(PartTerms::label($part)), View::e($part['area'] ?? '—'),
            View::e(self::date($part['start_date'])), View::e(self::date($part['end_date'])),
            Controller::money($part['amount']), View::e(self::date($part['effective_date'])),
        ];
        return RentalUI::modal('Условия договора '
        . $c['number'], Controller::table([
            'Объект',
            'Площадь для сдачи, м²',
            'Начало',
            'Конец',
            'В месяц с НДС, ₽',
            'ДС с даты'
        ], $out), RentalUI::button('Вернуться к договору', '/rental/contract?id='
        . $id), 'contracts-active', true);
    }

    public static function history(): string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['contract_id'] ?? 0);
        $c = self::contract($id);
        if (isset($_GET['version']))return ContractHistory::preview($id, (int)$_GET['version']);
        $out = [
        ];
        $archive=($_GET['archive'] ?? '')==='1';$floor=ZeroPoint::eventFloor(\App\DB::pdo());
        foreach (Controller::query("SELECT * FROM rental_events WHERE id ".($archive?'<=?':'>?')." AND (contract_id=? OR (action='party.block' AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.kind')) IN ('party-block-projects','committed-rental-workflow-event') AND JSON_CONTAINS(JSON_EXTRACT(source_json,'$.extra.projects'),JSON_OBJECT('id',CAST(? AS UNSIGNED))))) ORDER BY id DESC", [
            $floor,$id,$id
        ]) as $r)$out[] = [
            View::e($r['created_at']),
            View::e($r['actor_name']?:$r['actor_role']),
            View::e(self::historyEventText($r,$id))
        ];
        return RentalUI::modal('История и сообщения · '
        . $c['number'], ($floor>0?'<div class="demo-row">'.RentalUI::button('Текущая работа','/rental/history?contract_id='.$id).RentalUI::button('История до восстановления','/rental/history?contract_id='.$id.'&archive=1').'</div>':'').Controller::table([
            'Время',
            'Участник',
            'Событие'
        ], $out)
        . ContractHistory::listing($id), RentalUI::button('Вернуться к договору', '/rental/contract?id='
        . $id));
    }

    public static function historyEventText(array $event,int $contractId):string {
        if(($event['action']??null)==='party.block'){
            $metadata=json_decode($event['source_json']??'null',true);
            if(in_array($metadata['kind']??null,['party-block-projects','committed-rental-workflow-event'],true))foreach($metadata['extra']['projects']??[] as $project){
                if(($project['id']??null)===$contractId)return 'Договор '.$project['number'].' аннулирован: контрагент заблокирован. '.$project['after']['reason'];
            }
        }
        return $event['description'];
    }

    public static function action(): string {
        Auth::requirePermission('rental.read');
        $id = (int)($_GET['id'] ?? 0);
        $c = self::contract($id);
        $action = (string)($_GET['action'] ?? '');
        \App\PagePolicy::requireCommand($action);
        if ($action==='contract.send' && $c['status']==='approved' && PacketForm::requiresConfirmation('contract',$id)) $action='contract.packet';
        $allowed = [
            'contract.submit' => 'Отправить на согласование',
            'contract.legal' => 'Проверка юристом',
            'contract.packet' => 'Проверка пакета бухгалтером',
            'contract.return' => 'Вернуть на доработку',
            'contract.forward' => 'Передать замечания менеджеру',
            'contract.sign' => 'Загрузить подписанный договор',
            'contract.return-act' => 'Акт возврата помещения',
            'contract.reconcile' => 'Завершение договора',
            'contract.cancel' => 'Аннулировать проект',
            'contract.delete' => 'Удалить черновик',
            'contract.send' => 'Пакет и отправка',
            'contract.handed' => 'Документы переданы клиенту',
            'contract.close' => 'Завершить финальную сверку',
            'reconcile.finance' => 'Взаиморасчёты согласованы',
            'reconcile.legal' => 'Претензий нет'
        ];
        $title = $allowed[$action] ?? throw new RuntimeException('Действие не найдено.', 404);
        $fields = RentalUI::pairs([
            [
                'Договор',
                $c['number']
            ],
            [
                'Контрагент',
                $c['party_name']
            ]
        ]);
        if($action==='contract.delete'){
            Service::roles(['manager']);
            if(!ContractDraft::deletable(\App\DB::pdo(),$c))throw new RuntimeException('Этот договор нельзя удалить как черновик.',409);
            $fields.='<span class="registry-ui-modal-text">Удалить черновик? Он исчезнет из рабочих списков. История изменений сохранится.</span>';
        }
        $extra = '';
        if ($action === 'contract.packet') {
            $fields .= PacketForm::preview('contract', $id);
        }
        $formId = 'rental-action-'
        . bin2hex(random_bytes(6));
        if (in_array($action, [
            'contract.return',
            'contract.forward',
            'contract.cancel',
            'contract.reconcile'
        ], true))$fields.=Controller::input('reason', 'Причина', '', 'text', true);
        if ($action === 'contract.reconcile')$fields.=Controller::input('finish_date', 'Дата завершения', $c['end_date'], 'date', true);
        if (in_array($action, [
            'contract.legal',
            'contract.packet'
        ], true)) {
            $fields.='<label class="registry-ui-checkbox">'
            . '<input type="checkbox" name="counterparty_confirmed" value="1" required>'
            . '<span>Контрагент и реквизиты проверены</span>'
            . '</label>'
            . '<label class="registry-ui-checkbox">'
            . '<input type="checkbox" name="financial_confirmed" value="1" required>'
            . '<span>Финансовые условия проверены</span>'
            . '</label>';
            if ($action === 'contract.packet')$fields.='<label class="registry-ui-checkbox">'
            . '<input type="checkbox" name="document_confirmed" value="1" required>'
            . '<span>Документ и состав пакета проверены</span>'
            . '</label>';
            else {
                $extra = AuxController::uploadForm($c, 'lawyer-file', 'Актуальный Word или PDF')
                . '<form method="post" action="/rental/generate"><input type="hidden" name="_csrf" value="'
                . View::e(Http::csrf())
                . '"><input type="hidden" name="id" value="'
                . $id
                . '"><input type="hidden" name="version" value="'
                . $c['version']
                . '"><button class="btn btn-secondary" name="format" value="docx">Сформировать Word</button>'
                . '<button class="btn btn-secondary" name="format" value="pdf">Сформировать PDF</button></form>';
            }
        }
        if (in_array($action, [
            'contract.sign',
            'contract.return-act'
        ], true)) {
            $fields.='<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required><span>Подписано обеими сторонами</span></label>';
            $extra = AuxController::uploadForm($c, $action === 'contract.sign'?'signed-contract':'signed-return-act', 'Подписанный файл');
            if ($action === 'contract.return-act')$fields.=Controller::input('return_date', 'Дата возврата', $c['finish_date'] ?? date('Y-m-d'), 'date', true);
        }
        if (in_array($action, [
            'reconcile.finance',
            'reconcile.legal',
            'contract.handed'
        ], true))$fields.='<label class="registry-ui-checkbox">'
        . '<input type="checkbox" name="confirmed" value="1" required>'
        . '<span>Проверка выполнена, подтверждаю результат</span>'
        . '</label>';
        if ($action === 'contract.send')$extra = RentalUI::alert('Пакет ещё не отправлен', 'Отправка сохраняется как отдельная операция. Статус меняется только по подтверждению транспорта.')
        . RentalUI::button('Документы переданы клиенту', '/rental/action-form?id='
        . $id
        . '&action=contract.handed');
        $body = '<form id="'
        . $formId
        . '" method="post" action="/rental/action"><input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . $c['version']
        . '"><input type="hidden" name="action" value="'
        . View::e($action)
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '">'
        . $fields
        . '</form>'
        . $extra;
        $foot = '<button class="btn '.($action==='contract.delete'?'btn-danger':'btn-primary').'" type="submit" form="'
        . $formId
        . '">'.($action==='contract.delete'?'Удалить черновик':'Подтвердить').'</button>';
        return RentalUI::modal($title, $body, $foot);
    }
}
