<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, ContractOccupancy, PagePolicy};
use PDO;
use RuntimeException;

final class OccupiedBilling
{
    public const SAVE = 'contract.occupied-billing';
    public const LABEL = 'Разрешить начисления по занятому объекту';
    public const OBJECT_LABEL = 'Начисления по исключению';

    public static function byObject(string $day,?int $objectId=null): array
    {
        $path='CONCAT(\'$.'.ContractOccupancy::EXCEPTIONS.'."\',co.object_id,\'"\')';
        $until="JSON_UNQUOTE(JSON_EXTRACT(c.source_json,CONCAT($path,'.until')))";
        $rows=Controller::query("SELECT co.object_id,c.number,p.name,$until until_date
            FROM contract_objects co JOIN contracts c ON c.id=co.contract_id JOIN parties p ON p.id=c.party_id
            WHERE c.status='active' AND ".ContractOccupancy::exceptionAt('c','co')." AND $until>=?"
            .($objectId===null?'':' AND co.object_id=?').' ORDER BY c.id',
            $objectId===null?[$day]:[$day,$objectId]);
        $groups=[];
        foreach ($rows as $row) $groups[(int)$row['object_id']][]=$row;
        return $groups;
    }

    public static function contractLabel(array $row): string
    {
        return $row['name'].', №'.$row['number'];
    }

    public static function exceptions(array $contract): array
    {
        return Importer::decodeExact($contract['source_json'] ?? '{}')[ContractOccupancy::EXCEPTIONS] ?? [];
    }

    public static function canEdit(array $contract): bool
    {
        $user=Auth::user();
        return $contract['status']==='active' && in_array($user['role'] ?? '',['admin','accountant'],true)
            && Auth::can('finance.write') && PagePolicy::level('rental.contracts-active',$user)>=2;
    }

    private static function condition(PDO $db,int $objectId,int $except,string $start,string $end,array $input,array $previous=[]): array
    {
        $reason=DirectoryDetails::text($input,'reason',500);
        $until=Service::date((string)($input['until'] ?? ''));
        if ($reason==='' || $until!==$end) {
            throw new RuntimeException('Укажите согласованное основание и дату окончания из условия этого объекта.',422);
        }
        if (($previous['reason'] ?? '')===$reason && ($previous['until'] ?? '')===$until) return $previous;
        $object=Service::get($db,'rental_objects',$objectId);
        $parent=$object['cadastre_id']?Service::get($db,'cadastre',(int)$object['cadastre_id']):[];
        if (!$object['active'] || !$parent || !DirectoryScope::signals($object,$parent)) {
            throw new RuntimeException('Объект недоступен для аренды.',422);
        }
        $q=$db->prepare('SELECT c.id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id
            WHERE co.object_id=? AND c.id<>? AND '.ContractOccupancy::contractPredicate('co')
            ." AND (c.status='reconciling' OR c.status='active') AND c.return_confirmed IS NULL
            AND ".Occupancy::boundary('start_date').'<=? AND '.Occupancy::boundary('end_date').'>=? ORDER BY c.id LIMIT 2 FOR UPDATE');
        $q->execute([$objectId,$except,$end,$start]);
        $occupiers=$q->fetchAll(PDO::FETCH_COLUMN);
        if (!$previous && count($occupiers)!==1) {
            throw new RuntimeException('Для исключения нужен один действующий физический договор по этому объекту.',409);
        }
        return ['reason'=>$reason,'until'=>$until,'occupier_id'=>(int)($previous['occupier_id'] ?? $occupiers[0])];
    }

    public static function draftRows(array $input): array
    {
        $rows=[];
        foreach ((array)($input['billing_exception'] ?? []) as $id=>$entry) {
            if (is_array($entry) && ($entry['enabled'] ?? '')==='1') {
                $rows[(int)$id]=array_filter($entry,'is_string');
            }
        }
        return $rows;
    }

    public static function prepare(PDO $db,array $input,?array $old,array $ids,string $start,string $end,array $actor): array
    {
        $previous=self::exceptions($old ?? []);
        $entries=isset($input['billing_exception_present'])?self::draftRows($input):array_intersect_key($previous,array_flip($ids));
        $next=[];
        foreach ($entries as $oid=>$entry) {
            if (!in_array($oid,$ids,true)) throw new RuntimeException('Исключение относится только к выбранной строке.',422);
            $prior=$previous[$oid] ?? [];
            $value=self::condition($db,$oid,(int)($old['id'] ?? 0),$start,$end,$entry,$prior);
            $next[$oid]=$value===$prior?$prior:$value+['actor_id'=>(int)$actor['id'],'version'=>(int)($old['version'] ?? 0)+1];
        }
        return $next;
    }

    public static function store(PDO $db,int $id,array $rows): void
    {
        $path='$.'.ContractOccupancy::EXCEPTIONS;
        if ($rows) $db->prepare('UPDATE contracts SET source_json=JSON_SET(source_json,?,CAST(? AS JSON)) WHERE id=?')
            ->execute([$path,Importer::canonicalJson((object)$rows),$id]);
        else $db->prepare('UPDATE contracts SET source_json=JSON_REMOVE(source_json,?) WHERE id=?')->execute([$path,$id]);
    }

    public static function term(PDO $db,int $id,int $oid,bool $lock=false): array
    {
        $q=$db->prepare('SELECT t.start_date,t.end_date FROM contract_objects co JOIN contract_terms t
            ON t.contract_id=co.contract_id AND t.object_id=co.object_id
            WHERE co.contract_id=? AND co.object_id=? AND (t.effective_date IS NULL OR t.effective_date<=CURRENT_DATE)
            ORDER BY t.effective_date DESC,t.id DESC LIMIT 1'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$oid]);
        return $q->fetch() ?: throw new RuntimeException('У объекта нет действующего условия в этом договоре.',409);
    }

    public static function saveActive(PDO $db,array $actor,array $input): array
    {
        $id=(int)($input['id'] ?? 0);
        $contract=Service::get($db,'contracts',$id);
        if (!self::canEdit($contract)) throw new RuntimeException('Действие доступно бухгалтеру действующего договора.',403);
        Access::contract($id,2);
        Service::version($contract,$input);
        $oid=(int)($input['object_id'] ?? 0);
        $term=self::term($db,$id,$oid,true);
        $current=self::exceptions($contract);
        $previous=$current[$oid] ?? [];
        if (($input['enabled'] ?? '')!=='1') {
            if (!$previous) return ['id'=>$id,'message'=>'Исключение не установлено.'];
            Service::available($db,[$oid],max(date('Y-m-d'),$term['start_date']),max(date('Y-m-d'),$term['end_date']),$id);
            throw new RuntimeException('Для перехода к физической аренде оформите отдельное подтверждённое основание передачи.',409);
        }
        $q=$db->prepare("SELECT 1 FROM rental_documents d JOIN document_terms t ON t.document_id=d.id
            WHERE d.contract_id=? AND t.object_id=? AND d.applied=0 AND d.status NOT IN ('cancelled','closed','ended') LIMIT 1 FOR UPDATE");
        $q->execute([$id,$oid]);
        if ($q->fetchColumn()) throw new RuntimeException('По объекту уже оформляется ДС. Сначала сверяйте его условия.',409);
        $q=$db->prepare('SELECT 1 FROM contract_terms WHERE contract_id=? AND object_id=? AND effective_date>CURRENT_DATE
            AND end_date>? LIMIT 1 FOR UPDATE');
        $q->execute([$id,$oid,$term['end_date']]);
        if ($q->fetchColumn()) throw new RuntimeException('По объекту сохранено следующее условие с более поздним сроком. Сначала сверяйте его основание.',409);
        $q=$db->prepare('SELECT 1 FROM rental_object_holds h WHERE h.contract_id=? AND h.object_id=? AND h.return_confirmed=0
            AND '.ContractOccupancy::physicalPredicate('h').' LIMIT 1 FOR UPDATE');
        $q->execute([$id,$oid]);
        if ($q->fetchColumn()) throw new RuntimeException('По объекту ещё ожидается физический возврат. Сначала сверяйте его основание.',409);
        if (!$previous) foreach (PremisesActs::history($db,$id) as $act) {
            if ((int)$act['object_id']===$oid) {
                throw new RuntimeException('Есть зафиксированный физический акт по объекту. Нужна сверка исключения.',409);
            }
        }
        $value=self::condition($db,$oid,$id,$term['start_date'],$term['end_date'],$input,$previous);
        if ($value===$previous) return ['id'=>$id,'message'=>'Исключение уже сохранено.'];
        $current[$oid]=$value+['actor_id'=>(int)$actor['id'],'version'=>(int)$contract['version']+1];
        ContractHistory::remember($db,$contract,$actor,(string)$input['command_key']);
        self::store($db,$id,$current);
        $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$id]);
        Service::event($db,$actor,self::SAVE,'Зафиксировано исключение по объекту.', $id,
            ['object_id'=>$oid,'before'=>$previous,'exception'=>$current[$oid]]);
        return ['id'=>$id,'message'=>'Исключение по объекту сохранено.'];
    }

    public static function checkDocument(array $contract,array $term): void
    {
        $exception=self::exceptions($contract)[(int)$term['object_id']] ?? null;
        if ($exception && $term['end_date']>$exception['until']) {
            throw new RuntimeException('Срок расчётной строки выходит за согласованное исключение по объекту.',409);
        }
    }

    public static function noPhysicalReturn(PDO $db,array $contract): bool
    {
        if (!self::exceptions($contract) || !empty($contract['return_confirmed'])) return false;
        $relations=Occupancy::relations($db,(int)$contract['id']);
        if (!$relations || array_filter($relations,static fn(array $row): bool=>empty($row['billing_exception']))) return false;
        $q=$db->prepare('SELECT 1 FROM rental_object_holds h WHERE h.contract_id=? AND h.return_confirmed=0 AND '
            .ContractOccupancy::physicalPredicate('h').' LIMIT 1');
        $q->execute([$contract['id']]);
        return !$q->fetchColumn();
    }
}
