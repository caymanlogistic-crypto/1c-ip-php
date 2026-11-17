<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{DB,Menu};
use Modules\Rental\{Availability,DirectoryScope,DirectoryUi,Indicators,ObjectDirectory,ObjectFacts};

// Run only against an isolated task copy. All fixtures roll back, including on failure.
$p=DB::pdo();
if (!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
$today=date('Y-m-d');
foreach (['manager','director','lawyer','accountant','admin','viewer'] as $role) {
    foreach ([13,14,29,30] as $days) {
        $expected=match($role) {'manager','admin'=>$days>=30?'red':($days>=14?'yellow':''),'director'=>$days>=30?'red':'',default=>''};
        $check(ObjectFacts::idleSeverity($days,$role)===$expected,'Idle boundary '.$role.':'.$days);
    }
    $check(ObjectFacts::idleSeverity(null,$role)==='','Unknown date must not be a task');
}
$check(ObjectFacts::idleDays(['free_since'=>null],true,$today)===null,'Missing date');
$check(ObjectFacts::idleDays(['free_since'=>date('Y-m-d',strtotime('+1 day'))],true,$today)===null,'Future date');
$check(ObjectFacts::idleDays(['free_since'=>'2000-01-01'],false,$today)===null,'Occupied object');
$check(str_contains(Menu::badges([0,0,1,1],true,'objects'),'Недавно сданы: 1'),'Menu green label');
$check(str_contains(DirectoryUi::counts([0,0,1,1],'objects'),'Брони: 1'),'Tab blue label');

$session=$_SESSION ?? [];
$get=$_GET;
$p->beginTransaction();
try {
    $admin=$p->query("SELECT id,auth_version FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetch();
    $check((bool)$admin,'Fixture needs an existing administrator');
    $_SESSION=['user_id'=>$admin['id'],'auth_version'=>$admin['auth_version']];
    $_GET=['q'=>'old search before reset','floor'=>'2','reset'=>'1'];
    $html=ObjectDirectory::page();
    $dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);
    $xpath=new DOMXPath($dom);
    $query=$xpath->query('//form[contains(@class,"registry-ui-tabs")]/input[@name="q"]');
    $check($query->length===1 && $query->item(0)->getAttribute('value')==='','Reset must not carry the old search into tab navigation');
    $_GET=$get;
    $parent=null;
    foreach ($p->query('SELECT * FROM cadastre') as $row) if (DirectoryScope::realCadastre($row)&&DirectoryScope::cadastreAvailable($row)) {$parent=$row;break;}
    $check($parent!==null,'Fixture needs a rentable parent');
    $id=(int)$p->query('SELECT MAX(id) FROM rental_objects')->fetchColumn()+1;
    $contract=(int)$p->query('SELECT MAX(id) FROM contracts')->fetchColumn()+1;
    $party=(int)$p->query('SELECT MIN(id) FROM parties')->fetchColumn();
    $insert=$p->prepare('INSERT INTO rental_objects(id,name,type,area,floor,cadastre_id,active,free_since,source_json) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach (['free','draft','future_contract','expired_contract','booking','expired_booking','inactive','unrentable'] as $i=>$kind) {
        $insert->execute([$id+$i,'Signal regression '.$kind,'Офис','1','2',$parent['id'],$kind==='inactive'?0:1,date('Y-m-d',strtotime('-30 days')),$kind==='unrentable'?' {"rentable":false}':'{}']);
    }
    $insert=$p->prepare('INSERT INTO contracts(id,number,party_id,start_date,end_date,status,source_json) VALUES(?,?,?,?,?,?,?)');
    foreach ([['draft',$today,date('Y-m-d',strtotime('+1 month'))],['active',date('Y-m-d',strtotime('+1 day')),date('Y-m-d',strtotime('+1 month'))],['active',date('Y-m-d',strtotime('-2 months')),date('Y-m-d',strtotime('-1 day'))]] as $i=>[$status,$start,$end]) {
        $insert->execute([$contract+$i,'Signal regression '.$i,$party,$start,$end,$status,'{}']);
        $p->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$contract+$i,$id+1+$i]);
    }
    $reservation=(int)$p->query('SELECT MAX(id) FROM reservations')->fetchColumn()+1;
    foreach ([$today,date('Y-m-d',strtotime('-1 day'))] as $i=>$until) $p->prepare("INSERT INTO reservations(id,object_id,start_date,until_date,status,source_json) VALUES(?,?,?,?, 'active','{}')")->execute([$reservation+$i,$id+4+$i,$today,$until]);
    $signals=ObjectFacts::idleSignals($today);$manager=[];$director=[];
    foreach ($signals as $signal) if ($signal['id']>=$id&&$signal['id']<$id+8) {
        if ($signal['role']==='manager') $manager[]=$signal['id'];
        if ($signal['role']==='director') $director[]=$signal['id'];
    }
    $expected=[$id,$id+1,$id+2,$id+3,$id+5];
    sort($manager);sort($director);
    $check($manager===$expected&&$director===$expected,'Idle tasks must include draft/future/expired contracts and exclude active booking/inactive/unrentable');
    $tasks=array_values(array_filter(Indicators::tasks($today,null,['objects']),fn($task)=>$task['entity']==='object'&&$task['role']==='manager'&&$task['id']>=$id&&$task['id']<$id+8));
    $check(array_column($tasks,'id')===$expected,'Actual task registry must use directory semantics');
    $available=[];foreach(Availability::items($today,$today) as $item) $available[$item['id']]=$item['available'];
    foreach (range(0,7) as $i) $check(($available[$id+$i]??false)===in_array($id+$i,$expected,true),'Directory availability differs from task '.$i);
    $items=[];foreach(Availability::items($today,$today) as $item) $items[$item['id']]=$item;
    foreach ($p->query('SELECT * FROM rental_objects WHERE id>='.$id.' AND id<'.($id+8)) as $object) {
        $facts=ObjectFacts::project($object,$parent,$items[$object['id']] ?? null,$today);
        $severity=in_array((int)$object['id'],$expected,true)?'red':((int)$object['id']===$id+4?'blue':'');
        $check($facts['severity']===$severity,'Actual row projection differs from task '.$object['id']);
    }
    $check(count(ObjectFacts::idleSignals($today))===count($signals),'Repeated projection duplicates tasks');
} finally {$p->rollBack();$_SESSION=$session;$_GET=$get;}
echo "PASS: role/date boundaries, labels, directory/task availability, repeat projection; fixtures rolled back\n";
