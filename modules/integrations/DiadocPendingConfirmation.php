<?php
declare(strict_types=1);
namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** Existing challenge only: no bootstrap, password login or push renewal exists here. */
final class DiadocPendingConfirmation
{
    public const REQUEST='03a52240-3536-4a93-81a9-6fc996f0c2f3';
    public const FRESH_REQUEST='11c00c63-092d-4e4f-ac70-9532b41dc134';
    public static function binding(string $admission):array
    {
        return match($admission){
            'confirm-same-mounted-diadoc-20261116','confirm-same-mounted-diadoc-late-owner-20261116'=>[self::REQUEST,DiadocMountedProfile::REQUEST],
            'confirm-new-mounted-push-20261116'=>[self::FRESH_REQUEST,DiadocFreshContinuation::REQUEST],
            'confirm-owner-restart-push-20261116'=>['03d2f210-8fc4-41a2-9450-92c84938dbbe',DiadocFreshContinuation::OWNER_RESTART_REQUEST],
            default=>throw new RuntimeException('DIADOC_CONFIRM_NOT_AUTHORIZED'),
        };
    }
    public static function pending(array $pending,int $now):void
    {
        if(($pending['state']??null)!=='awaiting_confirmation'||!is_int($pending['created_at']??null)
            ||$now<$pending['created_at']||$now-$pending['created_at']>600)throw new RuntimeException('DIADOC_PENDING_EXPIRED_OR_INVALID');
        foreach(['PushId','UserId','PartialFactorToken']as$key)if(!is_string($pending['details'][$key]??null)||$pending['details'][$key]==='')throw new RuntimeException('DIADOC_PENDING_EXPIRED_OR_INVALID');
    }
    public static function lateOwnerPending(array $pending,int $now,array $attestation,string $originalRun):void
    {
        if(!is_int($pending['created_at']??null)||$pending['created_at']>$now||$now-$pending['created_at']<=600)throw new RuntimeException('DIADOC_LATE_OWNER_ATTESTATION_INVALID');
        self::pending($pending,$pending['created_at']);
        if(($attestation['provider']??null)!=='diadoc'||($attestation['revision']??null)!==3
            ||($attestation['original_run_id']??null)!==$originalRun||($attestation['owner_confirmed']??null)!==true
            ||($attestation['scope']??null)!=='one-late-existing-status-no-renewal'
            ||!is_int($attestation['issued_at']??null)||($attestation['expires_at']??null)!==$attestation['issued_at']+120
            ||$now<$attestation['issued_at']||$now>$attestation['expires_at'])throw new RuntimeException('DIADOC_LATE_OWNER_ATTESTATION_INVALID');
    }
    public function __construct(private PDO $db,private PrivateStore $store){}
    public function run():array
    {
        $admission=(string)getenv('IPPHP_DIADOC_CONFIRM_ADMISSION');
        $late=$admission==='confirm-same-mounted-diadoc-late-owner-20261116';
        if(PHP_SAPI!=='cli'||!Config::liveAllowed())throw new RuntimeException('DIADOC_CONFIRM_NOT_AUTHORIZED');
        [$requestKey,$initialRequest]=self::binding($admission);
        $lock='ipphp-provider:diadoc';$q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('PROVIDER_BUSY');
        $fileLock=null;$http=null;
        try{
            $q=$this->db->prepare("SELECT * FROM integration_runs WHERE provider='diadoc' AND actor_id=0 AND request_key=?");$q->execute([$requestKey]);
            if($old=$q->fetch(PDO::FETCH_ASSOC))return ['id'=>$old['id'],'status'=>$old['status'],'reason_code'=>$old['result_code'],'reused'=>true];
            $connection=Config::get($this->db,'diadoc');
            $source=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='diadoc'")->fetch(PDO::FETCH_ASSOC);
            DiadocMountedProfile::assertCurrent($connection,$source,3,DiadocMountedProfile::REFERENCE);
            $q=$this->db->prepare("SELECT * FROM integration_runs WHERE provider='diadoc' AND request_key=? AND revision=3");$q->execute([$initialRequest]);
            $initial=$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('DIADOC_ORIGINAL_PENDING_REQUIRED');
            $initialMetadata=Support::decode($initial['coverage_json']);$receipt=$this->store->verifySpool($initialMetadata['receipt_path']??'');
            $original=Support::decode(base64_decode($receipt['raw_base64'],true));
            if($initial['result_code']!=='PUSH_REQUIRED'||($original['code']??null)!=='PUSH_REQUIRED'
                ||($receipt['context']['run_id']??null)!==$initial['id']
                ||($initialMetadata['source_receipt_sha256']??null)!==$source['source_receipt_sha256'])throw new RuntimeException('DIADOC_ORIGINAL_PENDING_REQUIRED');
            $directory=Config::root().'/private/integrations/state/diadoc/'.DiadocMountedProfile::REFERENCE;
            $fileLock=fopen($directory.'/run.lock','c');if(!$fileLock||!flock($fileLock,LOCK_EX|LOCK_NB))throw new RuntimeException('PROVIDER_BUSY');
            $pendingFile=$directory.'/pending.json';
            if(!is_file($pendingFile)||is_link($pendingFile)||filesize($pendingFile)>65536)throw new RuntimeException('DIADOC_PENDING_EXPIRED_OR_INVALID');
            $pendingRaw=file_get_contents($pendingFile);$pending=Support::decode($pendingRaw);$attestation=[];
            if($late){
                $attested=$this->store->verifySpool((string)getenv('IPPHP_DIADOC_OWNER_ATTESTATION'));
                if(($attested['context']['operation']??null)!=='owner-existing-push-attestation')throw new RuntimeException('DIADOC_LATE_OWNER_ATTESTATION_INVALID');
                $attestation=Support::decode(base64_decode($attested['raw_base64'],true));
                self::lateOwnerPending($pending,time(),$attestation,$initial['id']);
            }else self::pending($pending,time());
            $created=strtotime($initial['created_at'].' Europe/Moscow');$finished=strtotime($initial['finished_at'].' Europe/Moscow');
            if($pending['created_at']<$created-1||$pending['created_at']>$finished+1)throw new RuntimeException('DIADOC_ORIGINAL_PENDING_REQUIRED');
            $profile=Config::profile('diadoc',DiadocMountedProfile::REFERENCE);
            $id=Support::uuid();$metadata=['read_only'=>true,'sends'=>0,'provider_writes'=>0,'canonical_document_imports'=>0,
                'auth_request_mode'=>$late?'late_owner_existing_push_once':'existing_push_confirmation_once','source_receipt_sha256'=>$source['source_receipt_sha256'],
                'operative_revision_at_check'=>3,'private_profile_reference'=>DiadocMountedProfile::REFERENCE,
                'auth_raw_body_archive_forbidden'=>true,'original_run_id'=>$initial['id'],'operative_scope_current'=>true,
                'owner_attested_late_continuation'=>$late,'local_window_elapsed'=>$late,'provider_validity'=>'unverified_until_response'];
            $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json) VALUES(?,'diadoc','auth-metadata',?,0,3,?,'pending',?)")
                ->execute([$id,$requestKey,hash('sha256',Support::canonical([$initial['id'],$source['source_receipt_sha256'],$requestKey])),Support::json($metadata)]);
            $status='failed';$code='AUTH_METADATA_CHECK_FAILED';$requests=[];
            $had=array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL',$GLOBALS);$prior=$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']??null;
            $hadCapture=array_key_exists('IPPHP_CAPTURE_RAW',$GLOBALS);$capture=$GLOBALS['IPPHP_CAPTURE_RAW']??null;
            $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=true;unset($GLOBALS['IPPHP_CAPTURE_RAW']);
            try{
                require_once __DIR__.'/native/php/diadoc/Http.php';require_once __DIR__.'/native/php/diadoc/WebClient.php';
                $http=new \Diadoc\Http($directory,Support::caFile(),function(array $row)use(&$requests,$source,$pending,$pendingRaw,$pendingFile,$late,$attestation,$initial){
                    if(($row['status']??null)==='started'){
                        if($late)self::lateOwnerPending($pending,time(),$attestation,$initial['id']);else self::pending($pending,time());
                        $currentSource=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='diadoc'")->fetch(PDO::FETCH_ASSOC);
                        DiadocMountedProfile::assertCurrent(Config::get($this->db,'diadoc'),$currentSource,3,DiadocMountedProfile::REFERENCE);
                        if($currentSource!==$source||!hash_equals(hash('sha256',$pendingRaw),hash_file('sha256',$pendingFile)))throw new RuntimeException('DIADOC_PENDING_CHANGED');
                    }elseif(is_int($row['status']??null))$requests[]=['stage'=>$row['stage'],'http_status'=>$row['status'],'curl_errno'=>$row['curl_errno']??0];
                },hrtime(true)/1e9+60);
                $client=new \Diadoc\WebClient($http);
                $cached=false;
                if($late){try{$client->page($profile['box_id'],'Any',1);$cached=true;}catch(RuntimeException $error){if($error->getMessage()!=='SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN')throw $error;}}
                // No callback or model roundtrip between the bound status check and confirmation.
                // WebClient retains its normal600s guard. Only this attested one-shot
                // checks the exact existing source routes directly after local expiry.
                $result=$cached?['state'=>'authenticated']:($late?self::confirmLate($http,$pending):$client->confirm($pending));
                if(($result['state']??null)==='authenticated'){
                    if(!$cached)$client->page($profile['box_id'],'Any',1);$status='success';$code='AUTH_METADATA_CHECKED';
                    if(!hash_equals(hash('sha256',$pendingRaw),hash_file('sha256',$pendingFile)))throw new RuntimeException('DIADOC_PENDING_CHANGED');
                    unlink($pendingFile);
                }else $code=$late?'EXISTING_CONFIRMATION_NOT_CONFIRMED':'PUSH_REQUIRED';
            }catch(\Throwable $error){$code=in_array($error->getMessage(),['CONFIRMATION_REFUSED','CONFIRMATION_EXPIRED_RUN_LOGIN','CONFIRMATION_PROVIDER_EXPIRED','AUTH_SESSION_MISSING','CONFIRMATION_CODE_MISSING','PUSH_REQUIRED','DIADOC_PENDING_CHANGED'],true)||preg_match('/^CONFIRMATION_(?:CHECK_FAILED|FAILED)_HTTP_[1-5][0-9]{2}$/D',$error->getMessage())?$error->getMessage():'AUTH_METADATA_CHECK_FAILED';$status='failed';}
            finally{if($http)$http->close();if($had)$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=$prior;else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);if($hadCapture)$GLOBALS['IPPHP_CAPTURE_RAW']=$capture;else unset($GLOBALS['IPPHP_CAPTURE_RAW']);}
            $metadata['auth_diagnostic']=['auth_mode'=>'existing_push','login_attempted'=>false,'stage'=>$status==='success'?'authenticated_metadata':'push_confirmation','requests'=>$requests];
            $metadata['receipt_path']=$this->store->spool(['run_id'=>$id,'provider'=>'diadoc','operation'=>'auth-metadata','request_key'=>$requestKey],Support::json(['status'=>$status,'code'=>$code,'metadata'=>$metadata]));
            $this->db->prepare("UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=? AND status='pending'")->execute([$status,$code,Support::json($metadata),$id]);
            return ['id'=>$id,'status'=>$status,'reason_code'=>$code,'revision'=>3,'receipt_path'=>$metadata['receipt_path'],'fresh_login_attempts'=>0,'new_pushes'=>0];
        }finally{if(is_resource($fileLock)){flock($fileLock,LOCK_UN);fclose($fileLock);}$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }

    public static function confirmLate(object $http,array $pending):array
    {
        $csrf=$http->cookie('AntiForgery');if($csrf==='')throw new RuntimeException('AUTH_SESSION_MISSING');
        $headers=['X-CSRF-Token: '.$csrf,'Origin: https://auth.kontur.ru','Referer: https://auth.kontur.ru/'];
        $response=$http->request('POST','https://auth.kontur.ru/api/authentication/totp/get-code-by-pushid?customize=diadoc',$pending['details']['PushId'],$headers);
        $data=Support::decode($response['body']);
        if($response['status']===410||($data['Status']??null)==='Expired')throw new RuntimeException('CONFIRMATION_PROVIDER_EXPIRED');
        if($response['status']!==200)throw new RuntimeException('CONFIRMATION_CHECK_FAILED_HTTP_'.$response['status']);
        if(($data['Status']??null)==='Refused')throw new RuntimeException('CONFIRMATION_REFUSED');
        if(($data['Status']??null)!=='Confirmed')return ['state'=>'awaiting_confirmation'];
        if(!is_string($data['Code']??null)||$data['Code']==='')throw new RuntimeException('CONFIRMATION_CODE_MISSING');
        $response=$http->request('POST','https://auth.kontur.ru/api/authentication/totp/auth-by-code?customize=diadoc',[
            'Code'=>$data['Code'],'PartialFactorToken'=>$pending['details']['PartialFactorToken'],'Remember'=>false,'UserId'=>$pending['details']['UserId']],$headers);
        $data=Support::decode($response['body']);
        if($response['status']!==200||isset($data['errorStatus']))throw new RuntimeException('CONFIRMATION_FAILED_HTTP_'.$response['status']);
        return ['state'=>'authenticated'];
    }
}
