<?php
declare(strict_types=1);
namespace Modules\Rental;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** One legal handoff's elapsed RF working seconds. No B/booking/payment timers. */
final class LegalSla
{
    public static function evaluate(array $record,?array $handoff,string $now,?SourceCalendar $calendar,string $kind='contract'): array
    {
        if(($record['status']??null)!=='legal')return ['state'=>'NOT_APPLICABLE'];
        $base=$kind==='document' && !empty($handoff['resubmitted'])?'blue':'yellow';
        if($handoff===null)return ['state'=>'UNKNOWN','severity'=>$base,'reason'=>'LEGAL_HANDOFF_NOT_RECORDED'];
        if($calendar===null)return ['state'=>'UNKNOWN','severity'=>$base,'reason'=>'CALENDAR_REFERENCE_UNAVAILABLE'];
        try{
            $zone=new DateTimeZone('Europe/Moscow');
            $from=(new DateTimeImmutable($handoff['created_at'],$zone))->setTimezone($zone);
            $until=(new DateTimeImmutable($now,$zone))->setTimezone($zone);
            if($until<$from)throw new RuntimeException('LEGAL_HANDOFF_IN_FUTURE');
            $elapsed=0;
            for($day=$from->setTime(0,0);$day<=$until;$day=$day->modify('+1 day')){
                // Validate only handoff..now, never the contract's future end date.
                if(!$calendar->isWorkingDate($day->format('Y-m-d')))continue;
                $start=max($from,$day->setTime(9,0));$end=min($until,$day->setTime(18,0));
                if($end>$start)$elapsed+=$end->getTimestamp()-$start->getTimestamp();
            }
            return ['state'=>'KNOWN','severity'=>$elapsed>=21600?'red':$base,'working_seconds'=>$elapsed,
                'handoff_event_id'=>$handoff['event_id'],'calendar'=>$calendar->metadata()];
        }catch(\Exception $error){return ['state'=>'UNKNOWN','severity'=>$base,'reason'=>'LEGAL_TIME_OR_CALENDAR_UNKNOWN'];}
    }
}
