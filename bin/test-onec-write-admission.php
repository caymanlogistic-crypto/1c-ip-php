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
 // A verified journal result must publish one typed reference for subsequent pages.
 // All rows below are transaction-local fixtures; no transport is called.
 $insert=static function(string $table,array $row)use($db):void{$db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));};
 $store=Config::store();$preparedId=Support::uuid();$intentId=Support::uuid();$nativeKey=Support::uuid();$request=Support::uuid();$fence=bin2hex(random_bytes(32));
 $actor=(int)$db->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
 $payload=['entity_type'=>'legal_entity','name'=>'Isolated demo','full_name'=>'Isolated demo','inn'=>'9999011015','kpp'=>'999901001'];
 $body=OnecMapping::build('counterparty',$payload,$nativeKey,$profile);$bodyRaw=OnecMapping::json($body);$bodySha=hash('sha256',$bodyRaw);
 $packetPath=$store->put('onec-admission-test',Support::json(['fixture'=>'isolated-saved-journal']));
 $prepared=['id'=>$preparedId,'source_instance'=>$source['source_instance'],'source_namespace'=>'test-native','logical_document_id'=>'party:991991','step_id'=>$intentId,'kind'=>'counterparty','request_key'=>$request,'actor_id'=>$actor,'workflow_epoch'=>'isolated-test','workflow_revision'=>1,'record_kind'=>'contract','record_id'=>1,'record_version'=>1,'attempt_fence'=>Support::uuid(),'packet_path'=>$packetPath,'packet_sha256'=>basename($packetPath),'context_path'=>$packetPath,'confirmation_ref'=>'isolated-fixture'];
 $insert('integration_native_prepared',$prepared);
 $facts=$body+['DataVersion'=>'fixture-1','Code'=>'DEMO','DeletionMark'=>false];$rawFacts=OnecMapping::json($facts);
 $context=['attempt_id'=>$intentId,'source_instance'=>$source['source_instance'],'native_key'=>$nativeKey,'request_key'=>$request,'body_sha256'=>$bodySha,'post_fence'=>$fence];
 $ack=$store->spool($context+['kind'=>'transport_receipt','http_status'=>201],$rawFacts);$store->linkReceipt($intentId,$ack);
 $read=$store->spool($context+['kind'=>'native-read-receipt','stage'=>'readback-test','http_status'=>200],$rawFacts);
 $intent=['id'=>$intentId,'project_id'=>CaptureReader::PROJECT,'source_instance'=>$source['source_instance'],'prepared_id'=>$preparedId,'request_key'=>$request,'actor_id'=>$actor,'kind'=>'counterparty','native_key'=>$nativeKey,'command_sha256'=>str_repeat('a',64),'profile_sha256'=>$source['profile_sha256'],'binding_sha256'=>str_repeat('b',64),'prepared_sha256'=>basename($packetPath),'payload_path'=>$store->put('onec-admission-test',OnecMapping::json($payload)),'body_path'=>$store->put('onec-admission-test',$bodyRaw),'body_sha256'=>$bodySha,'prepared_path'=>$packetPath,'state'=>'saved','post_fence'=>$fence,'post_started'=>1,'ack_path'=>$ack,'readback_path'=>$read,'native_number'=>'DEMO','native_version'=>'fixture-1'];
 $insert('integration_native_intents',$intent);
 $bindings=new Modules\Integrations\OnecBindings($db,$store);$binding=$bindings->recordSavedReference($intentId);
 ensureWrite($bindings->recordSavedReference($intentId)===$binding,'SAVED_REFERENCE_REPLAY_NO_DUPLICATE');
 $observed=$bindings->observation($source,'parties','991991','counterparty');ensureWrite($observed['reference']['key']===$nativeKey,'SAVED_REFERENCE_USABLE_BY_NEXT_PAGE');
 $newRaw=OnecMapping::json(array_replace($facts,['DataVersion'=>'fixture-2']));$newPath=$store->put('onec-admission-test',$newRaw);
 $newProof=$store->spool(['kind'=>'native-reference-get','method'=>'GET','http_status'=>200,'source_instance'=>$source['source_instance'],'native_type'=>OnecMapping::REFS['counterparty'],'native_key'=>$nativeKey],$newRaw);
 $db->prepare('UPDATE integration_native_bindings SET native_version=?,evidence_path=?,evidence_sha256=?,evidence_hmac_path=? WHERE id=?')->execute(['fixture-2',$newPath,hash('sha256',$newRaw),$newProof,$binding]);
 $bindings->recordSavedReference($intentId);ensureWrite($bindings->observation($source,'parties','991991','counterparty')['reference']['version']==='fixture-2','REPLAY_PRESERVES_NEWER_OBSERVATION');
 $bad=$store->spool(array_replace($context,['native_key'=>Support::uuid()])+['kind'=>'native-read-receipt','stage'=>'readback-test','http_status'=>200],$rawFacts);
 $db->prepare('UPDATE integration_native_intents SET readback_path=? WHERE id=?')->execute([$bad,$intentId]);
 try{$bindings->recordSavedReference($intentId);throw new LogicException('WRONG_READBACK_ACCEPTED');}catch(RuntimeException $e){ensureWrite($e->getMessage()==='ONEC_SAVED_REFERENCE_SCOPE_CHANGED','READBACK_SCOPE_GUARD');}
 $db->prepare('UPDATE integration_native_intents SET readback_path=? WHERE id=?')->execute([$read,$intentId]);
 $foreignKey=Support::uuid();$db->prepare('UPDATE integration_native_bindings SET native_key=? WHERE id=?')->execute([$foreignKey,$binding]);
 try{$bindings->recordSavedReference($intentId);throw new LogicException('FOREIGN_BINDING_OVERWRITTEN');}catch(RuntimeException $e){ensureWrite($e->getMessage()==='ONEC_SAVED_REFERENCE_CONFLICT','EXISTING_BINDING_CONFLICT_GUARD');}
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
