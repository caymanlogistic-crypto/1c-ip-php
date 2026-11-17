<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{Config,Support,OnecWriteAdmission,OnecMapping,CaptureReader};
$db=App\DB::pdo();
if(!str_starts_with($db->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_'))throw new RuntimeException('ISOLATED_TASK_DB_REQUIRED');
if(Config::liveAllowed()||getenv('IPPHP_ALLOW_ONEC_WRITE'))throw new RuntimeException('GLOBAL_GATES_MUST_BE_CLOSED');
$markerFile=APP_ROOT.'/.local/onec-write-admission.json';$secretFile=APP_ROOT.'/private/integrations/onec.enc';
if(file_exists($markerFile)||file_exists($secretFile)||$db->query('SELECT COUNT(*) FROM integration_native_sources')->fetchColumn())throw new RuntimeException('EXISTING_CONFIG_DO_NOT_OVERWRITE');
function ensureWrite(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
foreach(['organization','currency']as$field){
 ensureWrite(OnecMapping::referenceFlags(OnecMapping::REFS[$field],['DeletionMark'=>false]),'NON_HIERARCHICAL_REFERENCE');
 ensureWrite(!OnecMapping::referenceFlags(OnecMapping::REFS[$field],['DeletionMark'=>false,'IsFolder'=>null]),'NULL_FOLDER_DENIED');
}
foreach(['counterparty','contract','item']as$field){
 ensureWrite(!OnecMapping::referenceFlags(OnecMapping::REFS[$field],['DeletionMark'=>false]),'MISSING_HIERARCHICAL_FLAG_DENIED');
 ensureWrite(OnecMapping::referenceFlags(OnecMapping::REFS[$field],['DeletionMark'=>false,'IsFolder'=>false]),'HIERARCHICAL_RECORD');
 ensureWrite(!OnecMapping::referenceFlags(OnecMapping::REFS[$field],['DeletionMark'=>false,'IsFolder'=>true]),'FOLDER_DENIED');
}
ensureWrite(!OnecMapping::referenceFlags(OnecMapping::REFS['currency'],['DeletionMark'=>true]),'DELETED_REFERENCE_DENIED');
$contractFacts=['Организация_Key'=>'org','Owner_Key'=>'buyer','ВалютаВзаиморасчетов_Key'=>'rub','ВидДоговора'=>'СПокупателем'];
ensureWrite(OnecMapping::customerContract($contractFacts,'org','buyer','rub'),'REVIEWED_CONTRACT_WITHOUT_CLOSED_FIELD');
foreach([['Закрыт'=>true],['Закрыт'=>null],['Owner_Key'=>'other'],['ВалютаВзаиморасчетов_Key'=>'other'],['ВидДоговора'=>'СПоставщиком']]as$changed)ensureWrite(!OnecMapping::customerContract(array_replace($contractFacts,$changed),'org','buyer','rub'),'WRONG_OR_CLOSED_CONTRACT_DENIED');
$oldStand=getenv('APP_STAND');putenv('APP_STAND=ui');$db->beginTransaction();
try{
 $c=Config::get($db,'onec');$public=Support::decode($c['config_json']);$reference=$public['access_reference'];
 $selected=$db->query("SELECT source_receipt_sha256 FROM integration_configuration_sources WHERE provider='onec'")->fetchColumn();
 $profile=OnecMapping::profile();$raw=OnecMapping::json($profile);$path=Config::store()->put('onec-admission-test',$raw);
 $source=['source_instance'=>'onec-write-admission-test','project_id'=>CaptureReader::PROJECT,'workflow_namespace'=>'test-native',
 'profile_ref'=>$reference,'organization_key'=>OnecMapping::ORGANIZATION,'organization_inn'=>'390613939405',
 'profile_path'=>$path,'profile_file_sha256'=>hash('sha256',$raw),'profile_sha256'=>OnecMapping::profileHash($profile),
 'config_receipt_sha256'=>$selected,'freeze_sha256'=>hash('sha256',$raw),'source_version'=>str_repeat('a',40),'admission_ref'=>'isolated-test','enabled'=>1,'revision'=>1];
 $db->prepare('INSERT INTO integration_native_sources('.implode(',',array_keys($source)).') VALUES('.implode(',',array_fill(0,count($source),'?')).')')->execute(array_values($source));
 $policy=['enabled'=>true,'project_id'=>CaptureReader::PROJECT,'source_instance'=>$source['source_instance'],'organization_key'=>OnecMapping::ORGANIZATION,'organization_inn'=>'390613939405'];
 Config::saveProfile('onec',$reference,['username'=>'test-no-network','password'=>'test-only','write_policy'=>$policy]);
 $marker=['enabled'=>true,'project_id'=>CaptureReader::PROJECT,'stand'=>'ui','scope'=>'prepared-counterparty-customer-invoice-create','source_instance'=>$source['source_instance'],'profile_reference'=>$reference,'connection_revision'=>(int)$c['revision'],'source_revision'=>1];
 file_put_contents($markerFile,Support::json($marker));
 ensureWrite(OnecWriteAdmission::available($db),'PREPARED_ACCESS');
 foreach(OnecMapping::COLLECTIONS as$collection){ensureWrite(OnecWriteAdmission::allows($db,$source,$reference,'POST',$collection),'CREATE_ALLOWED');ensureWrite(OnecWriteAdmission::allows($db,$source,$reference,'GET',$collection),'PREFLIGHT_READBACK_ALLOWED');}
 foreach(['PUT','PATCH','DELETE']as$method)ensureWrite(!OnecWriteAdmission::allows($db,$source,$reference,$method,OnecMapping::COLLECTIONS['counterparty']),'UPDATE_DELETE_DENIED');
 ensureWrite(!OnecWriteAdmission::allows($db,$source,$reference,'POST',OnecMapping::REFS['contract']),'UNRELATED_CREATE_DENIED');
 ensureWrite(!OnecWriteAdmission::allows($db,$source,'other','POST',OnecMapping::COLLECTIONS['counterparty']),'OTHER_PROFILE_DENIED');
 ensureWrite(!OnecWriteAdmission::allows($db,array_replace($source,['source_instance'=>'other']),$reference,'POST',OnecMapping::COLLECTIONS['counterparty']),'OTHER_SOURCE_DENIED');
 file_put_contents($markerFile,Support::json(array_replace($marker,['connection_revision'=>$marker['connection_revision']+1])));ensureWrite(!OnecWriteAdmission::available($db),'STALE_CONNECTION_DENIED');
 file_put_contents($markerFile,Support::json(array_replace($marker,['source_revision'=>2])));ensureWrite(!OnecWriteAdmission::available($db),'STALE_SOURCE_DENIED');
 file_put_contents($markerFile,Support::json($marker));
 Config::saveProfile('onec',$reference,['write_policy'=>array_replace($policy,['enabled'=>false])]);ensureWrite(!OnecWriteAdmission::available($db),'DISABLED_PRIVATE_POLICY_DENIED');
 unlink($markerFile);ensureWrite(!OnecWriteAdmission::available($db),'ABSENT_MARKER_DENIED');
 putenv('APP_STAND=integrations');file_put_contents($markerFile,Support::json($marker));ensureWrite(OnecWriteAdmission::settings()===null,'OTHER_STAND_DENIED');
 ensureWrite(!Config::liveAllowed(),'GENERAL_ACCESS_STAYS_CLOSED');
 echo "Scoped OneC prepared CREATE: current binding/policy, GET preflight/readback, denied updates/providers/profiles/stale revisions, no network PASS\n";
}finally{$db->rollBack();if(is_file($markerFile))unlink($markerFile);if(is_file($secretFile))unlink($secretFile);$oldStand===false?putenv('APP_STAND'):putenv('APP_STAND='.$oldStand);}
