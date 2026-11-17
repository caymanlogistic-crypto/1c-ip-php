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
        $booking = !$c && !empty($_GET['booking_id'])?BookingController::record((int)$_GET['booking_id']):null;
        if ($booking && ($booking['status'] !== 'active' || $booking['contract_id'] !== null || $booking['until_date']<date('Y-m-d')))throw new RuntimeException('Бронь уже изменена или истекла.', 409);
        $start = $c['start_date'] ?? $booking['start_date'] ?? '';
        $end = $c['end_date'] ?? '';
        // Undated drafts show today's availability only as a picker preview.
        // The required submitted boundaries remain empty until chosen.
        $availabilityStart = $start ?: date('Y-m-d');
        $availabilityEnd = $end ?: $availabilityStart;
        $number = $c['number'] ?? '';
        if (!$c) {
            $max = 0;
            foreach (Controller::query('SELECT number FROM contracts') as $row)if (preg_match('/^([0-9]+)/', $row['number'], $m))$max = max($max, (int)$m[1]);
            $number = (string)($max+1);
        }
        $options = '<option value="">Выберите контрагента</option>';
        foreach (Controller::query('SELECT id,name,inn FROM parties WHERE blocked=0 ORDER BY name') as $party)$options.='<option value="'
        . $party['id']
        . '" data-name="'
        . View::e($party['name'])
        . '" data-description="'
        . View::e($party['inn']?'ИНН: '
        . $party['inn']:'')
        . '" data-search="'
        . View::e($party['name']
        . ' '
        . $party['inn'])
        . '"'
        . ((int)($c['party_id'] ?? $booking['party_id'] ?? 0) === (int)$party['id']?' selected':'')
        . '>'
        . View::e($party['name'])
        . '</option>';
        $selected = [
        ];
        if ($c)$selected = Controller::query('SELECT o.id,o.name,o.type,o.area,t.amount FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2 WHERE t2.contract_id=co.contract_id AND t2.object_id=o.id) WHERE co.contract_id=? ORDER BY o.id', [
            $id
        ]);
        elseif($booking)$selected = Controller::query('SELECT id,name,type,area,NULL amount FROM rental_objects WHERE id=?', [
            $booking['object_id']
        ]);
        $sum = '0.00';
        $rows = '';
        foreach ($selected as $o) {
            if ($o['amount'] !== null)$sum = bcadd($sum, $o['amount'], 2);
            $rows.=self::row($o);
        }
        foreach ($c?\App\BillingParts::terms(\App\DB::pdo(), 'contract', $id):[] as $part) {
            if ($part['amount'] !== null) $sum = bcadd($sum, $part['amount'], 2);
        }
        if (!$rows)$rows = '<tr data-rental-empty-terms><td colspan="3">Помещения пока не добавлены.</td></tr>';
        $fid = 'rental-contract-draft';
        $fields = $booking?'<span class="registry-ui-modal-help">'
        . View::e(($booking['client_name']?'Бронь: '
        . $booking['client_name']
        . '. ':'')
        . 'Выберите или создайте карточку клиента.')
        . '</span>':'';
        $fields.='<div class="field">'
        . '<label class="field-label" for="contract-party">Контрагент <span class="req">*</span>'
        . '</label>'
        . '<div class="registry-ui-modal-address-row">'
        . '<select class="field-input field-select" id="contract-party" name="party_id" required data-rental-picker="party">'
        . $options
        . '</select>'
        . '<button class="btn btn-secondary" type="button" data-rental-open="/rental/edit-party" data-rental-create-party>Создать контрагента</button>'
        . '</div>'
        . '</div>'
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . '<label class="field">'
        . '<span class="field-label">Номер договора</span>'
        . '<input class="field-input" name="number" value="'
        . View::e($number)
        . '" readonly aria-readonly="true"></label>'
        . Controller::input('document_date', 'Дата договора', $c['document_date'] ?? date('Y-m-d'), 'date', false)
        . Controller::input('start_date', 'Начало аренды', $start, 'date', true)
        . Controller::input('end_date', 'Окончание аренды', $end, 'date', true)
        . '</div>';
        $vat = Controller::query('SELECT vat FROM organization WHERE id=1')[0]['vat'] ?? null;
        $fields.='<span class="registry-ui-modal-help">НДС нашей организации: '
        . View::e($vat === null?'не задан':rtrim(rtrim($vat, '0'), '.')
        . '%')
        . '. Суммы включают НДС.</span>'
        . '<div class="registry-ui-modal-grid">'
        . '<div id="chosen-objects">'
        . '<span class="registry-ui-modal-summary">Помещения в договоре · <span data-rental-object-count>'
        . count($selected)
        . '</span>'
        . '</span>'
        . '<div class="table-scroll registry-ui-compact-table-scroll">'
        . '<table class="table registry-ui-table registry-ui-compact-table">'
        . '<thead>'
        . '<tr>'
        . '<th>Помещение</th>'
        . '<th>Аренда с НДС, ₽</th>'
        . '<th>Состав</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody data-rental-terms>'
        . $rows
        . '</tbody><tfoot><tr><td colspan="3" class="registry-ui-number"><input type="hidden" name="amount" value="'
        . $sum
        . '"><strong id="contract-rent-total" data-rental-contract-total data-rental-total-prefix="ИТОГО: ">ИТОГО: '
        . View::e(Controller::money($sum))
        . '</strong>'
        . '</td>'
        . '</tr>'
        . '</tfoot>'
        . '</table>'
        . '</div>'
        . '</div>'
        . '<div class="field">'
        . '<label class="field-label" for="contract-object">Выбрать помещение</label>'
        . '<div class="registry-ui-modal-address-row">'
        . '<select class="field-input field-select" id="contract-object" data-rental-object-picker data-rental-picker="room">'
        . Availability::options(Availability::items($availabilityStart, $availabilityEnd, $c?(int)$c['id']:null, $booking?(int)$booking['id']:null))
        . '</select>'
        . '<button class="btn btn-secondary" type="button" data-rental-add-object>Добавить помещение</button>'
        . '</div>'
        . '</div>'
        . '<span class="field-msg">Укажите месячную стоимость каждого помещения с НДС.</span>'
        . '</div>'
        . '<template data-rental-term-template>'
        . self::row([
            'id' => '__ID__',
            'name' => '',
            'type' => '',
            'area' => null,
            'amount' => null
        ], true)
        . '</template>'
        . PartTerms::render(\App\DB::pdo(), 'contract', $id, $start, $end);
        $availability = '/rental/availability'
        . ($c?'?contract_id='
        . $c['id']:($booking?'?booking_id='
        . $booking['id']:''));
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
        $body = '<form id="'
        . $fid
        . '" data-rental-contract-form data-rental-availability-url="'
        . View::e($availability)
        . '" method="post" action="/rental/action">'
        . $hidden
        . $fields
        . '</form>';
        $actions = '<button class="btn btn-secondary" type="submit" form="'
        . $fid
        . '" name="intent" value="draft">Сохранить в черновиках</button><button class="btn btn-primary" type="submit" form="'
        . $fid
        . '" name="intent" value="submit">'
        . ($c && $c['status'] === 'returned'?'Повторно согласовать':'Отправить на согласование')
        . '</button>';
        return RentalUI::modal($c?'Изменить проект договора':'Новый договор', $body, $actions, 'contracts', true);
    }

    private static function row(array $o, bool $template = false): string {
        $id = $o['id'];
        $area = $o['area'] === null?'—':rtrim(rtrim($o['area'], '0'), '.');
        return '<tr data-rental-term-id="'
        . $id
        . '"><td><span data-rental-term-name>'
        . View::e($o['name'])
        . '</span><div class="registry-ui-secondary"><span data-rental-term-type>'
        . View::e($o['type'])
        . '</span> · <span data-rental-term-area>'
        . View::e($area)
        . '</span> м²</div><input type="hidden" name="objects[]" value="'
        . $id
        . '"></td><td><input class="field-input" type="number" min="0.01" step="0.01" name="term_amount['
        . $id
        . ']" data-unit-price="'
        . $id
        . '" value="'
        . View::e($o['amount'] ?? '')
        . '" inputmode="decimal" required aria-label="Аренда с НДС за '
        . View::e($o['name']?:'помещение')
        . '"></td><td><button class="registry-ui-reset" type="button" data-rental-remove-object>Убрать</button></td></tr>';
    }
}
