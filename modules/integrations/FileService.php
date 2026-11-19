<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class FileService
{
    public function __construct(private \PDO $db,private PrivateStore $store,private mixed $transport=null) {}
    public static function canReconcile(string $provider,string $kind,array $attempt): bool {
        return (int)$attempt['actor_id']===0 && $attempt['status']==='uncertain'
            && $attempt['result_code']==='FILE_RESULT_UNKNOWN_NO_REPLAY'
            && !str_starts_with($attempt['request_key'],'reconcile-')
            && (($provider==='mosvodokanal'&&$kind==='original')||($provider==='pik'&&$kind==='pdf'));
    }
    public function fetch(string $documentId,string $kind,int $actor,string $requestKey,bool $scheduled=false): array {
        $q=$this->db->prepare('SELECT provider FROM external_documents WHERE id=?');$q->execute([$documentId]);$provider=$q->fetchColumn();if(!$provider)throw new \RuntimeException('DOCUMENT_NOT_FOUND');$lock='ipphp-provider:'.$provider;$q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new \RuntimeException('PROVIDER_BUSY');try{return $this->fetchLocked($documentId,$kind,$actor,$requestKey,$scheduled);}finally{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
    private function fetchLocked(string $documentId,string $kind,int $actor,string $requestKey,bool $scheduled=false): array {
        if(!in_array($kind,['pdf','original'],true)||!preg_match('/^[A-Za-z0-9-]{16,100}$/D',$requestKey))throw new \InvalidArgumentException('INVALID_FILE_COMMAND');$id=Support::uuid();
        $previous = null;
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM external_documents WHERE id=? FOR UPDATE');
            $q->execute([$documentId]);
            $d=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$d)throw new \RuntimeException('DOCUMENT_NOT_FOUND');
            $provider=$d['provider'];
            if (!in_array($provider,CabinetSyncAdmission::PROVIDERS,true)
                || $kind==='original'&&!in_array($provider,['diadoc','mosvodokanal'],true)) {
                throw new \RuntimeException('FILE_UNAVAILABLE');
            }
            if($scheduled&&($actor!==0||!CabinetSyncAdmission::allows($this->db,$provider,'sync'))) {
                throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            }
            $q=$this->db->prepare('SELECT * FROM external_fetch_attempts WHERE document_id=? AND document_version=? AND operation=? ORDER BY created_at DESC');
            $q->execute([$documentId,$d['version'],$kind]);
            foreach($q as $old) {
                if ($old['request_key']===$requestKey) {
                    $this->db->commit();
                    return $old;
                }
                if ($scheduled && self::canReconcile($provider,$kind,$old)) {
                    $previous ??= $old['id'];
                    continue;
                }
                if (in_array($old['status'],['pending','uncertain','success'],true)) {
                    $this->db->commit();
                    return $old;
                }
            }
            if ($previous!==null) $requestKey='reconcile-'.$requestKey;
            $this->db->prepare("INSERT INTO external_fetch_attempts(id,document_id,document_version,request_key,actor_id,operation,status) VALUES(?,?,?,?,?,?,'pending')")
                ->execute([$id,$documentId,$d['version'],$requestKey,$actor,$kind]);
            $this->db->commit();
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        $started=false;$fileId=null;$status='failed';$code='FILE_UNAVAILABLE';$receipt=[];
        if ($previous!==null) $receipt['reconciles_attempt']=$previous;
        $networkStart=count($GLOBALS['ONLINE_REQUESTS']??[]);
        try{$raw=Support::decode($this->store->read($d['raw_path'],$d['raw_sha256']));$normalized=Registry::normalize($provider,$raw);if($normalized['source_id']!==$d['source_id']||$normalized['account_id']!==$d['account_id']||$normalized['document_date']!==$d['document_date'])throw new \RuntimeException('FILE_BINDING_FAILED');$c=Config::get($this->db,$provider);if(!$c['enabled'])throw new \RuntimeException('CONNECTION_DISABLED');$config=json_decode($c['config_json'],true);$reference=Config::reference($config['access_reference']??'');$secret=Config::profile($provider,$reference);if(!is_callable($this->transport)&&!$scheduled&&!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            $call=function(string $operation,array $params=[])use($provider,&$secret,&$started,$scheduled){$started=true;$r=is_callable($this->transport)?($this->transport)($provider,$operation,$secret,$params):(new ProviderService($this->db,$this->store))->native($provider,$operation,$secret,$params,$scheduled);$secret['session']=$r['session']??$secret['session']??[];return $r['data']??[];};
            $row=$raw['fields']['record']??$raw;$account=$raw['fields']['account']??[];
            if($provider==='mosenergo'){$mes=new MesClient($secret['session']??[],is_callable($this->transport)?$this->transport:($scheduled?CabinetNetwork::mesTransport():null));$started=true;try{$bytes=$mes->pdf($d['account_id'],$row);}finally{$secret['session']=$mes->session;if(!$scheduled&&!is_callable($this->transport))Config::saveProfile($provider,$reference,$secret);}$data=['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];}
            else {if(!$scheduled)$call('authenticate');
                $mapping=null;
                if($provider==='mosvodokanal'){$metadata=$call('receipt_print_metadata');$mapping=MvkMapping::inspect($metadata);$receipt['source_mapping']=$mapping;}
                $plan=SourceFileProbe::plan($provider,$kind,(string)$d['account_id'],$row,$account,$mapping,false);
                if($plan['state']!=='ready')throw new \RuntimeException('FILE_BINDING_FAILED');
                $params=$plan['params'];
                if ($previous!==null && $provider==='pik') $params['receipt_status_only']=true;
                $data=$call($plan['operation'],$params);
                $bytes=base64_decode($data['body_base64']??$data['base64']??'',true);if($bytes===false||strlen($bytes)!==($data['bytes']??null)||!hash_equals($data['sha256']??'',hash('sha256',$bytes)))throw new \RuntimeException('FILE_CHECKSUM_FAILED');if(!$scheduled&&!is_callable($this->transport))Config::saveProfile($provider,$reference,$secret);
            }
            if($provider==='diadoc'&&$kind==='original'){if(!is_string($data['content_type']??null)||preg_match('/text\/html|application\/json|[\r\n]/i',$data['content_type']))throw new \RuntimeException('FILE_FORMAT_CHANGED');}if($kind==='pdf'&&!str_starts_with($bytes,'%PDF-'))throw new \RuntimeException('FILE_FORMAT_CHANGED');if($provider==='diadoc'&&$kind==='original'&&strlen($bytes)!==$row['file_size'])throw new \RuntimeException('FILE_BINDING_FAILED');$sourceDocx=$provider==='mosvodokanal'&&$kind==='original'&&str_starts_with($bytes,"PK\x03\x04");
            if($provider==='mosvodokanal'&&$kind==='original'&&!$sourceDocx&&!str_starts_with($bytes,'%PDF-'))throw new \RuntimeException('FILE_FORMAT_CHANGED');
            $extension=$sourceDocx?'.docx':(str_starts_with($bytes,'%PDF-')?'.pdf':(str_starts_with(ltrim($bytes,"\xEF\xBB\xBF \r\n\t"),'<')?'.xml':'.bin'));$filename=$provider.'-'.substr(hash('sha256',$d['source_id']),0,16).'-'.$kind.$extension;$signature=isset($data['signature_base64'])?base64_decode($data['signature_base64'],true):null;if($signature===false)throw new \RuntimeException('SIGNATURE_FORMAT_INVALID');$fileId=(new Registry($this->db,$this->store))->attach($d['id'],$d['source_id'],$filename,$bytes,hash('sha256',$bytes),$kind==='original'?'original':'native',$signature);$status='success';$code='ORIGINAL_BYTES_VERIFIED';$receipt+=['file_id'=>$fileId,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'source_id'=>$d['source_id'],'document_version'=>$d['version']];
        }catch(\Throwable $e){$status=$started?'uncertain':'failed';$safe=['EXTERNAL_ACCESS_NOT_AUTHORIZED','CONNECTION_DISABLED','FILE_BINDING_FAILED','FILE_CHECKSUM_FAILED','FILE_FORMAT_CHANGED','FILE_MAPPING_NOT_CONFIRMED','BILLING_ACCOUNT_REQUIRED','PIK_RECEIPT_NOT_READY'];$code=in_array($e->getMessage(),$safe,true)?$e->getMessage():'FILE_RESULT_UNKNOWN_NO_REPLAY';}
        if ($scheduled) {
            $receipt['requests']=array_map(
                static fn(array $request):array=>array_intersect_key($request,array_flip(['stage','method','http','curl_errno'])),
                array_slice($GLOBALS['ONLINE_REQUESTS']??[],$networkStart,20),
            );
        }
        $receiptPath=$this->store->spool(['fetch_id'=>$id,'document_id'=>$documentId,'kind'=>$kind],Support::json($receipt+['status'=>$status,'code'=>$code]));$this->db->prepare("UPDATE external_fetch_attempts SET status=?,result_code=?,receipt_path=?,file_id=?,finished_at=NOW(6) WHERE id=? AND status='pending'")->execute([$status,$code,$receiptPath,$fileId,$id]);return ['id'=>$id,'status'=>$status,'result_code'=>$code,'file_id'=>$fileId];
    }
}
