<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Historical M appendix is evidence, never a source of runnable messaging attempts. */
final class CaptureMessageHistory
{
    public const APPEND_PATH='local-m-history-appendix-20261116-9b0f6c1a.json';
    public const APPEND_SHA='f859e5c4b17834bddfe7cad4cfcaf6882db94a14c4ad552d0d594aaa75511d5a';
    public const RECEIPT_PATH='local-m-history-20261116-9b0f6c1a/history-receipt.json';
    public const RECEIPT_SHA='8651352bbfb7293a078379905b43addb23acf4e7c7d234a0291ce3f6902341ed';
    public function __construct(private CaptureReader $reader,private PrivateStore $store) {}
    private function bytes(string $path,string $sha,int $maximum): string {$p=$this->reader->path($path);if(filesize($p)>$maximum||!hash_equals($sha,(string)hash_file('sha256',$p)))throw new \RuntimeException('M_HISTORY_PIN_FAILED');return (string)file_get_contents($p);}
    public function load(): array {
        $appendRaw=$this->bytes(self::APPEND_PATH,self::APPEND_SHA,65536);$append=json_decode($appendRaw,true,64,JSON_THROW_ON_ERROR);if(($append['projectId']??'')!==CaptureReader::PROJECT||($append['parent']['sha256']??'')!==CaptureReader::PINS['rootpackage-manifest.json']||($append['history']['sha256']??'')!==self::RECEIPT_SHA||($append['history']['path']??'')!==self::RECEIPT_PATH||($append['queueReplay']??null)!==false||($append['journalRestore']??null)!==false)throw new \RuntimeException('M_HISTORY_POLICY_MISMATCH');
        $receiptRaw=$this->bytes(self::RECEIPT_PATH,self::RECEIPT_SHA,65536);$receipt=json_decode($receiptRaw,true,64,JSON_THROW_ON_ERROR);if(($receipt['projectId']??'')!==CaptureReader::PROJECT||($receipt['replay']??null)!==false||($receipt['activation']??null)!==false)throw new \RuntimeException('M_HISTORY_POLICY_MISMATCH');
        $paths=['append'=>$this->store->put('capture-m-history',$appendRaw),'receipt'=>$this->store->put('capture-m-history',$receiptRaw)];$files=[];
        foreach(['connections-nonsecret.json','groups-nonsecret.json','message-attempts-archive.json','source-observation.json'] as $name){$info=$receipt['files'][$name]??null;$expected='local-m-history-20261116-9b0f6c1a/'.$name;if(!is_array($info)||($info['path']??'')!==$expected||!is_int($info['bytes']??null)||$info['bytes']>1048576)throw new \RuntimeException('M_HISTORY_FILE_INVALID');$raw=$this->bytes($expected,$info['sha256'],1048576);if(strlen($raw)!==$info['bytes'])throw new \RuntimeException('M_HISTORY_SIZE_FAILED');$paths[$name]=$this->store->put('capture-m-history',$raw);$files[$name]=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        $providers=[];foreach($files['connections-nonsecret.json'] as $connection){$provider=Support::provider($connection['provider']??'');if(!in_array($provider,['email','max'],true)||isset($providers[(string)$connection['id']]))throw new \RuntimeException('M_HISTORY_CONNECTION_INVALID');$providers[(string)$connection['id']]=$provider;}
        $attempts=[];$statuses=['success'=>0,'failed'=>0];foreach($files['message-attempts-archive.json'] as $ordinal=>$a){$id=(string)($a['id']??'');$provider=$providers[(string)($a['connection_id']??'')]??null;if($id===''||strlen($id)>100||isset($attempts[$id])||$provider===null||!isset($statuses[$a['status']??''])||($a['operationalAuthority']??null)!==false||($a['attachmentReferences']['status']??'')!=='NOT_RECORDED_IN_SOURCE_MODEL'||(!array_key_exists('references',$a['attachmentReferences'])||$a['attachmentReferences']['references']!==null)||($a['settingsRevisionProof']??'')!=='UNKNOWN_MODEL_HAS_NO_CONFIG_REVISION')throw new \RuntimeException('M_HISTORY_ATTEMPT_INVALID');
            $context=$this->store->put('capture-m-context',Support::json(['source_archive'=>$paths['message-attempts-archive.json'],'source_archive_sha256'=>$receipt['files']['message-attempts-archive.json']['sha256'],'array_ordinal'=>$ordinal,'source_id'=>$id,'operative'=>false,'attachment_references'=>null,'attachment_state'=>'NOT_RECORDED','configuration_revision'=>null,'configuration_revision_state'=>'UNKNOWN']));$attempts[$id]=['id'=>$id,'provider'=>$provider,'status'=>$a['status'],'context_path'=>$context];$statuses[$a['status']]++;}
        if(count($attempts)!==8||$statuses!==['success'=>3,'failed'=>5])throw new \RuntimeException('M_HISTORY_COUNT_MISMATCH');return ['attempts'=>array_values($attempts),'statuses'=>$statuses,'paths'=>$paths,'operative'=>false];
    }
    public function import(\PDO $db,string $captureId): array {$data=$this->load();foreach($data['attempts'] as $attempt)$db->prepare('INSERT INTO integration_capture_message_attempts(capture_id,source_id,provider,historical_status,context_path) VALUES(?,?,?,?,?)')->execute([$captureId,$attempt['id'],$attempt['provider'],$attempt['status'],$attempt['context_path']]);return ['attempts'=>count($data['attempts']),'statuses'=>$data['statuses'],'operative'=>false];}
}
