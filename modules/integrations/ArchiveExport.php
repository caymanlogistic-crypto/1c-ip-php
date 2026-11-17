<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Source-style reports and byte-preserving provider bundles, with exact logical file contexts. */
final class ArchiveExport
{
    public function __construct(private \PDO $db,private PrivateStore $store) {}

    public function selection(string $provider): array
    {
        Support::provider($provider);
        if(in_array($provider,['email','max'],true))throw new \RuntimeException('DOCUMENT_PROVIDER_REQUIRED');
        $query=$this->db->prepare('SELECT * FROM external_documents WHERE provider=? ORDER BY document_date DESC,source_id LIMIT 1001');
        $query->execute([$provider]);$documents=$query->fetchAll(\PDO::FETCH_ASSOC);
        if(count($documents)>1000)throw new \RuntimeException('ARCHIVE_SELECTION_LIMIT');
        $records=[];$entries=[];$documentFiles=[];$unavailable=[];$expanded=0;$metadataBytes=0;$batches=[];$fieldCount=0;
        foreach($documents as$offset=>$document){
            $index=$offset+1;$recordDirectory='records/'.str_pad((string)$index,6,'0',STR_PAD_LEFT);
            $path=$this->store->verifiedPath($document['raw_path'],$document['raw_sha256']);
            $metadataBytes+=filesize($path);
            if($metadataBytes>16777216)throw new \RuntimeException('ARCHIVE_METADATA_LIMIT');
            $entries[]=['name'=>$recordDirectory.'/raw.json','path'=>$path,'size'=>filesize($path),'sha256'=>$document['raw_sha256']];
            $expanded+=filesize($path);
            $context=$this->store->verifiedPath($document['context_path']);
            $entries[]=['name'=>$recordDirectory.'/context.json','path'=>$context,'size'=>filesize($context),'sha256'=>basename($context)];
            $expanded+=filesize($context);
            $rawRecord=$this->store->read($document['raw_path'],$document['raw_sha256']);
            $fieldRows=RawJsonFields::read($rawRecord);$fieldCount+=count($fieldRows);
            if($fieldCount>100000)throw new \RuntimeException('ARCHIVE_METADATA_LIMIT');
            $records[]=['key'=>$document['source_id'],'account_id'=>$document['account_id'],'date'=>$document['document_date'],
                'number'=>$document['number'],'title'=>$document['title'],'amount'=>$document['amount'],'status'=>$document['status'],
                'raw_entry'=>$recordDirectory.'/raw.json','context_entry'=>$recordDirectory.'/context.json',
                'field_rows'=>$fieldRows];
            if(!isset($batches[$document['batch_id']])){
                $batches[$document['batch_id']]=true;
                $query=$this->db->prepare("SELECT coverage_json FROM integration_runs WHERE id=? AND provider=? AND operation='import'");
                $query->execute([$document['batch_id'],$provider]);$coverage=$query->fetchColumn();
                $source=$coverage?Support::decode($coverage):[];
                if(is_string($source['original_path']??null)){
                    $input=$this->store->verifiedPath($source['original_path']);
                    $entries[]=['name'=>'inputs/'.$document['batch_id'].'.json','path'=>$input,'size'=>filesize($input),'sha256'=>basename($input)];$expanded+=filesize($input);
                }
            }
            $query=$this->db->prepare('SELECT * FROM external_files WHERE document_id=? ORDER BY id');
            $query->execute([$document['id']]);
            foreach($query->fetchAll(\PDO::FETCH_ASSOC)as$file){
                if($file['source_id']!==$document['source_id'])throw new \RuntimeException('ARCHIVE_DOCUMENT_BINDING_FAILED');
                $path=$this->store->verifiedPath($file['private_path'],$file['sha256']);
                $name='documents/record-'.str_pad((string)$index,3,'0',STR_PAD_LEFT).'/file-'.$file['id'].self::extension($file['mime']);
                $entries[]=['name'=>$name,'path'=>$path,'size'=>(int)$file['byte_size'],'sha256'=>$file['sha256']];
                $expanded+=(int)$file['byte_size'];
                $binding=self::binding($name,$index,$document,$file['mime']==='application/pdf'&&$file['provenance']!=='original'?'pdf':'original',$file['filename'],$file['mime'],(int)$file['byte_size'],$file['sha256'],'native-files',$file['id']);
                $binding['provenance']=$file['provenance'];$documentFiles[]=$binding;
                if($file['signature_path']){
                    $signature=$this->store->verifiedPath($file['signature_path']);$signatureName='signatures/'.$file['id'].'.sig';
                    $entries[]=['name'=>$signatureName,'path'=>$signature,'size'=>filesize($signature),'sha256'=>basename($signature)];$expanded+=filesize($signature);
                    $documentFiles[]=self::binding($signatureName,$index,$document,'signature',$file['filename'].'.sig','application/octet-stream',filesize($signature),basename($signature),'native-signatures',$file['id']);
                }
            }
            foreach(CaptureFiles::forDocument($this->db,$document['id'])as$file){
                if(!$file['available']){
                    $unavailable[]=['capture_id'=>$file['capture_id'],'logical_file_id'=>$file['source_id'],'record_key'=>$document['source_id'],'sha256'=>$file['sha256'],'source_status'=>$file['source_status']];
                    continue;
                }
                $path=CaptureFiles::directory().'/'.$file['private_path'];
                if($file['private_path']!=='blobs/'.$file['sha256']||is_link(CaptureFiles::directory())||is_link(CaptureFiles::directory().'/blobs')||is_link($path)||!is_file($path)
                    ||filesize($path)!==(int)$file['byte_size']||!hash_equals($file['sha256'],(string)hash_file('sha256',$path)))throw new \RuntimeException('ARCHIVE_MEMBER_CHANGED');
                // Each source reference remains separate even when physical SHA is shared.
                $name='documents/record-'.str_pad((string)$index,3,'0',STR_PAD_LEFT).'/capture-'.$file['source_id'].self::extension($file['content_type']);
                $entries[]=['name'=>$name,'path'=>$path,'size'=>(int)$file['byte_size'],'sha256'=>$file['sha256']];$expanded+=(int)$file['byte_size'];
                $documentFiles[]=self::binding($name,$index,$document,$file['role']==='signature'?'signature':'original',$file['original_name'],$file['content_type'],(int)$file['byte_size'],$file['sha256'],$file['capture_id'],$file['source_id']);
            }
            if($expanded>RawZip::EXPANDED)throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
        }
        $report=['schema_version'=>1,'provider'=>$provider,'records'=>$records,'coverage'=>['complete'=>false,'scope'=>'exported_selection','source_history_complete'=>false],'unavailable_files'=>$unavailable];
        $json=Support::json($report);
        if(strlen($json)>RawZip::MEMBER)throw new \RuntimeException('ARCHIVE_METADATA_LIMIT');
        $entries[]=['name'=>'records.json','bytes'=>$json];
        $entries[]=['name'=>'records.csv','bytes'=>self::csv([['key','date','number','title','account_id','amount','status'],...array_map(static fn($r)=>array_map(static fn($k)=>$r[$k],['key','date','number','title','account_id','amount','status']),$records)])];
        $fieldRows=[['record','path','type','value']];
        foreach($records as$i=>$record)foreach($record['field_rows']as$field)$fieldRows[]=[$i+1,$field['path'],$field['type'],$field['value']];
        $entries[]=['name'=>'fields.csv','bytes'=>self::csv($fieldRows)];
        $entries[]=['name'=>'report.html','bytes'=>self::html($report)];
        $entries[]=['name'=>'README.txt','bytes'=>"Provider selection with source-bound originals. manifest.json binds every member by size and SHA-256. Equal physical hashes retain distinct logical references. Unavailable originals remain explicitly unavailable. Coverage is not complete source history. No queue or delivery state is operative in this archive.\n"];
        foreach($entries as$entry)if(isset($entry['bytes']))$expanded+=strlen($entry['bytes']);
        if($expanded>RawZip::EXPANDED)throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
        return compact('report','entries','documentFiles','expanded');
    }

    private static function binding(string$name,int$index,array$document,string$kind,string$filename,string$mime,int$bytes,string$sha,string$namespace,string$id):array
    {
        return ['name'=>$name,'record'=>$index,'record_key'=>$document['source_id'],'account_id'=>$document['account_id'],
            'kind'=>$kind,'filename'=>$filename,'mime_type'=>$mime,'bytes'=>$bytes,'sha256'=>$sha,'source_namespace'=>$namespace,'logical_file_id'=>$id];
    }

    private static function extension(string$mime):string
    {
        return match(strtolower($mime)){'application/pdf'=>'.pdf','application/xml','text/xml'=>'.xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'.docx',default=>'.bin'};
    }

    private static function csv(array$rows):string
    {
        $stream=fopen('php://temp','w+b');fwrite($stream,"\xEF\xBB\xBF");
        foreach($rows as$row)fputcsv($stream,array_map(static fn($v)=>preg_match('/^[=+\-@\t\r]/',(string)$v)?"'".$v:$v,$row),';',enclosure:'"',escape:'');
        rewind($stream);$raw=stream_get_contents($stream);fclose($stream);return $raw;
    }

    public static function html(array$report):string
    {
        $escape=Support::e(...);
        $html='<!doctype html><html lang="ru"><meta charset="utf-8"><title>Полученные документы</title><body><h1>'.$escape(Support::PROVIDERS[$report['provider']]).'</h1><p>Выгружена сохранённая выборка. Полнота истории источника не подтверждена.</p>';
        foreach($report['records']as$index=>$record){
            $html.='<h2>'.($index+1).'. '.$escape($record['title'].' '.$record['number']).'</h2><table><thead><tr><th>Поле</th><th>Тип</th><th>Значение</th></tr></thead><tbody>';
            foreach($record['field_rows']as$field)$html.='<tr><td>'.$escape($field['path']).'</td><td>'.$escape($field['type']).'</td><td><pre>'.$escape($field['value']).'</pre></td></tr>';
            $html.='</tbody></table>';
        }
        return $html.'</body></html>';
    }

    public function build(string$provider):array
    {
        $selection=$this->selection($provider);
        $configured=getenv('IPPHP_ARCHIVE_TEMP_DIR');
        $directory=is_string($configured)&&$configured!==''?$configured:Config::root().'/runtime';
        $real=realpath($directory);
        if(!$real||!is_dir($real))throw new \RuntimeException('ARCHIVE_TEMP_UNAVAILABLE');
        for($part=$real;;$part=dirname($part)){if(is_link($part))throw new \RuntimeException('ARCHIVE_TEMP_UNAVAILABLE');if(dirname($part)===$part)break;}
        $sameVolume=PHP_OS_FAMILY==='Windows'&&strtolower(substr($real,0,2))===strtolower(substr(Config::root(),0,2));
        if($selection['expanded']>104857600&&($configured===false||$sameVolume))throw new \RuntimeException('ARCHIVE_LARGE_TEMP_REQUIRED');
        $reserve=$sameVolume?536870912:67108864;
        if(disk_free_space($real)<$selection['expanded']+16777216+$reserve)throw new \RuntimeException('ARCHIVE_TEMP_BUDGET');
        $path=$real.'/provider-export-'.bin2hex(random_bytes(16)).'.zip';
        $result=RawZip::build($path,$provider,$selection['entries'],['record_count'=>count($selection['report']['records']),'coverage'=>$selection['report']['coverage'],'unavailable_files'=>$selection['report']['unavailable_files']],$selection['documentFiles']);
        $result['path']=$path;
        $result['receipt_path']=$this->store->spool(['provider'=>$provider,'operation'=>'export','kind'=>'raw-archive'],Support::json(['sha256'=>$result['sha256'],'bytes'=>$result['bytes'],'record_count'=>count($selection['report']['records'])]));
        return $result;
    }
}
