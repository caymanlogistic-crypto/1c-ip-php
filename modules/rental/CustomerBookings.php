<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit, Auth, ContractOccupancy, DB};
use PDO;
use RuntimeException;

/** Client applications overlap. They do not replace physical leases or document holds. */
final class CustomerBookings
{
    public static function canRead(array $actor): bool
    {
        return in_array($actor['role'],['admin','manager','lawyer','accountant','director'],true)
            && Auth::canFor($actor,'rental.read');
    }

    public static function readAccess(): void
    {
        Auth::requirePermission('rental.read');
        if (!self::canRead(Auth::user())) throw new RuntimeException('Резервирование недоступно для этой роли.',403);
    }

    public static function canWrite(): bool
    {
        $actor=Auth::user();
        return $actor && in_array($actor['role'],['admin','manager'],true) && Auth::can('rental.write')
            && \App\PagePolicy::level('rental.contracts',$actor)>=2;
    }

    public static function openForDecision(array $row): bool
    {
        return $row['document_id']===null && $row['contract_id']===null && $row['status']==='active';
    }

    public static function expired(array $row,string $date): bool
    {
        return $row['status']==='expired' || (self::openForDecision($row) && $row['until_date']<$date);
    }

    public static function stopped(PDO $db,array $row): ?array
    {
        if ($row['document_id']!==null || $row['contract_id']===null || $row['status']!=='consumed') return null;
        $q=$db->prepare("SELECT id,number,reason FROM contracts WHERE id=? AND status='cancelled'");
        $q->execute([$row['contract_id']]);
        return $q->fetch() ?: null;
    }

    public static function client(array $row): string
    {
        return trim((string)($row['client_name'] ?? '')) ?: trim((string)($row['party_name'] ?? '')) ?: 'Не указан';
    }

    public static function history(PDO $db,string $action,?array $before,array $after,string $reason='',?int $basis=null,?int $document=null,bool $system=false,?string $origin=null): void
    {
        $q=$db->prepare('SELECT o.name object_name,p.name party_name,u.name responsible FROM rental_objects o
            LEFT JOIN parties p ON p.id=? LEFT JOIN users u ON u.id=? WHERE o.id=?');
        $q->execute([$after['party_id'],$after['author_id'],$after['object_id']]);
        $labels=$q->fetch() ?: [];
        $evidence=[
            'reservation_id'=>(int)$after['id'],'object_id'=>(int)$after['object_id'],'object'=>$labels['object_name'] ?? '',
            'client'=>self::client($after+$labels),'responsible_id'=>(int)($after['author_id'] ?? 0),
            'responsible'=>$labels['responsible'] ?? 'Менеджер по аренде',
            'actor'=>$system?'Система':(Auth::user()['name'] ?? 'Система'),
            'start_date'=>$after['start_date'],'comment'=>$after['comment'],
            'before_until'=>$before['until_date'] ?? null,'until'=>$after['until_date'],'status'=>$after['status'],
            'reason'=>$reason,'contract_id'=>$basis,'document_id'=>$document,'version'=>(int)$after['version'],
        ];
        if ($system) {
            $db->prepare("INSERT INTO audit_log(user_id,action,entity_type,entity_id,before_json,after_json)
                VALUES(NULL,?,'reservation',?,?,?)")
                ->execute([$action,$after['id'],Importer::json($before),Importer::json($evidence)]);
        } else Audit::record($action,'reservation',(int)$after['id'],$before,$evidence);
        if (isset(BookingNotices::TEMPLATES[$action])) {
            $auditId=(int)$db->lastInsertId();
            $actor=$system?null:Auth::user();
            BookingNotices::record($db,$action,$evidence,$auditId,(string)$origin,$actor);
        }
    }

    public static function bind(PDO $db,array $booking,int $contract,int $party): void
    {
        $db->prepare("UPDATE reservations SET contract_id=?,party_id=?,status='consumed',version=version+1 WHERE id=?")
            ->execute([$contract,$party,$booking['id']]);
        $db->prepare("UPDATE contracts SET source_json=JSON_SET(source_json,'$.reservation_origin',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json(['id'=>(int)$booking['id'],'object_id'=>(int)$booking['object_id']]),$contract]);
        self::history($db,'reservation.linked',$booking,Service::get($db,'reservations',(int)$booking['id']),'Проект передан на согласование',$contract);
    }

    public static function contractOrigin(array $contract): string
    {
        $stored=Importer::decodeExact($contract['source_json'])['reservation_origin']['id'] ?? null;
        $id=(int)($stored ?? ContractDraft::input($contract)['booking_id'] ?? 0);
        $row=Controller::query("SELECT r.id,o.name FROM reservations r JOIN rental_objects o ON o.id=r.object_id
            WHERE ".WorkspaceReset::visibleSql('r')." AND r.document_id IS NULL AND (r.id=? OR r.contract_id=? OR EXISTS(
                SELECT 1 FROM audit_log h WHERE h.entity_type='reservation' AND h.entity_id=r.id
                  AND h.action='reservation.reopen' AND JSON_UNQUOTE(JSON_EXTRACT(h.after_json,'$.contract_id'))=?))
            ORDER BY (r.id=?) DESC,r.id LIMIT 1",
            [$id,$contract['id'],(string)$contract['id'],$id])[0] ?? null;
        if (!$row) return '';
        return RentalUI::alert('Исходная заявка №'.$row['id'],'Помещение заявки: '.$row['name'])
            .RentalUI::button('Открыть исходную заявку','/rental/booking?id='.$row['id']);
    }

    public static function periods(PDO $db,int $contract): array
    {
        $q=$db->prepare('SELECT co.object_id,'.Occupancy::boundary('start_date').' start_date,'.Occupancy::boundary('end_date').' end_date
            FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE co.contract_id=? AND '
            .ContractOccupancy::contractPredicate('co').' ORDER BY co.object_id');
        $q->execute([$contract]);
        return array_column($q->fetchAll(),null,'object_id');
    }

    public static function checkActivation(PDO $db,array $contract): void
    {
        ExpiryContinuation::beforeActivation($db,$contract);
        $predecessor=ExpiryContinuation::origin($contract)['contract_id'] ?? null;
        foreach(self::periods($db,(int)$contract['id']) as $term)
            Service::available($db,[(int)$term['object_id']],$term['start_date'],$term['end_date'],(int)$contract['id'],null,false,null,true,$predecessor);
    }

    public static function cancelOthers(PDO $db,int $contractId,?array $prior=null,?array $document=null): void
    {
        if (!$db->inTransaction()) throw new RuntimeException('Cancellation must share the activation transaction');
        $contract=Service::get($db,'contracts',$contractId);
        if ($contract['status']!=='active') throw new RuntimeException('A draft cannot cancel competing bookings');
        $q=$db->prepare("SELECT * FROM reservations WHERE object_id=? AND document_id IS NULL AND contract_id IS NULL
            AND status='active' AND until_date>=CURRENT_DATE AND start_date<=? AND COALESCE(end_date,start_date)>=?
            AND ".WorkspaceReset::visibleSql('reservations').' ORDER BY id FOR UPDATE');
        foreach (self::periods($db,$contractId) as $object=>$term) {
            if (!$term['start_date'] || !$term['end_date']) continue;
            $segments=[[$term['start_date'],$term['end_date']]];
            if ($document) {
                $earlier=$prior[$object] ?? null;
                $from=max($term['start_date'],$document['effective_date']);
                $segments=[[$from,$term['end_date']]];
                if ($earlier && $earlier['start_date'] && $earlier['end_date']) {
                    $left=(new \DateTimeImmutable($earlier['start_date']))->modify('-1 day')->format('Y-m-d');
                    $right=(new \DateTimeImmutable($earlier['end_date']))->modify('+1 day')->format('Y-m-d');
                    $segments=[[$from,min($term['end_date'],$left)],[max($from,$right),$term['end_date']]];
                }
            }
            foreach ($segments as [$from,$until]) {
                if ($from>$until) continue;
                $q->execute([$object,$until,$from]);
                foreach ($q->fetchAll() as $booking) {
                    $source=Importer::decodeExact($booking['source_json']);
                    $reason=$document?'Помещение занято по ДС №'.$document['number'].' к договору №'.$contract['number']
                        :'Помещение передано в аренду по договору №'.$contract['number'];
                    $source['cancellation']=['reason'=>$reason,'contract_id'=>$contractId,'document_id'=>$document['id'] ?? null];
                    $db->prepare("UPDATE reservations SET status='cancelled',source_json=?,version=version+1 WHERE id=?")
                        ->execute([Importer::json($source),$booking['id']]);
                    self::history($db,'reservation.auto-cancel',$booking,Service::get($db,'reservations',(int)$booking['id']),
                        $reason,$contractId,isset($document['id'])?(int)$document['id']:null);
                }
            }
        }
    }
}
