<?php
declare(strict_types=1);
/* Private broker worker: one bounded request, fixed native read methods only. */
namespace IpyaAccess;
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_once __DIR__.'/native/php/Portal.php';
require_once __DIR__.'/native/php/diadoc/PrintDiscovery.php';
require_once __DIR__.'/native/php/diadoc/NativePrint.php';

function failure(string $code): never {throw new \RuntimeException($code);}
function rows(array $data, int $maximum): array {
    $list=$data['list']??null;$total=$data['totalCount']??null;
    if(!is_array($list)||!array_is_list($list)||!(is_int($total)||(is_string($total)&&ctype_digit($total)))||$total<0)failure('SOURCE_SCHEMA_CHANGED');
    if($total>$maximum||count($list)>$maximum)failure('BROKER_LIMIT');
    if((int)$total!==count($list))failure('SOURCE_SCHEMA_CHANGED');
    foreach($list as$row)if(!is_array($row)||$row===[]||array_is_list($row))failure('SOURCE_SCHEMA_CHANGED');
    return ['items'=>$list,'next'=>null,'total'=>(int)$total];
}
function credentials(array $input): array {
    $c=$input['credentials']??null;
    if(!is_array($c)||!is_string($c['username']??null)||!is_string($c['password']??null)||$c['username']===''||$c['password']==='')failure('AUTH_REQUIRED');
    return [$c['username'],$c['password']];
}
function onecDiagnostic(?\NativeOnec\Reader $client,?\Throwable $failure=null):array {
    $requests=[];$limits=['curl_errno'=>9999,'http_code'=>599,'connect_ms'=>900000,'pretransfer_ms'=>900000,
        'starttransfer_ms'=>900000,'total_ms'=>900000,'ssl_verify_result'=>65535,'bytes'=>32*1024*1024];
    foreach(array_slice($client?->diagnostics??[],0,12)as$row){
        if(!is_array($row))continue;$safe=[];
        foreach($limits as$key=>$max){$value=$row[$key]??null;$safe[$key]=is_int($value)&&$value>=0&&$value<=$max?$value:null;}
        $code=$row['failure_code']??null;
        $safe['failure_code']=$code==='OK'?'OK':\NativeOnec\Reader::failureCode(new \RuntimeException(is_string($code)?$code:''));
        $requests[]=$safe;
    }
    $result=['native_failure_code'=>\NativeOnec\Reader::failureCode($failure),'requests'=>$requests];
    if(is_array($GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']??null))$result['dev_transport']=\Modules\Integrations\OnecReaderFactory::transportDiagnostic($GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']);
    return $result;
}
function run(array $input,array $factories=[]):array {
    \App\NativePlatform::requireOwnerChecks();
    $auth=($input['operation']??'')==='authenticate';
    $ephemeral=$auth||($input['check_probe']??false)===true;
    $requestStart=count($GLOBALS['ONLINE_REQUESTS']??[]);
    if($auth)$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']=['auth_mode'=>'unselected','stage'=>'input_validation','login_attempted'=>false,'requests'=>[]];
    $hadFlag=array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL',$GLOBALS);$oldFlag=$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']??null;
    $hadCapture=array_key_exists('IPPHP_CAPTURE_RAW',$GLOBALS);$oldCapture=$GLOBALS['IPPHP_CAPTURE_RAW']??null;
    if($ephemeral){$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=true;unset($GLOBALS['IPPHP_CAPTURE_RAW']);}
    try{return runInternal($input,$factories);}
    finally{if($ephemeral){
        if($auth)foreach(array_slice($GLOBALS['ONLINE_REQUESTS']??[],$requestStart,16)as$request)authReceipt($request);
        if($hadFlag)$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=$oldFlag;else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);if($hadCapture)$GLOBALS['IPPHP_CAPTURE_RAW']=$oldCapture;else unset($GLOBALS['IPPHP_CAPTURE_RAW']);}}
}
function authReceipt(array $request):void {
    if(empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']))return;
    $stage=$request['stage']??'';
    if(!in_array($stage,['login','accounts','metadata','report','auth-bootstrap','auth-login','auth-confirm-status','auth-confirm','registry-page'],true))$stage='other_read';
    $http=$request['http']??$request['status']??null;
    $errno=$request['curl_errno']??0;
    $GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['requests'][]=['stage'=>$stage,'http_status'=>is_int($http)&&$http>=100&&$http<=599?$http:null,'curl_errno'=>is_int($errno)&&$errno>=0&&$errno<=9999?$errno:null];
}
function runInternal(array $input,array $factories=[]):array {
    if(!$factories&&getenv('IPPHP_ALLOW_EXTERNAL')!=='owner-authorized-project-access')failure('ACCESS_DENIED');
    unset($GLOBALS['ONEC_DIAGNOSTIC']);
    if(array_diff(array_keys($input),['provider','operation','state','credentials','session','box_id','cursor','account','remaining_ms','force_login','document','receipt','ephemeral_probe','archive_period','include_metadata','check_probe','profile_reference','profile_revision']))failure('ACCESS_DENIED');
    $provider=$input['provider']??'';$operation=$input['operation']??'';$cursor=$input['cursor']??null;
    if((array_key_exists('profile_reference',$input)||array_key_exists('profile_revision',$input))&&$provider!=='onec')failure('ACCESS_DENIED');
    $checkReads=['records','accounts'];
    if($provider==='diadoc')$checkReads[]='original';
    if($provider==='mosvodokanal')$checkReads=array_merge($checkReads,['receipt_print_metadata','receipt_source_file']);
    if(array_key_exists('check_probe',$input)&&(!is_bool($input['check_probe'])||!in_array($operation,$checkReads,true)))failure('ACCESS_DENIED');
    if (array_key_exists('force_login', $input)
        && (!is_bool($input['force_login']) || $operation !== 'authenticate'
            || !in_array($provider, ['mosvodokanal', 'pik'], true))) failure('ACCESS_DENIED');
    if(array_key_exists('ephemeral_probe',$input)&&($provider!=='mosvodokanal'||!is_bool($input['ephemeral_probe'])))failure('ACCESS_DENIED');
    if(array_key_exists('archive_period',$input)&&($provider!=='onec'||$operation!=='archive_invoices'))failure('ACCESS_DENIED');
    if(array_key_exists('include_metadata',$input)&&($provider!=='onec'||$operation!=='authenticate'||!is_bool($input['include_metadata'])))failure('ACCESS_DENIED');
    $extras=['onec'=>['observed_invoice_context','archive_invoices'],'diadoc'=>['selected_native_print_pdf','original'],'mosvodokanal'=>['receipt_print_metadata','receipt_source_file'],'pik'=>['receipt_pdf']];
    if(!isset($extras[$provider])||!in_array($operation,array_merge(['authenticate','accounts','records'],$extras[$provider]),true))failure('ACCESS_DENIED');
    $budget=$input['remaining_ms']??null;
    if(!is_int($budget)||$budget<1||$budget>900000)failure('ACCESS_DENIED');
    $GLOBALS['ONLINE_DEADLINE']=hrtime(true)/1e9+$budget/1000;
    $state=\NativeClients\Portal::state($input['state']??'');
    $session=$input['session']??[];if(!is_array($session))failure('ACCESS_DENIED');
    $factory=$factories[$provider]??null;
    $account=$input['account']??null;
    if($provider==='onec'){
        unset($GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']);
        $client=null;$failed=null;
        try{
        $timeout=in_array($operation,['authenticate','observed_invoice_context'],true)?60:30;
        if($operation==='authenticate'){$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']='fixed_basic';$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='service_document';}
        [$u,$p]=credentials($input);
        $client=$factory?$factory($timeout):\Modules\Integrations\OnecReaderFactory::create(['username'=>$u,'password'=>$p,
            '_reference'=>$input['profile_reference']??'','_owner_bound_revision'=>$input['profile_revision']??null],
            $timeout,$operation==='authenticate'?'connection':'unsupported',\Modules\Integrations\Support::caFile());
        if($operation==='accounts')failure('ACCESS_DENIED');
        if($operation==='observed_invoice_context'){
            if($account!==null||$cursor!==null||!is_array($input['document']??null))failure('ACCESS_DENIED');
            return ['data'=>$client->observedInvoiceContext($input['document']),'session'=>[]];
        }
        if($operation==='authenticate'){
            $dev=!$factory&&\Modules\Integrations\OnecDevSshTransport::settings()!==null;
            if($dev){
                $GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']='fixed_basic_dev_ssh';$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='organization_read_capability';
                $data=\Modules\Integrations\OnecReaderFactory::connection($client);
                return ['data'=>$data,'session'=>[],'transport'=>\Modules\Integrations\OnecDevSshTransport::identity()];
            }
            $client->service();$data=null;if(!empty($input['include_metadata'])){$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='odata_metadata';$metadata=$client->metadata();$data=['metadata_entitysets'=>count($metadata)];}return ['data'=>$data,'session'=>[]];}
        if($operation==='archive_invoices'){
            $period=$input['archive_period']??null;
            if($account!==null||!is_array($period)||count($period)!==2||array_diff(array_keys($period),['from','to'])
                ||!is_string($period['from']??null)||!is_string($period['to']??null)
                ||$cursor!==null&&(!is_int($cursor)||$cursor<0||$cursor>100000||$cursor%100!==0))failure('ACCESS_DENIED');
            return ['data'=>$client->archiveInvoices($period['from'],$period['to'],$cursor??0),'session'=>[]];
        }
        // Negotiated ordered_latest scope: the fixed OData order selects the
        // latest ten. No archive cursor or invented archive total is exposed.
        if($account!==null||$cursor!==null)failure('ACCESS_DENIED');
        $r=$client->rows('invoices',10,0);
        return ['data'=>['items'=>$r['rows'],'next'=>null,'total'=>null],'session'=>[]];
        }catch(\Throwable $error){$failed=$error;throw $error;}
        finally{$GLOBALS['ONEC_DIAGNOSTIC']=onecDiagnostic($client,$failed);}
    }
    if($provider==='diadoc'){
        if($operation==='accounts'||$account!==null||($cursor!==null&&(!is_string($cursor)||strlen($cursor)>2048)))failure('ACCESS_DENIED');
        $box=$input['box_id']??'';if(!is_string($box)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$box))failure('PROFILE_NOT_CONFIGURED');
        $lock=fopen($state.'/run.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))failure('ACCESS_DENIED');
        $http=null;
        try{
            if($factory)$client=$factory();else{$http=new \Diadoc\Http($state,\Modules\Integrations\Support::caFile(),__NAMESPACE__.'\\authReceipt',hrtime(true)/1e9+$budget/1000);$client=new \Diadoc\WebClient($http);}
            if($operation==='authenticate'){
                $GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']='cookie_probe';$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='session_probe';
                try{$client->page($box,'Any',1,null);return ['data'=>null,'session'=>[]];}
                catch(\RuntimeException $e){if($e->getMessage()!=='SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN')throw $e;}
                $pending=$state.'/pending.json';
                if(is_file($pending)){
                    $saved=json_decode(file_get_contents($pending),true,32,JSON_THROW_ON_ERROR);
                    if(is_int($saved['created_at']??null)&&time()-$saved['created_at']<=600){
                        $confirmed=$client->confirm($saved);
                        if(($confirmed['state']??'')==='authenticated'){
                            unlink($pending);$client->page($box,'Any',1,null);return ['data'=>null,'session'=>[]];
                        }
                        failure('PUSH_REQUIRED');
                    }
                    // A fresh explicit test may request a new login after expiry.
                    unlink($pending);
                }
                [$u,$p]=credentials($input);$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['login_attempted']=true;$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='login';$result=$client->login($u,$p);
                if(($result['state']??'')==='awaiting_confirmation'){
                    \Diadoc\Export::writeJson($pending,$result);failure('PUSH_REQUIRED');
                }
                $GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='authenticated_metadata';$client->page($box,'Any',1,null);return ['data'=>null,'session'=>[]];
            }
            if($operation==='selected_native_print_pdf'){
                if($http===null||!is_array($input['document']??null))failure('ACCESS_DENIED');
                \Diadoc\PrintDiscovery::bindObserved($box,$input['document']);
                return ['data'=>(new \Diadoc\NativePrint($http,$state,$GLOBALS['ONLINE_DEADLINE']))->pdf($box,$input['document']),'session'=>[]];
            }
            if($operation==='original'){
                $document=$input['document']??null;
                if(!is_array($document)||!is_int($document['file_size']??null)||$document['file_size']<1||$document['file_size']>8*1024*1024)failure('ACCESS_DENIED');
                $raw=$client->original($box,$document);
                return ['data'=>['body_base64'=>base64_encode($raw['body']),'bytes'=>strlen($raw['body']),
                    'sha256'=>hash('sha256',$raw['body']),'content_type'=>$raw['content_type']],'session'=>[]];
            }
            $page=$client->page($box,'Any',10,$cursor);$flat=\Diadoc\Export::rows($page);$index=0;
            foreach($page['documentsByMessage']as$message){
                $metadata=$message;unset($metadata['documents']);
                foreach($message['documents']as$document){
                    $flat[$index]['native_message']=$metadata;$flat[$index]['native_document']=$document;$index++;
                }
            }
            if(count($flat)>1000)failure('BROKER_LIMIT');
            $next=$page['paging']['next']??null;if($next==='')$next=null;
            if($next!==null&&(!is_string($next)||strlen($next)>2048))failure('SOURCE_SCHEMA_CHANGED');
            // totalCounters counts messages/categories, not flattened documents.
            return ['data'=>['items'=>$flat,'next'=>$next,'total'=>null],'session'=>[]];
        }finally{$http?->close();flock($lock,LOCK_UN);fclose($lock);}
    }
    if($provider==='mosvodokanal'){
        if($cursor!==null)failure('ACCESS_DENIED');
        $ephemeral=($input['ephemeral_probe']??false)||!empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);
        $raw=$state.'/raw';if(!$ephemeral&&!is_dir($raw)&&!mkdir($raw,0700))failure('ACCESS_DENIED');
        $client=$factory?$factory():new \Mosvodokanal\Api($raw,30,150,__DIR__.'/certificates/mvk-chain.crt',recordResponses:!$ephemeral,
            responseLimit:!empty($input['check_probe'])?($operation==='receipt_print_metadata'?1048576:8388608):33554432);
        if(!empty($session['cookies'])&&empty($input['force_login'])){if($operation==='authenticate')$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']='cached_cookies';$client->importSession($session);}
        elseif($operation==='authenticate'){[$u,$p]=credentials($input);$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']='fresh_login';$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['login_attempted']=true;$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='login';$client->login($u,$p);}
        else failure('AUTH_REQUIRED');
        if($operation==='receipt_source_file'){
            $r=$input['receipt']??null;
            if(!is_string($account)||!preg_match('/^[0-9]{1,25}$/D',$account)||!is_array($r)||array_diff(array_keys($r),['id','type','publisherReportCd'])||count($r)!==3)failure('ACCESS_DENIED');
            return ['data'=>$client->sourceFinancialFile($r['publisherReportCd'],$r['id'],$r['type']),'session'=>$client->session()];
        }
        if($operation==='receipt_print_metadata')return ['data'=>$client->printMetadata(),'session'=>$client->session()];
        if($operation==='records'){
            if(!is_string($account)||!preg_match('/^[0-9]{1,25}$/D',$account))failure('ACCESS_DENIED');
            if(!empty($input['check_probe'])){
                $page=$client->page('NATURAL_PERSON_ACCOUNT_BILL_REGISTER',['ACCOUNT_ID'=>$account],0,10);
                if(!is_array($page['list']??null)||!array_is_list($page['list'])||count($page['list'])>10||!is_numeric($page['totalCount']??null))failure('SOURCE_SCHEMA_CHANGED');
                $result=['items'=>$page['list'],'next'=>null,'total'=>null];
            }else $result=rows($client->page('NATURAL_PERSON_ACCOUNT_BILL_REGISTER',['ACCOUNT_ID'=>$account],0,0),100000);
        }else {if($operation==='authenticate')$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='accounts';$result=rows($client->page('NATURAL_PERSON_USER_ACCOUNT_REGISTER',[],0,0),10000);}
        return ['data'=>$result,'session'=>$client->session()];
    }
    $initialToken = !empty($input['force_login']) ? null : ($session['token'] ?? null);
    $client=$factory?$factory():new \PikComfort\Client($initialToken,.25,30,caFile:\Modules\Integrations\Support::caFile(),accountsTimeout:60);
    if($operation==='authenticate')$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['auth_mode']=!empty($session['token'])&&empty($input['force_login'])?'cached_token':'fresh_login';
    if($operation==='authenticate'&&(empty($session['token'])||!empty($input['force_login']))){[$u,$p]=credentials($input);$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['login_attempted']=true;$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='login';$client->login($u,$p);}
    elseif(empty($session['token']))failure('AUTH_REQUIRED');
    if($operation==='receipt_pdf'){
        $receipt=$input['receipt']??null;
        if($cursor!==null||!is_string($account)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$account)||!is_array($receipt)||count($receipt)!==2||array_diff(array_keys($receipt),['_uid','period'])||!is_string($receipt['_uid']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$receipt['_uid'])||!is_string($receipt['period']??null))failure('ACCESS_DENIED');
        $data=\PikComfort\receiptPdf($client,$account,$receipt,'',30,static function(string $url,string $filename)use($client):array{
            $body=$client->pdfBytes($url,8*1024*1024);
            return ['filename'=>$filename,'mime'=>'application/pdf','mime_basis'=>'signature','bytes'=>strlen($body),'sha256'=>hash('sha256',$body),'base64'=>base64_encode($body)];
        });
        return ['data'=>$data,'session'=>$client->session()];
    }
    if($operation==='records'){
        if(!is_string($account)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$account)||($cursor!==null&&(!is_int($cursor)||$cursor<1||$cursor>10000)))failure('ACCESS_DENIED');
        $page=$cursor??1;$r=$client->json(\PikComfort\ENDPOINTS['receipts'],['account_id'=>$account,'page'=>$page,'page_size'=>50],$account);
        $list=$r['results']??null;$total=$r['count']??null;
        if(!is_array($list)||!array_is_list($list)||count($list)>50||!is_int($total)||$total<0||$total>100000||($r['page']??null)!==$page)failure('SOURCE_SCHEMA_CHANGED');
        $offset=($page-1)*50;
        if(($page>1&&$offset>=$total)||count($list)!==min(50,max(0,$total-$offset)))failure('SOURCE_SCHEMA_CHANGED');
        foreach($list as$row)if(!is_array($row)||array_is_list($row))failure('SOURCE_SCHEMA_CHANGED');
        $next=$page*50<$total?$page+1:null;
        $data=['items'=>$list,'next'=>$next,'total'=>$total];
    }else{
        if($operation==='authenticate')$GLOBALS['IPPHP_AUTH_DIAGNOSTIC']['stage']='accounts';$r=$client->json(\PikComfort\ENDPOINTS['accounts'],['notifications_size'=>0]);$list=$r['accounts']??null;
        if(!is_array($list)||!array_is_list($list))failure('SOURCE_SCHEMA_CHANGED');
        if(count($list)>10000)failure('BROKER_LIMIT');
        $billing=[];$skipped=0;
        foreach($list as$row){
            if(!is_array($row)||array_is_list($row))failure('SOURCE_SCHEMA_CHANGED');
            $number=$row['number']??null;
            if(($row['_type']??null)==='virtualaccount'&&($number===null||$number==='')){$skipped++;continue;}
            if(($row['_type']??null)!=='mobile_dashboard'||(!is_string($number)&&!is_int($number))||trim((string)$number)===''||!is_string($row['_uid']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$row['_uid']))failure('SOURCE_SCHEMA_CHANGED');
            $billing[]=$row;
        }
        $data=['items'=>$billing,'next'=>null,'total'=>count($billing),'skipped_no_billing_account'=>$skipped];
    }
    return ['data'=>$data,'session'=>$client->session()];
}
function errorCode(\Throwable $e):string {
    $message=$e->getMessage();
    if($message==='PRINT_SOURCE_CHANGED')return 'FILE_BINDING';
    if(str_starts_with($message,'PRINT_'))return 'FILE_UNAVAILABLE';
    $safe=['AUTH_REQUIRED','PUSH_REQUIRED','PROFILE_NOT_CONFIGURED','ACCESS_DENIED','BROKER_LIMIT','SOURCE_SCHEMA_CHANGED'];
    if(in_array($message,$safe,true))return $message;
    if($e instanceof \PikComfort\AuthenticationError&&in_array($message,['CAPTCHA_REQUIRED','MFA_REQUIRED'],true))return $message;
    if($e instanceof \PikComfort\AuthenticationError||in_array($message,['LOGIN_REJECTED','HTTP_401','SESSION_NOT_AUTHENTICATED','SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN'],true))return 'AUTH_REQUIRED';
    if(str_contains($message,'CAPTCHA')||str_contains($message,'MFA_REQUIRED'))return 'AUTH_REQUIRED';
    if($e instanceof \OnlineUtilities\Deadline||str_contains($message,'TIME_LIMIT'))return 'SOURCE_TIMEOUT';
    if(in_array($message,['RESPONSE_NOT_JSON','RESPONSE_NOT_OBJECT'],true)||str_contains($message,'SCHEMA')||str_contains($message,'READ_KEY')||str_contains($message,'READ_INPUT'))return 'SOURCE_SCHEMA_CHANGED';
    return 'SOURCE_UNAVAILABLE';
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    umask(0077);ini_set('display_errors','0');ini_set('log_errors','0');
    try{
        $line=fgets(STDIN,262145);
        if(!is_string($line)||strlen($line)>262144||!str_ends_with($line,"\n"))failure('ACCESS_DENIED');
        $input=json_decode($line,true,64,JSON_THROW_ON_ERROR);if(!is_array($input))failure('ACCESS_DENIED');
        $result=['ok'=>true]+run($input);
        if(($input['provider']??null)==='onec')$result['diagnostic']=$GLOBALS['ONEC_DIAGNOSTIC']??onecDiagnostic(null);
        $raw=json_encode(['type'=>'result']+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($raw)>16*1024*1024)failure('BROKER_LIMIT');
    }catch(\Throwable $e){
        $result=['type'=>'result','ok'=>false,'error'=>errorCode($e)];
        if(is_array($input??null)&&($input['provider']??null)==='onec')$result['diagnostic']=$GLOBALS['ONEC_DIAGNOSTIC']??onecDiagnostic(null,$e);
        $raw=json_encode($result);
    }
    echo $raw."\n";
}
