<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class Registry
{
    public function __construct(private \PDO $db,private PrivateStore $store) {}
    private static function first(array $v,array $keys): mixed {foreach($keys as $k)if(array_key_exists($k,$v)&&$v[$k]!==null&&$v[$k]!=='')return $v[$k];return null;}
    public static function normalize(string $provider,array $row): array {
        Support::provider($provider);Support::business($row);if(strlen(Support::json($row))>1048576)throw new \InvalidArgumentException('BUSINESS_RECORD_LIMIT');$fields=$row['fields']??[];$raw=$fields['record']??$row;$account=$fields['account']??[];
        if(!is_array($raw)||!is_array($account))throw new \InvalidArgumentException('INVALID_SOURCE_ENVELOPE');
        $v=$raw['value']??$raw;if(!is_array($v))throw new \InvalidArgumentException('INVALID_SOURCE_ENVELOPE');
        $accountId=self::first($row,['account_id','ACCOUNT_ID','accountId'])??self::first($raw,['account_id','ACCOUNT_ID','accountId'])??self::first($account,['id','ID','_uid','account_id','ACCOUNT_ID']);
        $accountId=$accountId===null?'':Support::identity($accountId);
        foreach([$row['account_id']??null,$raw['account_id']??null,$raw['ACCOUNT_ID']??null,$account['id']??null,$account['ID']??null,$account['_uid']??null] as $owner)if($owner!==null&&(string)$owner!==$accountId)throw new \InvalidArgumentException('ACCOUNT_BINDING_FAILED');
        if($provider===YakovlevImport::PROVIDER) return YakovlevImport::normalize($row);
        if($provider==='onec') {if(($raw['DeletionMark']??null)!==false)throw new \InvalidArgumentException('DELETED_OR_UNKNOWN_INVOICE');$id=Support::identity($raw['Ref_Key']??null);$date=Support::day($raw['Date']??null);$number=Support::identity($raw['Number']??null);$amount=Support::money($raw['СуммаДокумента']??null);if(isset($raw['Posted'])&&!is_bool($raw['Posted']))throw new \InvalidArgumentException('INVALID_POSTED_FLAG');$status=($raw['Posted']??false)?'Проведён':'Не проведён';$title='Счёт';}
        elseif($provider==='diadoc') {$message=Support::identity(self::first($v,['message_id','messageId']));$doc=Support::identity(self::first($v,['document_id','documentId']));if(str_contains($message,'/')||str_contains($doc,'/'))throw new \InvalidArgumentException('INVALID_DIADOC_ID');$id=$message.'/'.$doc;$date=Support::day(self::first($v,['message_date','date','document_date'])??$row['date']??null);$number=(string)($v['number']??'');$amount=Support::money($v['amount']??null);$title=(string)($v['name']??'');$st=$v['status']??'';$status=is_array($st)?(string)($st['primary']['text']??''):(string)$st;}
        elseif($provider==='bank') {$id=Support::identity(self::first($v,['Ref_Key','source_id','id','key']));$date=Support::day(self::first($v,['Date','date','document_date']));$number=(string)(self::first($v,['Number','number'])??'');$amount=Support::money(self::first($v,['СуммаДокумента','amount','total']));$status=(string)($v['status']??'Получена');$title='Банковская операция';}
        else {if($accountId==='')throw new \InvalidArgumentException('ACCOUNT_REQUIRED');$native=Support::identity(self::first($v,['_uid','ID','id','receipt_id','documentId','document_id','DOC_ID','BILL_ID']));$id=match($provider){
            'mosvodokanal'=>Support::json([$accountId,$native,Support::identity($v['FL_DOC']??null)]),
            'mosenergo'=>self::mesSourceId($accountId,$native,$v),
            default=>Support::json([$accountId,$native]),
        };$date=$provider==='pik'?Support::day(self::first($v,['period','billing_period','BILL_PERIOD','accountingPeriod'])):Support::day(self::first($v,['period','DOC_DATE','BILL_DATE','BILL_PERIOD','DATE','date','documentDate','document_date'])??$raw['date']??$row['source_date']??null);$number=(string)(self::first($v,['DOC_NUM','BILL_NUMBER','number','documentNum','documentNumber'])??'');$amount=Support::money(self::first($v,['total','CGS_SUM','DOC_SUM','TOTAL_SUM','SUM','amount','sum']));$status=(string)(self::first($v,['VST_DOC_STATUS_DESCR','status'])??$row['status']??'Получена');$title='Квитанция';}
        if(strlen($id)>500)throw new \InvalidArgumentException('SOURCE_ID_TOO_LONG');return ['provider'=>$provider,'source_id'=>$id,'account_id'=>$accountId,'document_date'=>$date,'number'=>$number,'title'=>$title,'amount'=>$amount,'status'=>$status,'raw'=>$row];
    }

    private static function mesSourceId(string $accountId,string $documentId,array $value): string
    {
        $parts = [$accountId,$documentId];
        $report = $value['reportId']??null;
        if ($report === null || $report === '') return Support::json($parts);
        $reportId = Support::identity($report);
        $type = $value['documentType']??'';
        $type = is_string($type)?mb_strtolower(trim($type),'UTF-8'):'';
        $type = str_replace('ё','е',preg_replace('/\s+/u',' ',$type)??'');
        // Payment invoices already use the unqualified key in the saved archive.
        if ($type !== 'счет на оплату') $parts[] = $reportId;
        return Support::json($parts);
    }
    public function import(string $provider,string $original,int $actor,string $requestKey,?string $pendingRun=null): array {
        Support::provider($provider);if(in_array($provider,['email','max'],true))throw new \InvalidArgumentException('DOCUMENT_PROVIDER_REQUIRED');if(strlen($original)>64*1024*1024)throw new \InvalidArgumentException('IMPORT_TOO_LARGE');
        $p=Support::decode($original);if(!is_array($p)||($p['provider']??$provider)!==$provider)throw new \InvalidArgumentException('PROVIDER_BINDING_FAILED');Support::business($p);$rows=$p['documents']??$p['records']??$p['rows']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)>100000)throw new \InvalidArgumentException('DOCUMENT_LIST_REQUIRED');
        $evidence=$p['coverage']??[];$scope=$p['scope']??[];$context=$p['context']??[];if(!is_array($evidence)||!is_array($scope)||!is_array($context))throw new \InvalidArgumentException('INVALID_COVERAGE');
        $cutoff=match($provider){'onec'=>'2026-09-01',YakovlevImport::PROVIDER=>$scope['from']??null,default=>'2026-10-01'};$docs=[];$excluded=0;
        foreach($rows as $row){if(!is_array($row))throw new \InvalidArgumentException('INVALID_RECORD');$d=self::normalize($provider,$row);if($provider!==YakovlevImport::PROVIDER&&$d['document_date']<$cutoff){$excluded++;continue;}$key=$d['source_id'];$hash=hash('sha256',Support::json($row));if(isset($docs[$key])&&$docs[$key]['hash']!==$hash)throw new \InvalidArgumentException('CONFLICTING_SOURCE_ID');$docs[$key]=$d+['hash'=>$hash];}
        $kind=$evidence['scope']??$scope['kind']??'unknown';$limited=str_contains((string)$kind,'latest')||isset($evidence['limit'])||isset($scope['limit'])||($evidence['scope_limited']??false)||($scope['scope_limited']??false)||($p['contract']??'')==='local-cabinet-sync-v1';
        $complete=!$limited&&($evidence['complete']??false)===true&&($evidence['pagination_exhausted']??false)===true&&($evidence['records_read']??null)===count($rows)&&($kind==='all_records'||in_array($kind,['period','period_all_records'],true)&&is_string($evidence['period_from']??$scope['from']??null)&&($evidence['period_from']??$scope['from'])<=$cutoff);
        $coverage=['complete'=>$complete,'period_from'=>$cutoff,'records_supplied'=>count($rows),'records_imported'=>count($docs),'period_to'=>$evidence['period_to']??$scope['to']??null,'excluded'=>$excluded,'evidence'=>$evidence,'scope'=>$scope];
        $originalPath=$this->store->put('imports',$original);$contextPath=$this->store->put('contexts',Support::json($context));$fp=hash('sha256',$original);$batch=Support::uuid();
        $this->db->beginTransaction();try{
            $q=$this->db->prepare('SELECT * FROM integration_runs WHERE provider=? AND actor_id=? AND request_key=? FOR UPDATE');
            $q->execute([$provider,$actor,$requestKey]);
            $existing=$q->fetch(\PDO::FETCH_ASSOC);
            if($pendingRun!==null){
                if($provider!==YakovlevImport::PROVIDER || !$existing || $existing['id']!==$pendingRun
                    || $existing['operation']!=='yakovlev-load' || $existing['status']!=='pending'
                    || Support::canonical(json_decode($existing['coverage_json'],true)['scope']??null)!==Support::canonical($scope)) {
                    throw new \RuntimeException('PENDING_IMPORT_NOT_BOUND');
                }
                $batch=$pendingRun;
            } else {
                if($existing){
                    if(!hash_equals($existing['fingerprint'],$fp)||$existing['operation']!=='import')throw new \RuntimeException('IDEMPOTENCY_CONFLICT');
                    $this->db->commit();
                    return ['id'=>$existing['id'],'status'=>$existing['status'],'coverage'=>json_decode($existing['coverage_json'],true),'reused'=>true];
                }
                $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,result_code,coverage_json,finished_at) VALUES(?,?,?,?,?,1,?,'success','IMPORTED',?,NOW(6))")->execute([$batch,$provider,'import',$requestKey,$actor,$fp,Support::json($coverage+['original_path'=>$originalPath])]);
            }
            $changes=['new'=>0,'changed'=>0,'unchanged'=>0];
            $mirror=$pendingRun!==null&&$scope['kind']==='yakovlev-parties'&&($scope['party_mirror']??false)===true;
            if($mirror && (($scope['trigger']??null)!=='hourly'||$actor!==0
                ||($evidence['received_by_kind']['parties']??null)!==count($docs)
                ||count($docs)!==count($rows)||array_diff(array_column($docs,'account_id'),['parties']))) {
                throw new \RuntimeException('INCOMPLETE_PARTY_MIRROR');
            }
            if($mirror)$changes+=['missing'=>0,'returned'=>0];
            foreach($docs as $d){
                $q=$this->db->prepare('SELECT * FROM external_documents WHERE provider=? AND source_id=? FOR UPDATE');
                $q->execute([$provider,$d['source_id']]);
                $old=$q->fetch(\PDO::FETCH_ASSOC);
                $same=$old&&hash_equals($old['raw_sha256'],$d['hash']);
                if($old&&!$same&&$provider===YakovlevImport::PROVIDER&&in_array($d['account_id'],['parties','invoices'],true)){
                    $previous=Support::decode($this->store->read($old['raw_path'],$old['raw_sha256']));
                    $same=Support::canonical(YakovlevChanges::document($previous))===Support::canonical(YakovlevChanges::document($d['raw']));
                }
                $returned=$provider===YakovlevImport::PROVIDER&&$d['account_id']==='parties'
                    &&($old['status']??null)===YakovlevImport::PARTY_MISSING;
                if($returned&&$mirror)$changes['returned']++;
                if($same&&!$returned){
                    $changes['unchanged']++;
                    if($provider!==YakovlevImport::PROVIDER) Details::save($this->db,$old['id'],$d['raw']);
                    continue;
                }
                $changes[$old?'changed':'new']++;
                $path=$this->store->put('records',Support::json($d['raw']));
                $id=$old['id']??Support::uuid();
                $version=$old?((int)$old['version']+1):1;
                if($old)$this->db->prepare('UPDATE external_documents SET account_id=?,document_date=?,number=?,title=?,amount=?,status=?,raw_sha256=?,raw_path=?,context_path=?,batch_id=?,version=?,updated_at=NOW(6) WHERE id=?')->execute([$d['account_id'],$d['document_date'],$d['number'],$d['title'],$d['amount'],$d['status'],$d['hash'],$path,$contextPath,$batch,$version,$id]);
                else $this->db->prepare('INSERT INTO external_documents(id,provider,source_id,account_id,document_date,number,title,amount,status,raw_sha256,raw_path,context_path,batch_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$provider,$d['source_id'],$d['account_id'],$d['document_date'],$d['number'],$d['title'],$d['amount'],$d['status'],$d['hash'],$path,$contextPath,$batch]);
                Details::save($this->db,$id,$d['raw']);$this->db->prepare('INSERT INTO external_document_versions(document_id,version,raw_sha256,raw_path,batch_id) VALUES(?,?,?,?,?)')->execute([$id,$version,$d['hash'],$path,$batch]);
            }
            if($mirror)$changes['missing']=$this->markMissingParties($docs);
            if($pendingRun!==null){
                $coverage['changes']=$changes;
                $this->db->prepare("UPDATE integration_runs SET status='success',result_code='IMPORTED',coverage_json=?,finished_at=NOW(6) WHERE id=?")->execute([Support::json($coverage+['original_path'=>$originalPath]),$batch]);
            }
            $this->db->commit();return ['id'=>$batch,'status'=>'success','coverage'=>$coverage,'reused'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function markMissingParties(array $observed): int
    {
        $query=$this->db->prepare("SELECT id,source_id FROM external_documents
            WHERE provider=? AND account_id='parties' AND status<>? FOR UPDATE");
        $query->execute([YakovlevImport::PROVIDER,YakovlevImport::PARTY_MISSING]);
        $missing=[];
        foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row){
            if(!isset($observed[$row['source_id']]))$missing[]=$row['id'];
        }
        foreach(array_chunk($missing,500) as $ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $this->db->prepare("UPDATE external_documents SET status=?,updated_at=NOW(6) WHERE id IN ($placeholders)")
                ->execute([YakovlevImport::PARTY_MISSING,...$ids]);
        }
        return count($missing);
    }
    public function attach(string $documentId,string $sourceId,string $filename,string $bytes,string $sha,string $provenance='native',?string $signature=null): string {
        if(!in_array($provenance,['native','original','generated_locally'],true)||strlen($bytes)>16*1024*1024||$bytes===''||!hash_equals($sha,hash('sha256',$bytes)))throw new \InvalidArgumentException('FILE_BINDING_FAILED');$q=$this->db->prepare('SELECT source_id FROM external_documents WHERE id=?');$q->execute([$documentId]);if($q->fetchColumn()!==$sourceId)throw new \InvalidArgumentException('FILE_SOURCE_MISMATCH');$filename=basename(str_replace('\\','/',$filename));if($filename===''||strlen($filename)>200||preg_match('/[\x00-\x1f\x7f]/',$filename))throw new \InvalidArgumentException('INVALID_FILENAME');$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$path=$this->store->put('originals',$bytes);$signaturePath=$signature!==null?$this->store->put('signatures',$signature):null;$id=Support::uuid();$q=$this->db->prepare('INSERT INTO external_files(id,document_id,source_id,filename,mime,sha256,byte_size,private_path,provenance,signature_path) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');$q->execute([$id,$documentId,$sourceId,$filename,$mime,$sha,strlen($bytes),$path,$provenance,$signaturePath]);$q=$this->db->prepare('SELECT id FROM external_files WHERE document_id=? AND sha256=? AND provenance=?');$q->execute([$documentId,$sha,$provenance]);return (string)$q->fetchColumn();
    }
}
