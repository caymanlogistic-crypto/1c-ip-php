<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Fixed DEV HTTP admission shared by the local caller and stateless remote process. */
final class OnecDevRequest
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';
    public const HOST='1c.dltkmsk.ru';
    public const IP='89.208.213.32';
    public const ROOT='/yakovlev_odata/odata/standard.odata/';
    private const BANK=['Document_ПоступлениеНаРасчетныйСчет','Document_СписаниеСРасчетногоСчета'];
    private const REFERENCES=['Catalog_Организации','Catalog_Контрагенты','Catalog_БанковскиеСчета'];
    public const NATIVE_COLLECTIONS=['Catalog_Контрагенты','Document_СчетНаОплатуПокупателю'];
    public const NATIVE_REFERENCES=['Catalog_Организации','Catalog_Контрагенты','Catalog_ДоговорыКонтрагентов','Catalog_Валюты','Catalog_Номенклатура'];

    public static function make(string $method,string $resource,array $query,array $headers,float $seconds,int $cap,string $purpose):array
    {
        $url='https://'.self::HOST.self::ROOT.rawurlencode($resource);
        if($query)$url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        return self::validate(['protocol'=>'ipphp-onec-dev-http-v1','project_id'=>self::PROJECT,
            'request_id'=>bin2hex(random_bytes(16)),'purpose'=>$purpose,'method'=>$method,'resource'=>$resource,
            'query'=>$query,'url'=>$url,'headers'=>$headers,'body'=>null,
            'timeout_ms'=>(int)floor($seconds*1000),'connect_timeout_ms'=>min(10000,(int)floor($seconds*1000)),
            'max_bytes'=>$cap]);
    }

    /** The business owner supplies its already frozen raw body; never parse/reserialize it. */
    public static function makeNative(string $method,string $resource,?string $key,string $body,array $query,array $headers):array
    {
        self::nativeRoute($method,$resource,$key,$body,$query);
        return self::validate(['protocol'=>'ipphp-onec-dev-http-v1','project_id'=>self::PROJECT,
            'request_id'=>bin2hex(random_bytes(16)),'purpose'=>'native','method'=>$method,'resource'=>$resource,
            'key'=>$key,'query'=>$query,'url'=>self::nativeUrl($resource,$key,$query),'headers'=>$headers,
            'body'=>null,'body_base64'=>$method==='POST'?base64_encode($body):null,
            'timeout_ms'=>60000,'connect_timeout_ms'=>10000,'max_bytes'=>2000000]);
    }

    /** Exact copy of the existing native route boundary; no generic OData execution. */
    public static function nativeRoute(string $method,string $resource,?string $key,string $body,array $query):void
    {
        if(!in_array($resource,[...self::NATIVE_COLLECTIONS,...self::NATIVE_REFERENCES],true)
            ||!in_array($method,['GET','POST'],true)||strlen($body)>200000
            ||$method==='GET'&&$body!==''
            ||$method==='POST'&&(!in_array($resource,self::NATIVE_COLLECTIONS,true)||$key!==null||$query||$body==='')){
            throw new \RuntimeException('ONEC_DEV_NATIVE_ROUTE_REJECTED');
        }
        if($key!==null&&(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)
            ||$key==='00000000-0000-0000-0000-000000000000'))throw new \RuntimeException('ONEC_DEV_NATIVE_ROUTE_REJECTED');
        if($method==='GET'&&$key===null){
            $keys=array_keys($query);sort($keys);
            if($resource!=='Catalog_Контрагенты'||$keys!==['$filter','$format','$select','$top']
                ||$query['$format']!=='json'||$query['$top']!=='2'||$query['$select']!=='Ref_Key,ИНН,КПП,DeletionMark'
                ||!is_string($query['$filter'])||!preg_match("/^DeletionMark eq false and ИНН eq '(?:[0-9]{10}|[0-9]{12})'(?: and КПП eq '[0-9A-Z]{9}')?$/D",$query['$filter'])){
                throw new \RuntimeException('ONEC_DEV_NATIVE_ROUTE_REJECTED');
            }
        }elseif($query)throw new \RuntimeException('ONEC_DEV_NATIVE_ROUTE_REJECTED');
    }

    private static function nativeUrl(string $resource,?string $key,array $query):string
    {
        return 'https://'.self::HOST.self::ROOT.rawurlencode($resource).($key!==null?"(guid'".$key."')":'')
            .'?'.http_build_query($query?:['$format'=>'json'],'','&',PHP_QUERY_RFC3986);
    }

    public static function body(array $request):string
    {
        if(($request['purpose']??null)!=='native'||$request['method']==='GET')return '';
        $encoded=$request['body_base64']??null;
        if(!is_string($encoded)||strlen($encoded)>266668)throw new \RuntimeException('ONEC_DEV_BODY_REJECTED');
        $body=base64_decode($encoded,true);
        if(!is_string($body)||$body===''||strlen($body)>200000||base64_encode($body)!==$encoded)throw new \RuntimeException('ONEC_DEV_BODY_REJECTED');
        return $body;
    }

    public static function validate(array $r):array
    {
        $native=($r['purpose']??null)==='native';
        $keys=array_keys($r);sort($keys);$expected=['protocol','project_id','request_id','purpose','method','resource','query','url','headers','body','timeout_ms','connect_timeout_ms','max_bytes'];
        if($native)$expected=[...$expected,'key','body_base64'];
        sort($expected);
        if($keys!==$expected||$r['protocol']!=='ipphp-onec-dev-http-v1'||$r['project_id']!==self::PROJECT
            ||!is_string($r['request_id'])||!preg_match('/^[a-f0-9]{32}$/D',$r['request_id']))throw new \RuntimeException('ONEC_DEV_REQUEST_REJECTED');
        if(!$native&&($r['method']!=='GET'||$r['body']!==null))throw new \RuntimeException('ONEC_DEV_GET_ONLY');
        if($native&&($r['body']!==null||!in_array($r['method'],['GET','POST'],true)
            ||!($r['key']===null||is_string($r['key']))||$r['method']==='GET'&&$r['body_base64']!==null))throw new \RuntimeException('ONEC_DEV_NATIVE_ROUTE_REJECTED');
        if(!is_string($r['resource'])||!is_array($r['query'])||!is_array($r['headers'])||!array_is_list($r['headers'])
            ||!is_int($r['timeout_ms'])||$r['timeout_ms']<1||$r['timeout_ms']>60000
            ||!is_int($r['connect_timeout_ms'])||$r['connect_timeout_ms']<1||$r['connect_timeout_ms']>min(10000,$r['timeout_ms'])
            ||!is_int($r['max_bytes'])||$r['max_bytes']<1||$r['max_bytes']>16777216)throw new \RuntimeException('ONEC_DEV_REQUEST_REJECTED');
        foreach($r['query']as$key=>$value)if(!in_array($key,$native?['$top','$format','$filter','$select']:['$top','$skip','$format','$orderby','$filter','$inlinecount'],true)
            ||!is_string($value)||strlen($value)>16384||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \RuntimeException('ONEC_DEV_QUERY_REJECTED');
        $query=$r['query'];
        if($native){
            if($r['max_bytes']!==2000000)throw new \RuntimeException('ONEC_DEV_REQUEST_REJECTED');
            self::nativeRoute($r['method'],$r['resource'],$r['key'],self::body($r),$query);
        }elseif($r['purpose']==='connection'){
            $want=['$top'=>'1','$skip'=>'0','$format'=>'json','$orderby'=>'Ref_Key'];
            $sorted=$query;ksort($sorted);ksort($want);
            if($r['resource']!=='Catalog_Организации'||$sorted!==$want)throw new \RuntimeException('ONEC_DEV_SCOPE_REJECTED');
        }elseif($r['purpose']==='bank_read'){
            if(!in_array($r['resource'],[...self::BANK,...self::REFERENCES],true)
                ||($query['$format']??null)!=='json'||preg_match('/^[0-9]+$/D',$query['$top']??'')!==1
                ||(int)$query['$top']<1||(int)$query['$top']>101)throw new \RuntimeException('ONEC_DEV_SCOPE_REJECTED');
            if(in_array($r['resource'],self::BANK,true)){
                if(($query['$top']??null)!=='100'||($query['$inlinecount']??null)!=='allpages'
                    ||($query['$orderby']??null)!=='Date asc,Ref_Key asc'||preg_match('/^[0-9]+$/D',$query['$skip']??'')!==1
                    ||(int)$query['$skip']>100000||(int)$query['$skip']%100!==0
                    ||!preg_match("/^(?:Posted eq true and )?DeletionMark eq false and Date ge datetime'[0-9]{4}-[0-9]{2}-[0-9]{2}T00:00:00' and Date lt datetime'[0-9]{4}-[0-9]{2}-[0-9]{2}T00:00:00'$/D",$query['$filter']??''))throw new \RuntimeException('ONEC_DEV_QUERY_REJECTED');
            }else{
                $guid="[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}";
                if(array_diff(array_keys($query),['$format','$top','$filter','$orderby'])
                    ||($query['$orderby']??null)!=='Ref_Key'
                    ||!preg_match("/^DeletionMark eq false and \\(Ref_Key eq guid'$guid'(?: or Ref_Key eq guid'$guid')*\\)$/D",$query['$filter']??''))throw new \RuntimeException('ONEC_DEV_QUERY_REJECTED');
            }
        }else throw new \RuntimeException('ONEC_DEV_SCOPE_REJECTED');
        $url=$native?self::nativeUrl($r['resource'],$r['key'],$query):'https://'.self::HOST.self::ROOT.rawurlencode($r['resource']);
        if(!$native&&$query)$url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        if($r['url']!==$url)throw new \RuntimeException('ONEC_DEV_ORIGIN_REJECTED');
        $seen=[];
        foreach($r['headers']as$header){
            if(!is_string($header)||preg_match('/[\x00-\x1f\x7f]/',$header)||!str_contains($header,': '))throw new \RuntimeException('ONEC_DEV_HEADER_REJECTED');
            [$name,$value]=explode(': ',$header,2);$name=strtolower($name);
            if(isset($seen[$name])||!in_array($name,$native?['accept','accept-encoding','authorization','content-type']:['accept','accept-encoding','authorization'],true))throw new \RuntimeException('ONEC_DEV_HEADER_REJECTED');
            $seen[$name]=$value;
        }
        if(($seen['accept']??null)!=='application/json'||($seen['accept-encoding']??null)!=='identity'
            ||!preg_match('/^Basic [A-Za-z0-9+\/]+={0,2}$/D',$seen['authorization']??''))throw new \RuntimeException('ONEC_DEV_HEADER_REJECTED');
        if($native&&($seen['content-type']??null)!=='application/json; charset=utf-8')throw new \RuntimeException('ONEC_DEV_HEADER_REJECTED');
        $pair=base64_decode(substr($seen['authorization'],6),true);
        if(!is_string($pair)||strlen($pair)>8192||!str_contains($pair,':')||preg_match('/[\x00-\x1f\x7f]/',$pair))throw new \RuntimeException('ONEC_DEV_HEADER_REJECTED');
        return $r;
    }
}
