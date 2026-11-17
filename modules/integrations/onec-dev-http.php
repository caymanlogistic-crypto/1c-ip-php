<?php
declare(strict_types=1);
// Stateless NEW-project command. No application/old-ERP bootstrap or profile discovery.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
require __DIR__.'/OnecDevRequest.php';require __DIR__.'/OnecDevHttp.php';
use Modules\Integrations\{OnecDevHttp,OnecDevRequest};
$result=['ok'=>false,'code'=>'ONEC_DEV_REMOTE_REJECTED'];
try{
    chdir('/');
    $status=file_get_contents('/proc/self/status');
    if(!preg_match('/^Uid:\s+\d+\s+(\d+)/m',$status,$uid)||!preg_match('/^Gid:\s+\d+\s+(\d+)/m',$status,$gid)
        ||(int)$uid[1]!==995||(int)$gid[1]!==988)throw new RuntimeException('ONEC_DEV_IDENTITY_REJECTED');
    $extension='/srv/ip_ya/php-standalone/runtime/php8.4/extensions/curl.so';$stat=lstat($extension);
    if(!$stat||is_link($extension)||$stat['nlink']!==1||$stat['uid']!==995||$stat['gid']!==988
        ||!extension_loaded('curl')||!str_contains(file_get_contents('/proc/self/maps'),$extension))throw new RuntimeException('ONEC_DEV_RUNTIME_REJECTED');
    $extensionSha=hash_file('sha256',$extension);
    $pins=[];foreach(['OnecDevHttp.php','OnecDevRequest.php','onec-dev-http.php']as$file)$pins[$file]=hash_file('sha256',__DIR__.'/'.$file);
    ksort($pins,SORT_STRING);$source=hash('sha256',json_encode($pins,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    echo json_encode(['type'=>'ready','project_id'=>OnecDevRequest::PROJECT,'source_sha256'=>$source,
        'uid'=>995,'gid'=>988,'extension_sha256'=>$extensionSha],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT); // The local client sends cfg/Basic only after verifying this frame.
    $line=fgets(STDIN,327681);
    if(!is_string($line)||strlen($line)>327680||!str_ends_with($line,"\n"))throw new RuntimeException('ONEC_DEV_REQUEST_REJECTED');
    $input=json_decode($line,true,32,JSON_THROW_ON_ERROR);unset($line);
    if(!is_array($input)||count($input)!==2||!is_array($input['request']??null)||!is_array($input['runtime']??null))throw new RuntimeException('ONEC_DEV_REQUEST_REJECTED');
    $request=OnecDevRequest::validate($input['request']);$runtime=$input['runtime'];
    $keys=array_keys($runtime);sort($keys);
    if($keys!==['ca_file','extension','extension_sha256']
        ||$runtime['extension']!=='/srv/ip_ya/php-standalone/runtime/php8.4/extensions/curl.so'
        ||!is_string($runtime['extension_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$runtime['extension_sha256'])
        ||!($runtime['ca_file']===null||is_string($runtime['ca_file'])&&preg_match('~^/(?:etc/ssl/certs/|srv/ip_ya/php-standalone/)[A-Za-z0-9_./-]+$~D',$runtime['ca_file'])))throw new RuntimeException('ONEC_DEV_RUNTIME_REJECTED');
    if(!hash_equals($runtime['extension_sha256'],$extensionSha))throw new RuntimeException('ONEC_DEV_RUNTIME_REJECTED');
    $response=OnecDevHttp::execute($request,$runtime['ca_file']);
    $result=['ok'=>true,'source_sha256'=>$source,'uid'=>995,'gid'=>988,
        'extension_sha256'=>$runtime['extension_sha256'],'response'=>$response];
}catch(Throwable $error){
    $code=$error->getMessage();
    $allowed=['ONEC_DEV_IDENTITY_REJECTED','ONEC_DEV_REQUEST_REJECTED','ONEC_DEV_RUNTIME_REJECTED',
        'ONEC_DEV_GET_ONLY','ONEC_DEV_SCOPE_REJECTED','ONEC_DEV_QUERY_REJECTED','ONEC_DEV_ORIGIN_REJECTED',
        'ONEC_DEV_NATIVE_ROUTE_REJECTED','ONEC_DEV_BODY_REJECTED',
        'ONEC_DEV_HEADER_REJECTED','ONEC_DEV_CURL_REQUIRED','ONEC_DEV_CA_UNAVAILABLE','ONEC_DEV_RESPONSE_TOO_LARGE',
        'ONEC_DEV_HTTP_UNKNOWN','ONEC_DEV_TLS_OR_PIN_REJECTED','ONEC_DEV_RESPONSE_UNVERIFIED'];
    $result=['ok'=>false,'code'=>in_array($code,$allowed,true)?$code:'ONEC_DEV_REMOTE_REJECTED'];
}
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
