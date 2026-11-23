<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class MessageTransport
{
    public function __construct(private mixed $maxHttpFixture = null,private mixed $beforeMaxRequest = null,private ?WorkflowMaxEvent $workflowEvent = null,private MaxReadCheck|LocalMaxReadCheck|ServerObjectMax|ServerWorkflowMax|BookingMax|ContractWorkflowReceiver|null $readCheck = null) {}
    public static function maxPayload(string $text,?string $format=null): string
    {
        return Support::json(['text'=>$text]+($format===null?[]:['format'=>$format]));
    }

    public static function safeException(\Throwable $error): array
    {
        $classes=['RuntimeException','InvalidArgumentException','PDOException','JsonException','TypeError','Error','Exception'];
        $codes=['RENTAL_MAX_POSTCOMMIT_MARKER_INVALID','RENTAL_MAX_POSTCOMMIT_MARKER_REQUIRED','RENTAL_MAX_POSTCOMMIT_MARKER_CHANGED',
            'LOCAL_MAX_SEND_NOT_AUTHORIZED','LOCAL_MAX_SEND_PATH_NOT_AUTHORIZED','LOCAL_MAX_READ_NOT_AUTHORIZED','LOCAL_MAX_READ_CONTEXT_CHANGED','LOCAL_MAX_READ_GROUP_CHANGED',
            'LOCAL_MAX_READ_PATH_NOT_AUTHORIZED','LOCAL_MAX_READ_BUSY','LOCAL_MAX_READ_ACTOR_NOT_AUTHORIZED',
            'LOCAL_MAX_READ_PENDING_REQUIRES_RECONCILIATION',
            'RENTAL_MAX_POSTCOMMIT_CONFIG_CHANGED','RENTAL_MAX_POSTCOMMIT_CONTEXT_CHANGED',
            'MAX_READ_SELECTOR_INVALID','MAX_READ_LOCAL_R2_ONLY','MAX_READ_PROJECT_EPOCH_CHANGED','MAX_READ_ACTOR_NOT_AUTHORIZED',
            'MAX_READ_REVISION_CHANGED','MAX_READ_APPROVED_GROUP_REQUIRED','MAX_READ_SOURCE_CHANGED','MAX_READ_SCOPE_NOT_AUTHORIZED',
            'MAX_READ_CONTEXT_CHANGED','MAX_READ_COMMITTED_CLAIM_REQUIRED','MAX_READ_OPERATION_NOT_AUTHORIZED','MAX_READ_PATH_NOT_AUTHORIZED',
            'MAX_READ_UPPER_GUARD_REQUIRED','MAX_READ_PLAN_CHANGED','MAX_READ_BUSY','MAX_READ_EXISTING_VAULT_REQUIRED','MAX_READ_PRIVATE_BINDING_CHANGED',
            'NETWORK_OR_TLS_FAILED','CA_FILE_UNAVAILABLE','RESPONSE_TOO_LARGE','HTTPS_REQUIRED','INVALID_HTTP_TIMEOUT','EXTERNAL_ACCESS_NOT_AUTHORIZED',
            'RENTAL_MAX_SCOPE_NOT_AUTHORIZED','RENTAL_MAX_AFTER_COMMIT_ONLY','RENTAL_MAX_SELECTOR_INVALID','RENTAL_MAX_COMMITTED_EVENT_REQUIRED',
            'RENTAL_MAX_EVENT_OUTSIDE_ROLLOUT','RENTAL_MAX_EVENT_NOT_PLANNED','RENTAL_MAX_ACTION_NOT_PLANNED','RENTAL_MAX_TRANSITION_PROOF_INVALID',
            'RENTAL_MAX_EVENT_BINDING_CHANGED','RENTAL_MAX_COMMAND_PROOF_CHANGED','RENTAL_MAX_EVENT_OWNER_CHANGED','RENTAL_MAX_ACTOR_MISSING',
            'RENTAL_MAX_ACTOR_CAPABILITY_CHANGED','RENTAL_MAX_REVISION_CHANGED','RENTAL_MAX_APPROVED_GROUP_REQUIRED','RENTAL_MAX_SOURCE_BINDING_CHANGED',
            'RENTAL_MAX_RESPONSIBLE_UNRESOLVED','RENTAL_MAX_CATALOG_REVISION_CHANGED','RENTAL_MAX_WORD_MODE_CATALOG_UPDATE_REQUIRED',
            'RENTAL_MAX_CONTEXT_CHANGED','RENTAL_MAX_TARGET_CHANGED','RENTAL_MAX_PRIVATE_BINDING_CHANGED','RENTAL_MAX_COMMITTED_CLAIM_REQUIRED',
            'RENTAL_MAX_HTTP_SCOPE_REJECTED','CREDENTIAL_REVISION_CONFLICT','CATALOG_ENTRY_NOT_FOUND','SECRET_STORE_CORRUPT','SPOOL_HMAC_FAILED','SPOOL_BYTES_FAILED',
            'MAX_RESPONSE_REJECTED','MAX_CONTEXT_CHANGED','BOT_IDENTITY_MISSING','BOT_MEMBERSHIP_MISSING','INVALID_GROUP'];
        return ['exception_class'=>in_array(get_class($error),$classes,true)?get_class($error):'Throwable',
            'exception_code'=>is_int($error->getCode())?$error->getCode():null,
            'exception_category'=>in_array($error->getMessage(),$codes,true)?$error->getMessage():'UNCLASSIFIED_EXCEPTION'];
    }
    /** Creates exact MIME bytes before claiming a delivery; same bytes enter signed spool. */
    public static function mime(array $config,string $target,string $subject,string $text,array $attachments,string $messageId): string {
        foreach([$target,$subject,$config['from_email']??'',$config['from_name']??''] as $v)if(preg_match('/[\r\n\x00]/',(string)$v))throw new \InvalidArgumentException('HEADER_INJECTION');
        if(!filter_var($target,FILTER_VALIDATE_EMAIL)||!filter_var($config['from_email']??'',FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('INVALID_EMAIL');
        $b='=_ipphp_'.bin2hex(random_bytes(18));$enc=static fn(string $v):string=>'=?UTF-8?B?'.base64_encode($v).'?=';
        $h='From: '.$enc((string)($config['from_name']??'')).' <'.$config['from_email'].">\r\nTo: <".$target.">\r\nSubject: ".$enc($subject)."\r\nMessage-ID: <".$messageId.">\r\nDate: ".gmdate('D, d M Y H:i:s +0000')."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"".$b."\"\r\n\r\n";
        $h.='--'.$b."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text),76,"\r\n");
        foreach($attachments as $a){$filename=rawurlencode($a['name']);$h.='--'.$b."\r\nContent-Type: ".$a['mime']."\r\nContent-Disposition: attachment; filename*=UTF-8''".$filename."\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($a['bytes']),76,"\r\n");}return $h.'--'.$b."--\r\n";
    }
    public function execute(string $provider,string $operation,array $config,array $secret,string $target,string $raw,string $remoteId,array $groups=[]): array {
        if(!in_array($operation,['check','send'],true))throw new \InvalidArgumentException('INVALID_MESSAGE_OPERATION');
        if($this->workflowEvent!==null&&$this->readCheck!==null)throw new \RuntimeException('MAX_READ_OPERATION_NOT_AUTHORIZED');
        if($this->readCheck!==null)$this->readCheck->assertOperation($provider,$operation,$target,$raw,$groups);
        elseif($this->workflowEvent!==null)$this->workflowEvent->assertRequest($provider,$operation,$target,$raw);
        elseif(!Config::liveAllowed()&&!($provider==='email'&&(Config::emailLiveAllowed()||EmailAdmission::allows($config))))return ['status'=>'failed','code'=>'EXTERNAL_ACCESS_NOT_AUTHORIZED'];
        if($operation==='send'){$allowed=$provider==='email'?($secret['approved_recipients']??[]):($secret['approved_chat_ids']??[]);if(!is_array($allowed)||!in_array($provider==='email'?strtolower($target):$target,array_map(static fn($v)=>$provider==='email'?strtolower((string)$v):(string)$v,$allowed),true))return ['status'=>'failed','code'=>'TARGET_NOT_APPROVED'];}
        if($provider==='max')return $this->max($operation,$secret,$target,$raw,$groups);
        if($provider!=='email')throw new \InvalidArgumentException('UNKNOWN_MESSAGE_PROVIDER');
        return $this->smtp($operation,$config,$secret,$target,$raw,$remoteId);
    }
    private function max(string $operation,array $secret,string $target,string $raw,array $groups): array
    {
        $token=$secret['secret']??'';
        if (!is_string($token)||$token===''||preg_match('/[\r\n]/',$token)) return ['status'=>'failed','code'=>'AUTH_REQUIRED'];
        $diagnostic=['http_status'=>null,'phase'=>'not_started','safe_error_code'=>null,'transport_stage'=>'request_setup',
            'curl_started'=>false,'curl_completed'=>false,'curl_errno'=>null];
        $call=function(string $path,?string $body=null)use($token,&$diagnostic):array {
            $diagnostic['phase']=$path==='/me'?'token_identity':($body===null?'chat_membership':'message_send');
            $diagnostic['http_status']=null;
            $headers=['Authorization: '.$token,'Content-Type: application/json'];
            $method=$body===null?'GET':'POST';
            $diagnostic['transport_stage']='before_request_guard';
            if($this->readCheck!==null)$this->readCheck->beforeRequest($method,$path,$body);
            if(is_callable($this->beforeMaxRequest))($this->beforeMaxRequest)($method,$path);
            $observer=static function(string $stage,array $facts)use(&$diagnostic):void{
                $diagnostic['transport_stage']=$stage;
                foreach(['ca_configured','ca_regular','ssl_backend','curl_started','curl_completed','curl_errno','observed_http_status'] as $key)
                    if(array_key_exists($key,$facts))$diagnostic[$key]=$facts[$key];
            };
            $response=is_callable($this->maxHttpFixture)
                ?($this->maxHttpFixture)($method,$path,$headers,$body,25)
                :HttpTransport::request($method,'https://platform-api2.max.ru'.$path,$headers,$body,1048576,25,$this->workflowEvent,$observer,$this->readCheck);
            $diagnostic['transport_stage']='response_validate';
            $status=$response['status']??null;
            if (!is_int($status)||$status<100||$status>599||!is_string($response['raw']??null)) throw new \RuntimeException('MAX_RESPONSE_REJECTED');
            $diagnostic['http_status']=$status;
            if ($status<200||$status>=300) throw new \RuntimeException('HTTP_'.$status);
            $diagnostic['transport_stage']='response_json';
            $value=json_decode($response['raw'],true,32,JSON_THROW_ON_ERROR);
            if (!is_array($value)||($value['success']??true)===false||!empty($value['code'])) throw new \RuntimeException('MAX_RESPONSE_REJECTED');
            return $value;
        };
        try {
            if ($operation==='check') {
                $bot=$call('/me');
                if (($bot['is_bot']??false)!==true||!is_int($bot['user_id']??null)) throw new \RuntimeException('BOT_IDENTITY_MISSING');
                foreach ($groups as $group) {
                    if (!preg_match('/^-?\d{1,24}$/D',$group)) throw new \RuntimeException('INVALID_GROUP');
                    $member=$call('/chats/'.$group.'/members/me');
                    if (($member['user_id']??null)!==$bot['user_id']) throw new \RuntimeException('BOT_MEMBERSHIP_MISSING');
                }
                return ['status'=>'success','code'=>'CHECKED_NO_SEND']+$diagnostic;
            }
            $value=$call('/messages?chat_id='.rawurlencode($target),$raw);
            $diagnostic['transport_stage']='acceptance_validate';
            $message=$value['message']['body']['mid']??null;
            if (!$message||(!is_string($message)&&!is_int($message))||(string)($value['message']['recipient']['chat_id']??'')!==$target) {
                $diagnostic['safe_error_code']='MESSAGE_ACK_BINDING_UNCONFIRMED';
                return ['status'=>'uncertain','code'=>'MAX_ACCEPTANCE_UNKNOWN']+$diagnostic;
            }
            if (($this->readCheck instanceof BookingMax || $this->readCheck instanceof ServerWorkflowMax)
                && ($format=Support::decode($raw)['format'] ?? null)) {
                $markup=$value['message']['body']['markup'] ?? [];
                $diagnostic['format']=$format;
                $diagnostic['bold_spans']=count(array_filter(is_array($markup)?$markup:[],
                    static fn($span)=>is_array($span) && ($span['type'] ?? '')==='strong'));
            }
            return ['status'=>'accepted','code'=>'MAX_ACCEPTED','remote_id'=>mb_substr((string)$message,0,200,'UTF-8')]+$diagnostic;
        } catch (\Throwable $error) {
            $diagnostic+=self::safeException($error);
            $code=$error->getMessage();
            $rejected=preg_match('/^HTTP_4\d\d$/D',$code)===1;
            $diagnostic['safe_error_code']=preg_match('/^HTTP_[1-5][0-9]{2}$/D',$code)
                ?'UPSTREAM_HTTP_REJECTED'
                :(in_array($code,['BOT_IDENTITY_MISSING','BOT_MEMBERSHIP_MISSING','INVALID_GROUP','MAX_RESPONSE_REJECTED','MAX_CONTEXT_CHANGED'],true)?$code:'RESPONSE_NOT_CONFIRMED');
            return ['status'=>$operation==='send'&&!$rejected?'uncertain':'failed',
                'code'=>$rejected?'MAX_REJECTED':($operation==='check'?'CHECK_FAILED':'MAX_ACCEPTANCE_UNKNOWN')]+$diagnostic;
        }
    }
    private function smtp(string $operation,array $c,array $secret,string $target,string $raw,string $remoteId): array {
        $stage='connect';$stream=null;
        try{$host=$c['host']??'';$port=(int)($c['port']??0);$security=$c['security']??'';if(!preg_match('/^[a-z0-9.-]{1,253}$/iD',$host)||$port<1||$port>65535||!in_array($security,['ssl','starttls'],true))throw new \InvalidArgumentException('INVALID_SMTP_CONFIG');$ssl=['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false];if($ca=Support::caFile())$ssl['cafile']=$ca;$context=stream_context_create(['ssl'=>$ssl]);$stream=@stream_socket_client(($security==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$error,25,STREAM_CLIENT_CONNECT,$context);if(!$stream)throw new \RuntimeException('SMTP_NETWORK');stream_set_timeout($stream,25);
            $reply=static function(array $codes)use($stream):int{$last='';$size=0;do{$line=fgets($stream,2048);if($line===false)throw new \RuntimeException('SMTP_NO_RESPONSE');$size+=strlen($line);if($size>65536||!preg_match('/^\d{3}[- ]/',$line))throw new \RuntimeException('SMTP_INVALID_RESPONSE');$last=$line;}while($line[3]==='-');$code=(int)substr($last,0,3);if(!in_array($code,$codes,true))throw new \RuntimeException('SMTP_'.$code);return $code;};
            $cmd=static function(string $line,array $codes)use($stream,$reply){if(fwrite($stream,$line."\r\n")!==strlen($line)+2)throw new \RuntimeException('SMTP_WRITE_FAILED');return $reply($codes);};
            $reply([220]);$cmd('EHLO localhost',[250]);if($security==='starttls'){$cmd('STARTTLS',[220]);if(!stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('SMTP_TLS');$cmd('EHLO localhost',[250]);}$stage='auth';$cmd('AUTH LOGIN',[334]);$cmd(base64_encode((string)($c['username']??'')),[334]);$cmd(base64_encode((string)($secret['secret']??'')),[235]);
            if($operation==='check'){$cmd('NOOP',[250]);return ['status'=>'success','code'=>'CHECKED_NO_SEND'];}
            $stage='envelope';$cmd('MAIL FROM:<'.$c['from_email'].'>',[250]);$cmd('RCPT TO:<'.$target.'>',[250,251]);$cmd('DATA',[354]);$stage='data';$data=preg_replace('/(?m)^\./','..',$raw)."\r\n.\r\n";for($offset=0;$offset<strlen($data);){$n=fwrite($stream,substr($data,$offset,65536));if(!$n)throw new \RuntimeException('SMTP_WRITE_FAILED');$offset+=$n;}$reply([250]);$stage='accepted';return ['status'=>'accepted','code'=>'SMTP_ACCEPTED_NOT_INBOX','remote_id'=>$remoteId];
        }catch(\Throwable $e){$confirmedReject=preg_match('/^SMTP_[45]\d\d$/D',$e->getMessage());return ['status'=>$stage==='data'&&!$confirmedReject?'uncertain':'failed','code'=>$confirmedReject?'SMTP_REJECTED':($stage==='data'?'SMTP_ACCEPTANCE_UNKNOWN':'SMTP_CHECK_OR_AUTH_FAILED')];}finally{if(is_resource($stream))fclose($stream);}
    }
}
