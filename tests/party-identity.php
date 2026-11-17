<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\Service;
$p=DB::pdo();
if(PHP_SAPI!=='cli'||getenv('APP_CONTRACT_DRAFT_TEST')!=='1'||!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Isolated task DB required');
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function keyForTest():string{return bin2hex(random_bytes(16));}
function save(array $in):array{return Service::command('party.save',$in+['command_key'=>keyForTest()]);}
function denied(array $in):void{try{save($in);throw new LogicException('Duplicate INN accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;check($e->getCode()===422&&str_contains($e->getMessage(),'ИНН'),'Wrong duplicate refusal');}}
$p->beginTransaction();
try{
 $u=$p->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
 $inn='99'.substr((string)hexdec(substr(keyForTest(),0,10)),0,8);check(strlen($inn)===10,'Fixture INN');
 $base=['name'=>'LOCAL identity '.keyForTest(),'inn'=>$inn,'document_recipient'=>0,'additional_document_recipient'=>0,'bank'=>'LOCAL test bank','account'=>str_repeat('0',20),'bik'=>str_repeat('0',9),'correspondent_account'=>str_repeat('0',20)];
 $r=save($base);$id=(int)$r['id'];$row=Service::get($p,'parties',$id);
 denied(array_replace($base,['name'=>'Other name']));
 save(array_replace($base,['id'=>$id,'version'=>$row['version'],'name'=>'LOCAL renamed']));
 $other=save(array_replace($base,['inn'=>'']));$oid=(int)$other['id'];$otherRow=Service::get($p,'parties',$oid);
 denied(array_replace($base,['id'=>$oid,'version'=>$otherRow['version']]));
 $p->prepare('UPDATE parties SET inn=? WHERE id=?')->execute([substr($inn,0,5).' '.substr($inn,5),$id]);
 denied($base);
 echo "PASS: duplicate create/change denied, same identity edit allowed, empty INN distinct, formatted historical INN matched; fixtures rolled back.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL);}finally{if($p->inTransaction())$p->rollBack();}
if(!empty($failed))exit(1);
