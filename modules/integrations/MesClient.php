<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Fixed-origin PHP translation of reviewed MES protocol. OTP is explicit, never mailbox automation. */
final class MesClient
{
    private int $requests=0;private int $bytes=0;private float $deadline;
    public function __construct(public array $session=[],private mixed $transport=null,private mixed $clock=null) {$this->deadline=$this->now()+300;}
    private function now(): float {return is_callable($this->clock)?($this->clock)():microtime(true);}
    private function call(string $method,string $path,?array $body=null,array $query=[],bool $pdf=false): mixed {
        if($this->now()>=$this->deadline||++$this->requests>200||$this->bytes>=67108864)throw new \RuntimeException('MES_BUDGET_EXCEEDED');
        $allowed=($method==='GET'&&in_array($path,['/config.json','/api/v8/accounts'],true))||($method==='GET'&&preg_match('~^/api/v7/accounts/\d{1,25}/(?:documents|details)$~D',$path))||($method==='POST'&&$path==='/api/v8/users/auth')||($method==='PUT'&&$path==='/api/v6/users/auth')||($method==='POST'&&preg_match('~^/api/v7/users/[a-f0-9-]{36}/email/check/(?:confirmation/send|verification)$~D',$path))||($method==='POST'&&preg_match('~^/api/v6/accounts/\d{1,25}/documents/download$~D',$path));if(!$allowed)throw new \InvalidArgumentException('MES_OPERATION_REJECTED');
        $h=['Accept: '.($pdf?'application/pdf':'application/json'),'customer: mes','VERSION: 3.17.0','Origin: https://lkkjr.mosenergosbyt.ru','Referer: https://lkkjr.mosenergosbyt.ru/auth/login'];if(isset($this->session['auth']))$h[]='Authorization: Bearer '.$this->session['auth'];if($path==='/api/v8/users/auth'){$h[]='Captcha: none';$h[]='withTotp: true';if(isset($this->session['verified']))$h[]='Auth-Verification: '.$this->session['verified'];}if($body!==null)$h[]='Content-Type: application/json';
        $rawBody=$body===null?null:($body===[]?'{}':Support::json($body));$r=is_callable($this->transport)?($this->transport)($method,$path,$body,$query,$h):HttpTransport::request($method,'https://lkkjr.mosenergosbyt.ru'.$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):''),$h,$rawBody,$pdf?16777216:8388608);
        if(!is_int($r['status']??null)||!is_string($r['raw']??null))throw new \RuntimeException('MES_RESPONSE_INVALID');$this->bytes+=strlen($r['raw']);if(strlen($r['raw'])>($pdf?16777216:8388608)||$this->bytes>67108864)throw new \RuntimeException('MES_BUDGET_EXCEEDED');$status=$r['status'];if(in_array($status,[401,403],true)){unset($this->session['auth'],$this->session['access'],$this->session['verified']);throw new \RuntimeException('AUTH_REQUIRED');}if($status>=300&&!($status===424&&$path==='/api/v8/users/auth'))throw new \RuntimeException('MES_HTTP_REJECTED');
        if(str_ends_with($path,'confirmation/send'))return [];
        if($pdf){if(!str_starts_with($r['raw'],'%PDF-'))throw new \RuntimeException('MES_FILE_FORMAT_CHANGED');return $r['raw'];}
        return ['status'=>$status,'data'=>Support::decode($r['raw'])];
    }
    private function consume(array $r): array {$d=$r['data'];if(!is_array($d))throw new \RuntimeException('INVALID_AUTH_RESPONSE');if($r['status']===424){$id=$d['transactionId']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)||!in_array('EMAIL',$d['types']??[],true))throw new \RuntimeException('MFA_FACTOR_UNSUPPORTED');$this->session=['transactionId'=>$id,'types'=>$d['types'],'otp_started_at'=>$this->now(),'otp_state'=>'not_requested'];return ['status'=>'awaiting_code','code'=>'OTP_REQUIRED'];}foreach(['auth','access'] as $k)if(!is_string($d[$k]??null)||$d[$k]===''||preg_match('/[\x00-\x1f\x7f]/',$d[$k]))throw new \RuntimeException('INVALID_AUTH_RESPONSE');$this->session=['auth'=>$d['auth'],'access'=>$d['access']]+(isset($d['verified'])?['verified'=>$d['verified']]:[]);return ['status'=>'success','code'=>'AUTHENTICATED'];}
    public function login(array $credentials): array {foreach(['username','password'] as $k)if(!is_string($credentials[$k]??null)||$credentials[$k]===''||preg_match('/[\x00-\x1f\x7f]/',$credentials[$k]))throw new \InvalidArgumentException('AUTH_REQUIRED');return $this->consume($this->call('POST','/api/v8/users/auth',['login'=>$credentials['username'],'password'=>$credentials['password'],'type'=>'EMAIL']));}
    public function refresh(): array {if(empty($this->session['auth'])||empty($this->session['access']))throw new \RuntimeException('AUTH_REQUIRED');return $this->consume($this->call('PUT','/api/v6/users/auth',['auth'=>$this->session['auth'],'access'=>$this->session['access']]));}
    private function otp(): string {if(empty($this->session['transactionId'])||!isset($this->session['otp_started_at'])||$this->now()-(float)$this->session['otp_started_at']>600)throw new \RuntimeException('OTP_EXPIRED_RELOGIN_REQUIRED');return $this->session['transactionId'];}
    public function requestCode(): array {$id=$this->otp();if(($this->session['otp_state']??'')!=='not_requested')throw new \RuntimeException('OTP_REQUEST_NOT_REPLAYABLE');$this->session['otp_state']='uncertain';$this->call('POST','/api/v7/users/'.$id.'/email/check/confirmation/send',[]);$this->session['otp_state']='awaiting_code';return ['status'=>'awaiting_code','code'=>'OTP_SENT'];}
    public function confirm(string $code): array {$id=$this->otp();if(!preg_match('/^\d{5}$/D',$code))throw new \InvalidArgumentException('INVALID_OTP');return $this->consume($this->call('POST','/api/v7/users/'.$id.'/email/check/verification',['code'=>$code]));}
    public function check(): array {if(empty($this->session['auth']))throw new \RuntimeException('AUTH_REQUIRED');$a=$this->accounts();return ['status'=>'success','code'=>'CHECKED_NO_SEND','accounts'=>count($a)];}
    private function bearerExpired(string $token): bool {
        // Expiry can only request a new sign-in. The provider still proves access to accounts.
        if (strlen($token)>16384||!preg_match('/^[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/D',$token,$match)) {
            return false;
        }
        $payload=base64_decode(strtr($match[1],'-_','+/'),true);
        $claims=is_string($payload)?json_decode($payload,true,16):null;
        return is_int($claims['exp']??null)&&$claims['exp']>0&&$claims['exp']<=$this->now();
    }

    public function accounts(): array {
        $auth=$this->session['auth']??null;
        if (!is_string($auth)||$auth===''||$this->bearerExpired($auth)) throw new \RuntimeException('AUTH_REQUIRED');
        $accounts=$this->call('GET','/api/v8/accounts')['data'];
        if(!is_array($accounts)||!array_is_list($accounts)||count($accounts)>100)throw new \RuntimeException('INVALID_ACCOUNT_LIST');
        $seen=[];
        foreach($accounts as $row){
            $id=(string)($row['id']??'');
            if(!preg_match('/^\d{1,25}$/D',$id)||isset($seen[$id]))throw new \RuntimeException('INVALID_ACCOUNT_LIST');
            $seen[$id]=1;
        }
        return $accounts;
    }
    public function documents(string $account,string $from,string $to): array {if(!preg_match('/^\d{1,25}$/D',$account))throw new \InvalidArgumentException('INVALID_ACCOUNT');$owned=array_map(static fn($a)=>(string)$a['id'],$this->accounts());if(!in_array($account,$owned,true))throw new \RuntimeException('ACCOUNT_NOT_AVAILABLE');$first=new \DateTimeImmutable(Support::day($from));$last=new \DateTimeImmutable(Support::day($to));if($last<$first||$first->diff($last)->days>3660)throw new \InvalidArgumentException('INVALID_PERIOD');$all=[];while($first<=$last){$end=min($last,$first->modify('+62 days'));$rows=$this->call('GET','/api/v7/accounts/'.$account.'/documents',null,['period'=>$first->format('d.m.Y').'|'.$end->format('d.m.Y')])['data'];if(!is_array($rows)||!array_is_list($rows)||count($all)+count($rows)>100000)throw new \RuntimeException('MES_SCHEMA_CHANGED');foreach($rows as $r)$all[]=['fields'=>['record'=>$r,'account'=>['id'=>$account]],'account_id'=>$account];$first=$end->modify('+1 day');}return $all;}
    public function pdf(string $account,array $record): string {
        if (!preg_match('/^\d{1,25}$/D',$account)||($record['canDownload']??false)!==true) {
            throw new \RuntimeException('FILE_UNAVAILABLE');
        }
        $value=$record['value']??[];
        foreach (['documentId','reportId','documentDate','documentType'] as $field) {
            if (!is_string($value[$field]??null)||$value[$field]==='') {
                throw new \RuntimeException('FILE_BINDING_FAILED');
            }
        }
        $number=$value['documentNum']??null;
        if (($value['system']??null)!=='BILLING'||!array_key_exists('documentNum',$value)
            || ($number!==null&&!is_string($number))) {
            throw new \RuntimeException('FILE_BINDING_FAILED');
        }
        $owned=array_map(static fn($item)=>(string)$item['id'],$this->accounts());
        if (!in_array($account,$owned,true)) throw new \RuntimeException('ACCOUNT_NOT_AVAILABLE');
        return $this->call('POST','/api/v6/accounts/'.$account.'/documents/download',['document'=>[
            'reportId'=>$value['reportId'],'docId'=>$value['documentId'],'documentDate'=>$value['documentDate'],
            'documentType'=>mb_strtolower($value['documentType'],'UTF-8'),'documentNumber'=>$number,
        ],'system'=>'BILLING'],[],true);
    }
}
