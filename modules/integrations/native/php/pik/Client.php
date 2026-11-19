<?php
declare(strict_types=1);

/** Standalone PHP 8.1+ CLI exporter. No Composer, Python or browser required. */
namespace PikComfort;
require_once dirname(__DIR__).'/Telemetry.php';

const API = 'https://resident-cabinet-back.pik-software.ru/';
const ENDPOINTS = [
    'accounts' => 'api/v25/aggregate/dashboard/',
    'receipts' => 'api/v7/mobile/receipt-list/',
    'payments' => 'api/v4/payment-userpayment-list/',
];
const MONEY = ['charge','charge_correct','payment','incoming_balance','subsidy','total','penalty','paid','debt','amount','boosted_charge'];
const ACCOUNT_FIELDS = ['account_uid','account_number','address'];
const RECEIPT_FIELDS = ['receipt_uid','period','charge','charge_correct','payment','incoming_balance','subsidy','total','penalty','paid','debt'];
const PAYMENT_FIELDS = ['payment_uid','payment_date','amount','status','payment_type','payment_point','bank_id','check_url','is_auto_payment'];
const SERVICE_FIELDS = ['receipt_uid','period','provider_uid','service_uid','service_code','service_name','service_type','total','charge','charge_correct','payment','subsidy','incoming_balance','boosted_charge'];

class ExportError extends \RuntimeException {}
class AuthenticationError extends ExportError {}
class CancelledError extends ExportError {}

function setEventSink(?callable $sink): void { $GLOBALS['PIK_COMFORT_EVENT_SINK']=$sink; }
function setCancelCheck(?callable $check): void { $GLOBALS['PIK_COMFORT_CANCEL_CHECK']=$check; }
function checkCancellation(): void {
    if (is_callable($GLOBALS['PIK_COMFORT_CANCEL_CHECK']??null) && ($GLOBALS['PIK_COMFORT_CANCEL_CHECK'])()) { throw new CancelledError('Export cancelled'); }
}

function stamp(): string { return date('c'); }
function encode($data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"; }

/** Quote decimal number tokens before decoding; quoted JSON strings are untouched. */
function decode(string $raw): array {
    $quoted = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
        static function(array $m): string {
            return $m[0][0] !== '"' && strpbrk($m[0], '.eE') !== false ? json_encode($m[0], JSON_THROW_ON_ERROR) : $m[0];
        }, ltrim($raw, "\xEF\xBB\xBF"));
    try { $data = json_decode($quoted ?? '', true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (\JsonException $e) { throw new ExportError('Invalid JSON response/file'); }
    if (!is_array($data)) { throw new ExportError('Unexpected JSON shape'); }
    return $data;
}


function publish(string $temporary,string $target): void {
    // Windows scanners/readers may briefly hold a handle that denies replacement.
    for ($attempt=0;$attempt<8;$attempt++) {
        if (@rename($temporary,$target)) { return; }
        if (PHP_OS_FAMILY!=='Windows' || !is_file($temporary)) { break; }
        usleep(min(400000,25000*(2**$attempt)));
    }
    throw new ExportError('Cannot publish export file');
}

function atomicWrite(string $path, string $bytes): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) { throw new ExportError('Cannot create export directory'); }
    $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
    try {
        $count = @file_put_contents($tmp, $bytes, LOCK_EX);
        if ($count !== strlen($bytes)) { throw new ExportError('Cannot write export file'); }
        publish($tmp,$path);
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($path, 0600)) { throw new ExportError('Cannot protect export file'); }
    } finally { if (is_file($tmp)) { @unlink($tmp); } }
}

function sha(string $file): string {
    $hash = @hash_file('sha256', $file);
    if (!is_string($hash)) { throw new ExportError('Cannot verify export file'); }
    return $hash;
}

function id($value): string {
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value)) { throw new ExportError('Unexpected API identifier'); }
    return $value;
}


function phone(string $value): string {
    $value = preg_replace('/[\s()+-]/','',$value) ?? '';
    if (strlen($value) === 10) { $value = '7'.$value; }
    if (strlen($value) === 11 && $value[0] === '8') { $value = '7'.substr($value,1); }
    if (!preg_match('/^7[0-9]{10}$/D',$value)) { throw new ExportError('Expected phone number with 10 or 11 digits'); }
    return $value;
}

class Client {
    public int $requestCount = 0;
    private float $lastRequest = 0;
    public function __construct(private ?string $token = null, private float $interval = 0.25, private int $timeout = 35,private $fixture=null,private ?string $caFile=null,private ?int $accountsTimeout=null) {
        if ($accountsTimeout !== null && ($accountsTimeout < 1 || $accountsTimeout > 60)) { throw new ExportError('Invalid accounts timeout'); }
        if ($token !== null && ($token === '' || preg_match('/\s/',$token))) { throw new AuthenticationError('Invalid authentication token'); }
    }
    public function session():array{return ['token'=>$this->token];}
    public function login(string $username, string $password): void {
        // A fresh login must neither send nor retain a previous session token.
        $this->token = null;
        $response = $this->json('api-token-auth/',[],null,['username'=>phone($username),'password'=>$password]);
        if(!empty($response['showCaptcha'])||!empty($response['captcha_required']))throw new AuthenticationError('CAPTCHA_REQUIRED');
        if(!empty($response['otp_required'])||!empty($response['two_factor_required']))throw new AuthenticationError('MFA_REQUIRED');
        $token = $response['token'] ?? null;
        if (!is_string($token) || $token === '' || preg_match('/\s/',$token)) { throw new AuthenticationError('No authentication token'); }
        $this->token = $token;
    }
    public function json(string $endpoint, array $params = [], ?string $account = null, ?array $payload = null, ?string $save = null): array {
        if (!in_array($endpoint,array_merge(array_values(ENDPOINTS),['api-token-auth/','request-bill/']),true) || ($payload !== null && $endpoint !== 'api-token-auth/')) {
            throw new ExportError('Operation outside export contract');
        }
        if ($save !== null && $endpoint === 'api-token-auth/') { throw new ExportError('Saving authentication response is forbidden'); }
        $url = API.$endpoint.($params ? '?'.http_build_query($params,'','&',PHP_QUERY_RFC3986) : '');
        $raw = $this->request($url,$account,$payload === null ? null : encode($payload));
        if(in_array($endpoint,[ENDPOINTS['accounts'],ENDPOINTS['receipts'],ENDPOINTS['payments']],true)&&empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'])&&is_callable($GLOBALS['IPPHP_CAPTURE_RAW']??null))($GLOBALS['IPPHP_CAPTURE_RAW'])($raw);
        $result = \Modules\Integrations\Support::decode($raw);
        if ($save !== null) { if(!empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']))throw new ExportError('AUTH_CHECK_RAW_ARCHIVE_FORBIDDEN'); atomicWrite($save,$raw); }
        return $result;
    }
    public function pdf(string $url, string $file): void {
        atomicWrite($file,$this->pdfBytes($url));
    }
    public function pdfBytes(string $url, int $maximum=50*1024*1024): string {
        if($maximum<5||$maximum>50*1024*1024)throw new ExportError('Invalid PDF limit');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''),['storage.pik-software.ru','resident-cabinet-back.pik-software.ru'],true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new ExportError('Unrecognised PDF download destination');
        }
        // Signed storage URL is enough; no cabinet credentials are forwarded.
        $raw = $this->request($url,null,null,true,$maximum);
        if (!str_starts_with($raw,'%PDF-')) { throw new ExportError('Downloaded response is not PDF'); }
        if(strlen($raw)>$maximum)throw new ExportError('PDF exceeds export limit');
        return $raw;
    }
    private function request(string $url, ?string $account = null, ?string $body = null, bool $binary = false, int $maximum=50*1024*1024): string {
        $headers = ['Accept: '.($binary ? 'application/pdf' : 'application/json')];
        if (!$binary) {
            if (!str_starts_with($url,API)) { throw new ExportError('API destination rejected'); }
            $headers[] = 'X-Source: web'; $headers[] = 'Origin: https://new.pik-comfort.ru';
            if ($this->token !== null) { $headers[] = 'Authorization: Token '.$this->token; }
            if ($account !== null) { $headers[] = 'X-Account-Uid: '.id($account); }
        }
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
        $attempts = 1; // The shared worker owns explicit retries/resume; one network attempt per stage.
        for ($attempt=0; $attempt<$attempts; $attempt++) {
            checkCancellation();
            $delay = $this->interval - (microtime(true)-$this->lastRequest);
            if ($delay>0) { usleep((int)($delay*1000000)); }
            $this->lastRequest=microtime(true); $this->requestCount++;
            $path=parse_url($url,PHP_URL_PATH);
            $stage=$binary?'pdf-download':match($path){'/api-token-auth/'=>'login','/'.ENDPOINTS['accounts']=>'accounts','/'.ENDPOINTS['receipts']=>'receipts','/'.ENDPOINTS['payments']=>'payments','/request-bill/'=>'pdf-request',default=>'provider-read'};
            // The aggregate dashboard covers all accounts and can approach 30s.
            $requestTimeout=$stage==='accounts'?($this->accountsTimeout??$this->timeout):$this->timeout;
            $telemetry=\OnlineUtilities\begin($stage,$body===null?'GET':'POST');
            if($this->fixture!==null){$response=($this->fixture)($url,$account,$body,$binary);$raw=$response['body'];$http=$response['status'];$errno=$response['errno']??0;$ok=$errno===0;$tooLarge=false;$retryAfter=0;}
            else{
            $ch=curl_init($url); $raw=''; $tooLarge=false; $retryAfter=0;
            $opts = [CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$requestTimeout,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_PROXY=>'',CURLOPT_TIMEOUT_MS=>(int)ceil(\OnlineUtilities\timeout($requestTimeout)*1000),
                CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static fn()=>\OnlineUtilities\stopped()||hrtime(true)/1e9>=($GLOBALS['ONLINE_DEADLINE']??INF)?1:0,
                CURLOPT_USERAGENT=>'PikComfortPHPExport/1.0',CURLOPT_ENCODING=>'',
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$raw,&$tooLarge,$maximum): int {
                    if (strlen($raw)+strlen($chunk)>$maximum) { $tooLarge=true; return 0; }
                    $raw.=$chunk; return strlen($chunk);
                },CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$retryAfter): int {
                    if (preg_match('/^Retry-After:\s*(\d+)/i',$line,$m)) { $retryAfter=min(60,(int)$m[1]); }
                    return strlen($line);
                }];
            if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) { $opts[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA; }
            if ($this->caFile!==null) { if(!is_file($this->caFile))throw new ExportError('CA_FILE_NOT_FOUND');$opts[CURLOPT_CAINFO]=$this->caFile; }
            if ($body!==null) { $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=$body; }
            curl_setopt_array($ch,(class_exists(\Modules\Integrations\CabinetNetwork::class)?\Modules\Integrations\CabinetNetwork::options((string)parse_url($url,PHP_URL_HOST)):[])+$opts); $ok=curl_exec($ch); $errno=curl_errno($ch); $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            unset($ch);
            }
            \OnlineUtilities\end($telemetry,$http,strlen($raw),$errno);\OnlineUtilities\check();
            checkCancellation();
            if ($tooLarge) { throw new ExportError('Response exceeds export limit'); }
            if ($ok!==false && $path==='/api-token-auth/' && $body!==null && in_array($http,[400,401,403],true)) {
                // Login errors can carry explicit challenges. Never expose their body.
                $failure=[];
                if (strlen($raw)<=65536) {
                    try { $failure=decode($raw); } catch (ExportError $e) {}
                }
                if (($failure['showCaptcha']??null)===true || ($failure['captcha_required']??null)===true) { throw new AuthenticationError('CAPTCHA_REQUIRED'); }
                if (($failure['otp_required']??null)===true || ($failure['two_factor_required']??null)===true) { throw new AuthenticationError('MFA_REQUIRED'); }
                // The current official login UI treats HTTP400 as a rejected login.
                // It does not establish which field or authentication factor caused it.
                if ($http===400) { throw new AuthenticationError('LOGIN_REJECTED'); }
            }
            if ($http===401) { throw new AuthenticationError('HTTP 401: sign in again'); }
            if (($ok===false || in_array($http,[429,500,502,503,504],true)) && $attempt+1<$attempts) {
                sleep(max($retryAfter,min(30,2**($attempt+1)))); continue;
            }
            if ($ok===false) { throw new ExportError('Network/TLS error (cURL '.$errno.')'); }
            if ($http<200 || $http>=300) { throw new ExportError('HTTP '.$http); }
            return $raw;
        }
        throw new ExportError('Request did not complete');
    }
}


function receiptPdf($client,string $account,array $receipt,string $dir,int $wait=90,?callable $download=null): array {
    $period=$receipt['period']??'';
    if (!is_string($period) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$period)) { throw new ExportError('Receipt period schema mismatch'); }
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$period);
    if (!$date || $date->format('Y-m-d')!==$period) { throw new ExportError('Invalid receipt date'); }
    $end=$date->modify('last day of this month')->format('Y-m-d'); $deadline=microtime(true)+$wait;
    do {
        $result=$client->json('request-bill/',['account_id'=>$account,'end_date'=>$end],$account);
        if (($result['status']??'')==='SUCCESS' && is_string($result['receipt_url']??null)) {
            $file=$date->format('Y-m').'_'.id($receipt['_uid']??null).'.pdf';
            if($download!==null)return $download($result['receipt_url'],$file);
            $client->pdf($result['receipt_url'],$dir.'/'.$file);
            return ['status'=>'complete','file'=>$file,'sha256'=>sha($dir.'/'.$file)];
        }
        if (($result['status']??'')!=='PENDING') { throw new ExportError('Receipt generation failed'); }
        if (microtime(true)>=$deadline) { throw new ExportError('PDF still pending; resume later'); }
        usleep((int)(min(3,max(0,$deadline-microtime(true)))*1000000));
    } while (true);
}

