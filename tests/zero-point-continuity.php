<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{BusinessIds,DB};
use Modules\Rental\{ObjectFacts,ObjectReadModel,Service,ZeroPoint,ZeroPointContinuity,ZeroPointRevision};
$db=DB::pdo();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_zero_continuity' || !in_array('--apply-isolated',$argv,true))throw new RuntimeException('Independent continuity database required');
$evidence=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$login=static function(string $role)use($db){$q=$db->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];};
$protected=['users','roles','permissions','role_permissions','role_profiles','role_page_grants','integration_connections','integration_native_intents',
    'messaging_attempts','messaging_receipts','rental_delivery_intents','rental_files','organization','organization_bank_accounts','rental_templates',
    'invoices','invoice_objects','invoice_coverage','invoice_part_scope','payments','payment_allocations'];
$hash=static function(bool $versions=true)use($db,$protected){$out=[];foreach($protected as $t){$rows=$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);if(!$versions && in_array($t,['invoices','payments'],true))foreach($rows as &$r)unset($r['version']);unset($r);$h=array_map(ZeroPoint::digest(...),$rows);sort($h);$out[$t]=ZeroPoint::digest($h);}return $out;};
$before=$hash();$beforeRestore=$hash(false);$old=ZeroPoint::load();$dir=ZeroPoint::directory();$oldPin=json_decode(file_get_contents($dir.'/installed.json'),true);
$login('manager');try{ZeroPointContinuity::prepare($evidence);throw new LogicException('Manager accepted');}catch(RuntimeException $e){$check($e->getCode()===403,'Manager denied');}
$login('admin');$plan=ZeroPointContinuity::prepare($evidence);$check(count($plan['document']['data']['tables']['contracts'])===58,'Exactly three scheduled contracts added');
$rows=static fn($data)=>array_column($data['tables']['contracts'],null,'number');
$oct=$rows(ZeroPointContinuity::project($plan['document']['data'],'2026-10-31'));
foreach(['124','125','126'] as $n)$check($oct[$n]['status']==='active','Old contract active at cutoff');
foreach(['156','157','158'] as $n)$check($oct[$n]['status']==='approved' && (int)$oct[$n]['signed']===0,'Future contract not active or falsely signed at cutoff');
$check(bccomp($oct['24']['amount'],'22050',2)===0,'October rate preserved');
$nov=$rows(ZeroPointContinuity::project($plan['document']['data'],'2026-11-06'));
$check($nov['156']['status']==='active' && $nov['157']['status']==='active' && $nov['158']['status']==='approved','Dated starts respected');
$check(bccomp($nov['24']['amount'],'46200',2)===0,'November fifth new object without premature indexation');
$later=$rows(ZeroPointContinuity::project($plan['document']['data'],'2026-11-18'));
$check(bccomp($later['24']['amount'],'48150',2)===0,'November eleventh dated rate');
$bad=$evidence;$bad['imports'][0]['bindings'][0]['source_id']='00000000-0000-0000-0000-000000000000';
try{ZeroPointContinuity::prepare($bad);throw new LogicException('Unproven GUID accepted');}catch(RuntimeException $e){$check($e->getCode()===409,'Foreign GUID refused');}
$badPlan=$plan;$badPlan['document']['data']['tables']['payments'][0]['amount']='1.00';$badPlan['next_sha256']=ZeroPoint::digest($badPlan['document']);
try{ZeroPointContinuity::apply($badPlan);throw new LogicException('Tampered money accepted');}catch(RuntimeException $e){$check(!is_file($dir.'/revision-pending.json'),'Tamper refused before publication');}
$db->beginTransaction();try{$id=BusinessIds::next($db,'reservations');$party=(int)$db->query('SELECT MIN(id) FROM parties')->fetchColumn();
    $db->prepare("INSERT INTO reservations(id,object_id,party_id,start_date,end_date,until_date,status,source_json) VALUES(?,1075,?,'2026-11-01','2027-09-30','2027-09-30','active','{}')")->execute([$id,$party]);
    try{Service::available($db,[1075],'2026-11-01','2027-09-30',1036);throw new LogicException('Conflicting booking accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'забронирован'),'Conflicting booking refused');}
}finally{$db->rollBack();}
ZeroPoint::write($dir.'/'.$plan['next_sha256'].'.json',$plan['document']);$newPin=$oldPin;$newPin['sha256']=$plan['next_sha256'];
ZeroPoint::write($dir.'/revision-pending.json',['action'=>'zero-point.continuity','command_key'=>str_repeat('a',32),'old'=>$oldPin,'next'=>$newPin]);
try{ZeroPoint::load();throw new LogicException('Pending restore accepted');}catch(RuntimeException $e){$check($e->getCode()===409,'Pending blocks restore');}
$recovery=ZeroPointRevision::recover();$check(!$recovery['committed'] && $recovery['sha256']===$old['sha256'],'Uncommitted correction keeps prior point');
$result=ZeroPointContinuity::apply($plan);$repeat=ZeroPointContinuity::apply($plan);
$check(ZeroPoint::json($result)===ZeroPoint::json($repeat),'Same command does not duplicate');$check($before===$hash(),'System, external receipts and money byte-identical');
$check(hash_file('sha256',$dir.'/'.$old['sha256'].'.json')===$old['sha256'],'Previous point retained');
$live=ZeroPoint::capture($db);$actual=$rows($live);
foreach(['124','125','126'] as $n)$check($actual[$n]['status']==='ended','Predecessor archived after re-contracting');
foreach(['156','157','158'] as $n)$check($actual[$n]['status']==='active' && (int)$actual[$n]['signed']===0,'Successor active without forged signature');
$occupancy=static function()use($check):void {$read=new ObjectReadModel('2026-11-18');$objects=array_column($read->objects,null,'id');foreach([1009,1015,1075,1077,1089,1099] as $id){$o=$objects[$id];$f=ObjectFacts::project($o,ObjectReadModel::parent($o),$read->availability[$id],'2026-11-18',$read);$check(!$f['free'],'Occupied source object '.$id);}foreach([1105=>'2026-10-09',1084=>'2026-06-30'] as $id=>$date){$o=$objects[$id];$f=ObjectFacts::project($o,ObjectReadModel::parent($o),$read->availability[$id],'2026-11-18',$read);$check($f['free'] && $o['free_since']===$date && $f['previous']!==null,'Confirmed date and last occupant '.$id);}foreach([1097,1101] as $id)$check($objects[$id]['free_since']===null,'Unproven date remains unknown');};
$occupancy();
ZeroPoint::write($dir.'/revision-pending.json',['action'=>'zero-point.continuity','command_key'=>$plan['command_key'],'old'=>$oldPin,'next'=>$newPin]);
$recovery=ZeroPointRevision::recover();$check($recovery['committed'] && $recovery['sha256']===$plan['next_sha256'],'Committed recovery selects new point');
$restore=ZeroPoint::preview();ZeroPoint::restore($restore+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$check(ZeroPoint::load()['sha256']===$plan['next_sha256'],'Corrected point still selected after restore');$occupancy();
$check($beforeRestore===$hash(false),'Restore preserves system and financial facts, allowing version increments');ZeroPoint::assertMoney($db);
echo json_encode(['passed'=>$checks,'contracts'=>58,'successors'=>3,'conditions'=>2,'dates'=>2,'final_state'=>'revised point restored with dated transitions','external_calls'=>0],JSON_PRETTY_PRINT).PHP_EOL;
