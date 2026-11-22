<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\DB;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Records notices in the business transaction. Transport is an independently admitted operation. */
final class BookingNotices
{
    public const NS='rental_booking_notice';
    public const TEMPLATES=[
        'reservation.create'=>['reservationCreated','Резервирование создано'],
        'reservation.extend'=>['reservationExtended','Резервирование продлено'],
        'reservation.cancel'=>['reservationCancelled','Резервирование снято'],
        'reservation.reminder'=>['reservationReminder','Резервирование истекает завтра'],
        'reservation.expire'=>['reservationExpired','Резервирование снято по истечении срока'],
    ];

    public static function record(PDO $db,string $action,array $evidence,int $auditId,string $origin,?array $actor): void
    {
        if (!isset(self::TEMPLATES[$action])) return;
        if (!$db->inTransaction()) throw new RuntimeException('Reservation notice requires its business transaction.');
        $epoch=(string)$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        $key=hash('sha256',implode(':',[$epoch,$action,$evidence['reservation_id'],$evidence['version']]));
        $notice=$evidence+['schema'=>1,'epoch'=>$epoch,'action'=>$action,'audit_id'=>$auditId,'origin'=>$origin,
            'actor_id'=>$actor?(int)$actor['id']:null,'auth_version'=>$actor?(int)$actor['auth_version']:null,'recorded_at'=>time()];
        $notice['sha256']=hash('sha256',Importer::canonicalJson($notice));
        $db->prepare('INSERT IGNORE INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::NS,$key,Importer::json($notice)]);
    }

    private static function candidates(PDO $db,string $date): array
    {
        $tomorrow=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $q=$db->prepare("SELECT r.*,o.name object_name FROM reservations r JOIN rental_objects o ON o.id=r.object_id
            WHERE r.document_id IS NULL AND r.contract_id IS NULL AND r.status='active'
            AND (r.until_date<? OR r.until_date=?) AND ".WorkspaceReset::visibleSql('r').' ORDER BY r.id');
        $q->execute([$date,$tomorrow]);
        $rows=[];
        foreach ($q->fetchAll() as $row) {
            $action=$row['until_date']<$date?'reservation.expire':'reservation.reminder';
            $rows[]=['action'=>$action,'id'=>(int)$row['id'],'until'=>$row['until_date'],'version'=>(int)$row['version'],
                'key'=>implode(':',[$action,$row['id'],$row['version']]),'author_id'=>$row['author_id']];
        }
        return $rows;
    }

    public static function dated(string $date,bool $apply=false): array
    {
        $date=Service::date($date);
        if (!$apply) return ['plan'=>self::candidates(DB::pdo(),$date),'applied'=>[]];
        return DB::transaction(static function(PDO $db) use($date): array {
            $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $plan=self::candidates($db,$date);
            $done=[];
            foreach ($plan as $item) {
                $insert=$db->prepare('INSERT IGNORE INTO rental_schedule_runs(run_key,action,details_json) VALUES(?,?,?)');
                $insert->execute([$item['key'],$item['action'],Importer::json($item+['processed_on'=>$date])]);
                if ($insert->rowCount()!==1) continue;
                $row=Service::get($db,'reservations',$item['id']);
                $after=$row;
                $reason='За день до истечения срока';
                if ($item['action']==='reservation.expire') {
                    $reason='Срок резервирования истёк';
                    $db->prepare("UPDATE reservations SET status='expired',version=version+1 WHERE id=?")->execute([$item['id']]);
                    $after['status']='expired';
                    $after['version']=(int)$row['version']+1;
                }
                CustomerBookings::history($db,$item['action'],$row,$after,$reason,null,null,true,$item['key']);
                $done[]=$item['key'];
            }
            return ['plan'=>$plan,'applied'=>$done];
        });
    }

    /** Queued advance reminders survive only while the version, deadline and day still match. */
    public static function current(PDO $db,array $notice,string $date): bool
    {
        if ($notice['epoch']!==(string)$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn()) return false;
        $q=$db->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$notice['reservation_id']]);
        $row=$q->fetch();
        if (!$row || WorkspaceReset::hidden($row)) return false;
        if ($notice['action']!=='reservation.reminder') return true;
        $tomorrow=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        return CustomerBookings::openForDecision($row) && (int)$row['version']===$notice['version'] && $row['until_date']===$tomorrow;
    }

    public static function values(array $notice): array
    {
        $comment=trim((string)($notice['comment'] ?? ''));
        $specific=match ($notice['action']) {
            'reservation.create'=>['reservationStart'=>Documents::dateText($notice['start_date']),
                'reservationCommentLine'=>$comment===''?'':"\nКомментарий · ".$comment],
            'reservation.extend'=>['previousDeadline'=>Documents::dateText($notice['before_until'])],
            default=>[],
        };
        return ['number'=>(string)$notice['reservation_id'],'context'=>$notice['object'],'party'=>$notice['client'],
            'manager'=>$notice['responsible'],'effective'=>Documents::dateText($notice['until']),
            'reason'=>$notice['reason'],'actor'=>$notice['actor']]+$specific;
    }
}
