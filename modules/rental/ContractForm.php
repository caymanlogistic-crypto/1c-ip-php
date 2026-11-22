<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, Http, View};
use RuntimeException;
final class ContractForm {

    public static function render(): string {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('rental.write');
        Service::roles([
            'manager'
        ]);
        $id = (int)($_GET['id'] ?? 0);
        \App\PagePolicy::requireCommand($id?'contract.edit':'contract.create');
        $c = $id?Access::contract($id):null;
        if ($c && (!empty(json_decode($c['source_json'], true)['imported']) || !in_array($c['status'], [
            'draft',
            'returned'
        ], true) || $c['return_to'] === 'lawyer'))throw new RuntimeException('Проект не доступен для редактирования.', 403);
        $draft = ContractDraft::input($c);
        if ($draft !== null) foreach (['party_id','document_date','start_date','end_date'] as $field) $c[$field]=$draft[$field] ?? '';
        $continuation=ExpiryContinuation::editorContext($c,'contract');
        $booking = !$c && !empty($_GET['booking_id'])?BookingController::record((int)$_GET['booking_id']):null;
        if ($booking && ($booking['status'] !== 'active' || $booking['contract_id'] !== null || $booking['until_date']<date('Y-m-d')))throw new RuntimeException('Резервирование уже изменено или истекло.', 409);
        $start = $c['start_date'] ?? $continuation['offer']['new_start'] ?? $booking['start_date'] ?? '';
        $end = $c['end_date'] ?? $continuation['offer']['new_end'] ?? '';
        // Undated drafts show today's availability only as a picker preview.
        // The required submitted boundaries remain empty until chosen.
        try { $availabilityStart=Service::date($start); } catch (RuntimeException) { $availabilityStart=date('Y-m-d'); }
        try { $availabilityEnd=Service::date($end); } catch (RuntimeException) { $availabilityEnd=$availabilityStart; }
        if ($availabilityEnd<$availabilityStart) $availabilityEnd=$availabilityStart;
        $number = $c['number'] ?? '';
        if (!$c) {
            $max = \App\BusinessIds::numberFloor(\App\DB::pdo());
            foreach (Controller::query('SELECT number FROM contracts') as $row)if (preg_match('/^([0-9]+)/', $row['number'], $m))$max = max($max, (int)$m[1]);
            $number = (string)($max+1);
        }
        $options = '<option value="">Выберите контрагента</option>';
        foreach (Controller::query("SELECT p.id,p.name,p.inn,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.fact_json,'$.search_name')),'') search_name "
            ."FROM parties p LEFT JOIN rental_facts d ON d.namespace='directory_parties' AND d.source_key=CAST(p.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND d.operative=1 "
            ."WHERE p.blocked=0 ORDER BY COALESCE(search_name,p.name)") as $party)$options.='<option value="'
        . $party['id']
        . '" data-name="'
        . View::e($party['search_name'] ?: $party['name'])
        . '" data-description="'
        . View::e($party['inn']?'ИНН: '
        . $party['inn']:'')
        . '" data-search="'
        . View::e(($party['search_name'] ?? '').' '.$party['name']
        . ' '
        . $party['inn'])
        . '"'
        . ((int)($c['party_id'] ?? $continuation['offer']['party_id'] ?? $booking['party_id'] ?? 0) === (int)$party['id']?' selected':'')
        . '>'
        . View::e($party['search_name'] ?: $party['name'])
        . '</option>';
        $selected = [
        ];
        if ($draft !== null)$selected = ContractDraft::objects(\App\DB::pdo(), $draft);
        elseif ($c)$selected = Controller::query('SELECT o.id,o.name,o.type,o.area,t.amount FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2 WHERE t2.contract_id=co.contract_id AND t2.object_id=o.id) WHERE co.contract_id=? ORDER BY o.id', [
            $id
        ]);
        elseif($booking)$selected = Controller::query('SELECT id,name,type,area,NULL amount FROM rental_objects WHERE id=?', [
            $booking['object_id']
        ]);
        elseif ($continuation) $selected=ExpiryContinuation::rows($continuation,'contract');
        $exceptions=$draft===null?OccupiedBilling::exceptions($c ?? []):OccupiedBilling::draftRows($draft);
        foreach ($selected as &$object) $object['billing_exception']=$exceptions[$object['id'] ?? $object['object_id']] ?? [];
        unset($object);
        $sum = '0.00';
        foreach ($selected as $o) {
            if ($o['amount'] !== null) {
                try { $sum = bcadd($sum, Service::money($o['amount']), 2); } catch (RuntimeException) {}
            }
        }
        if ($draft !== null) {
            foreach ($draft['term_amount'] ?? [] as $slot=>$value) if (str_starts_with((string)$slot,'part_')) {
                try { $sum=bcadd($sum,Service::money($value),2); } catch (RuntimeException) {}
            }
        } else foreach ($c?\App\BillingParts::terms(\App\DB::pdo(), 'contract', $id):[] as $part) {
            if ($part['amount'] !== null) $sum = bcadd($sum, $part['amount'], 2);
        }
        $fid = 'rental-contract-draft';
        $design = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)==='/rental/new-contract' && !$c ? 'rental.new-contract' : '';
        $section = static fn(string $key): string => $design!=='' ? ' data-design-key="'.$key.'"' : '';
        $field = static fn(string $key, string $html): string => $design!=='' ? '<div data-design-key="'.$key.'">'.$html.'</div>' : $html;
        $fields = $booking?'<span class="registry-ui-modal-help">'
        . View::e(($booking['client_name']?'Резервирование: '
        . $booking['client_name']
        . '. ':'')
        . 'Выберите или создайте карточку клиента.')
        . '</span>':'';
        if ($c) $fields.=CustomerBookings::contractOrigin($c);
        if ($continuation) $fields.=ExpiryContinuation::editorNote($continuation);
        $fields.='<div class="field"'.$section('party').'>'
        . '<label class="field-label" for="contract-party">Контрагент <span class="req">*</span>'
        . '</label>'
        . '<div class="registry-ui-modal-address-row">'
        . '<select class="field-input field-select" id="contract-party" name="party_id" required data-rental-picker="party">'
        . $options
        . '</select>'
        . '<button class="btn btn-secondary" type="button" data-rental-open="/rental/edit-party?context=contract-create" data-rental-create-party>Создать контрагента</button>'
        . '</div>'
        . '</div>'
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading"'.$section('dates').'>'
        . $field('number','<label class="field">'
        . '<span class="field-label">Номер договора</span>'
        . '<input class="field-input" name="number" value="'
        . View::e($number)
        . '"'.($c && $c['status']!=='draft'?' readonly aria-readonly="true"':'').'></label>')
        . $field('document_date', Controller::input('document_date', 'Дата договора', $c['document_date'] ?? date('Y-m-d'), 'date', true))
        . $field('start_date', Controller::input('start_date', 'Начало аренды', $start, 'date', true))
        . $field('end_date', Controller::input('end_date', 'Окончание аренды', $end, 'date', true))
        . '</div>';
        $vat = Controller::query('SELECT vat FROM organization WHERE id=1')[0]['vat'] ?? null;
        $parts = PartTerms::render(\App\DB::pdo(), 'contract', $id, $start, $end, $draft);
        $fields.=($design!==''?'<div'.$section('objects').'>':'').'<span class="registry-ui-modal-help">НДС нашей организации: '
        . View::e($vat === null?'не задан':rtrim(rtrim($vat, '0'), '.')
        . '%')
        . '. Суммы включают НДС.</span>'
        . ContractObjectEditor::render($selected,
            Availability::options(Availability::items($availabilityStart, $availabilityEnd, $c?(int)$c['id']:null, $booking?(int)$booking['id']:null, true,
                $continuation['contract_id'] ?? null,true), empty($draft['draft_object_id'])?null:(int)$draft['draft_object_id']),
            $sum, null, true)
        . ($design!==''?'</div>':'')
        . ($parts!=='' && $design!==''?'<div'.$section('parts').'>'.$parts.'</div>':$parts);
        $availability = '/rental/availability?mode=contract'
        . ($c?'&contract_id='
        . $c['id']:($booking?'&booking_id='
        . $booking['id']:''));
        if ($continuation) $availability.='&'.http_build_query(['expiry_contract'=>$continuation['contract_id'],'expiry_end'=>$continuation['end']]);
        $hidden = '<input type="hidden" name="_csrf" value="'
        . View::e(Http::csrf())
        . '"><input type="hidden" name="command_key" value="'
        . bin2hex(random_bytes(16))
        . '"><input type="hidden" name="action" value="'
        . ($c?'contract.edit':'contract.create')
        . '"><input type="hidden" name="id" value="'
        . $id
        . '"><input type="hidden" name="version" value="'
        . ($c['version'] ?? 0)
        . '">'
        . ($booking?'<input type="hidden" name="booking_id" value="'
        . $booking['id']
        . '"><input type="hidden" name="booking_version" value="'
        . $booking['version']
        . '">':'');
        if (!$booking && !empty($draft['booking_id'])) $hidden.='<input type="hidden" name="booking_id" value="'.(int)$draft['booking_id'].'"><input type="hidden" name="booking_version" value="'.(int)($draft['booking_version'] ?? 0).'">';
        if ($continuation) $hidden.=ExpiryContinuation::fields($continuation);
        $body = '<form id="'
        . $fid
        . '" class="registry-ui-modal-grid" novalidate data-source-validation data-rental-contract-form'.($design!==''?' data-design-fields':'').' data-rental-availability-url="'
        . View::e($availability)
        . '" method="post" action="/rental/action">'
        . $hidden
        . $fields
        . '</form>';
        $actions = '<button class="btn btn-secondary" type="submit" form="'
        . $fid
        . '" name="intent"'.$section('draft').' value="draft" formnovalidate>Сохранить в черновиках</button><button class="btn btn-primary" type="submit" form="'
        . $fid
        . '" name="intent"'.$section('submit').' value="submit">'
        . ($c && $c['status'] === 'returned'?'Повторно согласовать':'Отправить на согласование')
        . '</button>';
        $leading = $c ? InitialInvoiceCard::entry($c) : '';
        if ($c && ContractDraft::deletable(\App\DB::pdo(),$c))
            $leading.=RentalUI::button('Удалить черновик','/rental/action-form?id='.$id.'&action=contract.delete',false,true);
        return RentalUI::modal($c?'Изменить проект договора':'Новый договор', $body, $actions, 'contracts', true, $leading,design:$design);
    }

}
