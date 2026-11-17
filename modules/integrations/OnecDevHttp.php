<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Stateless cURL execution. No profile/config discovery, files, cookies, imports or logs. */
final class OnecDevHttp
{
    public static function execute(array $request,?string $caFile=null,?callable $fixture=null):array
    {
        $r=OnecDevRequest::validate($request);
        if($fixture)return self::response($r,$fixture($r));
        if(!extension_loaded('curl'))throw new \RuntimeException('ONEC_DEV_CURL_REQUIRED');
        if($caFile!==null&&(!is_file($caFile)||!is_readable($caFile)))throw new \RuntimeException('ONEC_DEV_CA_UNAVAILABLE');
        $raw='';$oversize=false;$ch=curl_init($r['url']);
        $options=[CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_CUSTOMREQUEST=>$r['method'],
            CURLOPT_HTTPHEADER=>$r['headers'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE=>[OnecDevRequest::HOST.':443:'.OnecDevRequest::IP],
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT_MS=>$r['connect_timeout_ms'],CURLOPT_TIMEOUT_MS=>$r['timeout_ms'],
            CURLOPT_WRITEFUNCTION=>static function($unused,string $part)use(&$raw,&$oversize,$r):int{
                if(strlen($raw)+strlen($part)>$r['max_bytes']){$oversize=true;return 0;}
                $raw.=$part;return strlen($part);
            }];
        if($caFile!==null)$options[CURLOPT_CAINFO]=$caFile;
        if($r['method']==='POST')$options[CURLOPT_POSTFIELDS]=OnecDevRequest::body($r);
        else $options[CURLOPT_HTTPGET]=true;
        try{
            if(!curl_setopt_array($ch,$options))throw new \RuntimeException('ONEC_DEV_HTTP_UNKNOWN');
            $ok=curl_exec($ch);$info=curl_getinfo($ch);$errno=curl_errno($ch);
            if($oversize)throw new \RuntimeException('ONEC_DEV_RESPONSE_TOO_LARGE');
            if($ok===false||$errno!==0)throw new \RuntimeException('ONEC_DEV_HTTP_UNKNOWN');
            if(($info['primary_ip']??null)!==OnecDevRequest::IP||($info['ssl_verify_result']??null)!==0)throw new \RuntimeException('ONEC_DEV_TLS_OR_PIN_REJECTED');
            return self::response($r,['status'=>(int)($info['http_code']??0),'body'=>$raw,
                'curl_errno'=>$errno,'ssl_verify_result'=>0,'primary_ip_verified'=>true,
                'duration_ms'=>(int)round(($info['total_time']??0)*1000)]);
        }finally{curl_close($ch);}
    }

    public static function response(array $request,array $result):array
    {
        if(!is_int($result['status']??null)||$result['status']<100||$result['status']>599
            ||!is_string($result['body']??null)||strlen($result['body'])>$request['max_bytes']
            ||($result['curl_errno']??null)!==0||($result['ssl_verify_result']??null)!==0
            ||($result['primary_ip_verified']??null)!==true
            ||!is_int($result['duration_ms']??null)||$result['duration_ms']<0||$result['duration_ms']>60000)throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        return ['protocol'=>'ipphp-onec-dev-http-v1','project_id'=>OnecDevRequest::PROJECT,
            'request_id'=>$request['request_id'],'status'=>$result['status'],'body_base64'=>base64_encode($result['body']),
            'bytes'=>strlen($result['body']),'curl_errno'=>0,'ssl_verify_result'=>0,
            'primary_ip_verified'=>true,'duration_ms'=>$result['duration_ms']];
    }
}
