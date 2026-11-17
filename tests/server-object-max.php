<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{ServerObjectMax,Support};
$m=['schema'=>1,'enabled'=>true,'project_id'=>Modules\Rental\WorkflowEvents::PROJECT,'scope'=>'object-activity','stand'=>'ui',
    'database'=>'ipphp_ui','revision'=>6,'group_id'=>'d426f2fc-0acb-537b-9139-331fc45a76ce','group_name'=>'ТЕСТОВАЯ',
    'chat_id'=>'-123','vault_sha256'=>str_repeat('a',64),'event_floor'=>1000];
ServerObjectMax::validate($m);
foreach ([['enabled'=>1],['scope'=>'all'],['stand'=>'integrations'],['database'=>'other'],['group_name'=>'other'],['revision'=>0],['event_floor'=>-1],['chat_id'=>'https://other'],['vault_sha256'=>'bad'],['extra'=>true]] as $change) {
    $denied=false;try{ServerObjectMax::validate(array_replace($m,$change));}catch(Throwable){$denied=true;}
    if(!$denied)throw new RuntimeException('Invalid admission accepted');
}
$url='https://platform-api2.max.ru/messages?chat_id=-123';$body=Support::json(['text'=>'fixture']);
ServerObjectMax::assertPath(0,'POST',$url,$body,'-123','fixture');
foreach ([[1,'POST',$url,$body],[0,'GET',$url,$body],[0,'POST',$url.'&extra=1',$body],[0,'POST',str_replace('-123','-456',$url),$body],[0,'POST',str_replace('https:','http:',$url),$body],[0,'POST',$url,Support::json(['text'=>'other'])]] as $args) {
    $denied=false;try{ServerObjectMax::assertPath(...[...$args,'-123','fixture']);}catch(RuntimeException){$denied=true;}
    if(!$denied)throw new RuntimeException('Invalid MAX HTTP scope accepted');
}
echo "PASS: server object MAX marker, target, method, body and one-request boundaries\n";
