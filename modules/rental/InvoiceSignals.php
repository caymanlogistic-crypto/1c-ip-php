<?php
declare(strict_types=1);
namespace Modules\Rental;
use PDO;
use App\Auth;
use RuntimeException;
final class InvoiceSignals
{
 /** Existing authenticated menu poll only; never joins a caller-owned business transaction. */
 public static function refreshForRequest(PDO $p,?string $date=null,?string $time=null):array
 {
  $date??=date('Y-m-d');$time??=date('H:i');Service::date($date);
  if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new RuntimeException('Некорректное время.');
  if($time<'13:00')return ['state'=>'BEFORE_CONTROL_TIME'];
  $actor=Auth::user();
  if(!$actor||!in_array($actor['role'],['accountant','admin'],true)||!Auth::can('rental.read')||!Auth::can('finance.write'))return ['state'=>'NOT_AUTHORIZED'];
  try{\App\PagePolicy::requireCommand('invoice.issue');}catch(RuntimeException $e){if($e->getCode()===403)return ['state'=>'NOT_AUTHORIZED'];throw $e;}
  if($p->inTransaction())return ['state'=>'OUTER_TRANSACTION'];
  try{$calendar=SourceCalendar::registered();}catch(RuntimeException $error){
   if($error instanceof \PDOException)throw $error;
   $calendar=null;
  }
  if(!$calendar||$calendar->metadata()['admission']!=='LOCAL_TEST')return ['state'=>'PAYMENT_CONTROL_REQUIRES_CALENDAR'];
  $p->beginTransaction();
  try{
   $result=self::refresh($p,$date,$time,$calendar,true);
   $p->commit();
   return ['state'=>'REFRESHED','date'=>$date,'signals'=>count($result['plan']),'new_events'=>count($result['applied'])];
  }catch(\Throwable $error){
   if($p->inTransaction())$p->rollBack();
   if($error instanceof RuntimeException&&!($error instanceof \PDOException)&&str_starts_with($error->getMessage(),'CALENDAR_UNKNOWN:'))
    throw new RuntimeException('Даты контроля оплаты не покрыты подтверждённым рабочим календарём.',422,$error);
   throw $error;
  }
 }
 public static function cached(PDO $p,array $invoice):string{$q=$p->prepare("SELECT fact_json FROM rental_facts WHERE namespace='invoice_signal' AND source_key=?");$q->execute([(string)$invoice['id']]);$row=$q->fetchColumn();if($row!==false)return json_decode($row,true)['lastSeverity']??'';$raw=json_decode($invoice['source_json'],true);$q=$p->query("SELECT fact_json FROM rental_facts WHERE namespace='state' AND source_key='invoiceSignals'");$state=json_decode($q->fetchColumn()?:'{}',true);return $state[$invoice['id']]['lastSeverity']??$raw['lastSeverity']??'';}
 public static function refresh(PDO $p,string $date,string $time,SourceCalendar $calendar,bool $apply=false):array
 {
  Service::date($date);if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new RuntimeException('Некорректное время.');if($apply){Auth::requirePermission('rental.read');Auth::requirePermission('finance.write');Service::roles(['accountant']);\App\PagePolicy::requireCommand('invoice.issue');if(!$p->inTransaction()||$calendar->metadata()['admission']!=='LOCAL_TEST')throw new RuntimeException('WORKDAY_ADMISSION_REQUIRED');$p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();}$plan=[];$blocked=[];$done=[];if($time<'13:00')return ['plan'=>[],'blocked'=>[],'applied'=>[],'beforeControlTime'=>true];
  $now=new \DateTimeImmutable($date.' '.$time,new \DateTimeZone('Europe/Moscow'));
  foreach($p->query("SELECT * FROM invoices WHERE status='active' AND control_closed=0 ORDER BY id")->fetchAll() as $i){
   $i=PaymentReview::attach($p,$i,$now);
   $raw=json_decode($i['source_json'],true);
   if($i['manual_review']||!empty($raw['issueError'])||!empty($raw['mismatch'])||!empty($raw['sendError']))continue;
   $severity=InvoiceFacts::overdueSeverity($i,$date);
   if($severity)$plan[]=['invoice_id'=>(int)$i['id'],'contract_id'=>(int)$i['contract_id'],'number'=>$i['number'],'severity'=>$severity,'remaining'=>InvoiceFacts::remaining($i),'date'=>$date,'calendar_reference'=>SourceCalendar::REFERENCE];
  }
  if($apply&&$blocked)throw new RuntimeException('CALENDAR_UNKNOWN: required payment control dates are outside the admitted reference.');
  if($apply)foreach($plan as $row){$p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('invoice_signal',?,?,1) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")->execute([(string)$row['invoice_id'],Importer::json($row+['lastSeverity'=>$row['severity']])]);$key='debt:'.$row['invoice_id'].':'.$row['severity'];$q=$p->prepare('INSERT IGNORE INTO rental_schedule_runs(run_key,action,details_json) VALUES(?,?,?)');$q->execute([$key,'control.signal',Importer::json($row)]);if($q->rowCount()){Service::event($p,Auth::user(),'control.signal','Контроль оплаты счёта '.$row['number'].': остаток '.$row['remaining'].' ₽',$row['contract_id']);$done[]=$key;}}
  return ['plan'=>$plan,'blocked'=>$blocked,'applied'=>$done,'beforeControlTime'=>false,'externalSends'=>0,'production_eligible'=>false];
 }
}
