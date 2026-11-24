<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\ContractOccupancy;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class RecentContractPackages
{
    private const DAYS=14;

    public static function age(?string $eventDate,string $today): ?int
    {
        if ($eventDate===null || $eventDate>$today) return null;
        $days=(int)(new DateTimeImmutable($eventDate))->diff(new DateTimeImmutable($today))->days;
        return $days<self::DAYS?$days:null;
    }

    public static function byContract(PDO $db,string $today): array
    {
        $date=new DateTimeImmutable($today);
        $from=$date->modify('-'.(self::DAYS-1).' days')->format('Y-m-d');
        $until=$date->modify('+1 day')->format('Y-m-d');
        $query=$db->prepare("SELECT e.id,e.contract_id,e.created_at,e.source_json,
            c.number,c.start_date,p.name tenant,r.fingerprint,r.result_json
            FROM rental_events e JOIN contracts c ON c.id=e.contract_id JOIN parties p ON p.id=c.party_id
            JOIN rental_commands r ON r.action=e.action AND r.command_key=JSON_UNQUOTE(JSON_EXTRACT(e.source_json,'$.command_key'))
            WHERE e.action='contract.packet' AND e.id>? AND e.created_at>=? AND e.created_at<?
                AND c.status='active' AND ".ContractDraft::visibleSql('c')."
                AND JSON_UNQUOTE(JSON_EXTRACT(e.source_json,'$.epoch'))=(SELECT epoch FROM rental_runtime_context WHERE id=1)
            ORDER BY e.created_at DESC,e.id DESC");
        $query->execute([ZeroPoint::eventFloor($db),$from,$until]);
        $packages=[];
        foreach ($query as $row) {
            $contract=(int)$row['contract_id'];
            if (isset($packages[$contract])) continue;
            try {
                $event=WorkflowEvents::decode($row);
                $result=Importer::decodeExact($row['result_json']);
            } catch (RuntimeException|JsonException) {
                continue;
            }
            if ($event===null || $event['action']!=='contract.packet' || $event['entity_kind']!=='contract'
                || $event['entity_id']!==$contract || $event['contract_id']!==$contract || $event['event_kind']!=='package'
                || !in_array($event['from_status'],['accounting','approved'],true) || $event['to_status']!=='active'
                || ($result['id']??null)!==$contract || ($result['status']??null)!=='active'
                || !hash_equals($row['fingerprint'],(string)($event['command_fingerprint']??''))
                || !hash_equals(hash('sha256',$row['result_json']),(string)($event['command_result_sha256']??''))) continue;
            $eventDate=substr($row['created_at'],0,10);
            $packages[$contract]=['contract_id'=>$contract,'date'=>$eventDate,'days'=>self::age($eventDate,$today),
                'number'=>$row['number'],'start_date'=>$row['start_date'],'tenant'=>$row['tenant']];
        }
        return $packages;
    }

    public static function byObject(PDO $db,string $today,?int $objectId=null,?array $packages=null): array
    {
        $packages??=self::byContract($db,$today);
        if (!$packages) return [];
        $ids=array_keys($packages);
        $slots=implode(',',array_fill(0,count($ids),'?'));
        $query=$db->prepare("SELECT co.contract_id,co.object_id FROM contract_objects co
            WHERE co.contract_id IN ($slots) AND ".ContractOccupancy::contractPredicate('co')
            .($objectId!==null?' AND co.object_id=?':''));
        $query->execute($objectId===null?$ids:[...$ids,$objectId]);
        $objects=[];
        foreach ($query as $row) $objects[(int)$row['contract_id']][]=(int)$row['object_id'];
        $recent=[];
        foreach ($packages as $contract=>$package) {
            foreach ($objects[$contract]??[] as $object) $recent[$object]??=$package;
        }
        return $recent;
    }
}
