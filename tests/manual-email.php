<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{DB,Http};
use Modules\Integrations\{Config,Messaging,MessageTransport,Support};

$db=DB::pdo();
if(!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$config=['host'=>'','port'=>465,'security'=>'ssl','username'=>'fixture','from_email'=>'sender@example.invalid','from_name'=>'Fixture'];
$old=Config::get($db,'email');Config::save($db,'email',$config,(int)$old['revision'],true);
Config::saveSecrets('email',['secret'=>'fixture','approved_recipients'=>['only@example.invalid'],'_owner_bound_revision'=>(int)$old['revision']+1]);
$users=$db->query("SELECT id,auth_version,role FROM users WHERE username IN('core_d6dc6bc7_admin','core_d6dc6bc7_viewer')")->fetchAll();
$roles=[];foreach($users as $user)$roles[$user['role']]=$user;
$check(isset($roles['admin'],$roles['viewer']),'FIXTURE_USERS_REQUIRED');
$session=$_SESSION??[];$post=$_POST;$server=$_SERVER;
$calls=0;
$fixture=static function($provider,$operation,$public,$secret,$target,$raw,$id)use(&$calls,$check):array {
    $check($provider==='email'&&$operation==='send','MANUAL_EMAIL_SCOPE');
    $check($secret['approved_recipients']===[$target],'EXACT_RECIPIENT_SCOPE');
    $check(str_contains($raw,'To: <'.$target.'>'),'MIME_TARGET');
    $calls++;return ['status'=>'accepted','code'=>'SMTP_ACCEPTED_NOT_INBOX','remote_id'=>$id];
};
$service=new Messaging($db,Config::store(),$fixture);
$login=static function(string $role)use($roles):void {$_SESSION=['user_id'=>$roles[$role]['id'],'auth_version'=>$roles[$role]['auth_version'],'csrf'=>'manual-email-fixture-csrf'];};
$deny=static function(callable $action,int $code)use($check):void {
    try{$action();throw new LogicException('MISSING_DENIAL');}catch(RuntimeException $e){$check($e->getCode()===$code,'DENIAL_STATUS');}
};
try {
    $_SERVER['REQUEST_METHOD']='POST';$login('admin');$_POST=['_csrf'=>Http::csrf()];
    $actor=(int)$roles['admin']['id'];$key=Support::uuid();
    $targets=['new-recipient@example.invalid','another@example.invalid'];
    $attachments=[['name'=>'Проверка.txt','bytes'=>'UTF-8 fixture']];
    $first=$service->dispatchManualEmail($actor,$key,$targets,'Fixture text','Fixture subject',$attachments);
    $check(count($first)===2&&$calls===2&&!array_filter($first,static fn($a)=>$a['status']!=='accepted'),'NEW_RECIPIENT_ACCEPTED');
    $again=$service->dispatchManualEmail($actor,$key,array_reverse($targets),'Fixture text','Fixture subject',$attachments);
    $check(array_column($first,'id')===array_column($again,'id')&&$calls===2,'REPLAY_NO_DUPLICATE');
    foreach($first as $attempt){
        $spool=Config::store()->verifySpool($attempt['spool_path']);
        $check(count($spool['context']['attachments'])===1&&Config::store()->receiptFor($attempt['id'])!==null,'SIGNED_ATTACHMENT_RECEIPT');
    }
    $check(Config::secrets('email')['approved_recipients']===['only@example.invalid'],'PROFILE_ALLOWLIST_UNCHANGED');
    try{$service->dispatchManualEmail($actor,$key,['changed@example.invalid'],'Fixture text','Fixture subject',$attachments);throw new LogicException('MISSING_CONFLICT');}
    catch(RuntimeException $e){$check($e->getMessage()==='IDEMPOTENCY_CONFLICT','CHANGED_COMMAND_CONFLICT');}
    $_POST['_csrf']='wrong';$deny(fn()=>$service->dispatchManualEmail($actor,Support::uuid(),$targets,'x','s'),403);
    $_POST['_csrf']='manual-email-fixture-csrf';$_SERVER['REQUEST_METHOD']='GET';$deny(fn()=>$service->dispatchManualEmail($actor,Support::uuid(),$targets,'x','s'),403);
    $_SERVER['REQUEST_METHOD']='POST';$login('viewer');$deny(fn()=>$service->dispatchManualEmail((int)$roles['viewer']['id'],Support::uuid(),$targets,'x','s'),403);
    $login('admin');$deny(fn()=>$service->dispatchManualEmail((int)$roles['viewer']['id'],Support::uuid(),$targets,'x','s'),403);
    $_SESSION=[];$deny(fn()=>$service->dispatchManualEmail($actor,Support::uuid(),$targets,'x','s'),403);
    $login('admin');$_POST['_csrf']=Http::csrf();
    try{$service->dispatchManualEmail($actor,Support::uuid(),['bad-address'],'x','s');throw new LogicException('MISSING_TARGET_VALIDATION');}
    catch(InvalidArgumentException $e){$check($e->getMessage()==='INVALID_TARGET','INVALID_TARGET_REJECTED');}
    putenv('IPPHP_ALLOW_EMAIL=owner-authorized-email-access');
    $result=(new MessageTransport())->execute('email','send',$config,Config::secrets('email'),'new-recipient@example.invalid','','');
    $check($result['code']==='TARGET_NOT_APPROVED','PROGRAMMATIC_SEND_STILL_RESTRICTED');
    $check($calls===2,'DENIED_COMMANDS_NEVER_SENT');
    echo "Manual email: new recipients, exact command scope, replay, attachments/receipts, CSRF/roles, and unchanged programmatic allowlist PASS; no network\n";
} finally {$_SESSION=$session;$_POST=$post;$_SERVER=$server;putenv('IPPHP_ALLOW_EMAIL');}
