<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
require_once dirname(__DIR__,4).'/app/bootstrap.php';
if(!\Modules\Integrations\Config::liveAllowed())exit(2);
require_once __DIR__.'/Portal.php';
try{
    $line=fgets(STDIN,65537);
    if(!is_string($line)||strlen($line)>65536||!str_ends_with($line,"\n"))throw new RuntimeException('INPUT_REJECTED');
    $input=json_decode($line,true,32,JSON_THROW_ON_ERROR);unset($line);
    if(!is_array($input))throw new RuntimeException('INPUT_REJECTED');
    $GLOBALS['ONLINE_DEADLINE']=hrtime(true)/1e9+300;$GLOBALS['ONLINE_REQUESTS']=[];
    $result=\NativeClients\Portal::run($input);unset($input);
    $result['report']['requests']??=$GLOBALS['ONLINE_REQUESTS'];
}catch(Throwable $e){
    $code=$e->getMessage();
    $allowed=['AUTH_REQUIRED','READ_ACTION_REJECTED','INPUT_REJECTED','FRESH_PRIVATE_STATE_REQUIRED','PRIVATE_OWNER_MODE_REQUIRED',
              'LIMIT_REJECTED','SESSION_REJECTED','ACCOUNT_SCOPE_REQUIRED','PAGE_REJECTED','SOURCE_LIMIT_NOT_ENFORCED',
              'NO_PENDING_CONFIRMATION','CONFIRMATION_EXPIRED_RUN_LOGIN','CONFIRMATION_REFUSED','CA_FILE_UNAVAILABLE',
              'MFA_REQUIRED','CAPTCHA_REQUIRED','STATE_BUSY','NETWORK_OR_TLS_FAILED','VERIFIED_PIN_REQUIRED'];
    if(str_contains($code,'CAPTCHA'))$code='CAPTCHA_REQUIRED';
    elseif(str_contains($code,'MFA'))$code='MFA_REQUIRED';
    elseif(preg_match('/(?:HTTP[_ ](?:401|403)|SESSION_OR_BOX_ACCESS)/',$code))$code='AUTH_REQUIRED';
    elseif(!in_array($code,$allowed,true))$code='NATIVE_CLIENT_FAILED';
    $result=['report'=>['ok'=>false,'result'=>$code,'write_operations'=>0,'requests'=>$GLOBALS['ONLINE_REQUESTS']??[]],'data'=>[],'session'=>[]];
}
// This terminal frame contains private DATA/SESSION. Broker must separate it from public telemetry.
echo json_encode(['type'=>'result']+$result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
