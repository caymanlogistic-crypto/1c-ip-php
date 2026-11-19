<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Rental\{WordGenerationDemo as Demo,Indicators,RegistryState,WordGenerationData,OriginalDocuments};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$plan=['record'=>['start_date'=>'2026-12-01'],'party'=>['name'=>'ДЕМО · ИП Соколов Алексей Андреевич'],'values'=>['floor'=>'-1']];
$fields=[];foreach(OriginalDocuments::catalog() as $source)$fields=array_merge($fields,$source['fields']);
$values=['vat'=>'5']+WordGenerationData::moneyFields('22000.01','5','rent');
$inputs=WordGenerationData::inputs(array_unique($fields),$values);
$inputs['custom_field']=['type'=>'text','label'=>'Особые условия'];
$demo=Demo::values($inputs,$plan);$complete=WordGenerationData::complete($values,$demo,array_merge($fields,['custom_field']));
foreach(array_unique($fields) as $field)check(trim((string)($complete[$field]??''))!=='','Missing demo field '.$field);
check(array_keys($demo)===array_keys($inputs),'Only requested fields');
check($demo['party_kpp']==='не применяется'&&strlen($demo['party_ogrn'])===15,'Entrepreneur sample');
check($demo['floor_words']==='первом подземном','Known floor respected');
check(str_starts_with($demo['custom_field'],'ДЕМО:'),'Unknown fields filled');
check(!isset($demo['number'],$demo['id'],$demo['version'],$demo['rent_net']),'Recorded/computed facts excluded');
$plan['party']['name']='ООО «Учебный пример»';$company=Demo::values($inputs,$plan);
check($company['party_kpp']==='000000000'&&strlen($company['party_ogrn'])===13,'Company sample');
foreach(['contract','document'] as $kind){
 $record=['id'=>42,'status'=>'returned','return_to'=>'lawyer','reason'=>'Исправить текст','number'=>'42','source_json'=>'{}','kind'=>'renewal'];
 check(Indicators::returnSeverity($record)==='yellow','Lawyer return yellow');
 $record['return_to']='manager';check(Indicators::returnSeverity($record)==='red','Manager return remains red');$record['return_to']='lawyer';
 $tasks=$kind==='document'?Indicators::documentTasks($record):[['role'=>'lawyer','severity'=>Indicators::returnSeverity($record),'text'=>'Исправить замечания']];
 foreach($tasks as &$task)$task+=['entity'=>$kind,'id'=>42,'page'=>'approvals'];unset($task);
 foreach(['lawyer','admin'] as $role){
  $view=RegistryState::approval($kind,$record,$record,$role,$tasks);check($view['severity']==='yellow','Row same color '.$kind.' '.$role);
  check(RegistryState::rowClass($view['severity'])==='registry-ui-warning','Canonical yellow row');
 }
 if($kind==='document')check(Indicators::documentTasks($record,false,true)[0]['severity']==='red','Real send failure retains priority');
}
echo "PASS: all sample fields, IP/company/custom fields, recorded facts preserved, lawyer return/row yellow, manager return/send error red\n";
