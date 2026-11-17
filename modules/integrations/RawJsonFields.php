<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Report-only JSON walker. Number lexemes and empty object/list types remain distinct. */
final class RawJsonFields
{
    private int $offset=0;
    private array $rows=[];
    private function __construct(private string $raw) {}

    public static function read(string $raw):array
    {
        Support::decode($raw); // Reject duplicate keys and invalid JSON before walking tokens.
        $parser=new self($raw);
        $parser->value('',0);
        return $parser->rows;
    }

    private function space():void
    {
        while(isset($this->raw[$this->offset])&&str_contains(" \t\r\n",$this->raw[$this->offset]))$this->offset++;
    }

    private function string():string
    {
        $start=$this->offset++;
        while(isset($this->raw[$this->offset])){
            $char=$this->raw[$this->offset++];
            if($char==='\\'){$this->offset++;continue;}
            if($char==='"')return json_decode(substr($this->raw,$start,$this->offset-$start),true,128,JSON_THROW_ON_ERROR);
        }
        throw new \RuntimeException('RAW_FIELD_SCHEMA_INVALID');
    }

    private function add(string $path,string $type,string $value):void
    {
        if(count($this->rows)>=100000)throw new \RuntimeException('RAW_FIELD_LIMIT');
        $this->rows[]=['path'=>$path?:'/','type'=>$type,'value'=>$value];
    }

    private function value(string $path,int $depth):void
    {
        if($depth>64)throw new \RuntimeException('RAW_FIELD_LIMIT');
        $this->space();$char=$this->raw[$this->offset]??'';
        if($char==='{'||$char==='['){
            $object=$char==='{';$end=$object?'}':']';$this->offset++;$this->space();
            if(($this->raw[$this->offset]??'')===$end){$this->offset++;$this->add($path,$object?'object':'array',$object?'{}':'[]');return;}
            $index=0;
            do{
                $this->space();
                if($object){$key=$this->string();$this->space();$this->offset++;}
                else $key=(string)$index++;
                $this->value($path.'/'.str_replace(['~','/'],['~0','~1'],$key),$depth+1);
                $this->space();$separator=$this->raw[$this->offset++]??'';
            }while($separator===',');
            if($separator!==$end)throw new \RuntimeException('RAW_FIELD_SCHEMA_INVALID');
            return;
        }
        if($char==='"'){$this->add($path,'string',$this->string());return;}
        if(!preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A',$this->raw,$match,0,$this->offset))throw new \RuntimeException('RAW_FIELD_SCHEMA_INVALID');
        $lexeme=$match[0];$this->offset+=strlen($lexeme);
        $type=match($lexeme){'true','false'=>'boolean','null'=>'null',default=>strpbrk($lexeme,'.eE')===false?'integer':'number'};
        $this->add($path,$type,$lexeme);
    }
}
