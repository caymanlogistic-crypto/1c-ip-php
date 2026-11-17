<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{BusinessIds,DB};
use Modules\Rental\{ObjectFacts,PartyOrigin,Service,ZeroPoint,ZeroPointController};

// Destructive restore scenarios are authorized only in this task's independent test database.
$db=DB::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_zero_point' || !in_array('--apply-isolated',$argv,true))
    throw new RuntimeException('Explicit isolated zero-point database required.');
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {if (!$ok) throw new RuntimeException($message);$checks++;};
$login=static function(string $role)use($db):void {
    $q=$db->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$user=$q->fetch();
    if (!$user) throw new RuntimeException('Missing test role');$_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
};
$expect=static function(callable $fn,int $code)use($check):void {
    try {$fn();} catch (Throwable $e) {$check((int)$e->getCode()===$code,'Unexpected error: '.$e->getMessage());return;}
    throw new RuntimeException('Expected denial '.$code);
};
$protected=['users','roles','permissions','role_permissions','role_profiles','role_page_grants','integration_connections',
    'integration_configuration_sources','messaging_attempts','messaging_receipts','rental_files','rental_templates','organization','organization_bank_accounts'];
$fingerprints=static function()use($db,$protected):array {
    $out=[];foreach ($protected as $table) {$rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$hashes=array_map(ZeroPoint::digest(...),$rows);sort($hashes);$out[$table]=ZeroPoint::digest($hashes);}return $out;
};
$login('manager');$expect(static fn()=>ZeroPoint::preview(),403);
$expect(static fn()=>ZeroPoint::restore([]),403);
$login('admin');$_SERVER['REQUEST_METHOD']='GET';$expect(static fn()=>ZeroPointController::preview(),405);
$_SERVER['REQUEST_METHOD']='POST';$_POST=['_csrf'=>'wrong'];$expect(static fn()=>ZeroPointController::preview(),403);
$beforeProtected=$fingerprints();
$baseline=ZeroPoint::load();
$check(count($baseline['data']['tables']['contracts'])===55,'Physical cutoff cohort');
$check(count($baseline['data']['tables']['invoices'])===115,'Invoice cohort');
$check(count($baseline['data']['tables']['payments'])===104,'Payment cohort');
$numbers=array_column($baseline['data']['tables']['contracts'],'number');
foreach (['32','148','56/10','64','161'] as $number) $check(!in_array($number,$numbers,true),'Excluded contract '.$number);
ZeroPoint::assertMoney($db);
$sourceParty=$db->query('SELECT * FROM parties ORDER BY id LIMIT 1')->fetch();$origin=PartyOrigin::read($sourceParty);
$check($origin['kind']==='onec' && $origin['guid']!==null && $origin['code']!==null,'Source identity is distinct from local PK');
$input=['name'=>'Контроль нулевой точки','inn'=>'7707083893','bank'=>'Контрольный банк',
    'account'=>'00000000000000000001','correspondent_account'=>'00000000000000000002','bik'=>'000000001',
    'command_key'=>bin2hex(random_bytes(16))];
$created=Service::command('party.save',$input);$id=(int)$created['id'];
$again=Service::command('party.save',$input);$check($again===$created,'Same-epoch command retry');
$party=$db->query('SELECT * FROM parties WHERE id='.$id)->fetch();$check(PartyOrigin::read($party)['kind']==='local','Locally created party');
$plan=ZeroPoint::preview();
$db->prepare('UPDATE parties SET version=version+1 WHERE id=?')->execute([$id]);
$expect(static fn()=>ZeroPoint::restore($plan+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']),409);
$check((bool)$db->query('SELECT 1 FROM parties WHERE id='.$id)->fetchColumn(),'Stale preview has no side effects');
$invoiceId=BusinessIds::next($db,'invoices',900000);
$contract=(int)$db->query('SELECT MIN(id) FROM contracts')->fetchColumn();
$db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,due,status,source_json) VALUES(?,?,?,'Аренда','2026-11',100,0,'2026-11-17','2026-11-20','active','{}')")
    ->execute([$invoiceId,'ZERO-POINT-CHECK-'.$invoiceId,$contract]);
$plan=ZeroPoint::preview();$key=bin2hex(random_bytes(16));$request=$plan+['command_key'=>$key,'confirmation'=>'ВОССТАНОВИТЬ'];
$priorVersion=(int)$db->query('SELECT version FROM parties WHERE id='.(int)$sourceParty['id'])->fetchColumn();
$result=ZeroPoint::restore($request);
$check(!$db->query('SELECT 1 FROM parties WHERE id='.$id)->fetchColumn(),'Created party removed from current dataset');
$check(!$db->query('SELECT 1 FROM invoices WHERE id='.$invoiceId)->fetchColumn(),'Created invoice removed from current dataset');
$check(BusinessIds::next($db,'parties',9000000)>$id,'Party id watermark');
$check(BusinessIds::next($db,'invoices',900000)>$invoiceId,'Invoice id watermark');
$check((int)$db->query('SELECT version FROM parties WHERE id='.(int)$sourceParty['id'])->fetchColumn()>$priorVersion,'Stale record versions invalidated');
$repeat=ZeroPoint::restore($request);$check(!empty($repeat['replayed']) && $repeat['operation']===$result['operation'],'Restore retry is idempotent');
$check($fingerprints()===$beforeProtected,'Identity, provider state, external history, documents and organization preserved');
try {Service::command('party.save',$input);throw new RuntimeException('Old-epoch command replayed');}
catch (RuntimeException $e) {$check(str_contains($e->getMessage(),'уже использован'),'Old-epoch command rejected');}
$backup=ZeroPoint::load($key);
$check((bool)array_filter($backup['data']['tables']['parties'],static fn($p)=>(int)$p['id']===$id),'Pre-restore data retained in backup');
$recovery=ZeroPoint::preview($key);
ZeroPoint::restore($recovery+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$check((bool)$db->query('SELECT 1 FROM parties WHERE id='.$id)->fetchColumn(),'Return from automatic backup');
$check((bool)$db->query('SELECT 1 FROM invoices WHERE id='.$invoiceId)->fetchColumn(),'Return restores commercial graph');
// Inject a signed private test fixture with a broken FK: no partial replacement may commit.
$bad=$baseline;unset($bad['sha256'],$bad['target']);$bad['data']['tables']['invoices'][0]['contract_id']=9223372036854775807;
$badSha=ZeroPoint::digest($bad);ZeroPoint::write(ZeroPoint::directory().'/'.$badSha.'.json',$bad);
$fixture=bin2hex(random_bytes(16));$receipt=ZeroPoint::directory().'/backups/'.$fixture.'.receipt.json';
ZeroPoint::write($receipt,['sha256'=>$badSha,'created_at'=>date(DATE_ATOM)]);
$stateBefore=ZeroPoint::digest(ZeroPoint::capture($db));
try {
    $badPlan=ZeroPoint::preview($fixture);
    try {ZeroPoint::restore($badPlan+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);throw new RuntimeException('Broken FK accepted');}
    catch (PDOException $e) {$check((string)$e->getCode()==='23000','Expected FK integrity failure');}
    $check(ZeroPoint::digest(ZeroPoint::capture($db))===$stateBefore,'Failed restore rolls back every commercial row');
} finally {unlink($receipt);}
$fresh=ZeroPoint::preview();ZeroPoint::restore($fresh+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$check($fingerprints()===$beforeProtected,'Final protected-state equality');
$check((int)$db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1,'FK checks never disabled');
$check(ZeroPoint::eventFloor($db)===(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn(),'Old journal retained below active event boundary');
$check((int)$db->query('SELECT COUNT(*) FROM rental_commands')->fetchColumn()>0,'Command history retained');
// Before any point was installed, a retry of the old fingerprint format must remain compatible.
$db->beginTransaction();
try {
    $db->exec("DELETE FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active'");
    $legacyKey=bin2hex(random_bytes(16));$legacyInput=['name'=>'Legacy retry','command_key'=>$legacyKey];
    $actor=\App\Auth::user();$fingerprint=hash('sha256',\Modules\Rental\Importer::json([$actor['id'],'party.save',$legacyInput]));
    $db->prepare('INSERT INTO rental_commands(command_key,action,fingerprint,result_json) VALUES(?,?,?,?)')
        ->execute([$legacyKey,'party.save',$fingerprint,'{"id":123}']);
    $check(Service::command('party.save',$legacyInput)===['id'=>123],'Legacy command receipt remains retryable before first reset');
} finally {$db->rollBack();}
$seed=$db->query("SELECT source_key,fact_json FROM rental_facts WHERE namespace='zero_point_data' AND source_key LIKE 'last_occupant:%' LIMIT 1")->fetch();
$objectId=(int)substr($seed['source_key'],strlen('last_occupant:'));$evidence=json_decode($seed['fact_json'],true);
$object=$db->query('SELECT * FROM rental_objects WHERE id='.$objectId)->fetch();$parent=$db->query('SELECT * FROM cadastre WHERE id='.(int)$object['cadastre_id'])->fetch();
$facts=ObjectFacts::project($object,$parent,['available'=>true],'2026-10-31');
$check($facts['free'] && $facts['previous']===$evidence['tenant'],'Free object retains its last tenant');
echo json_encode(['passed'=>$checks,'baseline_contracts'=>55,'invoices'=>115,'payments'=>104,'database'=>'ipphp_task_zero_point','final_state'=>'baseline','external_requests'=>0],JSON_PRETTY_PRINT).PHP_EOL;
