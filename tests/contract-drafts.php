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
function reject(callable $call,int $status,string $message=''):void {
 try {$call();throw new LogicException('Unexpected success');}
 catch (RuntimeException $error) {if ($error instanceof LogicException) throw $error; ok($error->getCode()===$status && ($message==='' || $error->getMessage()===$message),'Unexpected rejection: '.$error->getMessage());}
}
function record(int $id):array{return Service::get(DB::pdo(),'contracts',$id);}
$p->beginTransaction();
try {
 actor('manager');
 $before=(int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn();
 $from=date('Y-m-d');$until=date('Y-m-d',strtotime('+1 month'));
 $available=array_values(array_filter(Availability::items($from,$until),fn($r)=>$r['available']));
 ok(count($available)>=2,'Two available objects are required');$oid=(int)$available[0]['id'];$second=(int)$available[1]['id'];
 $empty=['command_key'=>keyForTest(),'intent'=>'draft','party_id'=>'','document_date'=>'','start_date'=>'','end_date'=>''];
 reject(fn()=>Service::command('contract.create',$empty),422);
 $input=$empty+['objects'=>[$oid],'term_amount'=>[$oid=>'123.45']];
 foreach (['','0','0.00','-1','bad'] as $rate) {
  $invalid=array_replace($input,['term_amount'=>[$oid=>$rate]]);
  reject(fn()=>Service::command('contract.create',$invalid),422);
 }
 $invalid=array_replace($input,['objects'=>[$oid,$second],'term_amount'=>[$oid=>'123.45',$second=>'']]);
 reject(fn()=>Service::command('contract.create',$invalid),422);
 ok((int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn()===$before,'Invalid prices created a draft');

 $r=Service::command('contract.create',$input);$id=(int)$r['id'];$c=record($id);
 ok($c['status']==='draft'&&$c['party_id']===null&&$c['start_date']===null&&$c['end_date']===null&&$c['amount']==='123.45','Priced incomplete draft projection');
 ok(Service::command('contract.create',$input)===$r,'Idempotent replay');
 ok((int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn()===$before+1,'Replay duplicated draft');
 $custom='LOCAL-'.substr(keyForTest(),0,12);
 Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'number'=>$custom,'command_key'=>keyForTest()]+$input);$c=record($id);
 ok($c['number']===$custom,'Custom draft number was not saved');
 $duplicate=(string)$p->query('SELECT number FROM contracts WHERE id<>'.$id.' LIMIT 1')->fetchColumn();
 try { Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'number'=>$duplicate,'command_key'=>keyForTest(),'intent'=>'draft']);throw new LogicException('Duplicate number accepted'); } catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
 $_GET=['id'=>$id];$form=ContractForm::render();ok(!preg_match('/name="number"[^>]*readonly/',$form),'Draft number remains locked');ok(str_contains($form,'formnovalidate'),'Draft button bypass');
 ok(str_contains(WorkRegistry::render(),'contract:'.$id),'Unassigned draft missing in registry');
 ok(str_contains(SourceCards::show(),'Сохранить в черновиках')&&!str_contains(SourceCards::show(),'Редактировать проект'),'Draft did not open editor directly');
 $p->exec('SAVEPOINT expected_submit');
 try { Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]);throw new LogicException('Incomplete submit accepted'); } catch (RuntimeException $e) {if($e instanceof LogicException)throw $e;$p->exec('ROLLBACK TO SAVEPOINT expected_submit');}
 ok(record($id)['status']==='draft','Failed submit changed status');
 $partial=['id'=>$id,'number'=>$c['number'],'version'=>$c['version'],'command_key'=>keyForTest(),'intent'=>'draft','party_id'=>'','start_date'=>$until,'end_date'=>$from,'objects'=>[$oid],'term_amount'=>[$oid=>'']];
 $p->exec('SAVEPOINT invalid_price');
 reject(fn()=>Service::command('contract.edit',$partial),422);
 $p->exec('ROLLBACK TO SAVEPOINT invalid_price');
 ok(record($id)===$c,'Invalid edit changed the saved draft');
 $partial['term_amount'][$oid]='100.11';
 Service::command('contract.edit',$partial);$c=record($id);$snapshot=ContractDraft::input($c);
 ok($c['amount']==='100.11','Monthly price lost');
 ok($snapshot['term_amount'][$oid]==='100.11'&&$snapshot['start_date']===$until,'Partial editor state lost');
 ok((int)$p->query('SELECT COUNT(*) FROM contract_terms WHERE contract_id='.$id)->fetchColumn()===0,'Draft created billing terms');
 ok((int)$p->query('SELECT COUNT(*) FROM contract_objects WHERE contract_id='.$id)->fetchColumn()===0,'Draft created occupancy relations');
 $_GET=['id'=>$id];ok(str_contains(ContractForm::render(),'name="objects[]" value="'.$oid.'"'),'Partial object not restored');
 try { Service::command('contract.edit',array_replace($partial,['command_key'=>keyForTest()]));throw new LogicException('Stale version accepted'); } catch(RuntimeException $e) {if($e instanceof LogicException)throw $e;ok($e->getCode()===409,'Stale version did not fail with conflict');}
 actor('accountant');try{Service::command('contract.create',['command_key'=>keyForTest(),'intent'=>'draft']);throw new LogicException('Forbidden actor saved draft');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;ok($e->getCode()===403,'Forbidden actor not denied');}actor('manager');
 $party=(int)$p->query('SELECT id FROM parties WHERE blocked=0 ORDER BY id LIMIT 1')->fetchColumn();
 $office=(int)$p->query("SELECT id FROM rental_objects WHERE type='Офис' ORDER BY id LIMIT 1")->fetchColumn();
 $parking=(int)$p->query("SELECT id FROM rental_objects WHERE type='Машиноместо' ORDER BY id LIMIT 1")->fetchColumn();
 ok($office>0 && $parking>0,'Office and parking types are required');
 ContractDraft::assertObjectTypes(['Машиноместо','Машино-место']);
 $mixed=['party_id'=>$party,'document_date'=>$from,'start_date'=>$from,'end_date'=>$until,
     'amount'=>'300.00','objects'=>[$office,$parking],'term_amount'=>[$office=>'100.00',$parking=>'200.00']];
 foreach (['draft','submit'] as $intent) {
  reject(fn()=>Service::command('contract.create',['command_key'=>keyForTest(),'intent'=>$intent]+$mixed),422,ContractDraft::OBJECT_TYPE_ERROR);
 }
 ok((int)$p->query('SELECT COUNT(*) FROM contracts')->fetchColumn()===$before+1,'A mixed offer created a contract');

 $p->exec('SAVEPOINT historical_mixed');
 $p->prepare('UPDATE contracts SET source_json=? WHERE id=?')->execute([
    Modules\Rental\Importer::json(['draft_input'=>$mixed]),$id,
 ]);
 $_GET=['id'=>$id];
 $legacy=ContractForm::render();
 foreach ([$office,$parking] as $object) {
  ok(str_contains($legacy,'name="objects[]" value="'.$object.'"'),'Existing mixed draft must remain editable');
 }
 $storedMixed=record($id);
 $p->exec('SAVEPOINT reject_mixed_submit');
 reject(fn()=>Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),422,ContractDraft::OBJECT_TYPE_ERROR);
 $p->exec('ROLLBACK TO SAVEPOINT reject_mixed_submit');
 $p->exec('SAVEPOINT reject_mixed_edit');
 reject(fn()=>Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'intent'=>'draft','number'=>$c['number'],'command_key'=>keyForTest()]+$mixed),422,ContractDraft::OBJECT_TYPE_ERROR);
 $p->exec('ROLLBACK TO SAVEPOINT reject_mixed_edit');
 ok(record($id)===$storedMixed,'Rejecting the mixed draft changed the record');
 $completeMixed=array_replace($mixed,['objects'=>[$office],'term_amount'=>[$office=>'100.00']]);
 Service::command('contract.edit',['id'=>$id,'version'=>$c['version'],'intent'=>'draft','number'=>$c['number'],'command_key'=>keyForTest()]+$completeMixed);
 ok(ContractDraft::input(record($id))['objects']===[$office],'Removing the extra type did not unblock draft save');
 $p->exec('ROLLBACK TO SAVEPOINT historical_mixed');

 $p->exec('SAVEPOINT old_object_relation');
 $p->prepare("UPDATE contracts SET source_json='{}' WHERE id=?")->execute([$id]);
 foreach ([$office,$parking] as $object) {
  $p->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$id,$object]);
 }
 reject(fn()=>Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]),422,ContractDraft::OBJECT_TYPE_ERROR);
 ok(record($id)['status']==='draft','Direct submit advanced a stored mixed draft');
 $p->exec('ROLLBACK TO SAVEPOINT old_object_relation');
 $complete=['id'=>$id,'version'=>$c['version'],'number'=>$c['number'],'command_key'=>keyForTest(),'intent'=>'submit','party_id'=>$party,'document_date'=>$from,'start_date'=>$from,'end_date'=>$until,'amount'=>'123.45','objects'=>[$oid],'term_amount'=>[$oid=>'123.45']];
 Service::command('contract.edit',array_replace($complete,['intent'=>'draft']));$c=record($id);
 Service::command('contract.submit',['id'=>$id,'version'=>$c['version'],'command_key'=>keyForTest()]);$c=record($id);
 ok($c['status']==='legal'&&!isset(json_decode($c['source_json'],true)['draft_input']),'Complete submission did not finalize staged draft');
 ok($p->query('SELECT amount FROM contract_terms WHERE contract_id='.$id)->fetchColumn()==='123.45','Money changed on submission');
 $event=Modules\Rental\WorkflowEvents::latest($p,'contract',$id);
 $entry=$p->query("SELECT title,body_text,version FROM integration_template_catalog WHERE channel='top' AND source_key='document-submitted'")->fetch();
 $notice=Modules\Rental\WorkflowNotices::sender($event,['document-submitted'=>$entry]);
 $partyName=$p->query('SELECT name FROM parties WHERE id='.$party)->fetchColumn();
 ok($notice['severity']==='green'&&str_contains($notice['title'],$partyName)&&!str_contains($notice['text'],'Сохранён договор'),'Submit notice missing counterparty/repeats save');

 echo "PASS: uniform object types on draft/edit/submission and rates required in new and edited drafts, partial metadata, reopen/card/registry, replay, version, forbidden role, incomplete refusal, complete submission, exact money; transaction rolled back.\n";
} catch (Throwable $failure) { fwrite(STDERR,get_class($failure).': '.$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine().PHP_EOL); $failed=true; } finally {if($p->inTransaction())$p->rollBack();}

if (!empty($failed)) exit(1);
