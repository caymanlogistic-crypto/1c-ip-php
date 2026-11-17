<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{LocalWorkflowMax,LocalMaxReadCheck};
$check=static function(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);};
$scope=['event_floor'=>1083,'contract_ids'=>[970425],'future_contracts_after'=>970425];
$event=['event_id'=>1084,'entity_kind'=>'contract','contract_id'=>970425,'event_kind'=>'resubmit'];
$check(LocalWorkflowMax::eventAllowed($scope,$event),'New selected contract event rejected');
foreach([['event_id'=>1083],['contract_id'=>1016],['entity_kind'=>'party'],['event_kind'=>'block'],['event_kind'=>'activated']] as $bad)
 $check(!LocalWorkflowMax::eventAllowed($scope,array_replace($event,$bad)),'Out-of-scope event accepted');
$check(LocalWorkflowMax::eventAllowed($scope,array_replace($event,['contract_id'=>970426])),'Future contract event rejected');
$check(!LocalWorkflowMax::eventAllowed($scope,array_replace($event,['entity_kind'=>'document'])),'Addendum outside contract scope admitted');
$check(LocalWorkflowMax::eventAllowed($scope,array_replace($event,['event_kind'=>'cancelled'])),'Selected contract cancellation rejected');
$p=App\DB::pdo();$check($p->query('SELECT DATABASE()')->fetchColumn()==='ipphp_task_menu_refresh','Own isolated schema required');
$count=(int)$p->query("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max'")->fetchColumn();
$q=$p->query("SELECT id,auth_version FROM users WHERE id=3");$u=$q->fetch();$_SESSION=['user_id'=>3,'auth_version'=>$u['auth_version']];
$_SERVER['REQUEST_METHOD']='POST';
$local=new LocalWorkflowMax($p);
$check($local->plan(1083,'old-event',App\Auth::user())===null,'Historical event was admitted');
$_SERVER['REQUEST_METHOD']='GET';$denied=false;try{$local->plan(1084,'none',App\Auth::user());}catch(RuntimeException){$denied=true;}
$check($denied,'GET admitted');
$_SERVER['REQUEST_METHOD']='POST';
$denied=false;try{(new LocalMaxReadCheck($p))->dispatchManual(3,'unauthorized-test',[], 'must not send');}catch(RuntimeException){$denied=true;}
$check($denied,'Manual send enabled by workflow scope');
$p->beginTransaction();$denied=false;try{$local->plan(1084,'none',App\Auth::user());}catch(RuntimeException){$denied=true;}finally{$p->rollBack();}
$check($denied,'Uncommitted workflow admitted');
$check((int)$p->query("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max'")->fetchColumn()===$count,'Guard test created an attempt');
echo "PASS local workflow: new/old events, selected/future contracts, denied kind/GET/transaction/manual-send; no transport attempts\n";
