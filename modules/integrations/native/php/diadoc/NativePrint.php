<?php
declare(strict_types=1);
namespace Diadoc;
use RuntimeException;

/** Exact native web-PDF contract observed in public commons.js; never opens a print dialog. */
final class NativePrint
{
    private const PROTOCOL='native-print-webapi-v2';
    private const HEADER='x-request-verification-token';
    private string $journal;
    private array $record=[];
    public function __construct(private object $http,private string $directory,private float $deadline) {}
    public static function jobKey(string $box,string $id,string $type):string{return hash('sha256',$box."\0".$id."\0".$type);}
    public static function validTaskId(mixed $id):bool {
        return is_string($id)&&strlen($id)>=1&&strlen($id)<=1024&&preg_match('//u',$id)===1&&preg_match('/\p{Cc}/u',$id)===0;
    }
    private static function candidateScalar(mixed $value):mixed {
        // Capture bounded UTF8 scalars privately before usability validation; JSON escapes controls.
        if(is_string($value))return strlen($value)<=1024&&preg_match('//u',$value)===1?$value:null;
        return is_int($value)||is_bool($value)||is_float($value)&&is_finite($value)||$value===null?$value:null;
    }
    private static function candidateTask(mixed $candidate,string $binding):?array {
        if(!is_array($candidate)||($candidate['http_status']??null)!==200||($candidate['source_binding']??null)!==$binding
            ||($candidate['recovery']??null)!=='VALIDATE_AND_GET_ONLY_NO_POST')return null;
        $type=$candidate['DocType']??null;
        $task=['id'=>$candidate['TaskId']??null,'type'=>is_int($type)?(string)$type:$type,'name'=>'document.pdf'];
        return self::validTask($task)?$task:null;
    }
    public static function validTask(array $task):bool {
        return count($task)===3&&self::validTaskId($task['id']??null)
            &&is_string($task['type']??null)&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$task['type'])
            &&is_string($task['name']??null)&&strlen($task['name'])<=500&&preg_match('//u',$task['name'])
            &&!preg_match('~[\x00-\x1f\x7f/\\\\]~',$task['name']);
    }
    public static function route(string $method,string $url,mixed $body,array $jobs):?string {
        $u=parse_url($url);if($u===false||($u['scheme']??'')!=='https'||($u['host']??'')!=='diadoc.kontur.ru'
            ||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||isset($u['port'])&&$u['port']!==443)return null;
        $guid='[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}';
        if(!preg_match('~^/webapi/('.$guid.')/Print/(PutPrintTask|GetPrintTaskStatus|DownloadPrintForm)$~iD',$u['path']??'',$m))return null;
        $box=$m[1];$operation=$m[2];parse_str($u['query']??'',$query);
        if($operation==='PutPrintTask'){
            if($method!=='POST'||isset($u['query'])||!is_array($body)||array_keys($body)!==['letterId','entityId']
                ||!is_string($body['letterId'])||!is_string($body['entityId'])||!PrintDiscovery::allowed($box,$body['letterId'],$body['entityId']))return null;
            return 'native-print-task';
        }
        if($method!=='GET'||$body!==null||!is_string($query['printFormId']??null)||!is_string($query['docType']??null)
            ||!isset($jobs[self::jobKey($box,$query['printFormId'],$query['docType'])]))return null;
        if($operation==='GetPrintTaskStatus'){
            if(array_keys($query)!==['printFormId','docType','iteration']||!ctype_digit($query['iteration'])||(int)$query['iteration']>19)return null;
            $stage='native-print-status';
        }else{
            if(array_keys($query)!==['printFormId','docType','overrideFileName']||!is_string($query['overrideFileName'])
                ||strlen($query['overrideFileName'])>500||preg_match('~[\x00-\x1f\x7f/\\\\]~',$query['overrideFileName']))return null;
            $stage='native-print-download';
        }
        return ($u['query']??'')===http_build_query($query,'','&',PHP_QUERY_RFC3986)?$stage:null;
    }
    private static function object(string $body):array {
        if(strlen($body)>65536||!str_starts_with(ltrim($body),'{'))throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
        $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('SOURCE_SCHEMA_CHANGED');return $data;
    }
    private static function token(array $response):?string {
        $value=$response['headers'][self::HEADER]??null;
        return is_string($value)&&strlen($value)>0&&strlen($value)<=8192&&!preg_match('/[\x00-\x20\x7f]/',$value)?$value:null;
    }
    private function persist(string $state,?array $task=null):void {
        $this->record['state']=$state;if($task!==null)$this->record['task']=$task;$this->record['updated_at']=time();
        $raw=json_encode($this->record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $tmp=$this->journal.'.'.bin2hex(random_bytes(8)).'.tmp';$stream=fopen($tmp,'x+b');
        if(!$stream)throw new RuntimeException('PRINT_TASK_UNKNOWN');chmod($tmp,0600);
        try{if(fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||!fsync($stream))throw new RuntimeException('PRINT_TASK_UNKNOWN');}
        finally{fclose($stream);}
        if(!rename($tmp,$this->journal))throw new RuntimeException('PRINT_TASK_UNKNOWN');
        if(PHP_OS_FAMILY!=='Windows'){
            $dir=fopen($this->directory,'r');if(!$dir)throw new RuntimeException('PRINT_TASK_UNKNOWN');
            try{if(!fsync($dir))throw new RuntimeException('PRINT_TASK_UNKNOWN');}finally{fclose($dir);}
        }
    }
    private function note(string $phase,?array $response=null):void {
        $allowed=['card_request','card_response','create_request','create_response','create_transport_unknown','task_candidate_persist','task_validate','task_persist','poll_request','poll_response','download_request','download_response'];
        if(!in_array($phase,$allowed,true))throw new RuntimeException('ACCESS_DENIED');
        $body=is_string($response['body']??null)?$response['body']:'';$status=$response['status']??null;
        $mime=strtolower(trim(explode(';',$response['headers']['content-type']??'',2)[0]));
        $mime=in_array($mime,['application/json','text/html','application/pdf','text/plain','application/octet-stream'],true)?$mime:($mime===''?'unknown':'other');
        $keys=['TaskId','DocType','ResultFileName','taskId','docType','resultFileName','Ready','Failed','ErrorCode','ErrorMessage','requiresTwoFactor'];$presence=array_fill_keys($keys,false);$count=0;$data=null;
        $kind=$body===''?'empty':(str_starts_with($body,'%PDF-')?'pdf':'non_json');
        if($body!==''&&strlen($body)<=65536){
            try{$value=json_decode($body,false,16,JSON_THROW_ON_ERROR);$kind=is_object($value)?'object':(is_array($value)?'array':'scalar');
                if(is_object($value)){$data=get_object_vars($value);$count=count($data);foreach($keys as$key)$presence[$key]=array_key_exists($key,$data);}}
            catch(\JsonException $error){/* The body and exception text remain private. */}
        }
        $id=$data['TaskId']??null;$type=$data['DocType']??null;
        $this->record['diagnostic']=['phase'=>$phase,'http_status'=>is_int($status)&&$status>=100&&$status<=599?$status:null,
            'content_type'=>$mime,'body_bytes'=>strlen($body),'body_kind'=>$kind,'known_key_presence'=>$presence,'unknown_key_count'=>max(0,$count-count(array_filter($presence))),
            'csrf_header_present'=>self::token($response??[])!==null,'task_id_type'=>get_debug_type($id),'task_id_length'=>is_string($id)?strlen($id):0,
            'task_id_shape_valid'=>self::validTaskId($id),
            'doc_type_type'=>get_debug_type($type),'doc_type_shape_valid'=>(is_int($type)||is_string($type))&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',(string)$type)===1,
            'post_attempts'=>$this->record['post_attempts']??0];
    }
    private function call(string $method,string $url,mixed $body=null,array $headers=[],int $limit=65536):array {
        $phase=str_contains($url,'/Document/Show?')?'card':(str_contains($url,'/Print/PutPrintTask')?'create':(str_contains($url,'/Print/GetPrintTaskStatus')?'poll':'download'));
        if($phase==='create')$this->record['post_attempts']=($this->record['post_attempts']??0)+1;
        $this->note($phase.'_request');
        if($phase==='create')$this->persist('INTENT');
        try{$response=$this->http->request($method,$url,$body,array_merge(['X-Requested-With: XMLHttpRequest'], $headers),$limit);}
        catch(\Throwable $error){if($phase==='create')$this->note('create_transport_unknown');throw $error;}
        $this->note($phase.'_response',$response);return $response;
    }
    public function pdf(string $box,array $document):array {
        $message=$document['message_id']??null;$id=$document['document_id']??null;$binding=$document['source_binding']??null;
        if(!is_string($message)||!is_string($id)||!PrintDiscovery::allowed($box,$message,$id)||!is_string($binding)||!preg_match('/^[a-f0-9]{64}$/D',$binding))throw new RuntimeException('ACCESS_DENIED');
        $this->journal=$this->directory.'/native-print-webapi-v2-'.hash('sha256',$box."\0".$message."\0".$id).'.json';
        $base='https://diadoc.kontur.ru/webapi/'.$box;
        if(file_exists($this->journal)||is_link($this->journal)){
            if(is_link($this->journal)||!is_file($this->journal)||filesize($this->journal)>16384
                ||PHP_OS_FAMILY!=='Windows'&&((fileperms($this->journal)&0077)!==0||fileowner($this->journal)!==posix_geteuid()))throw new RuntimeException('ACCESS_DENIED');
            $this->record=self::object(file_get_contents($this->journal));
            if(($this->record['protocol_version']??null)!==self::PROTOCOL)throw new RuntimeException('PRINT_TASK_UNKNOWN');
            if(($this->record['source_binding']??null)!==$binding)throw new RuntimeException('PRINT_SOURCE_CHANGED');
            $candidate=self::candidateTask($this->record['task_candidate']??null,$binding);
            if(in_array($this->record['state']??'', ['UNKNOWN','CANDIDATE'],true)&&$candidate!==null){$this->record['task']=$candidate;$this->record['state']='TASK';$this->record['recovery_get_only']=true;}
            if(($this->record['state']??'')==='UNKNOWN'&&self::validTask($this->record['task']??[])&&($this->record['diagnostic']['phase']??'')==='task_persist'&&($this->record['diagnostic']['http_status']??null)===200){$this->record['state']='TASK';$this->record['recovery_get_only']=true;}
            if(in_array($this->record['state']??'', ['INTENT','UNKNOWN','CSRF_REJECTED'],true))throw new RuntimeException('PRINT_TASK_UNKNOWN');
            if(($this->record['state']??'')==='FAILED')throw new RuntimeException('PRINT_FORM_UNAVAILABLE');
            if(!in_array($this->record['state']??'', ['TASK','READY','COMPLETE'],true)||!self::validTask($this->record['task']??[]))throw new RuntimeException('PRINT_TASK_UNKNOWN');
        }else{
            $card=$this->call('GET','https://diadoc.kontur.ru/'.$box.'/Document/Show?'.http_build_query(['letterId'=>$message,'documentId'=>$id],'','&',PHP_QUERY_RFC3986),null,[],2*1024*1024);
            if($card['status']!==200)throw new RuntimeException('AUTH_REQUIRED');$csrf=self::token($card);
            $this->record=['schema'=>3,'protocol_version'=>self::PROTOCOL,'intent'=>hash('sha256',self::PROTOCOL."\0".$box."\0".$message."\0".$id."\0".$binding),'source_binding'=>$binding,'created_at'=>time(),'task'=>null,'task_candidate'=>null,'post_attempts'=>0];$this->persist('INTENT');
            try{
                $body=['letterId'=>$message,'entityId'=>$id];$headers=$csrf?['X-Request-Verification-Token: '.$csrf]:[];
                $response=$this->call('POST',$base.'/Print/PutPrintTask',$body,$headers);
                if($response['status']===403){
                    $reject=self::object($response['body']);$newToken=self::token($response);
                    if($newToken!==null&&!array_intersect(['TaskId','DocType','ResultFileName'],array_keys($reject))&&!($reject['requiresTwoFactor']??false)){
                        $this->persist('CSRF_REJECTED');$this->persist('INTENT');
                        $response=$this->call('POST',$base.'/Print/PutPrintTask',$body,['X-Request-Verification-Token: '.$newToken]);
                    }
                }
                if($response['status']!==200)throw new RuntimeException('PRINT_TASK_UNKNOWN');
                $data=self::object($response['body']);$type=$data['DocType']??null;
                $this->record['task_candidate']=['http_status'=>200,'source_binding'=>$binding,
                    'TaskId'=>self::candidateScalar($data['TaskId']??null),'DocType'=>self::candidateScalar($type),
                    'recovery'=>'VALIDATE_AND_GET_ONLY_NO_POST'];
                $this->note('task_candidate_persist',$response);$this->persist('CANDIDATE');
                $task=['id'=>$data['TaskId']??null,'type'=>is_int($type)?(string)$type:$type,'name'=>'document.pdf'];
                $this->note('task_validate',$response);
                if(!self::validTask($task))throw new RuntimeException('PRINT_TASK_UNKNOWN');
                $this->note('task_persist',$response);
                $this->persist('TASK',$task);
            }catch(\Throwable $error){$this->record['diagnostic']['uncertain_create_result']=true;$this->persist('UNKNOWN');throw new RuntimeException('PRINT_TASK_UNKNOWN');}
        }
        $task=$this->record['task'];$this->http->bindPrintJob($box,$message,$id,$task['id'],$task['type']);
        if(!in_array($this->record['state'],['READY','COMPLETE'],true)){
            for($iteration=0;$iteration<20;$iteration++){
                $response=$this->call('GET',$base.'/Print/GetPrintTaskStatus?'.http_build_query(['printFormId'=>$task['id'],'docType'=>$task['type'],'iteration'=>(string)$iteration],'','&',PHP_QUERY_RFC3986));
                if($response['status']!==200)throw new RuntimeException('SOURCE_UNAVAILABLE');$data=self::object($response['body']);
                if(!is_bool($data['Ready']??null)||!is_bool($data['Failed']??null))throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
                if($data['Failed']){$this->persist('FAILED');throw new RuntimeException('PRINT_FORM_UNAVAILABLE');}
                if($data['Ready']){$this->persist('READY');break;}
                $retry=$response['headers']['retry-after']??'';
                if(!is_string($retry)||!ctype_digit($retry)||(int)$retry<1||(int)$retry>10||hrtime(true)/1e9+(int)$retry>=$this->deadline)throw new RuntimeException('PRINT_TASK_PENDING');
                usleep((int)$retry*1000000);
            }
            if($this->record['state']!=='READY')throw new RuntimeException('PRINT_TASK_PENDING');
        }
        $response=$this->call('GET',$base.'/Print/DownloadPrintForm?'.http_build_query(['printFormId'=>$task['id'],'docType'=>$task['type'],'overrideFileName'=>$task['name']],'','&',PHP_QUERY_RFC3986),null,[],8*1024*1024);
        $body=$response['body'];$mime=strtolower($response['headers']['content-type']??'');
        if($response['status']!==200||!str_starts_with($body,'%PDF-')||strlen($body)>8*1024*1024||str_contains($mime,'text/html')||str_contains($mime,'application/json'))throw new RuntimeException('PRINT_FORM_UNAVAILABLE');
        $this->persist('COMPLETE');
        return ['body_base64'=>base64_encode($body),'bytes'=>strlen($body),'sha256'=>hash('sha256',$body),'content_type'=>'application/pdf'];
    }
}
