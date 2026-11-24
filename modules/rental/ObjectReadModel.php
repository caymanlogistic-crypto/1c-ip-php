<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{ContractOccupancy,DB};

/** One explicitly owned read snapshot, never persisted across requests or commands. */
final class ObjectReadModel
{
    public readonly array $objects;
    public readonly array $availability;
    public readonly array $contractPackages;
    private array $leases=[],$bookings=[],$previous=[],$historical=[],$events=[],$applied=[],$documents=[],$work=[],$packages=[];

    public function __construct(public readonly string $today)
    {
        Service::date($today);
        $this->objects=Controller::query('SELECT o.*,c.number cadastral,c.area parent_area,c.type parent_type,c.rentable parent_rentable,c.source_json parent_source '
            .'FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id ORDER BY o.id');
        $availability=[];
        foreach (Availability::items($today,$today) as $item) $availability[$item['id']]=$item;
        $this->availability=$availability;
        $this->work=self::pendingWork();
        $db=DB::pdo();
        $this->contractPackages=RecentContractPackages::byContract($db,$today);
        $this->packages=RecentContractPackages::byObject($db,$today,packages:$this->contractPackages);
        $this->leases=Occupancy::leasesOn($today);
        foreach (Controller::query('SELECT r.object_id,r.id,r.until_date,r.comment,COALESCE(NULLIF(r.client_name,\'\'),p.name) client_name '
            .'FROM reservations r LEFT JOIN parties p ON p.id=r.party_id WHERE '.Occupancy::reservationPredicate('r')
            ." AND r.status='active' AND r.until_date>=? ORDER BY r.id DESC",[$today]) as $row) {
            $id=$row['object_id'];unset($row['object_id']);$this->bookings[$id][]=$row;
        }
        foreach (Controller::query('SELECT t.object_id,p.name,t.end_date FROM contract_terms t JOIN contracts c ON c.id=t.contract_id JOIN parties p ON p.id=c.party_id '
            .'LEFT JOIN contract_objects co ON co.contract_id=c.id AND co.object_id=t.object_id '
            .'WHERE '.ContractOccupancy::contractPredicate('co','t')." AND c.status IN ('active','reconciling','closed','ended') AND t.end_date<=? ORDER BY t.end_date DESC,c.id DESC",[$today]) as $row) $this->previous[$row['object_id']]??=$row;
        foreach (Controller::query("SELECT source_key,fact_json FROM rental_facts WHERE namespace='zero_point_data' AND source_key LIKE 'last_occupant:%'") as $row)
            $this->historical[(int)substr($row['source_key'],strlen('last_occupant:'))]=json_decode($row['fact_json'],true);
        foreach (Controller::query("SELECT contract_id,DATE(MIN(created_at)) at FROM rental_events WHERE id>? AND action IN ('contract.sign','contract.activated') GROUP BY contract_id",[ZeroPoint::eventFloor(\App\DB::pdo())]) as $row) $this->events[$row['contract_id']]=$row['at'];
        foreach (Controller::query("SELECT d.contract_id,DATE(a.created_at) at,a.after_json FROM audit_log a JOIN rental_documents d ON d.id=a.entity_id WHERE a.action='document.objects-applied' AND a.entity_type='document' AND d.applied=1 AND d.status='signed'") as $row) {
            $id=$row['contract_id'];unset($row['contract_id']);$this->applied[$id][]=$row;
        }
        foreach (Controller::query("SELECT d.contract_id,t.object_id,d.number,d.document_date FROM rental_documents d JOIN document_terms t ON t.document_id=d.id WHERE d.status='signed' AND d.applied=1 AND d.effective_date<=? AND (d.document_date IS NULL OR d.document_date<=?) AND t.start_date<=? AND t.end_date>=? ORDER BY d.effective_date DESC,d.id DESC",[$today,$today,$today,$today]) as $row) {
            $key=$row['contract_id'].':'.$row['object_id'];unset($row['contract_id'],$row['object_id']);$this->documents[$key]??=$row;
        }
    }

    public static function parent(array $object): array
    {
        return ['number'=>$object['cadastral'],'type'=>$object['parent_type'],'area'=>$object['parent_area'],'rentable'=>$object['parent_rentable'],'source_json'=>$object['parent_source']];
    }

    public static function pendingWork(?int $id=null): array
    {
        $rows=Controller::query("SELECT co.object_id,'contract' kind FROM contract_objects co JOIN contracts c ON c.id=co.contract_id "
            ."WHERE ".ContractOccupancy::contractPredicate('co')." AND c.status IN ('legal','accounting','returned','approved','sent') "
            ."AND ".ContractDraft::visibleSql('c')." AND (? IS NULL OR co.object_id=?) "
            ."UNION SELECT t.object_id,'renewal' FROM document_terms t JOIN rental_documents d ON d.id=t.document_id "
            ."WHERE ".ContractOccupancy::documentPredicate('t')." AND d.kind='renewal' AND d.applied=0 "
            ."AND d.status IN ('draft','legal','accounting','returned','approved','sent','signed') AND (? IS NULL OR t.object_id=?)",
            [$id,$id,$id,$id]);
        $work=[];
        foreach ($rows as $row) $work[$row['object_id']][$row['kind']]=true;
        return $work;
    }

    public function work(int $id): array {return $this->work[$id] ?? [];}
    public function package(int $id): ?array {return $this->packages[$id] ?? null;}
    public function lease(int $id): ?array {return $this->leases[$id] ?? null;}
    public function booking(int $id): ?array {return $this->bookings[$id][0] ?? null;}
    public function bookings(int $id): array {return $this->bookings[$id] ?? [];}
    public function previous(int $id): ?string {return ObjectFacts::previousTenant($this->previous[$id] ?? null,$this->historical[$id] ?? null,$this->today);}
    public function event(int $id): ?string {return $this->events[$id] ?? null;}
    public function applied(int $id): array {return $this->applied[$id] ?? [];}
    public function document(int $contract,int $object): ?array {return $this->documents[$contract.':'.$object] ?? null;}
}
