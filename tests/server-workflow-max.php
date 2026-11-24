<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{CurrentContractMaxProof,ServerWorkflowMax,Support,TemplateCatalog,WorkflowMaxNames};
$event=['project_id'=>Modules\Rental\WorkflowEvents::PROJECT,'epoch'=>'current-server-epoch','event_id'=>1094,'entity_kind'=>'contract','event_kind'=>'legalApprove','action'=>'contract.legal','to_status'=>'accounting'];
if(Modules\Integrations\CurrentContractMaxProof::select($event)!=='legalApprove')throw new RuntimeException('Current server epoch rejected');
$denied=false;try{Modules\Integrations\WorkflowMaxEvent::select($event);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Legacy rollout guard changed');
$m=['schema'=>1,'enabled'=>true,'project_id'=>Modules\Rental\WorkflowEvents::PROJECT,'scope'=>'contract-workflow','stand'=>'ui','database'=>'ipphp_ui','revision'=>6,'group_id'=>'d426f2fc-0acb-537b-9139-331fc45a76ce','group_name'=>'ТЕСТОВАЯ','chat_id'=>'-123','vault_sha256'=>str_repeat('a',64),'event_floor'=>1092,'catalog_versions'=>array_fill_keys(['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'],1)];
ServerWorkflowMax::validate($m);
$question = $m + ['handover_question'=>['event_floor'=>1096,'catalog_version'=>1]];
ServerWorkflowMax::validate($question);
foreach ([['event_floor'=>1091,'catalog_version'=>1], ['event_floor'=>1096,'catalog_version'=>0]] as $badQuestion) {
    $denied=false;
    try { ServerWorkflowMax::validate(array_replace($question,['handover_question'=>$badQuestion])); }
    catch (RuntimeException) { $denied=true; }
    if (!$denied) throw new RuntimeException('Invalid question admission accepted');
}
foreach([['scope'=>'object-activity'],['database'=>'other'],['stand'=>'other'],['group_name'=>'other'],['enabled'=>false],['catalog_versions'=>['submit'=>1]]] as $bad){
 $denied=false;try{ServerWorkflowMax::validate(array_replace($m,$bad));}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Invalid scope accepted');}
$raw=Support::json(['text'=>'fixture']);ServerWorkflowMax::assertPath(0,'POST','https://platform-api2.max.ru/messages?chat_id=-123',$raw,'-123','fixture');
foreach([[1,'POST','https://platform-api2.max.ru/messages?chat_id=-123',$raw],[0,'GET','https://platform-api2.max.ru/messages?chat_id=-123',$raw],[0,'POST','https://platform-api2.max.ru/messages?chat_id=-456',$raw],[0,'POST','https://platform-api2.max.ru/messages?chat_id=-123','{}']] as $bad){
 $denied=false;try{ServerWorkflowMax::assertPath(...[...$bad,'-123','fixture']);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Invalid request accepted');}
$entries=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/contract-max-templates.json'),true,32,JSON_THROW_ON_ERROR);
if(count($entries)!==8)throw new RuntimeException('Expected eight approved templates');
foreach($entries as $e){if(preg_match('/проверьте|исправьте|контролируйте|устраните|создайте|сформируйте|откройте|подтвердите/ui',$e['body']))throw new RuntimeException('Call to action remains');}
$submit=$entries['submit'];
$variables=Modules\Integrations\TemplateCatalog::messageVariables('max','submit');
Modules\Integrations\TemplateCatalog::validateFormat($submit['format'],$submit['body'],$variables);
$entry=['source_key'=>'submit','format'=>$submit['format'],'body_text'=>$submit['body']];
$values=Modules\Integrations\MaxTemplateAdmin::sampleValues($entry);
$render=Modules\Integrations\TemplateCatalog::preview('max',$entry,$values);
if ($render['format']!=='html' || !str_contains($render['body'],"Объект: <b>2.2</b> (Машиноместо) — 10 м²\nОбъект: <b>2.4</b> (Машиноместо) — 10 м²")
    || !str_contains($render['body'],'с <b>23.11.2026</b> по <b>06.12.2026</b>')
    || str_contains($render['body'],'Core copy')) throw new RuntimeException('Expected a separate formatted line per object');
$values['contractObjectLines']=Support::json([['name'=>'<2.2>','type'=>'Машиноместо & бокс','area'=>'']]);
$single=Modules\Integrations\TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_contains($single,'Объект: <b>&lt;2.2&gt;</b> (Машиноместо &amp; бокс)') || substr_count($single,'Объект: ')!==1)
    throw new RuntimeException('Single object fields must be escaped');
$people=WorkflowMaxNames::byRole([
    ['id'=>4,'name'=>'Ирина Юристова','kind'=>'lawyer'],
    ['id'=>5,'name'=>'Анна Правова','kind'=>'lawyer'],
    ['id'=>'990001203','name'=>'Core copy test L','kind'=>'lawyer'],
    ['id'=>'990001204','name'=>'Core copy test B','kind'=>'accountant'],
]);
if ($people!==['manager'=>'','lawyer'=>'Ирина Юристова, Анна Правова','accountant'=>'Бухгалтер','director'=>''])
    throw new RuntimeException('MAX staff projection should keep real employees and fall back to the test-only role');
$entry=['source_key'=>'resubmit','format'=>$entries['resubmit']['format'],'body_text'=>$entries['resubmit']['body']];
$values=array_replace(Modules\Integrations\MaxTemplateAdmin::sampleValues($entry),$people);
$resubmit=TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_contains($resubmit,'Ответственный <b>Ирина Юристова, Анна Правова</b>') || str_contains($resubmit,'Core copy'))
    throw new RuntimeException('Resubmission should show real lawyers only');
$staff=CurrentContractMaxProof::staffValues([
    'contract_number'=>'165','entity_kind'=>'contract','action'=>'contract.legal',
    'actor_id'=>990001203,'actor_name'=>'Core copy test L','actor_role'=>'lawyer',
    'staff_handoff'=>['task'=>'Проверить расчёт','targets'=>[
        ['id'=>990001204,'label'=>'Core copy test B','role'=>'accountant'],
        ['id'=>3,'label'=>'Раиса Шилова','role'=>'manager'],
    ]],
]);
if ($staff['actor']!=='Юрист' || $staff['recipientNames']!=='Бухгалтер, Раиса Шилова'
    || WorkflowMaxNames::display(3,'Core Copy Industries','manager')!=='Core Copy Industries')
    throw new RuntimeException('Staff handoff should replace test identities with their role labels');
$html=Modules\Integrations\MessageTransport::maxPayload($render['body'],'html');
$url='https://platform-api2.max.ru/messages?chat_id=-123';
ServerWorkflowMax::assertPath(0,'POST',$url,$html,'-123',$render['body'],'html');
try {
    ServerWorkflowMax::assertPath(0,'POST',$url,Support::json(['text'=>$render['body']]),'-123',$render['body'],'html');
    throw new LogicException('Unformatted payload was accepted');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='WORKFLOW_MAX_HTTP_REJECTED') throw $error;
}
echo "PASS server workflow MAX scope, recipient, one POST, body and approved templates\n";
