<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{DB,Audit};
use Modules\Rental\{Service,ContractDraft,ContractForm,SourceCards,WorkRegistry,Access,SummaryRegistry,ApprovalRegistry};
$p=DB::pdo();
if(PHP_SAPI!=='cli'||getenv('APP_CONTRACT_DRAFT_TEST')!=='1'||!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Isolated task DB required');
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function actor(string $role):void{$q=DB::pdo()->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();verify((bool)$u,'Missing actor');$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];}
function keyForTest():string{return bin2hex(random_bytes(16));}
function reject(callable $call,int $code):void{try{$call();throw new LogicException('Unexpected success');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;verify($e->getCode()===$code,'Wrong denial: '.$e->getMessage());}}
function record(int $id):array{return Service::get(DB::pdo(),'contracts',$id);}
$p->beginTransaction();
try{
 actor('manager');
 $number='DELETE-'.keyForTest();
 $r=Service::command('contract.create',['number'=>$number,'command_key'=>keyForTest(),'intent'=>'draft']);$id=(int)$r['id'];$c=record($id);$_GET=['id'=>$id];
 $html=SourceCards::show();verify(str_contains($html,'Сохранить в черновиках')&&str_contains($html,'Отправить на согласование')&&str_contains($html,'Удалить черновик')&&!str_contains($html,'Аннулировать'),'Editor actions');
 verify(str_contains(WorkRegistry::render(),'contract:'.$id),'Missing draft');
 reject(fn()=>Service::command('contract.delete',['id'=>$id,'version'=>0,'command_key'=>keyForTest()]),409);
 actor('accountant');reject(fn()=>Service::command('contract.delete',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),403);actor('manager');
 $p->prepare("UPDATE contracts SET status='legal',party_id=(SELECT id FROM parties ORDER BY id LIMIT 1) WHERE id=?")->execute([$id]);
 reject(fn()=>Service::command('contract.delete',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),409);
 $card=SourceCards::show();verify(!str_contains($card,'Остаток по счетам')&&!str_contains($card,'Условия договора')&&!str_contains($card,'История и сообщения'),'Obsolete view blocks');
 $p->prepare("UPDATE contracts SET status='draft',source_json=JSON_SET(source_json,'$.imported',true) WHERE id=?")->execute([$id]);
 reject(fn()=>Service::command('contract.delete',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),409);
 $p->prepare("UPDATE contracts SET source_json=JSON_REMOVE(source_json,'$.imported') WHERE id=?")->execute([$id]);
 $p->exec('SAVEPOINT child_guard');
 $p->prepare("INSERT INTO rental_approvals(entity_type,entity_id,role,baseline_hash,file_id,actor_id) VALUES('contract',?,'lawyer',REPEAT('0',64),REPEAT('0',32),?)")->execute([$id,App\Auth::user()['id']]);
 reject(fn()=>Service::command('contract.delete',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),409);
 $p->exec('ROLLBACK TO SAVEPOINT child_guard');
 $delete=['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()];
 $result=Service::command('contract.delete',$delete);$deleted=record($id);
 verify(ContractDraft::deleted($deleted)&&$deleted['status']==='draft'&&(int)$deleted['version']===(int)$c['version']+1,'Delete marker/version');
 verify(Service::command('contract.delete',$delete)===$result,'Delete replay');
 verify((int)$p->query("SELECT COUNT(*) FROM rental_events WHERE action='contract.delete' AND contract_id=".$id)->fetchColumn()===1,'Duplicate delete event');
 verify((int)$p->query('SELECT COUNT(*) FROM rental_contract_versions WHERE contract_id='.$id)->fetchColumn()===1,'Missing preserved history');
 verify(!str_contains(WorkRegistry::render(),'contract:'.$id.'"'),'Deleted draft in registry');
 reject(fn()=>Access::contract($id),404);reject(fn()=>ContractForm::render(),404);
 reject(fn()=>Service::command('contract.submit',['id'=>$id,'version'=>$deleted['version'],'command_key'=>keyForTest()]),404);
 reject(fn()=>Service::command('contract.edit',['id'=>$id,'number'=>$number,'intent'=>'draft','version'=>$deleted['version'],'command_key'=>keyForTest()]),404);
 $replacement=Service::command('contract.create',['number'=>$number,'intent'=>'draft','command_key'=>keyForTest()]);
 verify((int)$replacement['id']!==$id,'Number reuse');
 // Compatibility for a draft annulled before this change: positive audit proof is required.
 $oldId=(int)$replacement['id'];$old=record($oldId);
 $p->prepare("UPDATE contracts SET status='cancelled' WHERE id=?")->execute([$oldId]);
 verify(!ContractDraft::deletable($p,record($oldId)),'Cancelled contract without proof accepted');
 Audit::record('contract.cancel','contract',$oldId,$old,['status'=>'cancelled']);
 verify(ContractDraft::deletable($p,record($oldId)),'Legacy draft proof ignored');
 Service::command('contract.delete',['id'=>$oldId,'version'=>$old['version'],'command_key'=>keyForTest()]);
 verify(ContractDraft::deleted(record($oldId)),'Legacy draft not deleted');
 echo "PASS: direct editor/actions, delete guards/roles/version, replay/history, registry exclusion, no reopening, number reuse, legacy draft proof; fixtures rolled back.\n";
}catch(Throwable $e){$failure=$e;}finally{if($p->inTransaction())$p->rollBack();}
if(isset($failure)){fwrite(STDERR,$failure->getMessage().' at '.$failure->getLine().PHP_EOL);exit(1);}
