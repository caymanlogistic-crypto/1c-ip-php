<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{DB,View};
use Modules\Rental\{Availability,DirectoryScope,ObjectDirectory,ObjectFacts,ObjectReadModel,OccupiedBilling};

$p=DB::pdo();
if (!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void {if (!$ok) throw new RuntimeException($why);};
$session=$_SESSION ?? [];$get=$_GET;$server=$_SERVER;
$selects=static fn()=>(int)$p->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
$comparisons=0;$today=date('Y-m-d');$managerProjection=[];
$p->beginTransaction();
try {
    $actors=[];
    foreach ($p->query('SELECT id,role,auth_version FROM users WHERE active=1 ORDER BY id') as $actor) $actors[$actor['role']]??=$actor;
    foreach (['manager','lawyer','accountant','director','admin','viewer'] as $role) {
        $actor=$actors[$role];$_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
        foreach ([$today,date('Y-m-d',strtotime('+14 days')),date('Y-m-d',strtotime('+1 month'))] as $day) {
            $before=$selects();$read=new ObjectReadModel($day);
            $check($selects()-$before<=15,'Snapshot query count must be bounded, independently of object count');
            foreach ($read->objects as $object) {
                $parent=ObjectReadModel::parent($object);
                $availability=$read->availability[$object['id']] ?? null;
                $expected=ObjectFacts::project($object,$parent,$availability,$day);
                $before=$selects();$actual=ObjectFacts::project($object,$parent,$availability,$day,$read);
                $check($selects()===$before,'Projection must not query inside the object loop');
                $check($actual===$expected,'Single-record/batch mismatch: '.$role.' object '.$object['id'].' on '.$day);
                $key=$day.':'.$object['id'];
                if ($role==='manager') $managerProjection[$key]=$actual;
                else $check($actual===$managerProjection[$key],'Every role must see the manager object projection: '.$role.' '.$key);
                $comparisons++;
            }
        }
    }
    // Two independent reads in the same process must observe committed-in-command changes.
    $actor=$actors['admin'];$_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $parent=null;foreach($p->query('SELECT * FROM cadastre') as $row) if(DirectoryScope::realCadastre($row)&&DirectoryScope::cadastreAvailable($row)) {$parent=$row['id'];break;}
    $id=(int)$p->query('SELECT MAX(id) FROM rental_objects')->fetchColumn()+1;
    $p->prepare("INSERT INTO rental_objects(id,name,type,area,floor,cadastre_id,active,free_since,source_json) VALUES(?,'Object read freshness','Офис',1,'2',?,1,?,'{}')")->execute([$id,$parent,$today]);
    $beforeRead=new ObjectReadModel($today);$check($beforeRead->availability[$id]['available'],'New fixture must be free');
    $booking=(int)$p->query('SELECT MAX(id) FROM reservations')->fetchColumn()+1;
    $p->prepare("INSERT INTO reservations(id,object_id,start_date,until_date,status,source_json) VALUES(?,?,?,?,'active','{}')")->execute([$booking,$id,$today,$today]);
    $afterRead=new ObjectReadModel($today);
    $check(!$afterRead->availability[$id]['available'],'New read must see the new reservation; no cross-request cache');
    $check($beforeRead->availability[$id]['available'],'A snapshot must not mutate under the renderer');
    $p->prepare("INSERT INTO reservations(id,object_id,start_date,until_date,status,source_json) VALUES(?,?,?,?,'active','{}')")->execute([$booking+1,$id,$today,$today]);
    $multiple=new ObjectReadModel($today);
    $check(count($multiple->bookings($id))===2,'Batch read must retain all competing customer bookings');
    foreach ($multiple->objects as $object) if ((int)$object['id']===$id) {
        $parent=ObjectReadModel::parent($object);$availability=$multiple->availability[$id];
        $check(ObjectFacts::project($object,$parent,$availability,$today,$multiple)===ObjectFacts::project($object,$parent,$availability,$today),'Multiple booking projection must match the authoritative single-record query');
    }
    // The partial response uses the exact workspace renderer and keeps page authorization.
    $_GET=['flag'=>'work'];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_X_RENTAL_OBJECTS_FRAGMENT']='1';
    $before=$selects();$fragment=ObjectDirectory::page();
    $check($selects()-$before<80,'Tab refresh must not recompute the complete navigation');
    $check(str_starts_with($fragment,'<main id="workspace">')&&!str_contains($fragment,'id="navigation"'),'Fragment must contain only workspace');
    unset($_SERVER['HTTP_X_RENTAL_OBJECTS_FRAGMENT']);$full=ObjectDirectory::page();
    $body=static function(string $html):string {$d=new DOMDocument();$d->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$x=new DOMXPath($d);$node=$x->query('//*[@id="workspace"]')->item(0);$out='';foreach($node->childNodes as $child)$out.=$d->saveHTML($child);return $out;};
    // Auto-generated command form IDs do not appear in this read-only tab.
    $check($body($fragment)===$body($full),'Full and fragment workspaces must match');

    $party=(int)$p->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $p->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Object registry training party','{}')")->execute([$party]);
    $lease=(int)$p->query('SELECT COALESCE(MAX(id),0)+1 FROM contracts')->fetchColumn();
    $billing=$lease+1;
    foreach ([$lease=>'TEST-PHYSICAL-'.$lease,$billing=>'TEST-BILLING-'.$billing] as $contract=>$number) {
        $p->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,status,source_json)
            VALUES(?,?,?,?,?,'active','{}')")->execute([$contract,$number,$party,$today,$today]);
        $p->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$contract,$id]);
    }
    OccupiedBilling::store($p,$billing,[$id=>['reason'=>'Учебное основание','until'=>$today,'occupier_id'=>$lease]]);
    $_GET=['q'=>'TEST-BILLING-'.$billing,'floor'=>'2'];
    $_SERVER['HTTP_X_RENTAL_OBJECTS_FRAGMENT']='1';
    $result=ObjectDirectory::page();
    $check(substr_count($result,'data-rental-url="/rental/object?id='.$id.'"')===1,'Billing search must return the object once');
    $check(str_contains($result,'№TEST-PHYSICAL-'.$lease)
        && str_contains($result,OccupiedBilling::OBJECT_LABEL.': Object registry training party, №TEST-BILLING-'.$billing),
        'Search result must keep physical and billing links separate');

    $_SESSION=[];$_SERVER['HTTP_X_RENTAL_OBJECTS_FRAGMENT']='1';$denied=false;
    try {ObjectDirectory::page();} catch (RuntimeException $e) {$denied=in_array($e->getCode(),[401,403],true);}
    $check($denied,'Fragment must not bypass the page access check');
} finally {$p->rollBack();$_SESSION=$session;$_GET=$get;$_SERVER=$server;}
echo "PASS: $comparisons role/date/object comparisons; bounded queries, fresh reads, fragment parity/access; fixtures rolled back\n";
