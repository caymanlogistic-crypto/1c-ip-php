<?php
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{LocalMaxReadCheck,MaxSettings,Messaging,MessageTransport};
function ensure(bool $value): void { if (!$value) throw new RuntimeException('TEST_FAILED'); }
$raw = '{"text":"fixture test"}';
LocalMaxReadCheck::assertSendPath(0,'POST','/messages?chat_id=-123',$raw,'-123',$raw);
foreach ([[1,'POST','/messages?chat_id=-123',$raw],[0,'GET','/messages?chat_id=-123',$raw],
    [0,'POST','/messages?chat_id=-456',$raw],[0,'POST','/messages?chat_id=-123','{"text":"changed"}'],
    [0,'POST','/messages?chat_id=-123&other=1',$raw]] as $args) {
    try {LocalMaxReadCheck::assertSendPath(...[...$args,'-123',$raw]);throw new Exception('GUARD_ACCEPTED');}
    catch(RuntimeException $e){ensure($e->getMessage()==='LOCAL_MAX_SEND_PATH_NOT_AUTHORIZED');}
}
$groups = ['-123'=>['name'=>'ТЕСТОВАЯ','enabled'=>true]];
$stored = [['chat_id'=>'-123','name'=>'ТЕСТОВАЯ','enabled'=>1]];
ensure(MaxSettings::unchanged(['enabled'=>1],['secret'=>'fixture'],['secret'=>'fixture'],$groups,$stored,true));
ensure(!MaxSettings::unchanged(['enabled'=>1],['secret'=>'fixture'],['secret'=>'changed'],$groups,$stored,true));
ensure(!MaxSettings::unchanged(['enabled'=>1],[],[],$groups,$stored,false));
ensure(!MaxSettings::unchanged(['enabled'=>1],[],[],['-456'=>reset($groups)],$stored,true));
ensure(Messaging::exceptionResult('send','secret_revision',new RuntimeException('CREDENTIAL_REVISION_CONFLICT')) === ['status'=>'failed','code'=>'CREDENTIAL_REVISION_CONFLICT']);
ensure(Messaging::exceptionResult('send','transport_entry',new RuntimeException())['status']==='uncertain');
$previous = getenv('IPPHP_ALLOW_EXTERNAL');
try {
    putenv('IPPHP_ALLOW_EXTERNAL=owner-authorized-project-access');
    $calls = 0;
    $fixture = function($method,$path,$headers,$body) use (&$calls,$raw): array {
        ensure($method==='POST' && $path==='/messages?chat_id=-123' && $body===$raw);
        $calls++;
        return ['status'=>200,'raw'=>'{"message":{"body":{"mid":"fixture-mid"},"recipient":{"chat_id":-123}}}'];
    };
    $result = (new MessageTransport($fixture))->execute('max','send',[],['secret'=>'fixture','approved_chat_ids'=>['-123']],'-123',$raw,'');
    ensure($result['status']==='accepted' && $result['remote_id']==='fixture-mid' && $calls===1);
} finally { putenv($previous===false?'IPPHP_ALLOW_EXTERNAL':'IPPHP_ALLOW_EXTERNAL='.$previous); }
echo "Manual MAX path/body/recipient guards, unchanged settings, preflight classification and acceptance fixture PASS\n";
