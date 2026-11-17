<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Availability,ObjectFacts,ObjectReadModel,Service,ZeroPoint,ZeroPointCorrection};
$db=DB::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_zero_dates' || !in_array('--apply-isolated',$argv,true))
    throw new RuntimeException('Explicit independent correction database required');
$evidence=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$login=static function(string $role)use($db){$q=$db->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];};
$hash=static function(array $tables)use($db){$out=[];foreach($tables as $t){$rows=$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);if(in_array($t,['invoices','payments'],true))foreach($rows as &$row)unset($row['version']);unset($row);$h=array_map(ZeroPoint::digest(...),$rows);sort($h);$out[$t]=ZeroPoint::digest($h);}return $out;};
$protected=['users','roles','permissions','role_permissions','role_profiles','role_page_grants','integration_connections',
    'messaging_attempts','messaging_receipts','rental_files','organization','organization_bank_accounts','rental_templates',
    'invoices','invoice_objects','invoice_coverage','invoice_part_scope','payments','payment_allocations'];
$before=$hash($protected);$old=ZeroPoint::load();$dir=ZeroPoint::directory();$oldPin=json_decode(file_get_contents($dir.'/installed.json'),true);
$login('manager');try{ZeroPointCorrection::prepare($evidence);throw new LogicException('Manager accepted');}catch(RuntimeException $e){$check($e->getCode()===403,'Manager denied');}
$login('admin');$plan=ZeroPointCorrection::prepare($evidence);
$bad=$plan;$bad['document']['data']['tables']['contracts'][0]['amount']='1.00';$bad['next_sha256']=ZeroPoint::digest($bad['document']);
try{ZeroPointCorrection::apply($bad);throw new LogicException('Tampered plan accepted');}catch(RuntimeException $e){$check(!is_file($dir.'/revision-pending.json'),'Tampered plan fails before changes');}
$check($hash($protected)===$before,'Tamper preserves system and money');
ZeroPoint::write($dir.'/'.$plan['next_sha256'].'.json',$plan['document']);
$newPin=$oldPin;$newPin['sha256']=$plan['next_sha256'];
ZeroPoint::write($dir.'/revision-pending.json',['command_key'=>str_repeat('a',32),'old'=>$oldPin,'next'=>$newPin]);
try{ZeroPoint::load();throw new LogicException('Pending restore accepted');}catch(RuntimeException $e){$check($e->getCode()===409,'Pending revision blocks restore');}
$recovery=ZeroPointCorrection::recover();$check(!$recovery['committed'] && $recovery['sha256']===$old['sha256'],'Uncommitted revision recovers old pin');
$stale=$plan;$stale['current_sha256']=str_repeat('0',64);
try{ZeroPointCorrection::apply($stale);throw new LogicException('Stale plan accepted');}catch(RuntimeException $e){$check(!is_file($dir.'/revision-pending.json'),'Stale plan fails before changing pin');}
$result=ZeroPointCorrection::apply($plan);$check($result['date_count']===16,'Exactly sixteen dates corrected');
$after=ZeroPoint::capture($db);$next=ZeroPoint::load();$check($next['sha256']===$plan['next_sha256'],'New immutable point installed');
$check(hash_file('sha256',$dir.'/'.$old['sha256'].'.json')===$old['sha256'],'Old immutable point retained');
$check($hash($protected)===$before,'Money, files, identities and providers unchanged');
$repeat=ZeroPointCorrection::apply($plan);$check(ZeroPoint::json($repeat)===ZeroPoint::json($result),'Correction retry has no duplicate');
$dates=static function(array $data)use($evidence):bool{$objects=array_column($data['tables']['rental_objects'],null,'id');foreach($evidence['dates'] as $d)if($objects[$d['id']]['free_since']!==$d['free_since'])return false;return true;};
$check($dates($after) && $dates($next['data']),'Dates exist in live graph and baseline');
$occupancy=static function(string $date)use($check):void {
    $read=new ObjectReadModel($date);$objects=array_column($read->objects,null,'id');
    foreach([1044=>false,1048=>true] as $id=>$free){$o=$objects[$id];$facts=ObjectFacts::project($o,ObjectReadModel::parent($o),$read->availability[$id],$date,$read);$check($facts['free']===$free,'Replacement occupancy '.$id.' at '.$date);}
    $lease=$read->lease(1044);$check($lease['number']==='33','Same contract continues');
    $term=json_decode($lease['term_source'],true);$check($date<'2026-11-01'?isset($term['sourceTerm']):($term['amountEffectiveFrom'] ?? '')==='2026-11-01','Date selects correct tariff evidence');
};
$occupancy('2026-10-31');$occupancy('2026-11-18');
ZeroPoint::write($dir.'/revision-pending.json',['command_key'=>$plan['command_key'],'old'=>$oldPin,'next'=>$newPin]);
$recovery=ZeroPointCorrection::recover();$check($recovery['committed'] && $recovery['sha256']===$plan['next_sha256'],'Committed command recovers new pin');
$restore=ZeroPoint::preview();ZeroPoint::restore($restore+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$check($dates(ZeroPoint::capture($db)),'Restoring the point retains all sixteen dates');$occupancy('2026-10-31');$occupancy('2026-11-18');
$check($hash($protected)===$before,'Baseline restore preserves system and financial rows');ZeroPoint::assertMoney($db);
echo json_encode(['passed'=>$checks,'date_count'=>16,'revision'=>$plan['next_sha256'],'final_state'=>'corrected baseline','external_calls'=>0],JSON_PRETTY_PRINT).PHP_EOL;
