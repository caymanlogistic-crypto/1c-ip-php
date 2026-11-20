<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\{DB,Auth};
use PDO;
use RuntimeException;
final class Scheduler
{
    /** Calendar-independent signed terms and physical booking deadlines. No external delivery. */
    public static function dated(?string $date=null,bool $apply=false):array
    {
        $date=Service::date($date??date('Y-m-d'));
        if($apply){Auth::requirePermission('rental.read');Auth::requirePermission('finance.write');Service::roles(['accountant']);\App\PagePolicy::requireCommand('document.apply');}
        $plan=[];foreach(Controller::query("SELECT * FROM rental_documents WHERE status='signed' AND applied=0 AND effective_date<=? ORDER BY effective_date,id",[$date]) as $d)$plan[]=['key'=>'document-effective:'.$d['id'],'action'=>'document.effective','id'=>(int)$d['id'],'version'=>(int)$d['version'],'contract_id'=>(int)$d['contract_id']];
        $tomorrow=(new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $reservationNotices=[];
        $reservations=Controller::query("SELECT r.*,o.name object_name FROM reservations r JOIN rental_objects o ON o.id=r.object_id "
            ."WHERE r.document_id IS NULL AND r.contract_id IS NULL AND r.status IN ('active','expired') AND r.until_date<=? AND "
            .WorkspaceReset::visibleSql('r')." ORDER BY r.id",[$tomorrow]);
        foreach ($reservations as $reservation) {
            $expired=CustomerBookings::expired($reservation,$date);
            if (!$expired && $reservation['until_date']!==$tomorrow) continue;
            $kind=$expired?'review':'reminder';
            $reservationNotices[]=[
                'key'=>'reservation:'.$kind.':'.$reservation['id'].':'.$reservation['until_date'],
                'action'=>'reservation.'.$kind,
                'id'=>(int)$reservation['id'],
                'author_id'=>$reservation['author_id'],
                'text'=>'Резервирование №'.$reservation['id'].' · '.$reservation['object_name'].' · '
                    .($expired?'срок истёк. Продлите или снимите резервирование.':'истекает завтра, '.Documents::dateText($tomorrow).'.'),
            ];
        }
        if(count($plan)>5000)throw new RuntimeException('План превышает безопасный лимит.');$done=[];$blocked=[];
        if($apply)DB::transaction(function(PDO $p)use($plan,$date,&$done,&$blocked){$p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();foreach($plan as $item){$p->exec('SAVEPOINT rental_dated_event');try{
            $q=$p->prepare('SELECT 1 FROM rental_schedule_runs WHERE run_key=?');$q->execute([$item['key']]);if($q->fetchColumn())continue;
            if($item['action']==='document.effective'){$d=Service::get($p,'rental_documents',$item['id']);if($d['status']!=='signed'||$d['applied']||$d['effective_date']>$date||(int)$d['version']!==$item['version'])continue;$c=Service::get($p,'contracts',(int)$d['contract_id']);if($c['status']!=='active'||$d['base_hash']!==Evidence::documentBase($p,(int)$c['id']))throw new RuntimeException('Исходные условия изменились после подписания ДС.');$signedFile=Evidence::latest($p,'document',(int)$d['id'],'signed-addendum',(int)$d['version']-1);Evidence::signedDocumentParts($p,$d,$signedFile);
                if($d['kind']==='termination')$p->prepare("UPDATE contracts SET status='reconciling',finish_date=?,finish_reason=?,version=version+1 WHERE id=?")->execute([$d['effective_date'],'Подписанное соглашение о завершении',$c['id']]);else Addenda::apply($p,$c,$d,'document.apply',Auth::user(),'parts-apply-'.hash('sha256',$item['key'].':'.$item['version']));
                $p->prepare('UPDATE rental_documents SET applied=1,version=version+1 WHERE id=?')->execute([$d['id']]);Service::event($p,Auth::user(),'document.effective','Наступила дата подписанного ДС №'.$d['number'],(int)$c['id']);
            }
            $p->prepare('INSERT INTO rental_schedule_runs(run_key,action,details_json) VALUES(?,?,?)')->execute([$item['key'],$item['action'],Importer::json($item+['effective_on'=>$date])]);$done[]=$item['key'];
        }catch(RuntimeException $e){$p->exec('ROLLBACK TO SAVEPOINT rental_dated_event');if($e->getCode()===403)throw $e;$blocked[]=['key'=>$item['key'],'reason'=>$e->getMessage()];}}});
        return ['mode'=>$apply?'applied':'plan','date'=>$date,'calendarRequired'=>false,'externalSends'=>0,
            'plan'=>$plan,'reservationNotices'=>$reservationNotices,'applied'=>$done,'blocked'=>$blocked];
    }
    public static function run(?string $date=null,?string $time=null,bool $apply=false,bool $catchup=false,?SourceCalendar $reference=null):array
    {
        $date=Service::date($date??date('Y-m-d'));$time??=date('H:i');$reference??=SourceCalendar::registered();if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new RuntimeException('Некорректное время.');
        if($apply){Auth::requirePermission('rental.read');Auth::requirePermission('finance.write');Service::roles(['accountant']);\App\PagePolicy::requireCommand('invoice.issue');if(!$reference||$reference->metadata()['admission']!=='LOCAL_TEST'||!DB::pdo()->inTransaction())throw new RuntimeException('WORKDAY_ADMISSION_REQUIRED: начисления доступны только в изолированной транзакции LOCAL_TEST; производственный календарь не допущен.');}
        $periods=[];$current=substr($date,0,7);$next=(new \DateTimeImmutable($date))->modify('first day of next month')->format('Y-m');$blocked=[];foreach([$current,$next] as $candidate){try{if(!$reference)throw new RuntimeException('CALENDAR_REFERENCE_UNAVAILABLE');$issue=$reference->issueDate($candidate);if($candidate===$current||$date>=$issue)$periods[]=$candidate;}catch(RuntimeException $e){$blocked[]=['period'=>$candidate,'reason'=>$e->getMessage(),'scope'=>'calendar'];}}if($apply&&$blocked)throw new RuntimeException('CALENDAR_UNKNOWN: required invoice issue dates are outside the admitted reference.');        $plan=[];foreach(Controller::query("SELECT * FROM contracts WHERE status IN ('active','reconciling') ORDER BY id") as $c){$raw=json_decode($c['source_json'],true);if(!empty($raw['imported'])){$blocked[]=['contract_id'=>(int)$c['id'],'reason'=>'IMPORTED_COVERAGE_REVIEW_REQUIRED'];continue;}foreach($periods as $period){if(!$c['start_date']||!$c['end_date']||$c['end_date']<$period.'-01'||substr($c['start_date'],0,7)>$period)continue;try{$row=MonthlyBilling::plan(DB::pdo(),$c,$period);if(MonthlyBilling::hasTargets($row))$plan[]=$row+['action'=>'invoice.monthly','contract_id'=>(int)$c['id'],'contract_version'=>(int)$c['version']];}catch(RuntimeException $e){$blocked[]=['contract_id'=>(int)$c['id'],'period'=>$period,'reason'=>$e->getMessage()];}}}
        if(count($plan)>5000)throw new RuntimeException('План превышает безопасный лимит.');$done=[];if($apply)DB::transaction(function(PDO $p)use($plan,$date,&$done){$p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();foreach($plan as $item){$c=Service::get($p,'contracts',$item['contract_id']);if((int)$c['version']!==$item['contract_version']||!in_array($c['status'],['active','reconciling'],true))throw new RuntimeException('Условия договора изменились после расчёта плана.',409);$current=MonthlyBilling::plan($p,$c,$item['period']);if(!MonthlyBilling::hasTargets($current))continue;if(Importer::canonicalJson($current)!==Importer::canonicalJson(array_intersect_key($item,$current)))throw new RuntimeException('Покрытие счетов изменилось после расчёта плана.',409);$id=MonthlyBilling::issue($p,$c,$current,$date);$key='monthly:'.$current['key'];$p->prepare('INSERT IGNORE INTO rental_schedule_runs(run_key,action,details_json) VALUES(?,?,?)')->execute([$key,'invoice.monthly',Importer::json($item+['invoice_id'=>$id,'calendar_reference'=>SourceCalendar::REFERENCE,'admission'=>'LOCAL_TEST'])]);Service::event($p,Auth::user(),'invoice.monthly','Выставлен счёт Д-'.$id.' за '.$item['period'],(int)$c['id']);$done[]=$key;}});
        return ['mode'=>$apply?'applied':'plan','date'=>$date,'time'=>$time,'catchupRequested'=>$catchup,'catchupPolicy'=>'bounded_current_and_next_period_only','calendar'=>$reference?->metadata()??['admission'=>'UNAVAILABLE','production_eligible'=>false],'backgroundEnabled'=>false,'externalSends'=>0,'plan'=>$plan,'applied'=>$done,'blocked'=>$blocked,'datedEvents'=>self::dated($date,false)];
    }
}
