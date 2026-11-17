<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, Http, View};
use RuntimeException;
final class BookingController {

    public static function record(int $id): array {
        Access::pages([
            'contracts',
            'summary'
        ]);
        $r = Controller::query('SELECT r.*,o.name object_name FROM reservations r JOIN rental_objects o ON o.id=r.object_id WHERE r.id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Бронь не найдена.', 404);
        if ($r['document_id'] !== null)throw new RuntimeException('Бронь относится к допсоглашению.', 403);
        return $r;
    }

    private static function form(string $action, int $id, int $version, string $fields, string $label): array {
        $fid = 'rental-booking-'
        . bin2hex(random_bytes(6));
        return [
            '<form id="'
            . $fid
            . '" class="registry-ui-modal-grid" method="post" action="/rental/action" data-rental-availability-url="/rental/availability?mode=booking"><input type="hidden" name="_csrf" value="'
            . View::e(Http::csrf())
            . '"><input type="hidden" name="action" value="'
            . $action
            . '"><input type="hidden" name="id" value="'
            . $id
            . '"><input type="hidden" name="version" value="'
            . $version
            . '"><input type="hidden" name="command_key" value="'
            . bin2hex(random_bytes(16))
            . '">'
            . $fields
            . '</form>',
            '<button class="btn btn-primary" type="submit" form="'
            . $fid
            . '">'
            . View::e($label)
            . '</button>'
        ];
    }

    public static function create(): string {
        Auth::requirePermission('rental.write');
        Service::roles([
            'manager'
        ]);
        \App\PagePolicy::requireCommand('reservation.create');
        $start = date('Y-m-d');
        $fields = '<label class="field"><span class="field-label">Помещение</span><select class="field-input field-select" name="object_id" required data-rental-picker="room">'
        . Availability::options(Availability::items($start, $start, null, null, true))
        . '</select></label>'
        . Controller::input('client_name', 'Клиент', '', 'text', true)
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . Controller::input('start_date', 'Планируемое начало аренды', $start, 'date', true)
        . Controller::input('until_date', 'Бронь действует до', date('Y-m-d'), 'date', true)
        . '</div>'
        . Controller::input('comment', 'Описание', '', 'text', true);
        [
            $body,
            $actions
        ] = self::form('reservation.create', 0, 0, $fields, 'Забронировать');
        return RentalUI::modal('Забронировать помещение', $body, $actions, 'contracts');
    }

    public static function card(): string {
        $r = self::record((int)($_GET['id'] ?? 0));
        $active = $r['status'] === 'active' && $r['contract_id'] === null && $r['until_date'] >= date('Y-m-d');
        $body = RentalUI::pairs([
            [
                'Клиент',
                $r['client_name']?:'—'
            ],
            [
                'Помещение',
                $r['object_name']
            ],
            [
                'Планируемое начало',
                Documents::dateText($r['start_date'])
            ],
            [
                'Бронь действует до',
                Documents::dateText($r['until_date'])
            ],
            [
                'Описание',
                $r['comment']
            ],
            [
                'Состояние',
                $active?'Бронь':match ($r['status']) {
                    'cancelled' => 'Бронь снята',
                    'expired' => 'Срок брони истёк',
                    'consumed',
                    'converted' => 'Оформлен договор',
                    default => 'Бронь завершена'
                }
            ]
        ]);
        $source=json_decode($r['source_json'],true) ?: [];
        if(!empty($source['cancellation']['reason']))$body.=RentalUI::alert($source['cancellation']['reason']);
        $actions = '';
        if ($active && in_array(Auth::user()['role'], [
            'admin',
            'manager'
        ], true))$actions = RentalUI::button('Оформить договор', '/rental/new-contract?booking_id='
        . $r['id'], true)
        . RentalUI::button('Продлить бронь', '/rental/booking-action?id='
        . $r['id']
        . '&action=reservation.extend')
        . RentalUI::button('Снять бронь', '/rental/booking-action?id='
        . $r['id']
        . '&action=reservation.cancel');
        return RentalUI::modal('Бронь помещения', $body, $actions, 'contracts');
    }

    public static function action(): string {
        $r = self::record((int)($_GET['id'] ?? 0));
        $action = (string)($_GET['action'] ?? '');
        if (!in_array($action, [
            'reservation.extend',
            'reservation.cancel'
        ], true))throw new RuntimeException('Действие не найдено.', 404);
        Auth::requirePermission('rental.write');
        Service::roles([
            'manager'
        ]);
        \App\PagePolicy::requireCommand($action);
        $fields = RentalUI::pairs([
            [
                'Помещение',
                $r['object_name']
            ],
            [
                'Описание',
                $r['comment']
            ]
        ]);
        if ($action === 'reservation.extend')$fields.=Controller::input('until_date', 'Бронь действует до', $r['until_date'], 'date', true);
        [
            $body,
            $actions
        ] = self::form($action, (int)$r['id'], (int)$r['version'], $fields, $action === 'reservation.extend'?'Продлить бронь':'Снять бронь');
        return RentalUI::modal($action === 'reservation.extend'?'Продлить бронь':'Снять бронь', $body, $actions, 'contracts');
    }
}
