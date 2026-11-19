<?php
declare(strict_types=1);

namespace Mosvodokanal;
require_once dirname(__DIR__).'/Telemetry.php';

use CurlHandle;
use JsonException;
use RuntimeException;

/** Read-only client for the endpoints used by the official MVK portal. */
final class Api
{
    public const BASE = 'https://onewind.mosvodokanal.ru/';
    private ?CurlHandle $curl=null;
    private int $sequence = 0;
    private float $lastRequest = 0;
    private float $startedAt;
    private string $runId;
    public array $requests = [];
    public const REPORTS = [
        'NATURAL_PERSON_USER_ACCOUNT_REGISTER', 'NATURAL_PERSON_ACCOUNT_BILL_REGISTER',
        'MOBILE_ACCOUNT_PAYMENT_REESTR', 'BILL_PAYMENTS_REESTR',
        'NATURAL_PERSON_ACCOUNT_ACQ_ORDER_REGISTER', 'NATURAL_PERSON_ACCOUNT_PREPAYMENT_REGISTER',
        'ACCOUNT_METER_REGISTER', 'METER_READING_REESTR',
        'NATURAL_PERSON_METER_READ_REQUEST_HISTORY', 'METER_READ_REQUEST_HISTORY_DATA',
    ];

    public function __construct(
        private readonly string $rawDirectory,
        private readonly int $timeout = 45,
        private readonly int $delayMs = 150,
        ?string $caFile = null,
        private $fixture=null,
        private readonly bool $recordResponses=true,
        private readonly int $responseLimit=33554432,
    ) {
        if($responseLimit<1||$responseLimit>33554432)throw new RuntimeException('RESPONSE_LIMIT_INVALID');
        $this->startedAt = microtime(true);
        // Each broker request constructs a new client in the same private raw directory.
        $this->runId = bin2hex(random_bytes(12));
        if($fixture!==null)return;
        if (!extension_loaded('curl')) {
            throw new RuntimeException('CURL_EXTENSION_REQUIRED');
        }
        $this->curl = curl_init();
        $this->startedAt = microtime(true);
        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => '', // Enable the cookie engine in memory only.
            CURLOPT_ENCODING => '', // The portal also compresses JSON report responses.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'MvkReadonlyExporter/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Requested-With: XMLHttpRequest',
                'Origin: https://onewind.mosvodokanal.ru',
                'X-CSRF-Token: ' . bin2hex(random_bytes(16)),
            ],
            CURLOPT_REFERER => self::BASE,
        ]);
        if ($caFile !== null) {
            if (!is_file($caFile)) {
                throw new RuntimeException('CA_FILE_NOT_FOUND');
            }
            curl_setopt($this->curl, CURLOPT_CAINFO, $caFile);
        }
        if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA'))curl_setopt($this->curl,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
    }

    public function session():array{return ['cookies'=>$this->curl?curl_getinfo($this->curl,CURLINFO_COOKIELIST):[]];}
    public function importSession(array $session):void {
        if(!is_array($session['cookies']??null)||count($session['cookies'])>30)throw new RuntimeException('INVALID_SESSION');
        foreach($session['cookies']as$cookie){
            if(!is_string($cookie)||strlen($cookie)>8192||preg_match('/[\r\n]/',$cookie))throw new RuntimeException('INVALID_SESSION');
            $parts=explode("\t",$cookie);$host=ltrim(preg_replace('/^#HttpOnly_/','',$parts[0]??''),'.');
            if(count($parts)!==7||$host!=='onewind.mosvodokanal.ru')throw new RuntimeException('INVALID_SESSION');
            if($this->curl)curl_setopt($this->curl,CURLOPT_COOKIELIST,$cookie);
        }
    }

    public function login(string $username, string $password): void
    {
        $response = $this->request('api/login', [
            'username' => $username, 'password' => $password, 'captcha' => '',
        ], false, false);
        if (($response['authenticated'] ?? null) !== true) {
            throw new RuntimeException(!empty($response['showCaptcha'])
                ? 'LOGIN_REQUIRES_CAPTCHA' : 'LOGIN_REJECTED');
        }
    }

    public function definition(string $report, bool $record = true): array
    {
        $this->checkReport($report);
        return $this->request('api/ReportFormMaintenance/get', ['reportID' => $report], true, $record);
    }

    public function printMetadata(): array
    {
        // Do not persist the full definition (it can contain SQL or scripts).
        $data = $this->definition('NATURAL_PERSON_ACCOUNT_BILL_REGISTER', false);
        $last = $this->requests[array_key_last($this->requests)];
        return ['definition' => $data, 'source_sha256' => $last['sha256']];
    }

    /** Only the inherited public frontend financial-file route, never caller URL. */
    public function sourceFinancialFile(string $reportCd, string $id, string $type): array
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D',$reportCd)||!preg_match('/^[0-9]{1,25}$/D',$id)||!preg_match('/^[0-9]{1,25}$/D',$type))throw new RuntimeException('ACCESS_DENIED');
        if($this->sequence>=10000||microtime(true)-$this->startedAt>1800)throw new RuntimeException('RUN_REQUEST_OR_TIME_BUDGET_EXCEEDED');
        $query=http_build_query(['id'=>$reportCd,'parameterList'=>json_encode(['id'=>$id,'type'=>$type],JSON_THROW_ON_ERROR)],'', '&',PHP_QUERY_RFC3986);
        $body='';$large=false;$telemetry=\OnlineUtilities\begin('print_file','GET');
        curl_setopt_array($this->curl,[CURLOPT_URL=>self::BASE.'api/publisherReport?'.$query,CURLOPT_HTTPGET=>true,CURLOPT_POST=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT_MS=>(int)ceil(\OnlineUtilities\timeout($this->timeout)*1000),CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static fn()=>\OnlineUtilities\stopped()||hrtime(true)/1e9>=($GLOBALS['ONLINE_DEADLINE']??INF)?1:0,
            CURLOPT_WRITEFUNCTION=>function($h,string $chunk)use(&$body,&$large):int{if(strlen($body)+strlen($chunk)>min(16*1024*1024,$this->responseLimit)){$large=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($this->curl);$http=(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($this->curl);$mime=strtolower(trim(explode(';',(string)curl_getinfo($this->curl,CURLINFO_CONTENT_TYPE))[0]));
        $this->lastRequest=microtime(true);++$this->sequence;
        \OnlineUtilities\end($telemetry,$http,strlen($body),$errno);\OnlineUtilities\check();
        if($ok===false)throw new RuntimeException($large?'RESPONSE_TOO_LARGE':'SOURCE_UNAVAILABLE');
        if($http!==200)throw new RuntimeException($http===401||$http===403?'SESSION_NOT_AUTHENTICATED':'SOURCE_UNAVAILABLE');
        if(!(($mime==='application/pdf'&&str_starts_with($body,'%PDF-'))||($mime==='application/vnd.openxmlformats-officedocument.wordprocessingml.document'&&str_starts_with($body,"PK\x03\x04"))))throw new RuntimeException('RESPONSE_NOT_OBJECT');
        return ['body_base64'=>base64_encode($body),'bytes'=>strlen($body),'sha256'=>hash('sha256',$body),'mime'=>$mime];
    }

    public function page(string $report, array $parameters, int $start, int $limit): array
    {
        $this->checkReport($report);
        $list = [];
        foreach ($parameters as $type => $value) {
            $list[] = ['type' => $type, 'value' => $value];
        }
        return $this->request('api/ReportFormData/get', [
            'reportId' => $report,
            'parameterList' => json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'start' => $start,
            'limit' => $limit,
        ]);
    }

    private function checkReport(string $report): void
    {
        if (!in_array($report, self::REPORTS, true)) {
            throw new RuntimeException('REPORT_NOT_ALLOWED');
        }
    }

    private function request(string $path, array $parameters, bool $get = false, bool $record = true): array
    {
        if ($this->sequence >= 10000 || microtime(true) - $this->startedAt > 1800) {
            throw new RuntimeException('RUN_REQUEST_OR_TIME_BUDGET_EXCEEDED');
        }
        if (!in_array($path, ['api/login', 'api/ReportFormData/get', 'api/ReportFormMaintenance/get'], true)) {
            throw new RuntimeException('ENDPOINT_NOT_ALLOWED');
        }
        $wait = $this->delayMs / 1000 - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int)($wait * 1_000_000));
        }
        $body = '';
        $tooLarge = false;
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $stage=$path==='api/login'?'login':($path==='api/ReportFormMaintenance/get'?'metadata':match($parameters['reportId']??''){
            'NATURAL_PERSON_USER_ACCOUNT_REGISTER'=>'accounts','NATURAL_PERSON_ACCOUNT_BILL_REGISTER'=>'receipts',
            'MOBILE_ACCOUNT_PAYMENT_REESTR','BILL_PAYMENTS_REESTR'=>'payments','ACCOUNT_METER_REGISTER'=>'meters',
            'METER_READING_REESTR'=>'readings',default=>'report'});
        $telemetry=\OnlineUtilities\begin($stage,$get?'GET':'POST');
        if($this->fixture!==null){$response=($this->fixture)($path,$parameters,$get);$body=$response['body'];$http=$response['status'];$errno=$response['errno']??0;$ok=$errno===0;}
        else{
        curl_setopt_array($this->curl, (class_exists(\Modules\Integrations\CabinetNetwork::class)?\Modules\Integrations\CabinetNetwork::options('onewind.mosvodokanal.ru'):[])+[
            CURLOPT_URL => self::BASE . $path . ($get ? '?' . $encoded : ''),
            CURLOPT_POST => !$get,
            CURLOPT_PROXY => '',
            CURLOPT_TIMEOUT_MS=>(int)ceil(\OnlineUtilities\timeout($this->timeout)*1000),
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static fn()=>\OnlineUtilities\stopped()||hrtime(true)/1e9>=($GLOBALS['ONLINE_DEADLINE']??INF)?1:0,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->responseLimit) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($get) {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $encoded);
        }
        $ok = curl_exec($this->curl);
        $this->lastRequest = microtime(true);
        $http = (int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($this->curl);
        }
        if(strlen($body)>$this->responseLimit){$tooLarge=true;$ok=false;}
        \OnlineUtilities\end($telemetry,$http,strlen($body),$errno);\OnlineUtilities\check();
        $meta = [
            'sequence' => ++$this->sequence,
            'endpoint' => $path,
            'report' => $parameters['reportId'] ?? $parameters['reportID'] ?? null,
            'method' => $get ? 'GET' : 'POST',
            'start' => $parameters['start'] ?? null,
            'limit' => $parameters['limit'] ?? null,
            'http' => $http, 'curl_errno' => $errno,
            'bytes' => strlen($body), 'sha256' => hash('sha256', $body),
        ];
        if ($record && $this->recordResponses && empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']) && $body !== '') {
            $name = sprintf('%s_%05d_%s.json', $this->runId, $this->sequence, preg_replace('/[^A-Z0-9_]/i', '_', (string)$meta['report']));
            writeJsonBytes($this->rawDirectory . DIRECTORY_SEPARATOR . $name, $body);
            $meta['raw_file'] = 'raw/' . $name;
        }
        $this->requests[] = $meta;
        if ($ok === false) {
            throw new RuntimeException($tooLarge ? 'RESPONSE_TOO_LARGE' : 'TRANSPORT_' . $errno);
        }
        if ($http !== 200) {
            throw new RuntimeException('HTTP_' . $http);
        }
        if($path==='api/ReportFormData/get'&&empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'])&&is_callable($GLOBALS['IPPHP_CAPTURE_RAW']??null))($GLOBALS['IPPHP_CAPTURE_RAW'])($body);
        return decodeResponse($body, $path === 'api/login');
    }
}

function decodeResponse(string $body, bool $login = false): array
{
    try {
        $decoded = \Modules\Integrations\Support::decode($body);
    } catch (JsonException) {
        throw new RuntimeException('RESPONSE_NOT_JSON');
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('RESPONSE_NOT_OBJECT');
    }
    if (array_key_exists('authenticated', $decoded) && $decoded['authenticated'] === false) {
        // Only login() interprets rejected login/CAPTCHA. Reports always require authentication.
        if (!$login) {
            throw new RuntimeException('SESSION_NOT_AUTHENTICATED');
        }
        return $decoded;
    }
    if (($decoded['success'] ?? true) === false || !empty($decoded['error']) || !empty($decoded['exception']) || !empty($decoded['exceptions'])) {
        throw new RuntimeException('API_BUSINESS_ERROR');
    }
    return $decoded;
}

function writeJsonBytes(string $path, string $bytes): void
{
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('OUTPUT_FILE_EXISTS_OR_UNWRITABLE');
    }
    try {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('OUTPUT_WRITE_FAILED');
        }
    } finally {
        fclose($handle);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0600);
    }
}

function saveJson(string $path, mixed $value): void
{
    writeJsonBytes($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n");
}
