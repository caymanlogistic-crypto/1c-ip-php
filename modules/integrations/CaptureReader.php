<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Read-only, pinned capture reader. Source JSON columns are persisted bytes, never HTTP-wire evidence. */
final class CaptureReader
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';
    public const PINS=[
        'rootpackage-manifest.json'=>'186950adfb29ebdb45a1b1c373dae3c1070eb8d808f56d4e8e9fb823e48d6e87',
        'phase3/final-data-manifest.json'=>'bd618297dbc080c2ed9db1df512dec122501bc4de15e27968838f3974769b741',
        'phase3/local-verification-report.json'=>'9e4269f61c49af7828cb2a10095c9f54ada9df715ebd1f0bddddbee2cc0d4515',
        'phase3/field-whitelist.json'=>'7912dac3d9db67950dc889e67571d7db8f18ab88058acbd95caca7a03557d3b4',
    ];
    private string $root;
    public readonly array $manifest;
    private array $verification;
    private array $schemas=[];
    private array $files=[];
    private array $verifiedFiles=[];
    public readonly array $rootManifest;
    public function __construct(string $root,private array $pins=self::PINS) {
        if(!is_dir($root)||is_link($root))throw new \RuntimeException('CAPTURE_ROOT_INVALID');$this->root=str_replace('\\','/',(string)realpath($root));
        $this->rootManifest=$this->metadata('rootpackage-manifest.json',1048576);
        if(($this->rootManifest['projectId']??'')!==self::PROJECT)throw new \RuntimeException('CAPTURE_PROJECT_MISMATCH');
        $this->manifest=$this->metadata('phase3/final-data-manifest.json',8388608);
        $this->verification=$this->metadata('phase3/local-verification-report.json',4194304);
        $whitelist=$this->metadata('phase3/field-whitelist.json',2097152);
        foreach($whitelist['tables']??[] as $table){if(!is_string($table['table']??null)||isset($this->schemas[$table['table']]))throw new \RuntimeException('CAPTURE_SCHEMA_INVALID');$this->schemas[$table['table']]=$table;}
        foreach($this->manifest['files']??[] as $file){$path=$file['path']??null;if(!is_string($path)||isset($this->files[$path]))throw new \RuntimeException('CAPTURE_FILE_INDEX_INVALID');$this->files[$path]=$file;}
        foreach($this->verification['files']??[] as $file){$path=$file['path']??null;if(!is_string($path)||isset($this->verifiedFiles[$path]))throw new \RuntimeException('CAPTURE_FILE_INDEX_INVALID');$this->verifiedFiles[$path]=$file;}
    }
    private function metadata(string $path,int $max): array {
        $p=$this->path($path);$sha=$this->pins[$path]??'';if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||filesize($p)>$max||!hash_equals($sha,(string)hash_file('sha256',$p)))throw new \RuntimeException('CAPTURE_MANIFEST_HASH_FAILED');
        $v=json_decode((string)file_get_contents($p),true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($v))throw new \RuntimeException('CAPTURE_MANIFEST_INVALID');return $v;
    }
    public function path(string $relative): string {
        if($relative===''||strlen($relative)>500||str_contains($relative,'\\')||str_contains($relative,':')||str_contains($relative,"\0")||str_starts_with($relative,'/')||preg_match('~(?:^|/)(?:\.{1,2}|)(?:/|$)~',$relative))throw new \RuntimeException('CAPTURE_PATH_REJECTED');
        $p=$this->root;foreach(explode('/',$relative) as $part){$p.='/'.$part;if(is_link($p))throw new \RuntimeException('CAPTURE_LINK_REJECTED');}
        $real=realpath($p);if($real===false||!is_file($real)||!str_starts_with(str_replace('\\','/',$real),$this->root.'/'))throw new \RuntimeException('CAPTURE_PATH_MISSING');return $real;
    }
    public function schema(string $table): array {return $this->schemas[$table]??throw new \RuntimeException('CAPTURE_TABLE_NOT_ALLOWED');}
    public function tableInfo(string $table): array {
        $this->schema($table);$a=$this->manifest['tables'][$table]??null;$h=$this->manifest['sqlTableIntegrity'][$table]??null;$v=$this->verification['tables'][$table]??null;
        if(!is_array($a)||!is_array($h)||!is_array($v)||($a['path']??'')!=='sql/'.$table.'.ndjson'||($a['rowsReceived']??null)!==($h['rows']??null)||$h!==$v||!is_int($h['rows'])||!is_int($h['bytes'])||!preg_match('/^[a-f0-9]{64}$/D',$h['sha256']??''))throw new \RuntimeException('CAPTURE_TABLE_RECEIPT_MISMATCH');return $h+['path'=>$a['path']];
    }
    /** Iterate entire generator to validate terminal count/hash. A consumer must not commit before EOF. */
    public function rows(string $table): \Generator {
        $info=$this->tableInfo($table);$columns=array_column($this->schema($table)['columns'],'name');$stream=fopen($this->path('phase3/captured/'.$info['path']),'rb');if(!$stream)throw new \RuntimeException('CAPTURE_READ_FAILED');$hash=hash_init('sha256');$count=0;$bytes=0;
        try{while(!feof($stream)){$offset=ftell($stream);$line=fgets($stream,16777218);if($line===false)break;if(strlen($line)>16777216||!str_ends_with($line,"\n"))throw new \RuntimeException('CAPTURE_ROW_LIMIT_OR_TRUNCATION');hash_update($hash,$line);$bytes+=strlen($line);$row=json_decode($line,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($row)||array_is_list($row))throw new \RuntimeException('CAPTURE_ROW_INVALID');$keys=array_keys($row);sort($keys);$expected=$columns;sort($expected);if($keys!==$expected)throw new \RuntimeException('CAPTURE_COLUMN_MISMATCH');$count++;yield ['ordinal'=>$count,'offset'=>$offset,'bytes'=>strlen($line),'sha256'=>hash('sha256',$line),'row'=>$row];}
            if($count!==$info['rows']||$bytes!==$info['bytes']||!hash_equals($info['sha256'],hash_final($hash)))throw new \RuntimeException('CAPTURE_TABLE_HASH_FAILED');
        }finally{fclose($stream);}
    }
    public static function scalar(mixed $cell): string|bool|null {
        if($cell===null||is_string($cell)||is_bool($cell))return $cell;
        if(!is_array($cell))throw new \RuntimeException('CAPTURE_UNTYPED_NUMBER');$type=$cell['type']??'';
        if(in_array($type,['integer','decimal'],true)&&array_keys($cell)===['type','decimal']&&is_string($cell['decimal'])&&preg_match($type==='integer'?'/^-?(?:0|[1-9][0-9]*)$/D':'/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$cell['decimal']))return $cell['decimal'];
        if(in_array($type,['date','datetime','time'],true)&&is_string($cell['iso']??null)&&count($cell)===2)return $cell['iso'];
        throw new \RuntimeException('CAPTURE_CELL_NOT_SCALAR');
    }
    public function raw(array $cell,string $table,int $ordinal,string $column,int $maximum=16777216): string {
        $size=$cell['bytes']??null;$sha=$cell['sha256']??null;if(!is_int($size)||$size<0||$size>$maximum||!preg_match('/^[a-f0-9]{64}$/D',$sha??''))throw new \RuntimeException('CAPTURE_CELL_LIMIT');
        if(($cell['status']??'')==='INCLUDED_INLINE'&&($cell['encoding']??'')==='base64'&&$size<=65536){$raw=base64_decode($cell['base64']??'',true);if($raw===false)throw new \RuntimeException('CAPTURE_BASE64_INVALID');}
        elseif(($cell['status']??'')==='INCLUDED'){$relative='sql-cells/'.$table.'/'.$ordinal.'-'.$column.'.raw';if(($cell['path']??'')!==$relative)throw new \RuntimeException('CAPTURE_CELL_CONTEXT_MISMATCH');$this->payloadInfo($relative,$sha,$size);$raw=file_get_contents($this->path('phase3/captured/'.$relative));}
        else throw new \RuntimeException('CAPTURE_CELL_WITHHELD_OR_UNKNOWN');
        if(!is_string($raw)||strlen($raw)!==$size||!hash_equals($sha,hash('sha256',$raw)))throw new \RuntimeException('CAPTURE_CELL_HASH_FAILED');return $raw;
    }
    public function payloadInfo(string $relative,string $sha,int $bytes): array {
        $f=$this->payloadReceipt($relative,$sha,$bytes);if(($f['localBytesRetained']??false)!==true||!in_array($f['status']??'',['CAPTURED_SQL_SNAPSHOT_BYTES','STABLE_OBSERVED_COPY'],true))throw new \RuntimeException('CAPTURE_PAYLOAD_NOT_RETAINED');return $f;
    }
    public function payloadReceipt(string $relative,string $sha,int $bytes): array {
        $f=$this->files[$relative]??null;$verified=$this->verifiedFiles[$relative]??null;if(!is_array($f)||!is_array($verified)||($f['bytes']??null)!==$bytes||!hash_equals($sha,$f['sha256']??''))throw new \RuntimeException('CAPTURE_PAYLOAD_RECEIPT_MISMATCH');
        foreach(['status','bytes','sha256','localBytesRetained'] as $key)if(($f[$key]??null)!==($verified[$key]??null))throw new \RuntimeException('CAPTURE_VERIFICATION_RECEIPT_MISMATCH');
        return $f;
    }
    /** Independent physical copy, bounded memory; .complete is published only after full stream verification. */
    public function copy(string $relative,string $sha,int $bytes,string $destination): void {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||$bytes<0||is_link($destination))throw new \RuntimeException('CAPTURE_COPY_REJECTED');$source=$this->path('phase3/captured/'.$relative);
        if(is_file($destination)){if(filesize($destination)!==$bytes||!hash_equals($sha,(string)hash_file('sha256',$destination)))throw new \RuntimeException('CAPTURE_COPY_CONFLICT');return;}
        $dir=dirname($destination);if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new \RuntimeException('CAPTURE_COPY_FAILED');for($p=$dir;strlen($p)>3;$p=dirname($p))if(is_link($p))throw new \RuntimeException('CAPTURE_LINK_REJECTED');
        $temp=$destination.'.partial-'.bin2hex(random_bytes(8));$in=fopen($source,'rb');$out=fopen($temp,'xb');if(!$in||!$out)throw new \RuntimeException('CAPTURE_COPY_FAILED');$hash=hash_init('sha256');$total=0;
        try{while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false)throw new \RuntimeException('CAPTURE_READ_FAILED');$total+=strlen($chunk);if($total>$bytes||fwrite($out,$chunk)!==strlen($chunk))throw new \RuntimeException('CAPTURE_COPY_FAILED');hash_update($hash,$chunk);}if($total!==$bytes||!hash_equals($sha,hash_final($hash)))throw new \RuntimeException('CAPTURE_PAYLOAD_HASH_FAILED');fflush($out);if(function_exists('fsync'))fsync($out);fclose($out);$out=null;chmod($temp,0600);if(!rename($temp,$destination))throw new \RuntimeException('CAPTURE_COPY_FAILED');}
        finally{fclose($in);if(is_resource($out))fclose($out);if(is_file($temp))unlink($temp);}
    }
}
