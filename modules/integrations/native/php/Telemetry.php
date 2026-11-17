<?php
declare(strict_types=1);

/** NDJSON worker protocol. No URLs, bodies, account IDs or credentials enter events. */
namespace OnlineUtilities;

final class Stopped extends \RuntimeException {}
final class Deadline extends \RuntimeException {}

function stamp():string{return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');}
function send(string $type,array $data=[]):void {
    if(($GLOBALS['IPPHP_EMBEDDED_NATIVE']??false)===true)return;
    echo json_encode(['type'=>$type]+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
}
function stopped():bool {
    if(($GLOBALS['ONLINE_CANCEL']??false)===true)return true;
    if(isset($GLOBALS['ONLINE_CANCEL_FILE'])&&is_file($GLOBALS['ONLINE_CANCEL_FILE'])){$GLOBALS['ONLINE_CANCEL']=true;return true;}
    if(defined('STDIN')){
        $read=[STDIN];$write=[];$except=[];
        if(@stream_select($read,$write,$except,0,0)>0){$line=fgets(STDIN,128);if(is_string($line)&&str_contains($line,'"cancel":true'))$GLOBALS['ONLINE_CANCEL']=true;}
    }
    return ($GLOBALS['ONLINE_CANCEL']??false)===true;
}
function check():void {
    if(stopped())throw new Stopped('CANCELLED');
    if(hrtime(true)/1e9>=($GLOBALS['ONLINE_DEADLINE']??INF))throw new Deadline('RUN_TIME_LIMIT_EXCEEDED');
}
function timeout(float $maximum):float {check();return max(0.001,min($maximum,($GLOBALS['ONLINE_DEADLINE']??INF)-hrtime(true)/1e9));}
function begin(string $stage,string $method):array {
    check();$request=['stage'=>$stage,'method'=>$method,'sequence'=>($GLOBALS['ONLINE_SEQUENCE']??0)+1,'request_index'=>($GLOBALS['ONLINE_SEQUENCE']??0)+1,'started_at'=>stamp()];
    $GLOBALS['ONLINE_SEQUENCE']=$request['sequence'];
    send('event',['event'=>$request+['status'=>'started']]);return $request+['_clock'=>hrtime(true)/1e9];
}
function end(array $request,int $http,int $bytes,int $errno=0):void {
    $clock=$request['_clock'];unset($request['_clock']);
    $request+=['status'=>'finished','finished_at'=>stamp(),'http'=>$http?:null,'bytes'=>$bytes,'duration_ms'=>(int)round((hrtime(true)/1e9-$clock)*1000)];
    if($errno)$request['code']='TRANSPORT_FAILED';
    $GLOBALS['ONLINE_REQUESTS'][]=$request;send('event',['event'=>$request]);
}
function progress(string $stage,int $completed,int $total):void {check();send('event',['event'=>['stage'=>$stage,'status'=>'progress','completed'=>$completed,'total'=>$total,'at'=>stamp()]]);}
function code(\Throwable $e):string {
    if($e instanceof Stopped || str_contains($e->getMessage(),'CANCELLED'))return 'CANCELLED';
    if($e instanceof Deadline || str_contains($e->getMessage(),'TIME_BUDGET'))return 'RUN_TIME_LIMIT_EXCEEDED';
    if(str_contains($e->getMessage(),'CAPTCHA'))return 'CAPTCHA_REQUIRED';
    if(str_contains($e->getMessage(),'MFA_REQUIRED'))return 'MFA_REQUIRED';
    if($e instanceof \PikComfort\AuthenticationError)return 'AUTH_REQUIRED';
    if(str_contains($e->getMessage(),'401')||str_contains($e->getMessage(),'AUTHENTICATED')||str_contains($e->getMessage(),'LOGIN_REJECTED'))return 'AUTH_REQUIRED';
    if(str_contains($e->getMessage(),'403'))return 'ACCESS_DENIED';
    if(preg_match('/^HTTP[_ ]([0-9]{3})/',$e->getMessage(),$match))return 'HTTP_'.$match[1];
    if(str_contains($e->getMessage(),'Another PHP export'))return 'PROVIDER_BUSY';
    if(str_contains($e->getMessage(),'count')||str_contains($e->getMessage(),'COUNT')||str_contains($e->getMessage(),'schema')||str_contains($e->getMessage(),'SCHEMA'))return 'SOURCE_VALIDATION_FAILED';
    if(str_contains($e->getMessage(),'TRANSPORT')||str_contains($e->getMessage(),'Network/TLS'))return 'NETWORK_OR_TLS_FAILED';
    return 'PROVIDER_OPERATION_FAILED';
}
