<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{OnecReadAdmission,Config,Support};
if(!str_starts_with(App\DB::pdo()->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_'))throw new RuntimeException('ISOLATED_TASK_DB_REQUIRED');
$file=APP_ROOT.'/.local/onec-read-admission.json';
if(file_exists($file))throw new RuntimeException('EXISTING_ADMISSION_DO_NOT_OVERWRITE');
$oldStand=getenv('APP_STAND');putenv('APP_STAND=ui');
function check(bool $condition,string $name):void{if(!$condition)throw new RuntimeException($name);}
try{
 $db=App\DB::pdo();check(!Config::liveAllowed(),'GLOBAL_GATE_MUST_STAY_CLOSED');
 check(!OnecReadAdmission::allows($db,'onec','check'),'MISSING_MARKER_DENIED');
 $public=Support::decode(Config::get($db,'onec')['config_json']);
 $marker=['enabled'=>true,'project_id'=>'f6e21e3c-d974-4604-82fb-333da66559d2','stand'=>'ui','provider'=>'onec','scope'=>'bounded-check-only','profile_reference'=>$public['access_reference']];
 file_put_contents($file,Support::json($marker));
 foreach(['check','authenticate','records']as$operation)check(OnecReadAdmission::allows($db,'onec',$operation),'READ_ALLOWED_'.$operation);
 foreach(['sync','archive_invoices','bank_records','send_code','dispatch','reconcile']as$operation)check(!OnecReadAdmission::allows($db,'onec',$operation),'WRITE_DENIED_'.$operation);
 foreach(['bank','diadoc','email','max','pik']as$provider)check(!OnecReadAdmission::allows($db,$provider,'check'),'PROVIDER_DENIED_'.$provider);
 check(!OnecReadAdmission::allows($db,'onec','authenticate',['_reference'=>'other']),'WRONG_SECRET_DENIED');
 file_put_contents($file,Support::json(array_replace($marker,['profile_reference'=>'other'])));
 check(!OnecReadAdmission::allows($db,'onec','check'),'CHANGED_PROFILE_DENIED');
 file_put_contents($file,Support::json($marker+['extra'=>true]));
 try{OnecReadAdmission::settings();throw new LogicException('INVALID_MARKER_ACCEPTED');}catch(RuntimeException$e){check($e->getMessage()==='ONEC_READ_ADMISSION_INVALID','INVALID_MARKER_DENIED');}
 putenv('APP_STAND=integrations');check(OnecReadAdmission::settings()===null,'OTHER_STAND_DENIED');
 echo "OneC bounded admission: allowed reads, denied writes/providers/secrets/stale profile/invalid marker PASS\n";
}finally{if(is_file($file))unlink($file);$oldStand===false?putenv('APP_STAND'):putenv('APP_STAND='.$oldStand);}
