<?php
declare(strict_types=1);

namespace Diadoc;

use RuntimeException;
require_once __DIR__.'/PrintDiscovery.php';
require_once __DIR__.'/NativePrint.php';

/** Small HTTPS client. Cookies and response bodies never go to stdout. */
final class Http
{
    private ?\CurlHandle $curl;
    public array $receipts = [];
    private int $totalBytes = 0;
    private array $printJobs = [];
    private static function now():string {return \DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',microtime(true)))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i:s.vP');}

    public function __construct(private string $directory, ?string $caBundle = null, private $observer = null, private float $deadline = INF)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP extension curl is required');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create private state directory');
        }
        $this->curl = curl_init();
        $jar = $directory . '/cookies.txt';
        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'DiadocLocalPhpProbe/0.1',
        ]);
        curl_setopt($this->curl, CURLOPT_COOKIELIST, 'RELOAD');
        if ($caBundle !== null) {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                throw new RuntimeException('Configured CA bundle is not readable');
            }
            curl_setopt($this->curl, CURLOPT_CAINFO, $caBundle);
        }
    }

    /** Only authentication POSTs and read/download GETs; never a document mutation. */
    public static function stage(string $method, string $url, mixed $body = null, array $jobs = []): string
    {
        $print=NativePrint::route($method,$url,$body,$jobs);if($print!==null)return $print;
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || isset($p['user'], $p['pass']) || isset($p['fragment'])
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) && $p['port'] !== 443) throw new RuntimeException('DESTINATION_REJECTED');
        $host = $p['host'] ?? ''; $path = $p['path'] ?? '/';
        parse_str($p['query'] ?? '', $query);
        if ($host === 'auth.kontur.ru') {
            if ($method === 'GET' && $path === '/' && $query === ['customize'=>'diadoc','back'=>'https://diadoc.kontur.ru/']) return 'auth-bootstrap';
            if ($method === 'POST' && $query === ['customize'=>'diadoc']) {
                $routes=['/api/authentication/password/auth-by-password'=>'auth-login','/api/authentication/totp/get-code-by-pushid'=>'auth-confirm-status','/api/authentication/totp/auth-by-code'=>'auth-confirm'];
                if (isset($routes[$path])) return $routes[$path];
            }
        }
        $guid='[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}';
        if ($host === 'diadoc.kontur.ru' && $method === 'GET') {
            if(preg_match('~^/('.$guid.')/Document/Show$~iD',$path,$card)&&count($query)===2&&isset($query['letterId'],$query['documentId'])&&is_string($query['letterId'])&&is_string($query['documentId'])
                &&($p['query']??'')===http_build_query(['letterId'=>$query['letterId'],'documentId'=>$query['documentId']],'','&',PHP_QUERY_RFC3986)
                &&PrintDiscovery::allowed($card[1],$query['letterId'],$query['documentId']))return 'document-print-discovery';
            if (preg_match('~^/webapi/boxes/'.$guid.'/documents$~iD',$path) && !array_diff(array_keys($query),['category','limit','hideLogisticsDocuments','from'])
                && in_array($query['category']??'', ['Any','Incoming','Outgoing','Internal','Deleted'],true)
                && ctype_digit((string)($query['limit']??'')) && (int)$query['limit']>=1 && (int)$query['limit']<=50
                && ($query['hideLogisticsDocuments']??'')==='false' && (!isset($query['from']) || is_string($query['from']) && strlen($query['from'])<=8192)) return 'registry-page';
            if (preg_match('~^/webapi/'.$guid.'/Download/Attachment$~iD',$path) && count($query)===2 && !array_diff(array_keys($query),['letterId','attachmentId'])
                && preg_match('~^'.$guid.'$~iD',(string)($query['letterId']??'')) && preg_match('~^'.$guid.'$~iD',(string)($query['attachmentId']??''))) return 'document-original';
        }
        throw new RuntimeException('READ_ONLY_ROUTE_REJECTED');
    }

    public function bindPrintJob(string $box,string $message,string $document,string $id,string $type):void
    {
        if(!PrintDiscovery::allowed($box,$message,$document)||!NativePrint::validTask(['id'=>$id,'type'=>$type,'name'=>'document.pdf'])||count($this->printJobs)>=3)throw new RuntimeException('ACCESS_DENIED');
        $this->printJobs[NativePrint::jobKey($box,$id,$type)]=true;
    }

    public function cookie(string $name): string
    {
        foreach (curl_getinfo($this->curl, CURLINFO_COOKIELIST) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) === 7 && $fields[5] === $name) {
                return rawurldecode($fields[6]);
            }
        }
        return '';
    }

    public function close(): void
    {
        if ($this->curl !== null) {
            curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH');
            $this->curl = null;
        }
    }

    /** @return array{status:int,body:string,headers:array,url:string} */
    public function request(string $method, string $url, mixed $json = null, array $headers = [], int $limit = 8388608): array
    {
        $stage = self::stage($method,$url,$json,$this->printJobs);
        $left = $this->deadline - hrtime(true)/1e9;
        if ($left<=0) throw new RuntimeException('ONLINE_TIME_LIMIT');
        if (count($this->receipts)>=1005 || $this->totalBytes>=64*1024*1024) throw new RuntimeException('ONLINE_REQUEST_BUDGET');
        $limit=min($limit,24*1024*1024,64*1024*1024-$this->totalBytes);
        $started=self::now();$clock=hrtime(true);
        if ($this->observer) ($this->observer)(['stage'=>$stage,'status'=>'started','method'=>$method,'started_at'=>$started]);
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', ['auth.kontur.ru', 'diadoc.kontur.ru'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('Destination is outside the HTTPS allowlist');
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new RuntimeException('Unsupported HTTP method');
        }
        $body = '';
        $responseHeaders = [];
        $tooLarge = false;
        $requestHeaders = array_merge(['Accept: application/json, text/html;q=0.9'], $headers);
        $payload = null;
        if ($json !== null) {
            $payload = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $requestHeaders[] = 'Content-Type: application/json';
        }
        curl_setopt_array($this->curl, [
            // Clamp before casting: the default INF deadline casts to int 0.
            CURLOPT_CONNECTTIMEOUT_MS => max(1,(int)ceil(min(10.0,$left)*1000)),
            CURLOPT_TIMEOUT_MS => max(1,(int)ceil(min(30.0,$left)*1000)),
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($this->curl);
        $errno = curl_errno($this->curl);
        $status = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $this->totalBytes+=strlen($body);
        $receipt=['stage'=>$stage,'method'=>$method,'started_at'=>$started,'finished_at'=>self::now(),
            'status'=>$status,'bytes'=>strlen($body),'duration_ms'=>(int)round((hrtime(true)-$clock)/1e6),'curl_errno'=>$errno];
        $this->receipts[]=$receipt;
        if ($this->observer) ($this->observer)($receipt);
        curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH');
        if ($tooLarge) {
            throw new RuntimeException('ONLINE_RESPONSE_LIMIT');
        }
        if ($ok === false) {
            // curl_error can include URL fragments; emit only the numeric code.
            throw new RuntimeException(hrtime(true)/1e9>=$this->deadline?'ONLINE_TIME_LIMIT':'ONLINE_HTTPS_FAILED');
        }
        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders, 'url' => $url];
    }
}
