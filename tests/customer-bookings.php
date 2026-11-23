<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Service,Availability,CustomerBookings,Evidence,Occupancy,Importer,Scheduler,BookingNotices,Addenda};
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
 $deadline=new DateTimeImmutable($end);
 $notices=static function(DateTimeImmutable $date)use($a):array {
    $plan=Scheduler::dated($date->format('Y-m-d'));
    expectBooking($plan['externalSends']===0,'The dated plan must never send');
    return array_values(array_filter($plan['reservationNotices'],static fn($entry)=>$entry['id']===$a['id']));
 };
 $dayBefore=$deadline->modify('-1 day');
 $reminder=$notices($dayBefore);
 expectBooking(count($reminder)===1&&$reminder[0]['action']==='reservation.reminder'&&$reminder===$notices($dayBefore),'One stable reminder on the previous day');
 expectBooking($notices($deadline)===[],'The deadline is inclusive');
 $review=$notices($deadline->modify('+1 day'));
 expectBooking(count($review)===1&&$review[0]['action']==='reservation.expire','Expiry must be planned after the inclusive deadline');
 expectBooking(Service::get($p,'reservations',$a['id'])['status']==='active','Preview must preserve the reservation state');
 $same=array_values(array_filter(Availability::items($start,$end,null,null,true),fn($r)=>$r['id']===$oid))[0];expectBooking(!$same['disabled']&&str_contains($same['description'],'резервирований: 2'),'Second booking unavailable or count missing');
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
 denyBooking(fn()=>Service::command('reservation.create',array_replace($firstInput,['client_name'=>'Клиент В','command_key'=>commandKey()])),'резервировании');$p->exec('ROLLBACK TO SAVEPOINT document_reservation_fixture');
 paidInitialInvoices($cid);expectBooking(Evidence::activation($p,$cid),'Paid contract did not activate');$cancelled=Service::get($p,'reservations',(int)$b['id']);
 expectBooking($cancelled['status']==='cancelled'&&(int)$cancelled['version']===2,'Competing booking was not cancelled with version');expectBooking((int)json_decode($cancelled['source_json'],true)['cancellation']['contract_id']===$cid,'Cancellation reason lost');
 expectBooking(!Evidence::activation($p,$cid),'Repeated activation was not a no-op');
 expectBooking((int)$p->query("SELECT COUNT(*) FROM audit_log WHERE action='reservation.auto-cancel' AND entity_id=".(int)$b['id'])->fetchColumn()===1,'Cancellation history missing');
 paidInitialInvoices($other);denyBooking(fn()=>Evidence::activation($p,$other),'занят договором');expectBooking(Service::get($p,'contracts',$other)['status']==='sent','Second active lease admitted');
 denyBooking(fn()=>Service::command('reservation.create',array_replace($firstInput,['client_name'=>'Поздний клиент','command_key'=>commandKey()])),'занят договором');
 // Dates alone gate the working projection, and the first subsequent run closes the record once.
 $p->exec('SAVEPOINT booking_expiry_case');
 $late=(new DateTimeImmutable($end))->modify('+3 days')->format('Y-m-d');
 $expiry=Service::command('reservation.create',array_replace($firstInput,['start_date'=>$late,'client_name'=>'Другая заявка','command_key'=>commandKey()]));
 $idExpiry=$expiry['id'];
 expectBooking(!array_filter(BookingNotices::dated($end,false)['plan'],fn($e)=>$e['id']===$idExpiry),'Deadline was not inclusive');
 $pass=BookingNotices::dated($late,true);
 expectBooking(in_array('reservation.expire:'.$idExpiry.':1',$pass['applied'],true),'Skipped expiry was not caught up');
 expectBooking(Service::get($p,'reservations',$idExpiry)['status']==='expired','Expired state not persisted');
 expectBooking(!BookingNotices::dated($late,true)['applied'],'Expiry repeated');
 $p->exec('ROLLBACK TO SAVEPOINT booking_expiry_case');

 // A real applied unchanged DS keeps the request after the lease. Added physical coverage cancels an overlap.
 $newRoom=(int)(array_values(array_filter($rooms,fn($r)=>$r['id']!==$oid))[0]['id']??0);
 if (!$newRoom) {
    $newRoom=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM rental_objects')->fetchColumn();
    $p->prepare("INSERT INTO rental_objects(id,name,type,area,floor,cadastre_id,active,source_json)
      SELECT ?,'Учебный второй объект 15',type,area,floor,cadastre_id,1,source_json FROM rental_objects WHERE id=?")
      ->execute([$newRoom,$oid]);
 }
 $future=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
 $futureBooking=Service::command('reservation.create',array_replace($firstInput,['start_date'=>$future,'client_name'=>'После конца аренды','command_key'=>commandKey()]));
 $overlap=Service::command('reservation.create',array_replace($firstInput,['object_id'=>$newRoom,'client_name'=>'Новый объект','command_key'=>commandKey()]));
 $addon=static function(array $objects,string $num)use($p,$cid,$start,$end):array {
    $i=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM rental_documents')->fetchColumn();
    $p->prepare("INSERT INTO rental_documents(id,contract_id,number,kind,status,effective_date,start_date,end_date,amount,base_version,base_hash,source_json)
       VALUES(?,?,?,'renewal','signed',?,?,?,'123.45',1,?,'{}')")->execute([$i,$cid,$num,$start,$start,$end,str_repeat('0',64)]);
    foreach($objects as $object) Occupancy::insert($p,'document_terms',['document_id'=>$i,'object_id'=>$object,'start_date'=>$start,'end_date'=>$end,'amount'=>'123.45']);
    return Service::get($p,'rental_documents',$i);
 };
 $noop=$addon([$oid],'УЧ-ДС-15-БЕЗ-ИЗМЕНЕНИЙ');
 Addenda::apply($p,Service::get($p,'contracts',$cid),$noop);
 expectBooking(Service::get($p,'reservations',$futureBooking['id'])['status']==='active','Unchanged DS cancelled a future application');
 $added=$addon([$oid,$newRoom],'УЧ-ДС-15-ДОБАВЛЕНИЕ');
 Addenda::apply($p,Service::get($p,'contracts',$cid),$added);
 $cancel=Service::get($p,'reservations',$overlap['id']);
 expectBooking($cancel['status']==='cancelled' && Importer::decodeExact($cancel['source_json'])['cancellation']['document_id']===$added['id'],'Added physical room did not cancel only the overlap');
 expectBooking(Service::get($p,'reservations',$futureBooking['id'])['status']==='active','Independent future booking was cancelled');

 // The stopped project retains its association until the manager explicitly decides the application.
 $sp=$p->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,amount,status,source_json)
     VALUES(?,?,?,?,?,'123.45','returned','{}')");
 $stopped=(int)$p->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();
 $sp->execute([$stopped,'УЧ-ПРЕКРАЩЁН-15',$party,$start,$end]);
 $p->prepare("UPDATE reservations SET contract_id=?,status='consumed',until_date=DATE_SUB(CURRENT_DATE,INTERVAL 1 DAY) WHERE id=?")->execute([$stopped,$futureBooking['id']]);
 Service::command('contract.cancel',['id'=>$stopped,'version'=>1,'reason'=>'Оформление остановлено','command_key'=>commandKey()]);
 $bid=$futureBooking['id'];
 $ver=Service::get($p,'reservations',$bid)['version'];
 $reopen=['id'=>$bid,'version'=>$ver,'until_date'=>$end,'still_interested'=>'1','reason'=>'Клиент всё ещё заинтересован','command_key'=>commandKey()];
 denyBooking(fn()=>Service::command('reservation.reopen',$reopen),'Исходный срок истёк');
 $p->prepare('UPDATE reservations SET until_date=CURRENT_DATE WHERE id=?')->execute([$bid]);
 $opened=Service::command('reservation.reopen',$reopen);
 $row=Service::get($p,'reservations',$bid);
 expectBooking($row['status']==='active'&&$row['contract_id']===null&&$row['until_date']===$start&&Service::command('reservation.reopen',$reopen)===$opened,
     'Reopening on inclusive deadline or command repeat failed');
 expectBooking(str_contains(CustomerBookings::contractOrigin(Service::get($p,'contracts',$stopped)),'№'.$bid),'Historic contract lost the booking origin');
 Service::command('reservation.cancel',['id'=>$bid,'version'=>$row['version'],'client_declined'=>'1','reason'=>'Позвонил','command_key'=>commandKey()]);
 $row=Service::get($p,'reservations',$bid);
 expectBooking(str_starts_with(Importer::decodeExact($row['source_json'])['cancellation']['reason'],'Клиент отказался'),'Explicit decline reason lost');
 $denied=$p->query("SELECT id,auth_version FROM users WHERE role='accountant' AND active=1 ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$denied['id'],'auth_version'=>$denied['auth_version']];
 try{Service::command('reservation.create',array_replace($firstInput,['command_key'=>commandKey()]));throw new LogicException('Accountant created booking');}catch(RuntimeException $e){expectBooking($e->getCode()===403,'Wrong forbidden-role response');}
 echo "PASS: overlapping client bookings/replay/counts, selected conversion, no early cancellation, activation cancellation/history, no second active lease, unchanged and adding DS, dated catch-up/replay, inclusive reopen and expired refusal, explicit decline, document-hold and role guards; rolled back.\n";
}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine().PHP_EOL);$failed=true;}finally{if($p->inTransaction())$p->rollBack();}
if(!empty($failed))exit(1);
