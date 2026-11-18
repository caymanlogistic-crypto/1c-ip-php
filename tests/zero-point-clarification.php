<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{ObjectFacts,ObjectReadModel,Service,ZeroPoint,ZeroPointClarification,ZeroPointRevision};
$p=DB::pdo();if($p->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_zero_clarify' || !in_array('--apply-isolated',$argv,true))throw new RuntimeException('Own isolated clarification database required');
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$expect=static function(callable $fn,int $code)use($check){try{$fn();}catch(RuntimeException $e){$check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected denial');};
$login=static function(string $role)use($p){$q=$p->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];};
$protected=['users','roles','role_permissions','role_page_grants','organization','integration_connections','integration_native_intents','integration_native_receipts','messaging_attempts','messaging_receipts','rental_delivery_intents','rental_files','invoices','invoice_objects','invoice_coverage','payments','payment_allocations'];
$hash=static function(bool $versions=true)use($p,$protected){$out=[];foreach($protected as $t){$rows=$p->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);if(!$versions)foreach($rows as &$r)unset($r['version']);unset($r);$h=array_map(ZeroPoint::digest(...),$rows);sort($h);$out[$t]=ZeroPoint::digest($h);}return $out;};
$before=$hash();$moneyBeforeRestore=$hash(false);$old=ZeroPoint::load();$dir=ZeroPoint::directory();$pin=json_decode(file_get_contents($dir.'/installed.json'),true);
$login('manager');$expect(static fn()=>ZeroPointClarification::prepare(ZeroPointClarification::evidence()),403);
$login('admin');$plan=ZeroPointClarification::prepare(ZeroPointClarification::evidence());
$bad=ZeroPointClarification::evidence();$bad['expiry_accepted_as_release']['date']='2026-02-27';$expect(static fn()=>ZeroPointClarification::prepare($bad),409);
$tamper=$plan;$tamper['document']['data']['tables']['payments'][0]['amount']='1.00';$tamper['next_sha256']=ZeroPoint::digest($tamper['document']);$expect(static fn()=>ZeroPointClarification::apply($tamper),409);
$p->beginTransaction();try{$p->exec('UPDATE rental_objects SET version=version+1 WHERE id=1000');$expect(static fn()=>ZeroPointClarification::apply($plan),409);}finally{if($p->inTransaction())$p->rollBack();}
// The public command cannot be used without the operator's prepared revision.
$expect(static fn()=>Service::command('zero-point.clarify',$plan),409);
ZeroPoint::write($dir.'/'.$plan['next_sha256'].'.json',$plan['document']);
ZeroPoint::write($dir.'/revision-pending.json',['action'=>'zero-point.clarify','command_key'=>str_repeat('a',32),'old'=>$pin,'next'=>$pin+['previous_sha256'=>$pin['sha256']]]);
$expect(ZeroPoint::load(...),409);$recovery=ZeroPointRevision::recover();$check(!$recovery['committed'] && $recovery['sha256']===$old['sha256'],'Uncommitted revision recovered to previous point');
$result=ZeroPointClarification::apply($plan);$check(ZeroPoint::json($result)===ZeroPoint::json(ZeroPointClarification::apply($plan)),'Replay returns same receipt');
$check($before===$hash(),'Money, users, evidence and outgoing receipts unchanged');
$assertObjects=static function()use($p,$check){$read=new ObjectReadModel('2026-11-18');$objects=array_column($read->objects,null,'id');
    $a=$objects[1097];$f=ObjectFacts::project($a,ObjectReadModel::parent($a),$read->availability[1097],'2026-11-18',$read);
    $check($a['free_since']==='2026-02-26' && $f['days']===265 && $f['previous']==='ФЛ Алехин','Confirmed expiration gives265 days and original tenant');
    $b=$objects[1101];$f=ObjectFacts::project($b,ObjectReadModel::parent($b),$read->availability[1101],'2026-11-18',$read);
    $check($b['free_since']===null && $f['days']===null && $f['previous']===null && $f['idleUnknown']==='Нет данных о физической аренде','Legal address does not become physical tenant or fake date');
    $check(ObjectFacts::reviewedUnknownHistory($b,true,null,'Новый реальный арендатор')===null,'Future physical history takes priority');
    $check(ObjectFacts::reviewedUnknownHistory($b,true,0,null)===null,'A known new date takes priority');
    $check(ObjectFacts::reviewedUnknownHistory($b,false,null,null)===null,'Occupied object is not labeled as history missing');
    foreach([1097,1101] as $id){$q=$p->prepare("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_data' AND source_key=?");$q->execute(['owner_clarification:'.$id]);$fact=json_decode($q->fetchColumn(),true);$check($fact['owner_confirmed']===true,'Owner decision persisted');}
};
$assertObjects();$check(ZeroPoint::load()['sha256']===$plan['next_sha256'],'New point selected');$check(hash_file('sha256',$dir.'/'.$old['sha256'].'.json')===$old['sha256'],'Previous immutable point retained');
// An actual restore is tested only on this isolated schema, never the shared site.
$restore=ZeroPoint::preview();ZeroPoint::restore($restore+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$assertObjects();$check($moneyBeforeRestore===$hash(false),'Restore preserves money and protected tables allowing versions');ZeroPoint::assertMoney($p);
echo json_encode(['passed'=>$checks,'restored_new_point'=>true,'external_calls'=>0,'sha256'=>$result['sha256']]).PHP_EOL;
