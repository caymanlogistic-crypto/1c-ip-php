<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{ServerWorkflowMax,Support};
$event=['project_id'=>Modules\Rental\WorkflowEvents::PROJECT,'epoch'=>'current-server-epoch','event_id'=>1094,'entity_kind'=>'contract','event_kind'=>'legalApprove','action'=>'contract.legal','to_status'=>'accounting'];
if(Modules\Integrations\CurrentContractMaxProof::select($event)!=='legalApprove')throw new RuntimeException('Current server epoch rejected');
$denied=false;try{Modules\Integrations\WorkflowMaxEvent::select($event);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Legacy rollout guard changed');
$m=['schema'=>1,'enabled'=>true,'project_id'=>Modules\Rental\WorkflowEvents::PROJECT,'scope'=>'contract-workflow','stand'=>'ui','database'=>'ipphp_ui','revision'=>6,'group_id'=>'d426f2fc-0acb-537b-9139-331fc45a76ce','group_name'=>'ТЕСТОВАЯ','chat_id'=>'-123','vault_sha256'=>str_repeat('a',64),'event_floor'=>1092,'catalog_versions'=>array_fill_keys(['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'],1)];
ServerWorkflowMax::validate($m);
foreach([['scope'=>'object-activity'],['database'=>'other'],['stand'=>'other'],['group_name'=>'other'],['enabled'=>false],['catalog_versions'=>['submit'=>1]]] as $bad){
 $denied=false;try{ServerWorkflowMax::validate(array_replace($m,$bad));}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Invalid scope accepted');}
$raw=Support::json(['text'=>'fixture']);ServerWorkflowMax::assertPath(0,'POST','https://platform-api2.max.ru/messages?chat_id=-123',$raw,'-123','fixture');
foreach([[1,'POST','https://platform-api2.max.ru/messages?chat_id=-123',$raw],[0,'GET','https://platform-api2.max.ru/messages?chat_id=-123',$raw],[0,'POST','https://platform-api2.max.ru/messages?chat_id=-456',$raw],[0,'POST','https://platform-api2.max.ru/messages?chat_id=-123','{}']] as $bad){
 $denied=false;try{ServerWorkflowMax::assertPath(...[...$bad,'-123','fixture']);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Invalid request accepted');}
$entries=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/contract-max-templates.json'),true,32,JSON_THROW_ON_ERROR);
if(count($entries)!==8)throw new RuntimeException('Expected eight approved templates');
foreach($entries as $e){if(preg_match('/проверьте|исправьте|контролируйте|устраните|создайте|сформируйте|откройте|подтвердите/ui',$e['body']))throw new RuntimeException('Call to action remains');}
echo "PASS server workflow MAX scope, recipient, one POST, body and approved templates\n";
