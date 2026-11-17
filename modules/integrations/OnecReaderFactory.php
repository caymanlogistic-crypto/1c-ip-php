<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Shared UI/worker reader selection. DIRECT stays the original native Reader. */
final class OnecReaderFactory
{
    private const DEV_CODES=['ONEC_DEV_HOST_KEY_REJECTED','ONEC_DEV_CARRIER_REJECTED','ONEC_DEV_CARRIER_UNAVAILABLE',
        'ONEC_DEV_SUDO_CARRIER_REQUIRED','ONEC_DEV_GET_ONLY','ONEC_DEV_SCOPE_REJECTED','ONEC_DEV_ORIGIN_REJECTED',
        'ONEC_DEV_HEADER_REJECTED','ONEC_DEV_QUERY_REJECTED','ONEC_DEV_PROFILE_CHANGED','ONEC_DEV_CONFIG_REJECTED',
        'ONEC_DEV_COMMAND_REJECTED','ONEC_DEV_LOGGING_FORBIDDEN','ONEC_DEV_REQUEST_REJECTED',
        'ONEC_DEV_RESPONSE_TOO_LARGE','ONEC_DEV_RESPONSE_UNVERIFIED','ONEC_DEV_TRANSPORT_UNKNOWN',
        'ONEC_DEV_CURL_REQUIRED','ONEC_DEV_CA_UNAVAILABLE','ONEC_DEV_HTTP_UNKNOWN','ONEC_DEV_TLS_OR_PIN_REJECTED'];
    private const DEV_PHASES=['profile_binding','request_admission','dev_ssh_transport','response_validation'];

    /** Fixed metadata only: never return an exception message or an arbitrary transport field. */
    public static function transportDiagnostic(array $record,?string $code=null,string $phase='dev_ssh_transport'):array
    {
        $safe=['mode'=>'dev_ssh','stage'=>in_array($phase,self::DEV_PHASES,true)?$phase:'dev_ssh_transport'];
        if(in_array($record['stage']??null,self::DEV_PHASES,true))$safe['stage']=$record['stage'];
        if(is_string($record['transport_id']??null)&&preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$record['transport_id']))$safe['transport_id']=$record['transport_id'];
        if(is_string($record['config_sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$record['config_sha256']))$safe['config_sha256']=$record['config_sha256'];
        $code??=$record['safe_error_code']??null;
        if($code!==null)$safe['safe_error_code']=in_array($code,self::DEV_CODES,true)?$code:'ONEC_DEV_TRANSPORT_UNKNOWN';
        foreach(['http_status'=>599,'curl_errno'=>9999,'ssl_verify_result'=>65535,'duration_ms'=>90000,'bytes'=>16777216]as$key=>$max){
            $value=$record[$key]??null;
            if(is_int($value)&&$value>=($key==='http_status'?100:0)&&$value<=$max)$safe[$key]=$value;
        }
        foreach(['primary_ip_verified','frame_attempted','possible_request_transmission']as$key)if(is_bool($record[$key]??null))$safe[$key]=$record[$key];
        return $safe;
    }

    public static function devFailure(\Throwable $error,array $record=[],string $phase='profile_binding'):?array
    {
        $message=$error->getMessage();
        if(!in_array($message,self::DEV_CODES,true)&&($record['mode']??null)!=='dev_ssh')return null;
        $recordCode=$record['safe_error_code']??null;
        $code=in_array($message,self::DEV_CODES,true)?$message:(is_string($recordCode)?$recordCode:'ONEC_DEV_TRANSPORT_UNKNOWN');
        return self::transportDiagnostic($record,$code,$phase);
    }

    /** Actual selected OneC context, independent of an optional stored owner seal. */
    public static function selectedContext(\PDO $db,array $profile,array $transport):array
    {
        OnecDevSshTransport::validateSettings($transport);
        $connection=Config::get($db,'onec');
        $public=Support::decode($connection['config_json']);
        $reference=Config::reference($public['access_reference']??'');
        $query=$db->query("SELECT selected_profile,source_revision,source_receipt_sha256 FROM integration_configuration_sources WHERE provider='onec'");
        $source=$query->fetch(\PDO::FETCH_ASSOC)?:[];
        $selected=Config::profile('onec',$reference);
        return self::contextFromSelection($connection,$source,$selected,$profile,$transport);
    }

    private static function contextFromSelection(array $connection,array $source,array $selected,array $profile,array $transport):array
    {
        $reference=$selected['_reference']??null;$revision=(int)($connection['revision']??0);
        $public=Support::decode($connection['config_json']??'{}');
        if(empty($connection['enabled'])||$revision<1||!is_string($reference)||$reference===''
            ||($public['access_reference']??null)!==$reference||($profile['_reference']??null)!==$reference
            ||($source['selected_profile']??null)!==$reference||(int)($source['source_revision']??0)!==$revision
            ||($transport['profile_reference']??null)!==$reference||($transport['profile_revision']??null)!==$revision
            ||!is_string($source['source_receipt_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$source['source_receipt_sha256'])
            ||($selected['_source_receipt_sha256']??null)!==$source['source_receipt_sha256'])throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
        foreach([$selected,$profile]as$value){
            if(($value['_owner_bound_revision']??null)!==null&&$value['_owner_bound_revision']!==$revision)throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
        }
        foreach(['username','password']as$key)if(!is_string($selected[$key]??null)||$selected[$key]===''
            ||!is_string($profile[$key]??null)||!hash_equals($selected[$key],$profile[$key]))throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
        return ['profile_reference'=>$reference,'profile_revision'=>$revision];
    }

    public static function create(array $profile,int $timeout=30,string $purpose='connection',?string $caFile=null):\NativeOnec\Reader
    {
        require_once __DIR__.'/native/php/onec/ReadOnly.php';
        unset($GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']);
        $config=OnecDevSshTransport::settings();$callback=null;
        if($config!==null){
            $context=self::selectedContext(\App\DB::pdo(),$profile,$config);
            $transport=new OnecDevSshTransport($config);
            // Compatibility adapter for the existing strict transport guard. This
            // is actual DB transport metadata, not a newly persisted owner seal.
            $transport->assertProfile(['_reference'=>$context['profile_reference'],'_owner_bound_revision'=>$context['profile_revision']]);
            $identity=OnecDevSshTransport::identity();
            $callback=static function(string $method,string $resource,array $query,array $headers,float $seconds,int $cap)use($transport,$purpose,$identity,$timeout,$profile,$config,$context):array{
                $phase='profile_binding';
                try{
                    if(OnecDevSshTransport::identity()!==$identity)throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
                    if(self::selectedContext(\App\DB::pdo(),$profile,$config)!==$context)throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
                    $phase='request_admission';$request=OnecDevRequest::make($method,$resource,$query,$headers,$seconds,$cap,$purpose);
                    $phase='dev_ssh_transport';$response=$transport->exchange($request,$seconds<$timeout?0:30000);
                }
                catch(\Throwable $error){
                    $GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']=self::devFailure($error,$identity,$phase);
                    throw $error;
                }
                $GLOBALS['IPPHP_ONEC_DEV_LAST_RECEIPT']=self::transportDiagnostic($identity+$response['dev_transport'],null,'response_validation');
                return $response;
            };
        }
        return new \NativeOnec\Reader($profile['username']??'',$profile['password']??'',$caFile,$callback,$timeout);
    }

    public static function connection(\NativeOnec\Reader $reader):?array
    {
        if(OnecDevSshTransport::settings()===null){$reader->service();return null;}
        $page=$reader->rows('organizations',1,0);
        return ['scope'=>'organization_read_capability','organization_count'=>$page['count'],
            'service_root_checked'=>false,'bank_statement_checked'=>false,'native_write_checked'=>false,
            'transport'=>OnecDevSshTransport::identity(),'http'=>200];
    }
}
