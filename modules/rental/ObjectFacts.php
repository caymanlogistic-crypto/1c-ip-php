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
        $lease=$read ? $read->lease((int)$object['id']) : (Occupancy::leasesOn($today,(int)$object['id'])[$object['id']] ?? null);
        if ($free || !$eligible) $lease=null;
        if ($lease && $lease['status']==='reconciling') {
            $returned=false;
            if ($lease['return_confirmed']) {
                try {$returned=ReturnAct::confirmed(\App\DB::pdo(),$lease)!==null;}
                catch (\RuntimeException $error) {if ($error instanceof \PDOException) throw $error;}
            }
            $status=$returned?'Возврат подтверждён · финальная сверка договора '.$lease['number']:
                'Финальная сверка договора '.$lease['number'].' · возврат ещё не завершён';
        }
        $bookings=$read ? $read->bookings((int)$object['id']) : Controller::query('SELECT r.id,r.until_date,r.comment,COALESCE(NULLIF(r.client_name,\'\'),p.name) client_name '
            .'FROM reservations r LEFT JOIN parties p ON p.id=r.party_id WHERE '.Occupancy::reservationPredicate('r')
            ." AND r.object_id=? AND r.status='active' AND r.until_date>=? ORDER BY r.id DESC",[$object['id'],$today]);
        if (!$eligible) $bookings=[];
        $booking=$bookings[0] ?? null;
        $id=(int)$object['id'];
        $work=$eligible?($read?$read->work($id):(ObjectReadModel::pendingWork($id)[$id] ?? [])):[];
        $days=self::idleDays($object,$free,$today);
        if ($read) $previous=$read->previous((int)$object['id']);
        else {
            $previousRow=Controller::query('SELECT p.name,t.end_date FROM contract_terms t JOIN contracts c ON c.id=t.contract_id JOIN parties p ON p.id=c.party_id '
                .'LEFT JOIN contract_objects co ON co.contract_id=c.id AND co.object_id=t.object_id '
                ."WHERE ".ContractOccupancy::physicalPredicate('co')." AND t.object_id=? AND c.status IN ('active','reconciling','closed','ended') AND t.end_date<=? ORDER BY t.end_date DESC,c.id DESC LIMIT 1",[$object['id'],$today])[0] ?? null;
            $seed=Controller::query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_data' AND source_key=?",['last_occupant:'.$object['id']])[0]['fact_json'] ?? null;
            $previous=self::previousTenant($previousRow,$seed?json_decode($seed,true):null,$today);
        }
        $idleUnknown=self::reviewedUnknownHistory($object,$free,$days,$previous);
        $severity=''; $recent=null;
        if ($eligible) $severity=self::idleSeverity($days);
        if ($eligible && !$free && $booking && $status==='Резервирование') $severity='blue';
        if ($eligible && !$free && $lease && $lease['status']==='active' && !$booking && ($lease['term_start'] ?? $today)<=$today) {
            $event=$read ? $read->event((int)$lease['id']) : (Controller::query("SELECT DATE(MIN(created_at)) at FROM rental_events WHERE contract_id=? AND id>? AND action IN ('contract.sign','contract.activated')",[$lease['id'],ZeroPoint::eventFloor(\App\DB::pdo())])[0]['at'] ?? null);
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
        return compact('eligible','free','status','lease','document','booking','bookings','days','previous','severity','recent','deadline','idleUnknown','work');
    }

    public static function reviewedUnknownHistory(array $object,bool $free,?int $days,?string $previous): ?string
    {
        $review=json_decode($object['source_json'],true)['physicalHistoryReview'] ?? [];
        return $free && $days===null && $previous===null && ($review['status'] ?? '')==='NO_PHYSICAL_LEASE_FOUND'
            && ($review['owner_confirmed'] ?? false)===true && ($review['legal_address_excluded'] ?? false)===true
            ? 'Нет данных о физической аренде' : null;
    }

    public static function previousTenant(?array $current,?array $historical,string $today): ?string
    {
        if ($historical && ($historical['as_of'] ?? '9999-12-31')<=$today
            && (!$current || !empty($historical['end_date']) && $historical['end_date']>$current['end_date']))
            return $historical['tenant'] ?? ($current['name'] ?? null);
        return $current['name'] ?? null;
    }

    public static function days(int $days): string
    {
        $n=abs($days)%100;$last=$n%10;
        return $days.' '.($n>=11 && $n<=14?'дней':($last===1?'день':($last>=2 && $last<=4?'дня':'дней')));
    }
}
