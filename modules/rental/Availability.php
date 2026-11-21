<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\Http;
final class Availability {

    public static function items(string $start, string $end, ?int $except = null, ?int $booking = null, bool $sharedCustomerBookings = false, ?int $predecessor=null, bool $occupiedBilling=false): array {
        Service::date($start);
        Service::date($end);
        if ($start>$end)throw new \RuntimeException('Проверьте даты аренды.', 422);
        // Load occupancy sets once; the predicates and precedence are shared by every object.
        $busy=[];$held=[];$reserved=[];$expired=[];
        foreach (Controller::query('SELECT DISTINCT co.object_id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE '
            .\App\ContractOccupancy::contractPredicate('co')." AND c.id<>? AND (c.status='reconciling' OR c.status='active' AND ".Occupancy::boundary('start_date')."<=? AND ".Occupancy::boundary('end_date').">=?)",[$except ?? 0,$end,$start]) as $row) $busy[$row['object_id']]=true;
        foreach (Controller::query('SELECT DISTINCT co.object_id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE '
            .\App\ContractOccupancy::contractPredicate('co').' AND c.id<>? AND c.id<>? AND '
            .Occupancy::expiredWithoutReturn(Occupancy::boundary('end_date'),'LEAST(CURRENT_DATE,?)','?'),
            [$except ?? 0,$predecessor ?? 0,$end,$start]) as $row) $expired[$row['object_id']]=true;
        foreach (Controller::query('SELECT DISTINCT h.object_id FROM rental_object_holds h JOIN contracts c ON c.id=h.contract_id WHERE '
            .\App\ContractOccupancy::physicalPredicate('h')." AND h.contract_id<>? AND h.return_confirmed=0 AND c.status<>'closed'",[$except ?? 0]) as $row) $held[$row['object_id']]=true;
        foreach (Controller::query('SELECT DISTINCT r.object_id FROM reservations r WHERE '.Occupancy::reservationPredicate('r')
            ." AND r.id<>?".(($sharedCustomerBookings || $booking!==null)?" AND r.document_id IS NOT NULL":"")." AND r.status='active' AND (r.contract_id IS NULL OR r.contract_id<>?) AND ((r.document_id IS NULL AND r.until_date>=CURRENT_DATE) OR (r.document_id IS NOT NULL AND r.start_date<=? AND r.end_date>=?))",[$booking ?? 0,$except ?? 0,$end,$start]) as $row) $reserved[$row['object_id']]=true;
        $bookingCounts=[];
        if ($sharedCustomerBookings || $booking!==null) foreach (Controller::query("SELECT object_id,COUNT(*) n FROM reservations WHERE document_id IS NULL AND status='active' AND contract_id IS NULL AND until_date>=CURRENT_DATE AND id<>? GROUP BY object_id",[$booking ?? 0]) as $row) $bookingCounts[$row['object_id']]=(int)$row['n'];
        $rows=[];
        foreach (Controller::query('SELECT o.*,c.number cadastre_number,c.type cadastre_type,c.rentable cadastre_rentable,c.source_json cadastre_source FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id ORDER BY o.name,o.id') as $o) {
            if (!DirectoryScope::signals($o, [
                'number'=>$o['cadastre_number'],'type'=>$o['cadastre_type'],'rentable'=>$o['cadastre_rentable'],'source_json'=>$o['cadastre_source']
            ])) continue;
            $reason=isset($held[$o['id']]) || isset($expired[$o['id']])?'Ожидается акт возврата':(isset($busy[$o['id']])?'Занято договором или ожидается возврат':(isset($reserved[$o['id']])?'Резервирование':''));
            $billingOnly=$occupiedBilling && isset($busy[$o['id']])
                && !isset($held[$o['id']]) && !isset($expired[$o['id']]) && !isset($reserved[$o['id']]);
            if ($billingOnly) $reason='Занято · только по исключению для начислений';
            $bookingCount=$bookingCounts[$o['id']] ?? 0;
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
                . ($reason?:'Доступно').($bookingCount?' · резервирований: '.$bookingCount:''),
                'available' => $reason === '',
                'disabled' => $reason !== '' && !$billingOnly
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
        $continuation=isset($_GET['expiry_contract']) ? ExpiryContinuation::editorContext(null,'contract') : [];
        $predecessor=$continuation && $start===$continuation['offer']['new_start'] ? $continuation['contract_id'] : null;
        $mode=$_GET['mode'] ?? '';
        Http::json(['items' => self::items($start, $end, $except, $booking, in_array($mode,['booking','contract'],true),$predecessor,$mode==='contract')]);
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
