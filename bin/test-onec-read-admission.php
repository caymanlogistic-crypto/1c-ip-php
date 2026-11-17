<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{OnecReadAdmission,Config,Support};
if(!str_starts_with(App\DB::pdo()->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_'))throw new RuntimeException('ISOLATED_TASK_DB_REQUIRED');
$file=APP_ROOT.'/.local/onec-read-admission.json';
if(file_exists($file))throw new RuntimeException('EXISTING_ADMISSION_DO_NOT_OVERWRITE');
$secretFile=APP_ROOT.'/private/integrations/onec.enc';
if(file_exists($secretFile))throw new RuntimeException('EXISTING_SECRET_DO_NOT_OVERWRITE');
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
 // Exercise the actual worker gate without a factory/global bypass or any network.
 // The deliberately absent state must be reached only by an admitted read.
 require_once APP_ROOT.'/modules/integrations/worker.php';
 $dummy=['username'=>'isolated-onec-test','password'=>'test-only-no-network'];
 Config::saveProfile('onec',$public['access_reference'],$dummy);
 $input=['provider'=>'onec','operation'=>'authenticate','credentials'=>$dummy,
     'profile_reference'=>$public['access_reference'],'remaining_ms'=>1000,
     'state'=>APP_ROOT.'/private/absent-onec-admission-test-state'];
 check(!is_dir($input['state']),'TEST_STATE_MUST_BE_ABSENT');
 $expect=function(array $request,string $expected):void{
     try{\IpyaAccess\run($request);throw new LogicException('WORKER_UNEXPECTED_SUCCESS');}
     catch(RuntimeException$e){check($e->getMessage()===$expected,'WORKER_GATE_'.$expected.'_'.$e->getMessage());}
 };
 foreach(['authenticate','records']as$operation)$expect(array_replace($input,['operation'=>$operation]),'FRESH_PRIVATE_STATE_REQUIRED');
 $expect(array_replace($input,['operation'=>'archive_invoices']),'ACCESS_DENIED');
 $expect(array_replace($input,['credentials'=>array_replace($dummy,['password'=>'wrong'])]),'ACCESS_DENIED');
 $other=$input;$other['provider']='pik';unset($other['profile_reference']);$expect($other,'ACCESS_DENIED');
 unlink($file);$expect($input,'ACCESS_DENIED');file_put_contents($file,Support::json($marker));
 file_put_contents($file,Support::json(array_replace($marker,['profile_reference'=>'other'])));
 check(!OnecReadAdmission::allows($db,'onec','check'),'CHANGED_PROFILE_DENIED');
 file_put_contents($file,Support::json($marker+['extra'=>true]));
 try{OnecReadAdmission::settings();throw new LogicException('INVALID_MARKER_ACCEPTED');}catch(RuntimeException$e){check($e->getMessage()==='ONEC_READ_ADMISSION_INVALID','INVALID_MARKER_DENIED');}
 putenv('APP_STAND=integrations');check(OnecReadAdmission::settings()===null,'OTHER_STAND_DENIED');
 echo "OneC bounded admission and actual worker gate: reads allowed; writes/providers/secrets/stale profile/invalid marker denied; no network PASS\n";
}finally{if(is_file($file))unlink($file);if(is_file($secretFile))unlink($secretFile);$oldStand===false?putenv('APP_STAND'):putenv('APP_STAND='.$oldStand);}
