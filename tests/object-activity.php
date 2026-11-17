<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{DirectoryScope,ObjectActivity,Service};
use Modules\Integrations\ObjectActivityMax;
$db=DB::pdo();
if (!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$session=$_SESSION ?? [];$server=$_SERVER;$today=date('Y-m-d');$yesterday=date('Y-m-d',strtotime('-1 day'));
$db->beginTransaction();
try {
    $admin=$db->query("SELECT id,auth_version FROM users WHERE active=1 AND role='admin' ORDER BY id LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$admin['id'],'auth_version'=>$admin['auth_version']];$_SERVER['REQUEST_METHOD']='POST';
    $parent=null;foreach($db->query('SELECT * FROM cadastre') as $c)if(DirectoryScope::realCadastre($c)&&DirectoryScope::cadastreAvailable($c)){$parent=$c['id'];break;}
    $id=(int)$db->query('SELECT MAX(id) FROM rental_objects')->fetchColumn()+1;
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,floor,cadastre_id,active,free_since,source_json) VALUES(?,'Object activity fixture','Офис',1,'2',?,1,?,'{}')")->execute([$id,$parent,$yesterday]);
    $row=Service::get($db,'rental_objects',$id);
    $input=['id'=>$id,'version'=>$row['version'],'name'=>$row['name'],'type'=>$row['type'],'area'=>$row['area'],'floor'=>$row['floor'],'cadastre_id'=>$parent,'active'=>0,'active_on'=>$yesterday,'command_key'=>bin2hex(random_bytes(16))];
    $fresh=123;Service::command('object.save',$input,$fresh);$check($fresh===null,'Nested transaction must not emit a fresh committed marker');
    $event=$db->query("SELECT * FROM rental_events WHERE action='object.save' ORDER BY id DESC LIMIT 1")->fetch();$proof=ObjectActivity::decode($event);
    $check($proof['object_id']===$id&&$proof['from_active']===1&&$proof['to_active']===0&&$proof['changed_on']===$yesterday,'Off event must contain actual transition and selected date');
    $check(str_contains($proof['text'],'неактивные')&&str_contains($proof['text'],'Object activity fixture'),'MAX text must identify transition and object');
    $tampered=$event;$body=json_decode($event['source_json'],true);$body['text']='forged';$tampered['source_json']=json_encode($body);
    $denied=false;try{ObjectActivity::decode($tampered);}catch(RuntimeException){$denied=true;}
    $check($denied,'Changed message payload must fail provenance validation');
    $count=(int)$db->query('SELECT COUNT(*) FROM rental_object_periods WHERE object_id='.$id)->fetchColumn();
    Service::command('object.save',$input,$fresh);
    $check((int)$db->query('SELECT COUNT(*) FROM rental_object_periods WHERE object_id='.$id)->fetchColumn()===$count,'Command replay must not duplicate periods');
    $stale=$input;$stale['command_key']=bin2hex(random_bytes(16));$denied=false;
    try{Service::command('object.save',$stale,$fresh);}catch(RuntimeException $e){$denied=$e->getCode()===409;}
    $check($denied,'A new command with stale version must fail');
    $denied=false;try{(new ObjectActivityMax($db))->afterCommittedCommand((int)$event['id'],$input['command_key'],App\Auth::user());}catch(RuntimeException $e){$denied=$e->getMessage()==='Object MAX requires commit.';}
    $check($denied,'MAX must never run inside the business transaction');
    $input['version']=Service::get($db,'rental_objects',$id)['version'];$input['active']=1;$input['active_on']=$today;$input['command_key']=bin2hex(random_bytes(16));
    Service::command('object.save',$input,$fresh);
    $event=$db->query("SELECT * FROM rental_events WHERE action='object.save' ORDER BY id DESC LIMIT 1")->fetch();$proof=ObjectActivity::decode($event);
    $check($proof['from_active']===0&&$proof['to_active']===1&&$proof['changed_on']===$today,'On event must contain actual selected date');
    $check(str_contains($proof['text'],'возвращён в активные'),'MAX on text');
    $periods=$db->query('SELECT * FROM rental_object_periods WHERE object_id='.$id.' ORDER BY id')->fetchAll();
    $check(count($periods)===2&&$periods[0]['started_on']===$yesterday&&$periods[0]['ended_on']===$today&&$periods[1]['started_on']===$today&&$periods[1]['ended_on']===null,'Period boundaries must use selected dates');
    $check(Service::get($db,'rental_objects',$id)['free_since']===$yesterday,'Status date must not overwrite idle date');
    $input['version']=Service::get($db,'rental_objects',$id)['version'];$input['command_key']=bin2hex(random_bytes(16));
    Service::command('object.save',$input,$fresh);
    $event=$db->query("SELECT * FROM rental_events WHERE action='object.save' ORDER BY id DESC LIMIT 1")->fetch();
    $check(ObjectActivity::decode($event)===null,'Ordinary save must not create a MAX event');
    foreach (['',date('Y-m-d',strtotime('+1 day')),$yesterday] as $invalid) {
        $input['version']=Service::get($db,'rental_objects',$id)['version'];$input['active']=0;$input['active_on']=$invalid;$input['command_key']=bin2hex(random_bytes(16));
        $denied=false;try{Service::command('object.save',$input,$fresh);}catch(RuntimeException){$denied=true;}
        $check($denied&&Service::get($db,'rental_objects',$id)['active']===1,'Missing/future/backdated period must fail without status change');
    }
    $viewer=$db->query("SELECT id,auth_version FROM users WHERE active=1 AND role='viewer' ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$viewer['id'],'auth_version'=>$viewer['auth_version']];
    $denied=false;try{Service::command('object.save',$input,$fresh);}catch(RuntimeException $e){$denied=$e->getCode()===403;}
    $check($denied,'Viewer must not change object activity');
} finally {$db->rollBack();$_SESSION=$session;$_SERVER=$server;}
echo "PASS: selected dates, period history, activation/deactivation, repeat/no-op, rights, MAX after-commit guard; fixtures rolled back\n";
