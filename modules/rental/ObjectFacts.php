<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,ContractOccupancy};

/** Minimal object-directory projection, without exposing a counterparty bank or full contract. */
final class ObjectFacts
{
    public static function idleDays(array $object,bool $free,string $today): ?int
    {
        if (!$free || empty($object['free_since']) || $object['free_since']>$today) return null;
        return (int)(new \DateTimeImmutable($object['free_since']))->diff(new \DateTimeImmutable($today))->days;
    }

    public static function idleSeverity(?int $days): string
    {
        if ($days===null) return '';
        if ($days>=30) return 'red';
        return $days>=14?'yellow':'';
    }

    /** Use the directory's actual availability, including dates, holds and reservations. */
    public static function idleSignals(string $today,?ObjectReadModel $read=null): array
    {
        $read??=new ObjectReadModel($today);
        $signals=[];
        foreach ($read->objects as $object) {
            $days=self::idleDays($object,$read->availability[$object['id']]['available'] ?? false,$today);
            foreach (['manager','director'] as $role) {
                $severity=self::idleSeverity($days);
                // The common directory appearance does not reassign personal tasks.
                if ($role==='director' && $severity==='yellow') continue;
                if ($severity!=='') $signals[]=['id'=>(int)$object['id'],'role'=>$role,'severity'=>$severity,'text'=>'Простой '.self::days($days)];
            }
        }
        return $signals;
    }

    public static function counts(?ObjectReadModel $read=null): array
    {
        $counts=[0,0,0,0];
        if (!Auth::can('rental.read') || \App\PagePolicy::level('rental.objects',Auth::user())<1) return $counts;
        $today=date('Y-m-d');$read??=new ObjectReadModel($today);
        foreach ($read->objects as $object) {
            $parent=ObjectReadModel::parent($object);
            if (!DirectoryScope::signals($object,$parent)) continue;
            $facts=self::project($object,$parent,$read->availability[$object['id']] ?? null,$today,$read);
            $index=array_search($facts['severity'],['red','yellow','green','blue'],true);
            if ($index!==false) $counts[$index]++;
        }
        return $counts;
    }

    public static function project(array $object,array $parent,?array $availability,string $today,?ObjectReadModel $read=null): array
    {
        if ($read && $read->today!==$today) throw new \LogicException('Object snapshot date differs');
        $eligible=DirectoryScope::signals($object,$parent);
        $free=$eligible && ($availability['available'] ?? false);
        $status=!$object['active']?'Неактивен':(!$eligible?'Недоступен для сдачи':($free?'Свободно':str_replace(' · ',' ',(string)($availability['description'] ?? 'Занято'))));
        if ($eligible && !$free) $status=explode(' · ',(string)($availability['description'] ?? 'Занято'))[2] ?? 'Занято';
        $lease=$read ? $read->lease((int)$object['id']) : (Controller::query('SELECT c.id,c.number,c.document_date,c.status,p.name tenant,t.source_json term_source,COALESCE(t.start_date,c.start_date) term_start,COALESCE(t.end_date,c.end_date) end_date '
            .'FROM contract_objects co JOIN contracts c ON c.id=co.contract_id JOIN parties p ON p.id=c.party_id '
            .'LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2 WHERE t2.contract_id=c.id AND t2.object_id=co.object_id AND (t2.effective_date IS NULL OR t2.effective_date<=?)) '
            .'WHERE '.ContractOccupancy::physicalPredicate('co')." AND co.object_id=? AND c.status IN ('active','reconciling') ORDER BY c.id DESC LIMIT 1",[$today,$object['id']])[0] ?? null);
        if ($free) $lease=null;
        if ($lease && $lease['status']==='reconciling') $status='Финальная сверка договора '.$lease['number'].'; возврат ещё не завершён';
        $bookings=$read ? $read->bookings((int)$object['id']) : Controller::query('SELECT r.id,r.until_date,r.comment,COALESCE(NULLIF(r.client_name,\'\'),p.name) client_name '
            .'FROM reservations r LEFT JOIN parties p ON p.id=r.party_id WHERE '.Occupancy::reservationPredicate('r')
            ." AND r.object_id=? AND r.status='active' AND r.until_date>=? ORDER BY r.id DESC",[$object['id'],$today]);
        $booking=$bookings[0] ?? null;
        $days=self::idleDays($object,$free,$today);
        $previous=$read ? $read->previous((int)$object['id']) : (Controller::query('SELECT p.name FROM contract_terms t JOIN contracts c ON c.id=t.contract_id JOIN parties p ON p.id=c.party_id '
            .'LEFT JOIN contract_objects co ON co.contract_id=c.id AND co.object_id=t.object_id '
            .'WHERE '.ContractOccupancy::physicalPredicate('co').' AND t.object_id=? AND c.status IN (\'active\',\'reconciling\',\'closed\',\'ended\') AND t.end_date<=? ORDER BY t.end_date DESC,c.id DESC LIMIT 1',[$object['id'],$today])[0]['name'] ?? null);
        $severity=''; $recent=null;
        if ($eligible) $severity=self::idleSeverity($days);
        if ($eligible && !$free && $booking && str_contains($status,'бронир')) $severity='blue';
        if ($eligible && !$free && $lease && $lease['status']==='active' && !$booking && ($lease['term_start'] ?? $today)<=$today) {
            $event=$read ? $read->event((int)$lease['id']) : (Controller::query("SELECT DATE(MIN(created_at)) at FROM rental_events WHERE contract_id=? AND action IN ('contract.sign','contract.activated')",[$lease['id']])[0]['at'] ?? null);
            foreach (($read ? $read->applied((int)$lease['id']) : Controller::query("SELECT DATE(a.created_at) at,a.after_json FROM audit_log a JOIN rental_documents d ON d.id=a.entity_id WHERE a.action='document.objects-applied' AND a.entity_type='document' AND d.contract_id=? AND d.applied=1 AND d.status='signed'",[$lease['id']])) as $applied) {
                $data=json_decode($applied['after_json'],true);
                if (in_array((int)$object['id'],$data['added_object_ids'] ?? [],true)) {
                    $since=max($applied['at'],$data['effective_date']);
                    if ($since<=$today && ($event===null || $since>$event)) $event=$since;
                }
            }
            if ($event && $event<=$today) {
                $age=(int)(new \DateTimeImmutable($event))->diff(new \DateTimeImmutable($today))->days;
                if ($age<14) { $severity='green'; $recent=['date'=>$event,'days'=>$age]; }
            }
        }
        $deadline=null;
        if ($eligible && $lease && $lease['end_date']) {
            $date=new \DateTimeImmutable($today); $next=$date->modify('first day of next month');
            $bound=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$date->format('d'),(int)$next->format('t')))->format('Y-m-d');
            if ($lease['end_date']<$bound) {
                $remaining=(int)$date->diff(new \DateTimeImmutable($lease['end_date']))->format('%r%a');
                $deadline=$remaining===0?'Договор заканчивается сегодня':($remaining>0?'До окончания договора '.self::days($remaining):'Срок договора истёк '.self::days(-$remaining).' назад');
            }
        }
        $document=null;
        if ($lease) {
            $document=$read ? $read->document((int)$lease['id'],(int)$object['id']) : (Controller::query("SELECT d.number,d.document_date FROM rental_documents d JOIN document_terms t ON t.document_id=d.id WHERE d.contract_id=? AND d.status='signed' AND d.applied=1 AND d.effective_date<=? AND (d.document_date IS NULL OR d.document_date<=?) AND t.object_id=? AND t.start_date<=? AND t.end_date>=? ORDER BY d.effective_date DESC,d.id DESC LIMIT 1",[$lease['id'],$today,$today,$object['id'],$today,$today])[0] ?? null);
            if (!$document) {
                $raw=json_decode($lease['term_source'] ?? '{}',true) ?: [];
                $at=$raw['agreementDate'] ?? null;
                $parsed=is_string($at)?\DateTimeImmutable::createFromFormat('!Y-m-d',$at):false;
                if (!empty($raw['agreement']) && $parsed && $parsed->format('Y-m-d')===$at && $at<=$today) {
                    $document=['number'=>preg_replace('/^ДС\s*№?\s*/iu','',trim($raw['agreement'])),'document_date'=>$at];
                }
            }
        }
        return compact('eligible','free','status','lease','document','booking','bookings','days','previous','severity','recent','deadline');
    }

    public static function days(int $days): string
    {
        $n=abs($days)%100;$last=$n%10;
        return $days.' '.($n>=11 && $n<=14?'дней':($last===1?'день':($last>=2 && $last<=4?'дня':'дней')));
    }
}
