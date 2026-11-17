<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{Config, MessageTransport};

// No network calls: rejected gates/targets, or invalid SMTP config before connect.
$external=getenv('IPPHP_ALLOW_EXTERNAL');
$email=getenv('IPPHP_ALLOW_EMAIL');
$assert=static function(bool $ok):void {if(!$ok)throw new RuntimeException('EMAIL_ACCESS_TEST_FAILED');};
try {
    putenv('IPPHP_ALLOW_EXTERNAL');
    putenv('IPPHP_ALLOW_EMAIL');
    $transport=new MessageTransport();
    $execute=static fn(string $provider,string $operation,array $secret=[],string $target='test@example.invalid')
        =>$transport->execute($provider,$operation,[],$secret,$target,'','');
    $assert($execute('email','check')['code']==='EXTERNAL_ACCESS_NOT_AUTHORIZED');
    putenv('IPPHP_ALLOW_EMAIL=1');
    $assert(!Config::emailLiveAllowed());
    putenv('IPPHP_ALLOW_EMAIL=owner-authorized-email-access');
    $assert(Config::emailLiveAllowed()&&!Config::liveAllowed());
    $assert($execute('email','check')['code']==='SMTP_CHECK_OR_AUTH_FAILED');
    $assert($execute('email','send')['code']==='TARGET_NOT_APPROVED');
    $assert($execute('email','send',['approved_recipients'=>['other@example.invalid']])['code']==='TARGET_NOT_APPROVED');
    $assert($execute('email','send',['approved_recipients'=>['TEST@example.invalid']])['code']==='SMTP_CHECK_OR_AUTH_FAILED');
    $assert($execute('max','check')['code']==='EXTERNAL_ACCESS_NOT_AUTHORIZED');
    $assert($execute('max','send')['code']==='EXTERNAL_ACCESS_NOT_AUTHORIZED');
    try {$execute('email','other');throw new RuntimeException('MISSING_OPERATION_GUARD');}
    catch(InvalidArgumentException $e){$assert($e->getMessage()==='INVALID_MESSAGE_OPERATION');}
    echo "Email gate: default deny, explicit opt-in, recipient guard, MAX isolation OK\n";
} finally {
    putenv($external===false?'IPPHP_ALLOW_EXTERNAL':'IPPHP_ALLOW_EXTERNAL='.$external);
    putenv($email===false?'IPPHP_ALLOW_EMAIL':'IPPHP_ALLOW_EMAIL='.$email);
}
