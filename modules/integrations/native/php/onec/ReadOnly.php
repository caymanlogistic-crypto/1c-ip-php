<?php
declare(strict_types=1);
namespace NativeOnec;
require_once __DIR__.'/OnecProbe.php';
require_once __DIR__.'/FreshRead.php';
require_once __DIR__.'/PartySupplement.php';

/** Fixed HTTPS target, GET-only, up to ten primary records. No credential-file fallback. */
final class Reader
{
    use FreshRead;
    use PartySupplement;
    public const COLLECTIONS=[
        'invoices'=>'Document_СчетНаОплатуПокупателю',
        'bank_in'=>'Document_ПоступлениеНаРасчетныйСчет',
        'bank_out'=>'Document_СписаниеСРасчетногоСчета',
        'organizations'=>'Catalog_Организации','items'=>'Catalog_Номенклатура',
    ];
    private float $deadline;
    private int $bytes=0;
    public array $requests=[];
    public array $diagnostics=[];
    public static function failureCode(?\Throwable $error):string
    {
        if($error===null)return 'OK';
        $code=$error->getMessage();
        return in_array($code,['AUTH_REQUIRED','TIME_LIMIT','REQUEST_BUDGET','CA_FILE_UNAVAILABLE','RESPONSE_TOO_LARGE',
            'NETWORK_OR_TLS_FAILED','VERIFIED_PIN_REQUIRED','SOURCE_HTTP_FAILED','READ_SCHEMA_OR_LIMIT',
            'READ_KEY_INVALID','READ_INPUT_REJECTED','READ_ROUTE_REJECTED'],true)?$code:'NATIVE_ERROR';
    }
    private static function timing(array $info,string $key):?int
    {
        $value=$info[$key]??null;
        return (is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0&&$value<=900?(int)round($value*1000):null;
    }
    public function __construct(private string $username,private string $password,
                                private ?string $caFile=null,private $fixture=null,private int $requestTimeout=30)
    {
        if($username===''||$password===''||preg_match('/[\x00-\x1f\x7f:]/',$username)||preg_match('/[\x00-\x1f\x7f]/',$password))throw new \RuntimeException('AUTH_REQUIRED');
        if(!in_array($requestTimeout,[30,60],true))throw new \RuntimeException('READ_INPUT_REJECTED');
        $this->deadline=hrtime(true)/1e9+300;
        $budget=$GLOBALS['ONLINE_DEADLINE']??null;
        if(is_float($budget)&&is_finite($budget))$this->deadline=min($this->deadline,$budget);
    }
    private function get(string $resource,array $query=[]):array
    {
        if(!in_array($resource,['','$metadata',...self::FRESH_CATALOGS,...self::SUPPLEMENT_CATALOGS,...array_values(self::COLLECTIONS)],true))throw new \RuntimeException('READ_ROUTE_REJECTED');
        if(count($this->requests)>=12||$this->bytes>=32*1024*1024)throw new \RuntimeException('REQUEST_BUDGET');
        $seconds=min($this->requestTimeout,$this->deadline-hrtime(true)/1e9);if($seconds<=0)throw new \RuntimeException('TIME_LIMIT');
        $cap=min($resource==='$metadata'?16*1024*1024:8*1024*1024,32*1024*1024-$this->bytes);
        $path=\OnecProbe::ROOT.rawurlencode($resource);if($query)$path.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $headers=['Accept: '.(in_array($resource,['','$metadata'],true)?'application/xml, application/atomsvc+xml':'application/json'),
                  'Authorization: Basic '.base64_encode($this->username.':'.$this->password),'Accept-Encoding: identity'];
        $started=gmdate(DATE_ATOM);$clock=hrtime(true);$error=null;$info=[];$errno=null;$body='';
        try{
            if($this->fixture)$r=($this->fixture)('GET',$resource,$query,$headers,$seconds,$cap);
            else{
                if($this->caFile!==null&&(!is_file($this->caFile)||!is_readable($this->caFile)))throw new \RuntimeException('CA_FILE_UNAVAILABLE');
                $body='';$large=false;$ch=curl_init('https://'.\OnecProbe::HOST.$path);
                $opts=[CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                       CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
                       CURLOPT_RESOLVE=>[\OnecProbe::HOST.':443:'.\OnecProbe::IP],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                       CURLOPT_CONNECTTIMEOUT_MS=>min(10000,(int)ceil($seconds*1000)),CURLOPT_TIMEOUT_MS=>(int)ceil($seconds*1000),
                       CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$body,&$large,$cap):int{if(strlen($body)+strlen($part)>$cap){$large=true;return 0;}$body.=$part;return strlen($part);}];
                if($this->caFile!==null)$opts[CURLOPT_CAINFO]=$this->caFile;
                if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA'))$opts[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
                curl_setopt_array($ch,$opts);$ok=curl_exec($ch);$info=curl_getinfo($ch);$errno=curl_errno($ch);
                if($ok===false)throw new \RuntimeException($large?'RESPONSE_TOO_LARGE':'NETWORK_OR_TLS_FAILED');
                if(($info['primary_ip']??'')!==\OnecProbe::IP||($info['ssl_verify_result']??-1)!==0)throw new \RuntimeException('VERIFIED_PIN_REQUIRED');
                $r=['status'=>(int)$info['http_code'],'body'=>$body];
            }
            if(!is_string($r['body']??null)||strlen($r['body'])>$cap)throw new \RuntimeException('RESPONSE_TOO_LARGE');
            $this->bytes+=strlen($r['body']);
            if(in_array($r['status'],[401,403],true))throw new \RuntimeException('AUTH_REQUIRED');
            if($r['status']!==200)throw new \RuntimeException('SOURCE_HTTP_FAILED');
            if(!in_array($resource,['','$metadata'],true)&&empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'])&&is_callable($GLOBALS['IPPHP_CAPTURE_RAW']??null))($GLOBALS['IPPHP_CAPTURE_RAW'])($r['body']);
            return $r;
        }catch(\Throwable $e){$error=$e;throw $e;}
        finally{
            $http=$info['http_code']??$r['status']??null;$ssl=$info['ssl_verify_result']??null;
            $this->diagnostics[]=['curl_errno'=>is_int($errno)&&$errno>=0&&$errno<=9999?$errno:null,
                'http_code'=>is_int($http)&&$http>=0&&$http<=599?$http:null,
                'connect_ms'=>self::timing($info,'connect_time'),'pretransfer_ms'=>self::timing($info,'pretransfer_time'),
                'starttransfer_ms'=>self::timing($info,'starttransfer_time'),'total_ms'=>self::timing($info,'total_time'),
                'ssl_verify_result'=>is_int($ssl)&&$ssl>=0&&$ssl<=65535?$ssl:null,
                'bytes'=>min(32*1024*1024,is_string($r['body']??null)?strlen($r['body']):strlen($body)),
                'failure_code'=>self::failureCode($error)];
            $this->requests[]=['stage'=>$resource===''?'service':($resource==='$metadata'?'metadata':'read'),
                  'method'=>'GET','started_at'=>$started,'finished_at'=>gmdate(DATE_ATOM),
                  'duration_ms'=>(int)round((hrtime(true)-$clock)/1e6),'http'=>$r['status']??null,
                  'bytes'=>isset($r['body'])?strlen($r['body']):0,'status'=>$error?'failed':'finished'];}
    }
    public function service():array{return \OnecProbe::service($this->get('')['body']);}
    public function metadata():array{return \OnecProbe::metadata($this->get('$metadata')['body']);}
    public function rows(string $kind,int $limit=10,int $skip=0):array
    {
        if(!isset(self::COLLECTIONS[$kind])||$limit<1||$limit>10||$skip<0||$skip>10000)throw new \RuntimeException('READ_INPUT_REJECTED');
        $query=['$top'=>(string)$limit,'$skip'=>(string)$skip,'$format'=>'json'];
        $query['$orderby']=in_array($kind,['invoices','bank_in','bank_out'],true)?'Date desc,Ref_Key desc':'Ref_Key';
        if($kind==='invoices')$query['$filter']='DeletionMark eq false';
        $data=\Modules\Integrations\Support::decode($this->get(self::COLLECTIONS[$kind],$query)['body']);
        // A server continuation can shorten a page. Never mistake it for EOF or
        // follow a source-provided URL outside this reader's fixed route contract.
        foreach([$data,is_array($data['d']??null)?$data['d']:[]]as$envelope)
            foreach(['odata.nextLink','@odata.nextLink','__next']as$field)
                if(isset($envelope[$field]))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $rows=$data['value']??$data['d']['results']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>$limit)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $seen=[];foreach($rows as$row){$key=$row['Ref_Key']??null;if(!is_string($key)||isset($seen[$key]))throw new \RuntimeException('READ_KEY_INVALID');$seen[$key]=true;}
        return ['rows'=>$rows,'count'=>count($rows),'next_skip'=>count($rows)===$limit?$skip+count($rows):null,
                'has_more_unknown'=>count($rows)===$limit,'scope_limited'=>true,'primary_record_limit'=>$limit];
    }
    /** Explicit operation diagnostic; does not use or change the service-root handshake. */
    public function capabilityMetadata(string $alias):int
    {
        $resource=match($alias){'organization_metadata'=>self::COLLECTIONS['organizations'],'invoice_metadata'=>self::COLLECTIONS['invoices'],default=>throw new \RuntimeException('READ_ROUTE_REJECTED')};
        $present=array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL',$GLOBALS);$previous=$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']??null;
        $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=true;
        try{
            $raw=$this->get($resource,['$select'=>'Ref_Key,DataVersion','$top'=>'1'])['body'];
            $data=\Modules\Integrations\Support::decode($raw);
            foreach([$data,is_array($data['d']??null)?$data['d']:[]]as$envelope)
                foreach(['odata.nextLink','@odata.nextLink','__next']as$field)if(isset($envelope[$field]))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
            $rows=$data['value']??$data['d']['results']??null;
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>1)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
            foreach($rows as$row){
                if(!is_array($row)||!is_string($row['Ref_Key']??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$row['Ref_Key'])
                    ||!is_string($row['DataVersion']??null)||$row['DataVersion']===''||strlen($row['DataVersion'])>128)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
                if(array_diff(array_keys($row),['Ref_Key','DataVersion','__metadata']))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
            }
            return count($rows);
        }finally{if($present)$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=$previous;else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);}
    }
    /** Fixed period, numeric paging only. Caller cannot provide OData or a URL.
     * The broker seals the complete worklist before acknowledging any document.
     * A source without the requested count/continuation contract fails closed.
     */
    public function archiveInvoices(string $first,string $last,int $skip=0):array
    {
        return $this->archivePeriod('invoices',$first,$last,$skip);
    }
    public function archiveBank(string $first,string $last,int $skip=0):array
    {
        $page=$this->archivePeriod('bank_in',$first,$last,$skip);
        $groups=['Catalog_Контрагенты'=>[],'Catalog_Организации'=>[],'Catalog_БанковскиеСчета'=>[]];
        foreach($page['items']as$row){
            $refs=[$row['Контрагент_Key']??$row['Контрагент']??'',$row['Организация_Key']??'',
                $row['БанковскийСчетОрганизации_Key']??$row['СчетОрганизации_Key']??''];
            foreach(array_keys($groups)as$i=>$catalog)
                if(is_string($refs[$i])&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$refs[$i])&&$refs[$i]!=='00000000-0000-0000-0000-000000000000')
                    $groups[$catalog][]=$refs[$i];
        }
        foreach($groups as$catalog=>$keys)$groups[$catalog]=$this->freshReferences($catalog,$keys);
        foreach($page['items']as&$row){
            $party=$groups['Catalog_Контрагенты'][$row['Контрагент_Key']??$row['Контрагент']??'']??[];
            $org=$groups['Catalog_Организации'][$row['Организация_Key']??'']??[];
            $account=$groups['Catalog_БанковскиеСчета'][$row['БанковскийСчетОрганизации_Key']??$row['СчетОрганизации_Key']??'']??[];
            $row['BankPayer']=$party['НаименованиеПолное']??$party['Description']??'';
            $row['BankPayerINN']=$party['ИНН']??'';
            $row['BankOrganization']=$org['НаименованиеПолное']??$org['Description']??'';
            $row['BankAccount']=$account['НомерСчета']??$account['Description']??'';
        }
        unset($row);
        return $page;
    }
    private function archivePeriod(string $kind,string $first,string $last,int $skip):array
    {
        $zone=new \DateTimeZone('Europe/Moscow');
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$first,$zone);
        $end=\DateTimeImmutable::createFromFormat('!Y-m-d',$last,$zone);
        if(!$start||!$end||$start->format('Y-m-d')!==$first||$end->format('Y-m-d')!==$last
            ||$first<($kind==='invoices'?'2026-09-01':'2026-10-01')||$last<$first||$last>(new \DateTimeImmutable('now',$zone))->format('Y-m-d')
            ||$skip<0||$skip>100000||$skip%100!==0)throw new \RuntimeException('READ_INPUT_REJECTED');
        $until=$end->modify('+1 day')->format('Y-m-d');
        $query=['$format'=>'json','$top'=>'100','$skip'=>(string)$skip,'$inlinecount'=>'allpages',
            '$orderby'=>'Date asc,Ref_Key asc',
            '$filter'=>"DeletionMark eq false and Date ge datetime'".$first."T00:00:00' and Date lt datetime'".$until."T00:00:00'"];
        if($kind==='bank_in')$query['$filter']="Posted eq true and ".$query['$filter'];
        $data=\Modules\Integrations\Support::decode($this->get(self::COLLECTIONS[$kind],$query)['body']);
        foreach([$data,is_array($data['d']??null)?$data['d']:[]]as$envelope)
            foreach(['odata.nextLink','@odata.nextLink','__next']as$field)
                if(isset($envelope[$field]))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $rows=$data['value']??$data['d']['results']??null;
        $total=$data['odata.count']??$data['@odata.count']??$data['d']['__count']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>100
            ||!(is_int($total)||is_string($total)&&ctype_digit($total))||$total<0||$total>100000
            ||count($rows)!==min(100,max(0,(int)$total-$skip))||$skip>0&&$skip>=(int)$total)
            throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $prior=null;
        foreach($rows as$row){
            $key=$row['Ref_Key']??null;$stamp=$row['Date']??null;$version=$row['DataVersion']??null;
            $parsed=is_string($stamp)?\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$stamp,$zone):false;
            if(!is_string($key)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)
                ||!$parsed||$parsed->format('Y-m-d\TH:i:s')!==$stamp||$stamp<$first.'T00:00:00'||$stamp>=$until.'T00:00:00'
                ||($row['DeletionMark']??null)!==false||!is_string($version)||$version===''||strlen($version)>250)
                throw new \RuntimeException('READ_KEY_INVALID');
            $order=[$stamp,$key];if($prior!==null&&$order<=$prior)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');$prior=$order;
        }
        return ['items'=>$rows,'next'=>$skip+count($rows)<(int)$total?$skip+count($rows):null,'total'=>(int)$total];
    }
    private function readRows(string $resource,array $query,int $maximum):array
    {
        $data=\Modules\Integrations\Support::decode($this->get($resource,$query)['body']);
        $rows=$data['value']??$data['d']['results']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>$maximum)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        foreach($rows as$row)if(!is_array($row)||array_is_list($row))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $more=false;
        foreach([$data,is_array($data['d']??null)?$data['d']:[]]as$envelope)
            foreach(['odata.nextLink','@odata.nextLink','__next']as$field)
                if(isset($envelope[$field])&&$envelope[$field]!==''){
                    if(!is_string($envelope[$field]))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
                    $more=true; // Deliberately never follow a source-supplied URL.
                }
        return [$rows,$more];
    }
}
