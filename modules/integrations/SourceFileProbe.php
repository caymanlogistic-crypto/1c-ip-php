<?php
declare(strict_types=1);
namespace Modules\Integrations;

use RuntimeException;

/** Full bounded bodies in memory only. No Registry, PrivateStore, print job or writer. */
final class SourceFileProbe
{
    public const FILES=2;
    public const BODY=8388608;
    public const WIRE=33554432;
    private int $attempts=0;
    private int $wireBytes=0;
    private float $deadline;
    private ?array $mapping=null;
    private bool $stopped=false;
    public function __construct(private mixed $call){$this->deadline=hrtime(true)/1e9+120;}

    /** Shared request identity for ordinary FileService and ephemeral CHECK. */
    public static function plan(string $provider,string $kind,string $accountId,array $row,array $account,?array $mapping,bool $check):array
    {
        if(!in_array($kind,['original','pdf'],true))throw new RuntimeException('FILE_BINDING_FAILED');
        if($check&&$provider==='pik')return ['state'=>'requires_generation'];
        if($check&&$provider==='mosenergo')return ['state'=>'unprobed_download_post'];
        if($provider==='pik'){
            if(($account['_type']??'')==='virtualaccount'||empty($account['number']))throw new RuntimeException('BILLING_ACCOUNT_REQUIRED');
            if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$accountId)||!is_string($row['_uid']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$row['_uid']))throw new RuntimeException('FILE_BINDING_FAILED');
            return ['state'=>'ready','operation'=>'receipt_pdf','params'=>['account'=>$accountId,'receipt'=>['_uid'=>Support::identity($row['_uid']??null),'period'=>Support::day($row['period']??null)]],'expected_bytes'=>null,'format'=>'pdf'];
        }
        if($provider==='mosvodokanal'){
            if(!preg_match('/^[0-9]{1,25}$/D',$accountId)||!preg_match('/^[0-9]{1,25}$/D',(string)($row['ID']??''))||!preg_match('/^[0-9]{1,25}$/D',(string)($row['FL_DOC']??'')))throw new RuntimeException('FILE_BINDING_FAILED');
            if($mapping===null)return ['state'=>'mapping_required'];
            return ['state'=>'ready','operation'=>'receipt_source_file','params'=>['account'=>$accountId,'receipt'=>['id'=>Support::identity($row['ID']??null),'type'=>Support::identity($row['FL_DOC']??null),'publisherReportCd'=>$mapping['publisher_report_cd']]],'expected_bytes'=>null,'format'=>'source_pdf_or_docx'];
        }
        if($provider!=='diadoc')return ['state'=>'unsupported_source_file'];
        $native=$row['native_document']??null;
        if(!is_array($native)||($native['isEncrypted']??null)!==false||!is_string($row['name']??null)||!is_int($row['file_size']??null)||$row['file_size']<1||$row['file_size']>self::BODY)throw new RuntimeException('FILE_BINDING_FAILED');
        foreach(['message_id','document_id']as$key)if(!is_string($row[$key]??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$row[$key]))throw new RuntimeException('FILE_BINDING_FAILED');
        $document=['message_id'=>Support::identity($row['message_id']??null),'document_id'=>Support::identity($row['document_id']??null),
            'name'=>(string)($row['name']??''),'file_size'=>$row['file_size']];
        if($kind==='pdf'){
            if($check)throw new RuntimeException('CHECK_PRINT_JOB_FORBIDDEN');
            $observed=['baseline'=>'current_registry_v1']+$document;
            $observed['type']=$row['type']??'';$observed['encrypted']=false;
            $document['source_binding']=hash('sha256',Support::canonical($observed));
        }
        return ['state'=>'ready','operation'=>$kind==='original'?'original':'selected_native_print_pdf','params'=>['document'=>$document],
            'expected_bytes'=>$row['file_size'],'format'=>'original'];
    }

    private function request(string $operation,array $params):array
    {
        if(!in_array($operation,['original','receipt_print_metadata','receipt_source_file'],true))throw new RuntimeException('CHECK_FILE_OPERATION_FORBIDDEN');
        $remaining=(int)floor(($this->deadline-hrtime(true)/1e9)*1000);
        if($remaining<1||$this->wireBytes>=self::WIRE)throw new RuntimeException('CHECK_FILE_BUDGET');
        $response=($this->call)($operation,$params+['check_probe'=>true,'remaining_ms'=>$remaining]);
        return $response['data']??[];
    }

    public function inspect(string $provider,array $row,string $accountId,array $account):array
    {
        $result=['state'=>'unconfirmed','full_body_read'=>false,'independent_integrity'=>'unknown','originals_verified'=>0,
            'raw_response_archives'=>0,'original_file_writes'=>0,'native_print_jobs'=>0,'canonical_document_imports'=>0,
            'technical_session_state'=>$provider==='diadoc'?'existing_cookie_jar_may_flush':'caller_session_cache_may_update'];
        if($this->stopped)return $result+['reason_code'=>'CHECK_FILE_STOPPED_AFTER_UNCONFIRMED'];
        if($this->attempts>=self::FILES)return $result+['reason_code'=>'CHECK_FILE_BUDGET'];
        $this->attempts++;
        try{
            $plan=self::plan($provider,'original',$accountId,$row,$account,$this->mapping,true);
            if($plan['state']==='mapping_required'){
                $metadata=$this->request('receipt_print_metadata',[]);
                $size=strlen(Support::json($metadata));if($size>1048576)throw new RuntimeException('CHECK_FILE_BUDGET');
                $this->wireBytes+=$size;$this->mapping=MvkMapping::inspect($metadata);
                $plan=self::plan($provider,'original',$accountId,$row,$account,$this->mapping,true);
            }
            if($plan['state']!=='ready')return array_replace($result,['state'=>$plan['state']]);
            $data=$this->request($plan['operation'],$plan['params']);
            $encoded=$data['body_base64']??$data['base64']??null;
            if(!is_int($data['bytes']??null)||$data['bytes']<1||$data['bytes']>self::BODY||!is_string($encoded)
                ||strlen($encoded)>4*(int)ceil(self::BODY/3)||$this->wireBytes+$data['bytes']>self::WIRE)throw new RuntimeException('CHECK_FILE_BUDGET');
            $bytes=base64_decode($encoded,true);
            if($bytes===false||strlen($bytes)!==$data['bytes']||!is_string($data['sha256']??null)||!hash_equals($data['sha256'],hash('sha256',$bytes)))throw new RuntimeException('FILE_CHECKSUM_FAILED');
            $this->wireBytes+=strlen($bytes);
            if(hrtime(true)/1e9>$this->deadline)throw new RuntimeException('CHECK_FILE_BUDGET');
            if($plan['expected_bytes']!==null&&strlen($bytes)!==$plan['expected_bytes'])throw new RuntimeException('FILE_BINDING_FAILED');
            $mime=$data['content_type']??$data['mime']??'';
            if(!is_string($mime)||preg_match('/text\/html|application\/json|[\r\n]/i',$mime))throw new RuntimeException('FILE_FORMAT_CHANGED');
            if($plan['format']==='source_pdf_or_docx'&&!str_starts_with($bytes,'%PDF-')&&!str_starts_with($bytes,"PK\x03\x04"))throw new RuntimeException('FILE_FORMAT_CHANGED');
            return array_replace($result,['state'=>'body_read_fingerprinted','full_body_read'=>true,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),
                'expected_size'=>$plan['expected_bytes']===null?'unknown':'matched','expected_provider_hash'=>'not_published',
                'format_validation'=>$plan['format']==='source_pdf_or_docx'?'signature_only':($mime!==''?'content_type_only':'unknown')]);
        }catch(\Throwable $error){
            $this->stopped=true;
            $safe=['CHECK_FILE_BUDGET','FILE_BINDING_FAILED','FILE_CHECKSUM_FAILED','FILE_FORMAT_CHANGED','FILE_MAPPING_NOT_CONFIRMED','SOURCE_SCHEMA_CHANGED','AUTH_REQUIRED','SESSION_NOT_AUTHENTICATED','REVISION_CONFLICT','RESPONSE_TOO_LARGE'];
            return $result+['reason_code'=>in_array($error->getMessage(),$safe,true)?$error->getMessage():'FILE_PROBE_UNCONFIRMED'];
        }
    }
}
