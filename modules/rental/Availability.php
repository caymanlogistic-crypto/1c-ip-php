<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{DB, Http};
final class Availability {

    public static function items(string $start, string $end, ?int $except = null, ?int $booking = null, bool $sharedCustomerBookings = false): array {
        Service::date($start);
        Service::date($end);
        if ($start>$end)throw new \RuntimeException('Проверьте даты аренды.', 422);
        $p = DB::pdo();
        $rows = [
        ];
        foreach (Controller::query('SELECT o.*,c.number cadastre_number,c.type cadastre_type,c.rentable cadastre_rentable,c.source_json cadastre_source FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id ORDER BY o.name,o.id') as $o) {
            if (!DirectoryScope::signals($o, [
                'number' => $o['cadastre_number'],
                'type' => $o['cadastre_type'],
                'rentable' => $o['cadastre_rentable'],
                'source_json' => $o['cadastre_source']
            ]))continue;
            $q = $p->prepare("SELECT 1 FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE ".\App\ContractOccupancy::physicalPredicate('co')." AND co.object_id=? AND c.id<>? AND (c.status='reconciling' OR c.status='active' AND ".Occupancy::boundary('start_date')."<=? AND ".Occupancy::boundary('end_date').">=?) LIMIT 1");
            $q->execute([
                $o['id'],
                $except ?? 0,
                $end,
                $start
            ]);
            $reason = $q->fetchColumn()?'Занято договором или ожидается возврат':'';
            $q = $p->prepare("SELECT 1 FROM rental_object_holds h JOIN contracts c ON c.id=h.contract_id WHERE ".\App\ContractOccupancy::physicalPredicate('h')." AND h.object_id=? AND h.contract_id<>? AND h.return_confirmed=0 AND c.status<>'closed' LIMIT 1");
            $q->execute([
                $o['id'],
                $except ?? 0
            ]);
            if ($q->fetchColumn())$reason = 'Ожидается акт возврата';
            $q = $p->prepare("SELECT 1 FROM reservations r WHERE ".Occupancy::reservationPredicate('r')." AND object_id=? AND id<>?".(($sharedCustomerBookings || $booking!==null)?" AND document_id IS NOT NULL":"")." AND status='active' AND (contract_id IS NULL OR contract_id<>?) AND ((document_id IS NULL AND until_date>=CURRENT_DATE) OR (document_id IS NOT NULL AND start_date<=? AND end_date>=?)) LIMIT 1");
            $q->execute([
                $o['id'],
                $booking ?? 0,
                $except ?? 0,
                $end,
                $start
            ]);
            if ($q->fetchColumn() && $reason==='')$reason = 'Забронировано';
            $bookingCount=0;
            if($sharedCustomerBookings || $booking!==null){
                $count=DB::pdo()->prepare("SELECT COUNT(*) FROM reservations WHERE object_id=? AND document_id IS NULL AND status='active' AND contract_id IS NULL AND until_date>=CURRENT_DATE AND id<>?");$count->execute([$o['id'],$booking ?? 0]);$bookingCount=(int)$count->fetchColumn();
            }
            $area = $o['area'] === null?'—':rtrim(rtrim($o['area'], '0'), '.');
            $rows[] = [
                'id' => (int)$o['id'],
                'name' => $o['name'],
                'type' => $o['type'],
                'area' => $area,
                'label' => $o['name']
                . ' · '
                . $area
                . ' м²',
                'description' => ($o['type']?:'Помещение')
                . ' · '
                . $area
                . ' м² · '
                . ($reason?:'Доступно').($bookingCount?' · броней: '.$bookingCount:''),
                'available' => $reason === '',
                'disabled' => $reason !== ''
            ];
        }
        return $rows;
    }

    public static function endpoint(): never {
        Access::pages([
            'contracts',
            'contracts-active'
        ]);
        $except = !empty($_GET['contract_id'])?(int)$_GET['contract_id']:null;
        if ($except)Access::contract($except);
        $booking = !empty($_GET['booking_id'])?(int)$_GET['booking_id']:null;
        if ($booking)BookingController::record($booking);
        $start = Service::date($_GET['start'] ?? $_GET['start_date'] ?? date('Y-m-d'));
        $endInput = $_GET['end'] ?? $_GET['end_date'] ?? $start;
        $end = Service::date($endInput === '' ? $start : $endInput);
        Http::json(['items' => self::items($start, $end, $except, $booking, in_array($_GET['mode'] ?? '',['booking','contract'],true))]);
    }

    public static function options(array $items, ?int $selected = null): string {
        $h = '<option value="">Выберите помещение</option>';
        foreach ($items as $o)$h.='<option value="'
        . $o['id']
        . '" data-name="'
        . \App\View::e($o['name'])
        . '" data-type="'
        . \App\View::e($o['type'])
        . '" data-area="'
        . \App\View::e($o['area'])
        . '" data-description="'
        . \App\View::e($o['description'])
        . '" data-search="'
        . \App\View::e($o['name']
        . ' '
        . $o['type']
        . ' '
        . $o['area'])
        . '" data-available="'
        . ($o['available']?'true':'false')
        . '"'
        . ($o['disabled']?' disabled':'')
        . ($selected === $o['id']?' selected':'')
        . '>'
        . \App\View::e($o['label'])
        . '</option>';
        return $h;
    }
}
