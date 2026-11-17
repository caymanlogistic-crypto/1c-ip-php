<?php
declare(strict_types=1);
namespace Diadoc;
use RuntimeException;

/** Inert discovery only. Never fetch linked routes/assets or execute page code. */
final class PrintDiscovery
{
    private const CARD_HASHES = ['668ad37c2d480e300ffc27dcc276f4e6e78f9555aa68e07521762c8595fba7fd','a5b03dcd528604981a8673d637f260fcc107a5b213d4cc9b34327f9a3deaeec2','f2a89e37285e93a031eb2fae3fbf447220542fe7d093ec1ed1907d268653eba4'];
    private static array $observed=[];
    // Callable only by the private native worker after driver same-session selection.
    public static function bindObserved(string $box,array $document):void {
        if(count(self::$observed)>=1||array_diff(array_keys($document),['message_id','document_id','name','file_size','source_binding'])
            ||!is_string($document['source_binding']??null)||!preg_match('/^[a-f0-9]{64}$/D',$document['source_binding'])
            ||!is_string($document['name']??null)||strlen($document['name'])<1||strlen($document['name'])>1000
            ||!is_int($document['file_size']??null)||$document['file_size']<1||$document['file_size']>8*1024*1024)throw new RuntimeException('ACCESS_DENIED');
        $ids=[$box,$document['message_id']??null,$document['document_id']??null];
        foreach($ids as$id)if(!is_string($id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$id))throw new RuntimeException('ACCESS_DENIED');
        self::$observed[]=hash('sha256',implode("\0",array_map('strtolower',$ids)));
    }
    public static function allowed(string $box,string $message,string $document):bool {
        foreach([$box,$message,$document]as$id)if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$id))return false;
        return in_array(hash('sha256',strtolower($box)."\0".strtolower($message)."\0".strtolower($document)),[...self::CARD_HASHES,...self::$observed],true);
    }
    private static function parts(string $raw):?array {
        if(strlen($raw)>4096||preg_match('/[\x00-\x20\\\\]/',$raw))return null;
        $p=parse_url($raw);if($p===false||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['port'])&&$p['port']!==443)return null;
        if(isset($p['scheme'])&&$p['scheme']!=='https'||isset($p['host'])&&strtolower($p['host'])!=='diadoc.kontur.ru')return null;
        $path=$p['path']??'';
        if(!str_starts_with($path,'/')||str_contains($path,'..')||str_contains($path,'%')||!preg_match('~^/[A-Za-z0-9_./-]{1,300}$~D',$path))return null;
        return $p;
    }
    private static function kind(string $url):string {
        if($url==='')return 'empty';if(str_starts_with($url,'//'))return 'protocol_relative';
        if(preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/',$url))return 'absolute';
        return str_starts_with($url,'/')?'root_relative':'relative';
    }
    private static function firstParty(string $host):bool {return $host==='kontur.ru'||str_ends_with($host,'.kontur.ru');}
    /** Query/fragment are discarded before parsing or emitting the asset URI. */
    private static function resolve(string $raw,string $base,?string &$reason):?string {
        $reason=null;
        if(strlen($raw)>4096){$reason='url_too_long';return null;}
        $raw=explode('?',explode('#',$raw,2)[0],2)[0];
        if($raw===''||preg_match('/[\x00-\x20\\\\]/',$raw)){$reason='invalid_uri';return null;}
        $b=parse_url($base);$u=parse_url(str_starts_with($raw,'//')?'https:'.$raw:$raw);
        if($u===false||isset($u['user'])||isset($u['pass'])||isset($u['scheme'])&&!isset($u['host'])){$reason='userinfo_or_invalid';return null;}
        if(isset($u['scheme'])&&$u['scheme']!=='https'||isset($u['port'])&&$u['port']!==443){$reason='non_https_or_port';return null;}
        $host=strtolower($u['host']??$b['host']??'');
        if(!self::firstParty($host)||!preg_match('/^(?:[a-z0-9-]+\.)*kontur\.ru$/D',$host)){$reason='foreign_origin';return null;}
        $path=$u['path']??'/';
        if(!str_starts_with($path,'/')){$path=substr($b['path']??'/',0,strrpos($b['path']??'/','/')+1).$path;}
        $parts=[];foreach(explode('/',$path)as$segment){if($segment===''||$segment==='.')continue;if($segment==='..'){array_pop($parts);continue;}$parts[]=$segment;}
        $path='/'.implode('/',$parts).($parts&&str_ends_with($path,'/')?'/':'');
        if(strlen($path)>512||!preg_match('~^/[A-Za-z0-9_./-]*$~D',$path)){$reason='unsafe_path';return null;}
        if(preg_match('/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}/i',$path)){$reason='guid_in_path';return null;}
        if(preg_match('~(?:^|/)(?:token|session|secret|signature|credential)(?:/|[._-])|eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+~i',$path)){$reason='tokenized_path';return null;}
        foreach($parts as$segment)if(strlen($segment)>160){$reason='opaque_long_segment';return null;}
        return 'https://'.$host.$path;
    }
    private static function cspOrigins(string $csp):array {
        if(strlen($csp)>32768)return [];$out=[];
        foreach(explode(';',$csp)as$directive){$tokens=preg_split('/\s+/',trim($directive));$name=array_shift($tokens);if(!in_array($name,['script-src','script-src-elem','default-src'],true))continue;
            foreach($tokens as$t){if($t==="'self'"){$out[]=['directive'=>$name,'origin'=>'https://diadoc.kontur.ru'];continue;}
                $p=parse_url($t);$h=strtolower($p['host']??'');
                if(($p['scheme']??'')==='https'&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['query'])&&!isset($p['fragment'])&&(!isset($p['port'])||$p['port']===443)&&preg_match('/^(?:\*\.)?(?:[a-z0-9-]+\.)*kontur\.ru$/D',$h))$out[]=['directive'=>$name,'origin'=>'https://'.$h];
                if(count($out)>=32)return $out;
            }
        }return $out;
    }

    public static function project(string $html,string $box,string $message,string $document,string $headerCsp = ''):array {
        if(!self::allowed($box,$message,$document))throw new RuntimeException('ACCESS_DENIED');
        if(strlen($html)>2*1024*1024)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        if(stripos($html,'<!ENTITY')!==false)throw new RuntimeException('DOCUMENT_LIST_SCHEMA_CHANGED');
        if(!class_exists(\DOMDocument::class))throw new RuntimeException('SOURCE_UNAVAILABLE');
        $previous=libxml_use_internal_errors(true);
        try{$dom=new \DOMDocument();$ok=$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT);}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        if(!$ok||$dom->getElementsByTagName('*')->length>10000)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        $anchors=$dom->getElementsByTagName('a');$scripts=$dom->getElementsByTagName('script');
        if($anchors->length>512||$scripts->length>128)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        $links=[];$assets=[];$redacted=0;$dropped=0;$allowedIds=array_map('strtolower',[$box,$message,$document]);
        $diagnostics=[];$diagnosticsOmitted=0;$srcCount=0;$jsonStructures=[];$jsonCount=0;
        $note=static function($node,string $element,int $index,string $kind,string $reason)use(&$diagnostics,&$diagnosticsOmitted):void{
            if(count($diagnostics)>=128){++$diagnosticsOmitted;return;}
            $diagnostics[]=['dom_path'=>$node->getNodePath(),'element'=>$element,'index'=>$index,'index_basis'=>'element_type','url_kind'=>$kind,'reason'=>$reason];
        };
        $card='https://diadoc.kontur.ru/'.$box.'/Document/Show';$base=$card;
        $bases=$dom->getElementsByTagName('base');
        if($bases->length){$raw=$bases->item(0)->getAttribute('href');$why=null;
            // A base URI carrying a query/fragment is not a public resolution root.
            $candidate=strpbrk($raw,'?#')===false?self::resolve($raw,$card,$why):null;
            if($candidate!==null)$base=$candidate;else $note($bases->item(0),'base',0,self::kind($raw),'unsafe_base_ignored');
        }
        $csp=self::cspOrigins($headerCsp);
        foreach($dom->getElementsByTagName('meta')as$meta)if(strtolower($meta->getAttribute('http-equiv'))==='content-security-policy')$csp=array_slice(array_merge($csp,self::cspOrigins($meta->getAttribute('content'))),0,32);

        foreach($anchors as$anchorIndex=>$node){
            $href=$node->getAttribute('href');$p=self::parts($href);if($p===null){++$dropped;$note($node,'a',$anchorIndex,self::kind($href),'existing_print_filter_uri_rejected');continue;}
            $path=$p['path'];if(!preg_match('~print|download|pdf~i',$path)){$note($node,'a',$anchorIndex,self::kind($href),'not_print_path');continue;}
            $safePath=true;
            $knownQuery=['letterId'=>strtolower($message),'messageId'=>strtolower($message),'documentId'=>strtolower($document),'attachmentId'=>strtolower($document),'boxId'=>strtolower($box)];
            foreach(explode('/',trim($path,'/'))as$segment){
                if(in_array(strtolower($segment),$allowedIds,true))continue;
                if(!preg_match('/^[A-Za-z][A-Za-z_.-]{0,63}$/D',$segment)&&!preg_match('/^v[0-9]{1,2}$/D',$segment)){$safePath=false;break;}
            }
            if(!$safePath){++$dropped;$note($node,'a',$anchorIndex,self::kind($href),'existing_print_filter_path_rejected');continue;}
            $query=[];$unknown=[];$seen=[];
            foreach(explode('&',$p['query']??'')as$pair){
                if($pair==='')continue;[$key,$value]=array_pad(explode('=',$pair,2),2,'');$key=rawurldecode($key);$value=rawurldecode($value);
                if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$key)||isset($seen[$key])){++$redacted;continue;}$seen[$key]=true;
                if(preg_match('/token|auth|session|cookie|csrf|secret|password|signature|key/i',$key)){++$redacted;continue;}
                if(isset($knownQuery[$key])&&strtolower($value)===$knownQuery[$key])$query[$key]=strtolower($value);
                elseif(in_array($value,['true','false'],true))$query[$key]=$value==='true';
                else{$unknown[]=['key'=>$key,'length'=>strlen($value),'sha256'=>hash('sha256',$value)];++$redacted;}
                if(count($unknown)>16||count($query)>16)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
            }
            $links[]=['path'=>$path,'query'=>$query,'unresolved_query'=>$unknown,'executable'=>false];
            if(count($links)>32)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        }
        foreach($scripts as$scriptIndex=>$node){
            if(!$node->hasAttribute('src')){
                if(strtolower(trim($node->getAttribute('type')))==='application/json'){
                    ++$jsonCount;$raw=$node->textContent;
                    if(strlen($raw)>65536){$note($node,'script',$scriptIndex,'inline_json','inline_json_too_large');continue;}
                    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
                    catch(\JsonException $e){$note($node,'script',$scriptIndex,'inline_json','invalid_json_no_eval');continue;}
                    $nodes=0;$shape=[];
                    $walk=static function($x,string $path='$',int $depth=0)use(&$walk,&$nodes,&$shape):void{
                        if(++$nodes>512||$depth>12)return;
                        $shape[]=['path'=>$path,'type'=>is_array($x)?(array_is_list($x)?'array':'object'):(is_string($x)?'string':(is_bool($x)?'boolean':(is_null($x)?'null':'number')))];
                        if(is_array($x))foreach($x as$k=>$v){$key=is_int($k)?'['.$k.']':(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',(string)$k)?'['.json_encode($k).']':'["redacted_key"]');$walk($v,$path.$key,$depth+1);}
                    };$walk($data);
                    if(count($jsonStructures)>=4){$note($node,'script',$scriptIndex,'inline_json','inline_json_count_limit');continue;}
                    $jsonStructures[]=['dom_path'=>$node->getNodePath(),'structure'=>$shape,'truncated'=>$nodes>512];
                }else $note($node,'script',$scriptIndex,'inline_js','inline_code_not_inspected');
                continue;
            }
            ++$srcCount;$raw=$node->getAttribute('src');$why=null;$url=self::resolve($raw,$base,$why);
            if($url===null){++$dropped;$note($node,'script',$scriptIndex,self::kind($raw),$why??'invalid_uri');continue;}
            if(!str_ends_with(strtolower(parse_url($url,PHP_URL_PATH)),'.js')){++$dropped;$note($node,'script',$scriptIndex,self::kind($raw),'not_js_path');continue;}
            $assets[]=$url;$note($node,'script',$scriptIndex,self::kind($raw),'accepted_query_fragment_removed');
            if(count($assets)>32)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        }
        $result=['kind'=>'diadoc_print_discovery','html_sha256'=>hash('sha256',$html),'html_bytes'=>strlen($html),'print_links'=>$links,'public_script_candidates'=>array_values(array_unique($assets)),
            'counts'=>['anchors'=>$anchors->length,'scripts'=>$scripts->length,'script_src'=>$srcCount,'inline_json'=>$jsonCount],'diagnostics'=>$diagnostics,'diagnostics_omitted'=>$diagnosticsOmitted,'csp_script_origins'=>$csp,'inline_json_structures'=>$jsonStructures,'redacted_query_values'=>$redacted,'dropped_links'=>$dropped,'pdf_contract_confirmed'=>false,'diagnostic'=>count($links)||count($assets)?'INERT_REFERENCES_ONLY':'NO_STATIC_PRINT_REFERENCES'];
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>65536)throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        return $result;
    }
}
