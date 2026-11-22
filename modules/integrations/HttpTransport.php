<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** HTTPS only, bounded responses, no redirects/proxies, explicit project authorization. */
final class HttpTransport
{
    public static function request(string $method,string $url,array $headers=[],?string $body=null,int $limit=8388608,int $timeoutSeconds=30,?WorkflowMaxEvent $workflowEvent=null,?callable $observer=null,MaxReadCheck|LocalMaxReadCheck|ServerObjectMax|ServerWorkflowMax|DadataLookup|BookingMax|null $readCheck=null): array
    {
        $mark=static function(string $stage,array $facts=[])use($observer):void{
            if($observer!==null)try{$observer($stage,$facts);}catch(\Throwable){} // Diagnostics never mask the original result.
        };
        $mark('http_guard');
        if($timeoutSeconds<1||$timeoutSeconds>30)throw new \InvalidArgumentException('INVALID_HTTP_TIMEOUT');
        if($workflowEvent!==null&&$readCheck!==null)throw new \RuntimeException('MAX_READ_OPERATION_NOT_AUTHORIZED');
        if($readCheck!==null)$readCheck->assertHttpRequest($method,$url,$body);
        elseif($workflowEvent!==null)$workflowEvent->assertHttpRequest($method,$url,$body);
        elseif(!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        $parts=parse_url($url);
        if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||isset($parts['port'])&&$parts['port']!==443)throw new \InvalidArgumentException('HTTPS_REQUIRED');
        $mark('curl_init');
        $ch=curl_init($url);$bytes='';$tooLarge=false;
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeoutSeconds,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$bytes,&$tooLarge,$limit){if(strlen($bytes)+strlen($chunk)>$limit){$tooLarge=true;return 0;}$bytes.=$chunk;return strlen($chunk);}]);
        if($readCheck instanceof DadataLookup && ($resolve=$readCheck->resolve()))curl_setopt($ch,CURLOPT_RESOLVE,$resolve);
        $mark('ca_select',['ca_configured'=>(bool)(getenv('IPPHP_CAFILE')?:getenv('APP_INTEGRATION_CAFILE')?:ini_get('curl.cainfo'))]);
        $ca=$readCheck instanceof ServerObjectMax||$readCheck instanceof ServerWorkflowMax||$readCheck instanceof BookingMax?$readCheck->caFile():Support::caFile();
        $effectiveCa=$ca?:ini_get('curl.cainfo');
        $ssl='';
        if($observer!==null)try{$ssl=curl_version()['ssl_version']??'';}catch(\Throwable){}
        $mark('ca_selected',['ca_regular'=>is_string($effectiveCa)&&$effectiveCa!==''&&!is_link($effectiveCa)&&is_file($effectiveCa)&&is_readable($effectiveCa),
            'ssl_backend'=>is_string($ssl)&&preg_match('~^[A-Za-z0-9 ._+/()=-]{0,100}$~D',$ssl)?$ssl:'UNKNOWN']);
        if($ca)curl_setopt($ch,CURLOPT_CAINFO,$ca);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $mark('curl_start',['curl_started'=>true]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
        $mark('curl_return',['curl_completed'=>true,'curl_errno'=>$errno,'observed_http_status'=>$status?:null]);
        if($tooLarge)throw new \RuntimeException('RESPONSE_TOO_LARGE');
        if($ok===false||$errno)throw new \RuntimeException('NETWORK_OR_TLS_FAILED');
        return ['status'=>$status,'raw'=>$bytes];
    }
}
