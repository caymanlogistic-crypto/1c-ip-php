<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Service,Availability,CustomerBookings,Evidence,Occupancy,Importer};
$p=DB::pdo();
if(PHP_SAPI!=='cli'||getenv('APP_CONTRACT_DRAFT_TEST')!=='1'||!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Explicit isolated task DB required');
function expectBooking(bool $yes,string $why):void{if(!$yes)throw new RuntimeException($why);}
function commandKey():string{return bin2hex(random_bytes(16));}
function denyBooking(callable $action,string $message):void{ $p=DB::pdo();$p->exec('SAVEPOINT expected_booking_failure');try{$action();throw new LogicException('Operation unexpectedly succeeded');}catch(RuntimeException $e){expectBooking(str_contains($e->getMessage(),$message),'Unexpected refusal: '.$e->getMessage());$p->exec('ROLLBACK TO SAVEPOINT expected_booking_failure');}}
function paidInitialInvoices(int $cid):void{$p=DB::pdo();foreach(['Обеспечительный платёж','Первый период'] as $kind){$id=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM invoices')->fetchColumn();$p->prepare("INSERT INTO invoices(id,number,contract_id,kind,amount,paid,issued,due,status,initial_invoice,source_json) VALUES(?,?,?,?,'1.00','1.00',CURRENT_DATE,CURRENT_DATE,'active',1,'{}')")->execute([$id,'LOCAL-INVOICE-'.commandKey(),$cid,$kind]);}}
$p->beginTransaction();
try{
 $actor=$p->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
 $start=date('Y-m-d');$end=date('Y-m-d',strtotime('+1 month'));
 $rooms=array_values(array_filter(Availability::items($start,$end),fn($r)=>$r['available']));expectBooking((bool)$rooms,'No free fixture room');$oid=$rooms[0]['id'];
 $firstInput=['object_id'=>$oid,'start_date'=>$start,'until_date'=>$end,'client_name'=>'Локальный клиент А','comment'=>'Первый запрос','command_key'=>commandKey()];
 $a=Service::command('reservation.create',$firstInput);$b=Service::command('reservation.create',array_replace($firstInput,['client_name'=>'Локальный клиент Б','comment'=>'Второй запрос','command_key'=>commandKey()]));
 expectBooking($a['id']!==$b['id'],'Bookings were not independent');expectBooking(Service::command('reservation.create',$firstInput)===$a,'Replay duplicated booking');
 $same=array_values(array_filter(Availability::items($start,$end,null,null,true),fn($r)=>$r['id']===$oid))[0];expectBooking(!$same['disabled']&&str_contains($same['description'],'броней: 2'),'Second booking unavailable or count missing');
 $party=(int)$p->query('SELECT id FROM parties WHERE blocked=0 ORDER BY id LIMIT 1')->fetchColumn();
 $draft=Service::command('contract.create',['number'=>'LOCAL-BOOK-'.commandKey(),'party_id'=>$party,'start_date'=>$start,'end_date'=>$end,'amount'=>'123.45','objects'=>[$oid],'term_amount'=>[$oid=>'123.45'],'booking_id'=>$a['id'],'booking_version'=>1,'intent'=>'draft','command_key'=>commandKey()]);$cid=(int)$draft['id'];
 expectBooking(Service::get($p,'reservations',(int)$b['id'])['status']==='active','Draft cancelled competitor');
 $c=Service::get($p,'contracts',$cid);$snapshot=Modules\Rental\ContractDraft::input($c);
 Service::command('contract.edit',array_merge($snapshot,['id'=>$cid,'version'=>$c['version'],'number'=>$c['number'],'intent'=>'submit','command_key'=>commandKey()]));
 expectBooking(Service::get($p,'reservations',(int)$a['id'])['status']==='consumed','Selected booking was not consumed');expectBooking(Service::get($p,'reservations',(int)$b['id'])['status']==='active','Submission cancelled competitor too soon');
 $p->prepare("UPDATE contracts SET status='sent',version=version+1 WHERE id=?")->execute([$cid]);
 // Competing sent contract models the moment before either client becomes an actual tenant.
 $other=(int)$p->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();$p->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,amount,status,source_json) VALUES(?,?,?,?,?,'123.45','sent','{}')")->execute([$other,'LOCAL-RIVAL-'.commandKey(),$party,$start,$end]);Occupancy::insert($p,'contract_objects',['contract_id'=>$other,'object_id'=>$oid]);$p->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,source_json) VALUES(?,?,?,?,'123.45','{}')")->execute([$other,$oid,$start,$end]);
 // A document reservation must still prevent creating another client request.
 $p->exec('SAVEPOINT document_reservation_fixture');$did=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM rental_documents')->fetchColumn();
 $p->prepare("INSERT INTO rental_documents(id,contract_id,number,kind,status,effective_date,start_date,end_date,amount,base_version,base_hash,source_json) VALUES(?,?,'LOCAL-DS','renewal','legal',?,?,?,'123.45',1,?,'{}')")->execute([$did,$other,$start,$start,$end,str_repeat('0',64)]);
 $rid=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM reservations')->fetchColumn();$p->prepare("INSERT INTO reservations(id,object_id,party_id,document_id,start_date,end_date,until_date,status,source_json) VALUES(?,?,?,?,?,?,?,'active','{}')")->execute([$rid,$oid,$party,$did,$start,$end,$end]);
 denyBooking(fn()=>Service::command('reservation.create',array_replace($firstInput,['client_name'=>'Клиент В','command_key'=>commandKey()])),'забронирован');$p->exec('ROLLBACK TO SAVEPOINT document_reservation_fixture');
 paidInitialInvoices($cid);expectBooking(Evidence::activation($p,$cid),'Paid contract did not activate');$cancelled=Service::get($p,'reservations',(int)$b['id']);
 expectBooking($cancelled['status']==='cancelled'&&(int)$cancelled['version']===2,'Competing booking was not cancelled with version');expectBooking((int)json_decode($cancelled['source_json'],true)['cancellation']['contract_id']===$cid,'Cancellation reason lost');
 expectBooking(!Evidence::activation($p,$cid),'Repeated activation was not a no-op');
 expectBooking((int)$p->query("SELECT COUNT(*) FROM audit_log WHERE action='reservation.auto-cancel' AND entity_id=".(int)$b['id'])->fetchColumn()===1,'Cancellation history missing');
 paidInitialInvoices($other);denyBooking(fn()=>Evidence::activation($p,$other),'занят договором');expectBooking(Service::get($p,'contracts',$other)['status']==='sent','Second active lease admitted');
 denyBooking(fn()=>Service::command('reservation.create',array_replace($firstInput,['client_name'=>'Поздний клиент','command_key'=>commandKey()])),'занят договором');
 $denied=$p->query("SELECT id,auth_version FROM users WHERE role='accountant' AND active=1 ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$denied['id'],'auth_version'=>$denied['auth_version']];
 try{Service::command('reservation.create',array_replace($firstInput,['command_key'=>commandKey()]));throw new LogicException('Accountant created booking');}catch(RuntimeException $e){expectBooking($e->getCode()===403,'Wrong forbidden-role response');}
 echo "PASS: overlapping client bookings/replay/counts, selected conversion, no early cancellation, activation cancellation/history, no second active lease, document-hold and role guards; rolled back.\n";
}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine().PHP_EOL);$failed=true;}finally{if($p->inTransaction())$p->rollBack();}
if(!empty($failed))exit(1);
