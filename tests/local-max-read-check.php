<?php
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\LocalMaxReadCheck as Check;
$groups=['-123'];
Check::assertPath(0,'GET','/me',null,$groups);
Check::assertPath(1,'GET','/chats/-123/members/me',null,$groups);
$bad=[[0,'POST','/me',null],[0,'GET','/me','{}'],[0,'GET','/messages',null],[1,'GET','/chats/-456/members/me',null],[2,'GET','/me',null],[-1,'GET','/me',null],[0,'GET','/me?token=x',null]];
foreach($bad as $args){try{Check::assertPath(...[...$args,$groups]);throw new Exception('GUARD_ACCEPTED');}catch(RuntimeException $e){if($e->getMessage()!=='LOCAL_MAX_READ_PATH_NOT_AUTHORIZED')throw $e;}}
if(Modules\Integrations\Config::liveAllowed())throw new Exception('GENERAL_GATE_OPEN');
$r=(new Modules\Integrations\MessageTransport())->execute('max','send',[],['secret'=>'fixture'],'-123','{}','');
if($r['code']!=='EXTERNAL_ACCESS_NOT_AUTHORIZED')throw new Exception('SEND_GATE_OPEN');
echo "Local MAX path boundaries and send gate PASS\n";