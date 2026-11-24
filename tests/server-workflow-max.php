<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{AccountantPackageMax,ContractSecurityMax,ContractWorkflowRelay,CurrentContractMaxProof,MaxTemplateAdmin,ServerWorkflowMax,Support,TemplateCatalog,WorkflowMaxNames};
use Modules\Rental\{AccountantPacketSummary,WorkflowEvents};
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
$key=ContractSecurityMax::KEY;
$legacy=['source_key'=>$key,'format'=>$entries[$key]['format'],'body_text'=>$entries[$key]['body']];
$entry=array_replace($legacy,['body_text'=>ContractSecurityMax::template()['body']]);
$values=Modules\Integrations\MaxTemplateAdmin::sampleValues($legacy);
unset($values[ContractSecurityMax::TOKEN]);
$unchanged=TemplateCatalog::preview('max',$entry,$values)['body'];
if ($unchanged!==TemplateCatalog::preview('max',$legacy,$values)['body']) {
    throw new RuntimeException('A legal approval without a multiplier change must keep the old message');
}
$change=['entity_kind'=>'contract','event_kind'=>$key,'security_multiple_change'=>['amount'=>'40000.00','current'=>2,'previous'=>1]];
$values[ContractSecurityMax::TOKEN]=ContractSecurityMax::value($change);
$changed=TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_contains($changed,"по <b>06.12.2026</b>\nКратность обеспечения изменена с <b>1</b> до <b>2</b>. Обеспечительный платёж с НДС — <b>40 000,00 ₽</b>.\nКонтрагент")) {
    throw new RuntimeException('The security change should appear with bold values immediately after the dates');
}
$change['security_multiple_change']=['previous'=>2,'current'=>0,'amount'=>'0.00'];
$values[ContractSecurityMax::TOKEN]=ContractSecurityMax::value($change);
$zero=TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_contains($zero,'с <b>2</b> до <b>0</b>. Обеспечительный платёж не предусмотрен.') || str_contains($zero,'₽')) {
    throw new RuntimeException('Zero multiplier must state that no security payment is required');
}
$now=time();
$scope=['key'=>str_repeat('a',64),'source'=>Support::uuid(),'source_epoch'=>Support::uuid(),
    'actor_map'=>[4=>4],'catalog_versions'=>[$key=>4],'valid_from'=>$now-1,'expires_at'=>$now+30,
    'event_floor'=>1,'future_after'=>1,'contract_ids'=>[]];
$wire=['source'=>$scope['source'],'epoch'=>$scope['source_epoch'],'event_id'=>2,'recorded_at'=>$now,
    'contract_id'=>2,'actor_id'=>4,'kind'=>$key,'action'=>'contract.legal','version'=>4,
    'values'=>array_intersect_key($values,array_flip(['number','party','contractStart','contractEnd','contractObjectLines']))];
$verify=static function (array $payload,array $binding) use ($now): array {
    $body=Support::json($payload);
    return ContractWorkflowRelay::verify($body,(string)$now,ContractWorkflowRelay::signature($body,$now,$binding['key']),$binding,$now);
};
if ($verify($wire,$scope)!==$wire) throw new RuntimeException('Approved legacy wire payload changed');
$withChange=$wire;
$withChange['values'][ContractSecurityMax::TOKEN]=$values[ContractSecurityMax::TOKEN];
try {
    $verify($withChange,$scope);
    throw new LogicException('The legacy wire payload accepted an unknown field');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='CONTRACT_RELAY_VALUES') throw $error;
}
$scope['catalog_versions'][$key]=5;
$wire['version']=$withChange['version']=5;
if ($verify($wire,$scope)!==$wire || $verify($withChange,$scope)!==$withChange)
    throw new RuntimeException('The updated wire payload did not accept both the normal and changed cases');
$withChange['values'][ContractSecurityMax::TOKEN]='{"previous":2,"current":2,"amount":"0.00"}';
try {
    $verify($withChange,$scope);
    throw new LogicException('The updated wire payload accepted an invalid multiplier snapshot');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='CONTRACT_RELAY_VALUES') throw $error;
}
$packageKey=AccountantPackageMax::KEY;
$packageEvent=array_replace($event,['event_kind'=>$packageKey,'action'=>'contract.packet','actor_role'=>'accountant',
    'contract_id'=>42,'entity_id'=>42,'target_role'=>'manager','from_status'=>'accounting','to_status'=>'active',
    'accountant_packet_confirmation'=>['external_operations'=>AccountantPacketSummary::EXTERNAL_DISABLED,
        'planned'=>[['kind'=>'security','label'=>'Обеспечительный платёж','amount'=>'40000.00'],
            ['kind'=>'rent','amount'=>'4666.67'],['kind'=>'next','amount'=>'20000.00']],'issued'=>[]]]);
foreach (['accounting','approved'] as $from) {
    $packetSource=array_replace($packageEvent,['from_status'=>$from]);
    if (CurrentContractMaxProof::select($packetSource)!==$packageKey
        || WorkflowEvents::transition('contract.packet',['packet_confirmed'=>1],['status'=>$from],['status'=>'active'])['event_kind']!==$packageKey)
        throw new RuntimeException('The checked accounting transition was not selected');
}
if (CurrentContractMaxProof::select(array_replace($packageEvent,['to_status'=>'approved']))!==$packageKey
    || WorkflowEvents::transition('contract.packet',[],['status'=>'accounting'],['status'=>'active'])!==null
    || WorkflowEvents::transition('contract.sign',['packet_confirmed'=>1],['status'=>'sent'],['status'=>'active'])['event_kind']===$packageKey)
    throw new RuntimeException('The new packet snapshot changed historical selection or broadened the trigger');
try {
    CurrentContractMaxProof::select(array_diff_key($packageEvent,['accountant_packet_confirmation'=>true]));
    throw new LogicException('A generic active transition was routed as packet confirmation');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='RENTAL_MAX_TRANSITION_PROOF_INVALID') throw $error;
}
$entry=['source_key'=>$packageKey,'format'=>'html','body_text'=>AccountantPackageMax::template()['body']];
$values=MaxTemplateAdmin::sampleValues($entry);
$values[AccountantPackageMax::TOKEN]=AccountantPackageMax::fromEvent($packageEvent);
$packetText=TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_starts_with($packetText,"<b>Договор №178 · ООО «Учебный клиент»</b>\nПакет документов подтверждён бухгалтерией.")
    || !str_contains($packetText,"<b>Расчёт первых платежей</b>\nОбеспечительный платёж — <b>40 000,00 ₽</b>\nАренда за первый период — <b>4 666,67 ₽</b>\nАренда за полный следующий месяц — <b>20 000,00 ₽</b>")
    || !str_contains($packetText,'Создание контрагента в 1С, выставление счетов и отправка документов через ЭДО отключены.'))
    throw new RuntimeException('The accountant packet did not render the exact confirmation and calculated sums');
$packageEvent['accountant_packet_confirmation']['planned']=[];
$values[AccountantPackageMax::TOKEN]=AccountantPackageMax::fromEvent($packageEvent);
if (str_contains(TemplateCatalog::preview('max',$entry,$values)['body'],'Расчёт первых платежей'))
    throw new RuntimeException('The amount section was fabricated when no amount was calculated');
$packageEvent['accountant_packet_confirmation']['issued']=[['kind'=>'rent','amount'=>'4666.67','number'=>'77']];
$values[AccountantPackageMax::TOKEN]=AccountantPackageMax::fromEvent($packageEvent);
$issuedText=TemplateCatalog::preview('max',$entry,$values)['body'];
if (!str_contains($issuedText,'Ранее выставленные первые счета') || str_contains($issuedText,'Обеспечительный платёж'))
    throw new RuntimeException('Existing payment documents were represented as unissued calculations');
$scope['catalog_versions'][$packageKey]=3;
$legacyPackage=array_replace($wire,['kind'=>$packageKey,'action'=>'contract.packet','version'=>3,
    'values'=>array_intersect_key($values,array_flip(['number','party','contractStart','contractEnd','contractObjectLines','invoiceSummary','actor']))]);
if ($verify($legacyPackage,$scope)!==$legacyPackage) throw new RuntimeException('The historical packet payload changed');
$freshPackage=$legacyPackage;
$freshPackage['values'][AccountantPackageMax::TOKEN]=$values[AccountantPackageMax::TOKEN];
try {
    $verify($freshPackage,$scope);
    throw new LogicException('A v3 packet payload accepted v4 fields');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='CONTRACT_RELAY_VALUES') throw $error;
}
$scope['catalog_versions'][$packageKey]=4;
$freshPackage['version']=4;
if ($verify($freshPackage,$scope)!==$freshPackage) throw new RuntimeException('The new package payload was rejected');
$freshPackage['values'][AccountantPackageMax::TOKEN]='{"external_operations":"enabled","planned":[],"issued":[]}';
try {
    $verify($freshPackage,$scope);
    throw new LogicException('The recipient accepted a claim of no operations without the corresponding packet status');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='CONTRACT_RELAY_VALUES') throw $error;
}
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
