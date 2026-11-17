<?php
declare(strict_types=1);
namespace Modules\Integrations;

use phpseclib3\Net\SSH2;

/** Optional local DEV transport. No daemon, discovery, reconnect or DIRECT fallback. */
final class OnecDevSshTransport
{
    private const OWN='/srv/ip_ya/php-standalone/';
    private const SOURCE_SHA256='6fa728d6d1f2480065c8848e27d9b25129cbe9c743ed48678b245138e5650e00';
    private const NATIVE_SOURCE_SHA256='ea8caf81ca6f1b57b7122b3ec6bda3fd35fee3ee79827567f19208ea7614d65c';
    private const GENERATIONS=[
        self::SOURCE_SHA256=>'onec-dev-http-6fa728d6d1f24800',
        self::NATIVE_SOURCE_SHA256=>'onec-dev-native-ea8caf81ca6f1b57',
    ];
    private const EXTENSION_SHA256='9b93f78c3efa763c4e57079f21ec3003a40115112752885bd35ecb9b7f076db5';
    public function __construct(private array $config,private mixed $sessionFactory=null,private mixed $credentialLoader=null)
    {
        self::validateSettings($config);
    }

    public static function settings():?array
    {
        $file=APP_ROOT.'/.local/onec-dev-transport.json';
        if(!is_file($file))return null;
        if(is_link($file)||filesize($file)>65536)throw new \RuntimeException('ONEC_DEV_CONFIG_REJECTED');
        $config=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($config))throw new \RuntimeException('ONEC_DEV_CONFIG_REJECTED');
        if(($config['mode']??null)==='direct')return null;
        self::validateSettings($config);return $config;
    }

    public static function identity():array
    {
        $config=self::settings();
        return $config===null?['mode'=>'direct']:['mode'=>'dev_ssh','transport_id'=>$config['transport_id'],
            'config_sha256'=>hash('sha256',Support::canonical($config))];
    }

    public static function validateSettings(array $c):void
    {
        $allowed=['mode','project_id','transport_id','profile_reference','profile_revision','ssh_host','ssh_port',
            'ssh_user','ssh_host_key','credential_file','remote_argv','remote_source_sha256','remote_extension',
            'remote_extension_sha256','ca_file','sudo_stdin'];
        $keys=array_keys($c);sort($keys);sort($allowed);
        if($keys!==$allowed||$c['mode']!=='dev_ssh'||$c['project_id']!==OnecDevRequest::PROJECT
            ||$c['ssh_host']!=='89.208.213.14'||$c['ssh_port']!==22
            ||!is_int($c['profile_revision'])||$c['profile_revision']<1
            ||!is_string($c['transport_id'])||!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D',$c['transport_id'])
            ||!is_string($c['profile_reference'])||$c['profile_reference']===''
            ||$c['ssh_user']!=='user'
            ||!is_string($c['ssh_host_key'])||!preg_match('/^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/]+={0,2}$/D',$c['ssh_host_key'])
            ||$c['credential_file']!==str_replace('\\','/',APP_ROOT).'/.local/integration-source/onec-dev-ssh.credentials.json'||$c['sudo_stdin']!==true
            ||!is_string($c['remote_source_sha256'])||!isset(self::GENERATIONS[$c['remote_source_sha256']])
            ||$c['remote_extension']!==self::OWN.'runtime/php8.4/extensions/curl.so'
            ||$c['remote_extension_sha256']!==self::EXTENSION_SHA256
            ||$c['ca_file']!=='/etc/ssl/certs/ca-certificates.crt'
            ||!is_array($c['remote_argv'])||!array_is_list($c['remote_argv'])||count($c['remote_argv'])>32)throw new \RuntimeException('ONEC_DEV_CONFIG_REJECTED');
        // One reviewed invocation, not a configurable shell/command-token language.
        $expected=['/usr/bin/sudo','-k','-S','-p','IPPHP_SUDO_PASSWORD_REQUIRED','--',
            '/usr/bin/setpriv','--reuid=995','--regid=988','--clear-groups',
            '/usr/bin/env','-i','PATH=/usr/bin:/bin','LANG=C.UTF-8','HOME=/',
            '/usr/bin/php8.4','-n','-d','extension='.self::OWN.'runtime/php8.4/extensions/curl.so',
            '-d','zend.exception_ignore_args=1','-d','display_errors=0','-d','log_errors=0',
            self::OWN.'transport/'.self::GENERATIONS[$c['remote_source_sha256']].'/onec-dev-http.php'];
        if($c['remote_argv']!==$expected)throw new \RuntimeException('ONEC_DEV_COMMAND_REJECTED');
    }

    public function assertProfile(array $profile):void
    {
        if(($profile['_reference']??null)!==$this->config['profile_reference']
            ||($profile['_owner_bound_revision']??null)!==$this->config['profile_revision'])throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
    }


    private function credentials():array
    {
        if(is_callable($this->credentialLoader))return ($this->credentialLoader)();
        $file=$this->config['credential_file'];
        if(!is_file($file)||is_link($file)||filesize($file)>65536)throw new \RuntimeException('ONEC_DEV_CARRIER_UNAVAILABLE');
        $c=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($c)||($c['username']??null)!==$this->config['ssh_user'])throw new \RuntimeException('ONEC_DEV_CARRIER_REJECTED');
        return $c;
    }

    private static function word(string $value):string{return "'".str_replace("'","'\\''",$value)."'";}

    public function exchange(array $request,int $overheadMs=30000):array
    {
        $r=OnecDevRequest::validate($request); // Before creating even a mock SSH session.
        if($r['purpose']==='native'&&$this->config['remote_source_sha256']!==self::NATIVE_SOURCE_SHA256){
            throw new \RuntimeException('ONEC_DEV_NATIVE_GENERATION_REQUIRED');
        }
        if(!Config::liveAllowed())throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        if(defined('NET_SSH2_LOGGING')||defined('NET_SFTP_LOGGING'))throw new \RuntimeException('ONEC_DEV_LOGGING_FORBIDDEN');
        $clock=hrtime(true);$ssh=null;$requestSent=false;
        $outerMs=min(90000,$r['timeout_ms']+max(0,min(30000,$overheadMs)));
        $deadline=$GLOBALS['ONLINE_DEADLINE']??null;
        if(is_float($deadline)&&is_finite($deadline))$outerMs=min($outerMs,(int)floor(($deadline-hrtime(true)/1e9)*1000));
        try{
            $ssh=is_callable($this->sessionFactory)?($this->sessionFactory)():new SSH2($this->config['ssh_host'],22,min(10,$r['timeout_ms']/1000));
            if(!hash_equals($this->config['ssh_host_key'],(string)$ssh->getServerPublicHostKey()))throw new \RuntimeException('ONEC_DEV_HOST_KEY_REJECTED');
            $credential=$this->credentials();
            $auth=$credential['password']??null;
            if(isset($credential['private_key']))$auth=\phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($credential['private_key'],$credential['passphrase']??false);
            if($auth===null||!$ssh->login($this->config['ssh_user'],$auth))throw new \RuntimeException('ONEC_DEV_CARRIER_REJECTED');
            $left=$outerMs-(int)ceil((hrtime(true)-$clock)/1e6);
            if($left<1)throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
            $ssh->disablePTY();$ssh->disableQuietMode();$ssh->setTimeout(min(10,$left/1000));
            $command=implode(' ',array_map(self::word(...),$this->config['remote_argv']));
            if($ssh->exec($command,false)!==true)throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
            $ready='';$passwordSent=false;$readyDeadline=hrtime(true)/1e9+10;
            while(!str_contains($ready,"\n")){
                $readyLeft=min($readyDeadline-hrtime(true)/1e9,($outerMs-(hrtime(true)-$clock)/1e6)/1000);
                if($readyLeft<=0)throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
                $ssh->setTimeout($readyLeft);
                $part=$ssh->read('',SSH2::READ_NEXT,SSH2::CHANNEL_EXEC);
                if($ssh->isTimeout()||!is_string($part)||$part==='')throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
                $ready.=$part;
                if(strlen($ready)>8192)throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
                if(str_contains($ready,'IPPHP_SUDO_PASSWORD_REQUIRED')){
                    if(!$this->config['sudo_stdin']||$passwordSent||!is_string($credential['sudo_password']??null)
                        ||preg_match('/[\x00-\x1f\x7f]/',$credential['sudo_password']))throw new \RuntimeException('ONEC_DEV_SUDO_CARRIER_REQUIRED');
                    // SSH2::write is void; a failed or incomplete write throws.
                    $ssh->write($credential['sudo_password']."\n",SSH2::CHANNEL_EXEC);
                    $passwordSent=true;$ready=str_replace('IPPHP_SUDO_PASSWORD_REQUIRED','',$ready);
                }
            }
            self::ready($ready,$this->config); // No HTTP credentials/config have been sent yet.
            $left=$outerMs-(int)ceil((hrtime(true)-$clock)/1e6);
            if($left<1)throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
            $r['timeout_ms']=min($r['timeout_ms'],$left);$r['connect_timeout_ms']=min(10000,$r['timeout_ms']);
            $frame=['request'=>$r,'runtime'=>['extension'=>$this->config['remote_extension'],
                'extension_sha256'=>$this->config['remote_extension_sha256'],'ca_file'=>$this->config['ca_file']]];
            $input=Support::json($frame)."\n";
            if(strlen($input)>($r['purpose']==='native'?327680:131072))throw new \RuntimeException('ONEC_DEV_REQUEST_REJECTED');
            $ssh->enableQuietMode();$ssh->setTimeout($left/1000);
            $requestSent=true; // A partial write can already have reached a native POST.
            $ssh->write($input,SSH2::CHANNEL_EXEC);
            unset($credential,$auth,$input);
            $output='';$frameCap=(int)ceil($r['max_bytes']/3)*4+8192;
            while(true){
                $left=$outerMs-(int)ceil((hrtime(true)-$clock)/1e6);
                if($left<1)throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
                $ssh->setTimeout($left/1000);$part=$ssh->read('',SSH2::READ_NEXT,SSH2::CHANNEL_EXEC);
                if($ssh->isTimeout())throw new \RuntimeException('ONEC_DEV_TRANSPORT_UNKNOWN');
                if(!is_string($part)||$part==='')break;
                if(strlen($output)+strlen($part)>$frameCap)throw new \RuntimeException('ONEC_DEV_RESPONSE_TOO_LARGE');
                $output.=$part;
            }
            $stderr=$ssh->getStdError();
            if(($stderr!==''&&$stderr!==($passwordSent?'IPPHP_SUDO_PASSWORD_REQUIRED':''))||$ssh->getExitStatus()!==0)throw new \RuntimeException('ONEC_DEV_REMOTE_FAILED');
            return self::decode($output,$r,$this->config);
        }catch(\Throwable $error){
            $code=$error->getMessage();
            if($requestSent)$code='ONEC_DEV_TRANSPORT_UNKNOWN';
            if(!in_array($code,['ONEC_DEV_HOST_KEY_REJECTED','ONEC_DEV_CARRIER_REJECTED','ONEC_DEV_CARRIER_UNAVAILABLE',
                'ONEC_DEV_SUDO_CARRIER_REQUIRED','ONEC_DEV_REQUEST_REJECTED','ONEC_DEV_RESPONSE_TOO_LARGE','ONEC_DEV_RESPONSE_UNVERIFIED'],true))$code='ONEC_DEV_TRANSPORT_UNKNOWN';
            throw new \RuntimeException($code);
        }finally{if($ssh!==null)$ssh->disconnect();}
    }

    public static function ready(string $line,array $config):void
    {
        if(!str_ends_with($line,"\n")||substr_count($line,"\n")!==1)throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        $ready=json_decode($line,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($ready)||($ready['type']??null)!=='ready'||($ready['project_id']??null)!==OnecDevRequest::PROJECT
            ||($ready['source_sha256']??null)!==$config['remote_source_sha256']||($ready['uid']??null)!==995||($ready['gid']??null)!==988
            ||($ready['extension_sha256']??null)!==$config['remote_extension_sha256'])throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
    }

    public static function decode(string $output,array $request,array $config):array
    {
        if(!str_ends_with($output,"\n")||substr_count($output,"\n")!==1)throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        $frame=json_decode($output,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($frame)||($frame['ok']??null)!==true||($frame['source_sha256']??null)!==$config['remote_source_sha256']
            ||($frame['uid']??null)!==995||($frame['gid']??null)!==988
            ||($frame['extension_sha256']??null)!==$config['remote_extension_sha256'])throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        $r=$frame['response']??null;
        if(!is_array($r)||($r['protocol']??null)!=='ipphp-onec-dev-http-v1'||($r['project_id']??null)!==OnecDevRequest::PROJECT
            ||($r['request_id']??null)!==$request['request_id']||!is_string($r['body_base64']??null))throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        $body=base64_decode($r['body_base64'],true);
        if(!is_string($body)||($r['bytes']??null)!==strlen($body))throw new \RuntimeException('ONEC_DEV_RESPONSE_UNVERIFIED');
        OnecDevHttp::response($request,$r+['body'=>$body]);
        return ['status'=>$r['status'],'body'=>$body,'dev_transport'=>['mode'=>'dev_ssh','http_status'=>$r['status'],
            'curl_errno'=>0,'ssl_verify_result'=>0,'primary_ip_verified'=>true,'duration_ms'=>$r['duration_ms'],'bytes'=>strlen($body)]];
    }
}
