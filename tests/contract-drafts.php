<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Service,ContractDraft,ContractForm,WorkRegistry,SourceCards,Availability};
$p=DB::pdo();
if (PHP_SAPI!=='cli' || getenv('APP_CONTRACT_DRAFT_TEST')!=='1' || !preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Explicit isolated test database required');
function ok(bool $yes,string $message):void { if(!$yes)throw new RuntimeException($message); }
function actor(string $role):void { $p=DB::pdo();$q=$p->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();ok((bool)$u,'Test actor missing');$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']]; }
function keyForTest():string{return bin2hex(random_bytes(16));}
function record(int $id):array{return Service::get(DB::pdo(),'contracts',$id);}
$p->beginTransaction();
try {
 actor('manager');
 $before=(int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn();
 $empty=['command_key'=>keyForTest(),'intent'=>'draft','party_id'=>'','document_date'=>'','start_date'=>'','end_date'=>'','amount'=>'0.00'];
 $r=Service::command('contract.create',$empty);$id=(int)$r['id'];$c=record($id);
 ok($c['status']==='draft'&&$c['party_id']===null&&$c['start_date']===null&&$c['end_date']===null,'Empty draft projection');
 ok(Service::command('contract.create',$empty)===$r,'Idempotent replay');
 ok((int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn()===$before+1,'Replay duplicated draft');
 $custom='LOCAL-'.substr(keyForTest(),0,12);
 Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'number'=>$custom,'command_key'=>keyForTest(),'intent'=>'draft']);$c=record($id);
 ok($c['number']===$custom,'Custom draft number was not saved');
 $duplicate=(string)$p->query('SELECT number FROM contracts WHERE id<>'.$id.' LIMIT 1')->fetchColumn();
 try { Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'number'=>$duplicate,'command_key'=>keyForTest(),'intent'=>'draft']);throw new LogicException('Duplicate number accepted'); } catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
 $_GET=['id'=>$id];$form=ContractForm::render();ok(!preg_match('/name="number"[^>]*readonly/',$form),'Draft number remains locked');ok(str_contains($form,'formnovalidate'),'Draft button bypass');
 ok(str_contains(WorkRegistry::render(),'contract:'.$id),'Unassigned draft missing in registry');
 ok(str_contains(SourceCards::show(),'Редактировать проект'),'Unassigned card failed');
 $p->exec('SAVEPOINT expected_submit');
 try { Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]);throw new LogicException('Incomplete submit accepted'); } catch (RuntimeException $e) {if($e instanceof LogicException)throw $e;$p->exec('ROLLBACK TO SAVEPOINT expected_submit');}
 ok(record($id)['status']==='draft','Failed submit changed status');
 $from=date('Y-m-d');$until=date('Y-m-d',strtotime('+1 month'));
 $available=array_values(array_filter(Availability::items($from,$until),fn($r)=>$r['available']));ok((bool)$available,'No available object');$oid=(int)$available[0]['id'];
 $partial=['id'=>$id,'number'=>$c['number'],'version'=>$c['version'],'command_key'=>keyForTest(),'intent'=>'draft','party_id'=>'','start_date'=>$until,'end_date'=>$from,'amount'=>'0.00','objects'=>[$oid],'term_amount'=>[$oid=>'']];
 Service::command('contract.edit',$partial);$c=record($id);$snapshot=ContractDraft::input($c);
 ok($c['amount']===null,'Missing rent became zero');
 ok($snapshot['term_amount'][$oid]===''&&$snapshot['start_date']===$until,'Partial editor state lost');
 ok((int)$p->query('SELECT COUNT(*) FROM contract_terms WHERE contract_id='.$id)->fetchColumn()===0,'Draft created billing terms');
 ok((int)$p->query('SELECT COUNT(*) FROM contract_objects WHERE contract_id='.$id)->fetchColumn()===0,'Draft created occupancy relations');
 $_GET=['id'=>$id];ok(str_contains(ContractForm::render(),'name="objects[]" value="'.$oid.'"'),'Partial object not restored');
 try { Service::command('contract.edit',array_replace($partial,['command_key'=>keyForTest()]));throw new LogicException('Stale version accepted'); } catch(RuntimeException $e) {if($e instanceof LogicException)throw $e;ok($e->getCode()===409,'Stale version did not fail with conflict');}
 actor('accountant');try{Service::command('contract.create',['command_key'=>keyForTest(),'intent'=>'draft']);throw new LogicException('Forbidden actor saved draft');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;ok($e->getCode()===403,'Forbidden actor not denied');}actor('manager');
 $party=(int)$p->query('SELECT id FROM parties WHERE blocked=0 ORDER BY id LIMIT 1')->fetchColumn();
 $complete=['id'=>$id,'version'=>$c['version'],'number'=>$c['number'],'command_key'=>keyForTest(),'intent'=>'submit','party_id'=>$party,'document_date'=>$from,'start_date'=>$from,'end_date'=>$until,'amount'=>'123.45','objects'=>[$oid],'term_amount'=>[$oid=>'123.45']];
 Service::command('contract.edit',array_replace($complete,['intent'=>'draft']));$c=record($id);
 Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]);$c=record($id);
 ok($c['status']==='legal'&&!isset(json_decode($c['source_json'],true)['draft_input']),'Complete submission did not finalize staged draft');
 ok($p->query('SELECT amount FROM contract_terms WHERE contract_id='.$id)->fetchColumn()==='123.45','Money changed on submission');
 echo "PASS: empty/partial drafts, reopen/card/registry, replay, version, forbidden role, incomplete refusal, complete submission, exact money; transaction rolled back.\n";
} catch (Throwable $failure) { fwrite(STDERR,get_class($failure).': '.$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine().PHP_EOL); $failed=true; } finally {if($p->inTransaction())$p->rollBack();}

if (!empty($failed)) exit(1);

