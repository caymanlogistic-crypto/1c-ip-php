<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, Http, View};
use RuntimeException;
final class BookingController {

    public static function record(int $id): array {
        CustomerBookings::readAccess();
        $r=Controller::query('SELECT r.*,o.name object_name,p.name party_name,u.name responsible FROM reservations r
            JOIN rental_objects o ON o.id=r.object_id LEFT JOIN parties p ON p.id=r.party_id
            LEFT JOIN users u ON u.id=r.author_id WHERE r.id=?',[$id])[0] ?? throw new RuntimeException('Резервирование не найдено.',404);
        if(WorkspaceReset::hidden($r))throw new RuntimeException('Резервирование не найдено.',404);
        if ($r['document_id'] !== null)throw new RuntimeException('Резервирование относится к допсоглашению.', 403);
        return $r;
    }

    public static function listing(): string
    {
        $registry=WorkRegistry::render(true);
        $actions=CustomerBookings::canWrite()?RentalUI::button('Резервирование помещения','/rental/reservations',true):'';
        return View::page('Резервирование',Controller::heading('Резервирование','',$actions).$registry,'booking-list');
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
        . Availability::options(Availability::items($start, $start, null, null, true),(int)($_GET['object_id'] ?? 0) ?: null)
        . '</select></label>'
        . Controller::input('client_name', 'Предварительное имя клиента', '', 'text', true)
        . '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
        . Controller::input('start_date', 'Планируемое начало аренды', $start, 'date', true)
        . Controller::input('until_date', 'Резервирование действует до', date('Y-m-d'), 'date', true)
        . '</div>'
        . Controller::input('comment', 'Комментарий', '', 'text', false);
        [
            $body,
            $actions
        ] = self::form('reservation.create', 0, 0, $fields, 'Сохранить резервирование');
        return RentalUI::modal('Резервирование помещения', $body, $actions, 'contracts');
    }

    public static function card(): string {
        $r = self::record((int)($_GET['id'] ?? 0));
        $expired=CustomerBookings::expired($r,date('Y-m-d'));
        $active=CustomerBookings::openForDecision($r) && !$expired;
        $stopped=CustomerBookings::stopped(\App\DB::pdo(),$r);
        $body=RentalUI::pairs([
            ['Заявка','№'.$r['id']],['Клиент',CustomerBookings::client($r)],['Помещение заявки',$r['object_name']],
            ['Планируемое начало',Documents::dateText($r['start_date'])],
            ['Резервирование действует до',Documents::dateText($r['until_date'])],
            ['Ответственный',$r['responsible'] ?: People::label('manager')],['Комментарий',$r['comment']],
            ['Состояние',$stopped?'Оформление договора прекращено':($expired?'Срок резервирования истёк':match ($r['status']) {
                'active'=>'Резервирование действует', 'cancelled'=>'Резервирование снято',
                'consumed','converted'=>'Заявка связана с договором', default=>'Резервирование завершено',
            })],
        ]);
        $source=Importer::decodeExact($r['source_json']);
        if (!empty($source['cancellation']['reason'])) $body.=RentalUI::alert($source['cancellation']['reason']);
        if ($r['contract_id']) {
            $contract=Controller::query('SELECT number FROM contracts WHERE id=?',[$r['contract_id']])[0] ?? null;
            if ($contract) $body.=RentalUI::pairs([['Связанный договор','№'.$contract['number']]]);
            try {
                Access::contract((int)$r['contract_id']);
                $body.=RentalUI::button('Открыть договор','/rental/contract?id='.$r['contract_id']);
            } catch (RuntimeException $e) {
                if (!in_array($e->getCode(),[403,404],true)) throw $e;
            }
        }
        $history=[];
        foreach (Controller::query("SELECT created_at,after_json FROM audit_log
            WHERE entity_type='reservation' AND entity_id=? ORDER BY id DESC",[$r['id']]) as $entry) {
            $step=Importer::decodeExact($entry['after_json']);
            $history[]=array_map(View::e(...),[$entry['created_at'],$step['actor'] ?? '—',
                $step['before_until'] ?? '—',$step['until'] ?? '—',($step['reason'] ?? '') ?: ($step['status'] ?? '—')]);
        }
        if ($history) $body.='<details><summary>История заявки</summary>'
            .Controller::table(['Дата','Кто','Прежний срок','Новый срок','Причина'],$history,['short','text','short','short','flex']).'</details>';
        $actions='';
        if (CustomerBookings::canWrite() && ($active || $stopped)) {
            if ($active) $actions=RentalUI::button('Оформить договор','/rental/new-contract?booking_id='.$r['id'],true)
                .self::actionButton($r,'extend','Продлить резервирование');
            if ($stopped && $r['until_date']>=date('Y-m-d')) $actions=self::actionButton($r,'reopen','Вернуть заявку в работу');
            $actions.=self::actionButton($r,'cancel',$stopped?'Клиент отказался':'Снять резервирование');
        }
        return RentalUI::modal('Резервирование помещения',$body,$actions,'booking-list');
    }

    private static function actionButton(array $row,string $action,string $label): string
    {
        return RentalUI::button($label,'/rental/booking-action?id='.$row['id'].'&action=reservation.'.$action);
    }

    public static function action(): string {
        $r = self::record((int)($_GET['id'] ?? 0));
        $action = (string)($_GET['action'] ?? '');
        $labels=['reservation.extend'=>'Продлить резервирование','reservation.cancel'=>'Снять резервирование',
            'reservation.reopen'=>'Вернуть заявку в работу'];
        if (!isset($labels[$action])) throw new RuntimeException('Действие не найдено.',404);
        Auth::requirePermission('rental.write');
        Service::roles(['manager']);
        \App\PagePolicy::requireCommand($action);
        $stopped=CustomerBookings::stopped(\App\DB::pdo(),$r);
        $active=CustomerBookings::openForDecision($r) && !CustomerBookings::expired($r,date('Y-m-d'));
        if (($action==='reservation.reopen' && (!$stopped || $r['until_date']<date('Y-m-d'))) || ($action==='reservation.extend' && !$active)
            || ($action==='reservation.cancel' && !$active && !$stopped))
            throw new RuntimeException('Действие недоступно для этой заявки.',409);
        $fields=RentalUI::pairs([['Помещение',$r['object_name']],['Клиент',CustomerBookings::client($r)]]);
        if ($action==='reservation.extend') {
            $fields.=Controller::input('until_date','Резервирование действует до',max(date('Y-m-d'),$r['until_date']),'date',true);
        }
        if ($action==='reservation.reopen') $fields.=RentalUI::pairs([['Действует до',Documents::dateText($r['until_date'])]]).'<label class="field"><span><input type="checkbox" name="still_interested" value="1" required> Клиент по-прежнему заинтересован</span></label>'
            .Controller::input('reason','Основание возврата в работу','','text',true);
        if ($action==='reservation.cancel') $fields.=($stopped?'<input type="hidden" name="client_declined" value="1">':
            '<label class="field"><span><input type="checkbox" name="client_declined" value="1"> Клиент отказался</span></label>')
            .Controller::input('reason','Причина снятия','','text',false);
        $label=$action==='reservation.cancel' && $stopped?'Зафиксировать отказ клиента':$labels[$action];
        [$body,$actions]=self::form($action,(int)$r['id'],(int)$r['version'],$fields,$label);
        return RentalUI::modal($label,$body,$actions,'booking-list');
    }
}
