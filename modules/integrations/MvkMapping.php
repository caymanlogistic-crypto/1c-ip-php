<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Inspect literal source metadata only. Never eval JS/SQL or follow a metadata URL. */
final class MvkMapping
{
    public static function inspect(array $value): array {
        if(!is_array($value['definition']??null)||!preg_match('/^[a-f0-9]{64}$/D',$value['source_sha256']??''))throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');$visited=0;$decoded=0;$candidates=[];$bindings=[];
        $normalize=function(mixed $node,int $depth=0)use(&$normalize,&$visited,&$decoded):mixed {if(++$visited>12000||$depth>16)throw new \RuntimeException('METADATA_BUDGET');if(!is_array($node))return $node;if(array_is_list($node)){if($node&&array_reduce($node,static fn($ok,$v)=>$ok&&is_array($v)&&count($v)===2&&array_key_exists('key',$v)&&array_key_exists('value',$v),true)){$pairs=[];foreach($node as $v){if(!is_string($v['key'])||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$v['key'])||array_key_exists($v['key'],$pairs))return ['__opaque__'=>true];$pairs[$v['key']]=$v['value'];}return $normalize($pairs,$depth+1);}return array_map(static fn($v)=>$normalize($v,$depth+1),$node);} $out=[];foreach($node as $key=>$item){if(!is_string($key)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$key))continue;if(preg_match('/password|passwd|cookie|secret|session|authorization|credential|token|csrf|otp|^(auth|headers)$|sql|query|javascript|^(script|handler|code|events?|listeners)$/i',$key)){$out['__opaque__']=true;continue;}if(in_array($key,['jsonProperties','properties','commandProperties'],true)&&is_string($item)){if(strlen($item)>65536||($decoded+=strlen($item))>524288){$out['__opaque__']=true;continue;}try{$item=Support::decode($item);}catch(\Throwable){$out['__opaque__']=true;continue;}if(!is_array($item)){$out['__opaque__']=true;continue;}}$out[$key]=$normalize($item,$depth+1);}return $out;};
        $opaque=function(mixed $n)use(&$opaque):bool {if(!is_array($n))return false;if(($n['__opaque__']??false)===true)return true;foreach($n as $v)if($opaque($v))return true;return false;};
        $walk=function(mixed $n)use(&$walk,&$candidates,&$bindings,$opaque):void {if(!is_array($n))return;$command=$n['commandType']??$n['ctype']??$n['command']??null;if(in_array($command,['DownloadFinancialDocumentFromBZCommand','Leaves.command.file.DownloadFinancialDocumentFromBZCommand'],true)){$candidates[]=$n;$props=$n['commandProperties']??$n['properties']??[];$parameters=$n['parameters']??null;$valid=is_array($props)&&!array_diff(array_keys($props),['publisherReportCd','url','windowName'])&&preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D',$props['publisherReportCd']??'')&&($props['url']??'publisherReport')==='publisherReport'&&is_array($parameters)&&count($parameters)===2&&!$opaque($n);$seen=[];foreach(is_array($parameters)?$parameters:[] as $p){$expected=['id'=>'ID','type'=>'FL_DOC'];if(!is_array($p)||array_diff(array_keys($p),['name','valueContext','valueField','defaultValue'])||!isset($expected[$p['name']??''])||isset($seen[$p['name']])||($p['valueContext']??'')!=='FIELD'||($p['valueField']??'')!==$expected[$p['name']]||!in_array($p['defaultValue']??null,[null,''],true)){$valid=false;continue;}$seen[$p['name']]=$p['valueField'];}if($valid&&count($seen)===2)$bindings[]=$props['publisherReportCd'];}foreach($n as $v)$walk($v);};
        $definition=$value['definition'];
        if(array_key_exists('plugins',$definition)) {
            if(($definition['reportId']??null)!=='NATURAL_PERSON_ACCOUNT_BILL_REGISTER'
                || !is_array($definition['plugins']) || !array_is_list($definition['plugins'])
                || count($definition['plugins'])>80) {
                throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
            }
            $actions=[];
            foreach($definition['plugins'] as $plugin) {
                $serialized=$plugin['uiElement']['propertyData']??null;
                if(!is_string($serialized)||strlen($serialized)>65536)continue;
                try {
                    $action=Support::decode($serialized);
                } catch(\Throwable) {
                    continue;
                }
                if(is_array($action))$actions[]=$action;
            }
            $definition=$actions;
        }
        $walk($normalize($definition));if(count($candidates)!==1||count($bindings)!==1)throw new \RuntimeException('FILE_MAPPING_NOT_CONFIRMED');return ['publisher_report_cd'=>$bindings[0],'source_sha256'=>$value['source_sha256'],'mapping'=>['id'=>'ID','type'=>'FL_DOC']];
    }
}
