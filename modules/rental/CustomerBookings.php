<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\Audit;
use PDO;
use RuntimeException;

/** Client requests may overlap. Document reservations remain exclusive. */
final class CustomerBookings
{
    public static function checkActivation(PDO $pdo,array $contract): void
    {
        $q=$pdo->prepare('SELECT co.object_id,'.Occupancy::boundary('start_date').' start_date,'.Occupancy::boundary('end_date').' end_date FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE co.contract_id=? AND '.\App\ContractOccupancy::physicalPredicate('co').' ORDER BY co.object_id');
        $q->execute([$contract['id']]);
        foreach($q->fetchAll() as $term)Service::available($pdo,[(int)$term['object_id']],$term['start_date'],$term['end_date'],(int)$contract['id'],null,false,null,true);
    }

    public static function cancelOthers(PDO $pdo,int $contractId): void
    {
        if(!$pdo->inTransaction())throw new RuntimeException('Cancellation must share the activation transaction');
        $contract=Service::get($pdo,'contracts',$contractId);
        if($contract['status']!=='active')throw new RuntimeException('A draft cannot cancel competing bookings');
        $q=$pdo->prepare("SELECT r.* FROM reservations r JOIN contract_objects co ON co.object_id=r.object_id WHERE co.contract_id=? AND ".\App\ContractOccupancy::physicalPredicate('co')." AND r.document_id IS NULL AND r.status='active' AND r.until_date>=CURRENT_DATE AND (r.contract_id IS NULL OR r.contract_id<>?) ORDER BY r.id FOR UPDATE");
        $q->execute([$contractId,$contractId]);
        foreach($q->fetchAll() as $booking){
            $source=json_decode($booking['source_json'],true) ?: [];
            $source['cancellation']=['reason'=>'Помещение передано в аренду по договору №'.$contract['number'],'contract_id'=>$contractId];
            $pdo->prepare("UPDATE reservations SET status='cancelled',source_json=?,version=version+1 WHERE id=?")->execute([Importer::json($source),$booking['id']]);
            Audit::record('reservation.auto-cancel','reservation',(int)$booking['id'],$booking,['status'=>'cancelled','contract_id'=>$contractId,'reason'=>$source['cancellation']['reason']]);
        }
    }
}
