<?php
declare(strict_types=1);
namespace NativeClients;
require_once __DIR__.'/Telemetry.php';
require_once __DIR__.'/mosvodokanal/Api.php';
require_once __DIR__.'/mosvodokanal/Exporter.php';
require_once __DIR__.'/pik/Client.php';
require_once __DIR__.'/diadoc/Http.php';
require_once __DIR__.'/diadoc/WebClient.php';
require_once __DIR__.'/diadoc/Export.php';
require_once __DIR__.'/onec/ReadOnly.php';

/** No hub, DPAPI, environment credentials, mailbox or legacy state discovery. */
final class Portal
{
    public static function state(string $path):string
    {
        $real=realpath($path);
        if(!$real||$real!==rtrim($path,'/\\')||!is_dir($real))throw new \RuntimeException('FRESH_PRIVATE_STATE_REQUIRED');
        for($p=$real;;$p=dirname($p)){
            if(is_link($p))throw new \RuntimeException('FRESH_PRIVATE_STATE_REQUIRED');
            if(dirname($p)===$p)break;
        }
        $private=realpath(dirname(__DIR__,4).'/private');
        if(!$private||!str_starts_with(str_replace('\\','/',$real).'/',str_replace('\\','/',$private).'/'))throw new \RuntimeException('FRESH_PRIVATE_STATE_REQUIRED');
        if(PHP_OS_FAMILY!=='Windows'&&((fileperms($real)&0077)!==0||fileowner($real)!==posix_geteuid()))throw new \RuntimeException('PRIVATE_OWNER_MODE_REQUIRED');
        return $real;
    }
    private static function credentials(array $input):array
    {
        $c=$input['credentials']??[];
        if(!is_array($c)||array_diff(array_keys($c),['username','password'])||!is_string($c['username']??null)||!is_string($c['password']??null)||$c['username']===''||$c['password']===''||strlen($c['username'])>320||strlen($c['password'])>1024||preg_match('/[\x00-\x1f\x7f]/',$c['username'].$c['password']))throw new \RuntimeException('AUTH_REQUIRED');
        return [$c['username'],$c['password']];
    }
    public static function run(array $input,array $factories=[]):array
    {
        $auth = in_array($input['action'] ?? '', ['login', 'confirm', 'connection'], true);
        $present = array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL', $GLOBALS);
        $previous = $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] ?? null;
        if ($auth) $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = true;
        try {
            return self::runInternal($input, $factories);
        } finally {
            if ($auth) {
                if ($present) $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = $previous;
                else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);
            }
        }
    }
    private static function runInternal(array $input,array $factories=[]):array
    {
        umask(0077);
        $GLOBALS['ONLINE_DEADLINE']=hrtime(true)/1e9+300;$GLOBALS['ONLINE_REQUESTS']=[];
        if(array_diff(array_keys($input),['provider','action','config','credentials','session','params']))throw new \RuntimeException('INPUT_REJECTED');
        $provider=$input['provider']??'';$action=$input['action']??'';$config=$input['config']??[];$params=$input['params']??[];
        if(!in_array($provider,['onec','diadoc','mosvodokanal','pik'],true)||!is_array($params)||!is_array($config)||array_diff(array_keys($config),['state_dir','ca_file','box_id'])||!is_string($config['state_dir']??null))throw new \RuntimeException('INPUT_REJECTED');
        $state=self::state($config['state_dir']);$ca=$config['ca_file']??null;
        foreach(['cookies.txt','pending.json','run.lock']as$name){$p=$state.'/'.$name;
            if(is_link($p)||file_exists($p)&&(!is_file($p)||PHP_OS_FAMILY!=='Windows'&&((fileperms($p)&0077)!==0||fileowner($p)!==posix_geteuid())))throw new \RuntimeException('PRIVATE_OWNER_MODE_REQUIRED');}
        if($ca!==null&&(!is_string($ca)||realpath($ca)!==$ca||!is_file($ca)||!is_readable($ca)))throw new \RuntimeException('CA_FILE_UNAVAILABLE');
        $actions=['onec'=>['connection','metadata','list'],'diadoc'=>['login','confirm','connection','list','original'],
                  'mosvodokanal'=>['login','connection','accounts','definition','page'],
                  'pik'=>['login','connection','accounts','page','pdf']];
        if(!in_array($action,$actions[$provider],true))throw new \RuntimeException('READ_ACTION_REJECTED');
        $allowedParams=match($provider){
            'onec'=>$action==='list'?['kind','limit','skip']:[],
            'diadoc'=>$action==='original'?['document']:($action==='list'?['limit','from']:[]),
            'mosvodokanal'=>$action==='page'?['report','scope','start','limit']:($action==='definition'?['report']:['limit']),
            default=>$action==='page'?['kind','account_id','page','limit']:($action==='pdf'?['account_id','receipt']:['limit'])};
        if(array_diff(array_keys($params),$allowedParams))throw new \RuntimeException('INPUT_REJECTED');
        $limit=$params['limit']??10;if(!is_int($limit)||$limit<1||$limit>10)throw new \RuntimeException('LIMIT_REJECTED');
        $data=[];$session=$input['session']??[];if(!is_array($session))throw new \RuntimeException('SESSION_REJECTED');
        if($provider==='onec'){
            [$u,$p]=self::credentials($input);$c=isset($factories['onec'])?($factories['onec'])($u,$p,$ca):new \NativeOnec\Reader($u,$p,$ca);
            if($action==='connection')$data=['collections'=>$c->service()];
            elseif($action==='metadata')$data=['entities'=>$c->metadata()];
            else $data=$c->rows($params['kind']??'invoices',$limit,$params['skip']??0);
            return ['report'=>['ok'=>true,'requests'=>$c->requests,'write_operations'=>0],'data'=>$data,'session'=>[]];
        }
        if($provider==='diadoc'){$GLOBALS['NATIVE_DIADOC_REQUESTS']=[];$r=self::diadoc($input,$state,$ca,$limit,$factories['diadoc']??null);$r['report']['requests']=$GLOBALS['NATIVE_DIADOC_REQUESTS'];return $r;}
        if($provider==='mosvodokanal'){
            $raw=$state.'/raw';if(!is_dir($raw)&&!mkdir($raw,0700))throw new \RuntimeException('PRIVATE_STATE_FAILED');
            $c=isset($factories['mosvodokanal'])?($factories['mosvodokanal'])():new \Mosvodokanal\Api($raw,30,150,$ca);
            if($action==='login'){[$u,$p]=self::credentials($input);$c->login($u,$p);}
            else {if(!$session)throw new \RuntimeException('AUTH_REQUIRED');$c->importSession($session);}
            if(in_array($action,['login','connection','accounts'],true))$data=$c->page('NATURAL_PERSON_USER_ACCOUNT_REGISTER',[],0,$limit);
            elseif($action==='definition')$data=$c->definition($params['report']??'');
            else{
                $scope=$params['scope']??[];if(!is_array($scope)||!$scope)throw new \RuntimeException('ACCOUNT_SCOPE_REQUIRED');
                $start=$params['start']??0;if(!is_int($start)||$start<0||$start>10000)throw new \RuntimeException('PAGE_REJECTED');
                $data=$c->page($params['report']??'',$scope,$start,$limit);
            }
            if($action!=='definition'&&(!is_array($data['list']??null)||count($data['list'])>$limit))throw new \RuntimeException('SOURCE_LIMIT_NOT_ENFORCED');
            return ['report'=>['ok'=>true,'scope_limited'=>true,'write_operations'=>0,'requests'=>$GLOBALS['ONLINE_REQUESTS']],'data'=>$data,'session'=>$c->session()];
        }
        $c=isset($factories['pik'])?($factories['pik'])():new \PikComfort\Client($session['token']??null,.25,30,null,$ca);
        if($action==='login'){[$u,$p]=self::credentials($input);$c->login($u,$p);}
        elseif(!is_string($session['token']??null)||$session['token']==='')throw new \RuntimeException('AUTH_REQUIRED');
        if(in_array($action,['login','connection','accounts'],true)){
            $raw=$c->json(\PikComfort\ENDPOINTS['accounts'],['notifications_size'=>0]);
            if(!is_array($raw['accounts']??null))throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
            $data=['rows'=>array_slice($raw['accounts'],0,$limit),'received_count'=>count($raw['accounts']),'network_row_limit_enforced'=>false];
        }elseif($action==='page'){
            $account=\PikComfort\id($params['account_id']??null);$kind=$params['kind']??'';$page=$params['page']??1;
            if(!in_array($kind,['receipts','payments'],true)||!is_int($page)||$page<1||$page>10000)throw new \RuntimeException('PAGE_REJECTED');
            $data=$c->json(\PikComfort\ENDPOINTS[$kind],['account_id'=>$account,'page'=>$page,'page_size'=>$limit],$account);
            if(!is_array($data['results']??null)||count($data['results'])>$limit||($data['page']??null)!==$page)throw new \RuntimeException('SOURCE_LIMIT_NOT_ENFORCED');
        }else{
            $account=\PikComfort\id($params['account_id']??null);$receipt=$params['receipt']??null;
            if(!is_array($receipt))throw new \RuntimeException('RECEIPT_REQUIRED');
            $data=\PikComfort\receiptPdf($c,$account,$receipt,$state.'/pdf',30);
        }
        return ['report'=>['ok'=>true,'scope_limited'=>true,'write_operations'=>0,'requests'=>$GLOBALS['ONLINE_REQUESTS']],'data'=>$data,'session'=>$c->session()];
    }
    private static function diadoc(array $input,string $state,?string $ca,int $limit,?callable $factory):array
    {
        $config=$input['config'];$params=$input['params']??[];$action=$input['action'];$http=null;
        $lock=fopen($state.'/run.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('STATE_BUSY');
        try{
            if($factory)$c=$factory();else{$observer=static function(array $r):void{if(is_int($r['status']??null))$GLOBALS['NATIVE_DIADOC_REQUESTS'][]=$r;\OnlineUtilities\send('event',['event'=>$r]);};$http=new \Diadoc\Http($state,$ca,$observer,hrtime(true)/1e9+300);$c=new \Diadoc\WebClient($http);}
            $pending=$state.'/pending.json';
            if(in_array($action,['login','confirm'],true)){
                if($action==='login'){[$u,$p]=self::credentials($input);$auth=$c->login($u,$p);}
                else{
                    if(!is_file($pending)||is_link($pending)||filesize($pending)>65536)throw new \RuntimeException('NO_PENDING_CONFIRMATION');
                    if(PHP_OS_FAMILY!=='Windows'&&(fileperms($pending)&0077))throw new \RuntimeException('PRIVATE_OWNER_MODE_REQUIRED');
                    $auth=$c->confirm(json_decode(file_get_contents($pending),true,32,JSON_THROW_ON_ERROR));
                }
                if(($auth['state']??'')==='awaiting_confirmation'){\Diadoc\Export::writeJson($pending,$auth);return ['report'=>['ok'=>false,'result'=>'MFA_REQUIRED','auth_state'=>'awaiting_confirmation'],'data'=>[],'session'=>[]];}
                if(is_file($pending))unlink($pending);
                return ['report'=>['ok'=>true,'auth_state'=>'authenticated','write_operations'=>0],'data'=>[],'session'=>[]];
            }
            if($action==='original'){
                $document=$params['document']??null;if(!is_array($document))throw new \RuntimeException('DOCUMENT_REQUIRED');
                $raw=$c->original($config['box_id']??'',$document);$file=$state.'/original-'.bin2hex(random_bytes(8)).'.bin';
                if(file_put_contents($file,$raw['body'])!==strlen($raw['body']))throw new \RuntimeException('OUTPUT_FAILED');
                return ['report'=>['ok'=>true,'write_operations'=>0],'data'=>['private_file'=>$file,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)],'session'=>[]];
            }
            $page=$c->page($config['box_id']??'','Any',$action==='connection'?1:$limit,$params['from']??null);$rows=\Diadoc\Export::rows($page);
            return ['report'=>['ok'=>true,'write_operations'=>0,'scope_limited'=>count($rows)>$limit||!empty($page['paging']['next'])],
                    'data'=>['rows'=>array_slice($rows,0,$limit),'received_count'=>count($rows),'next_cursor'=>$page['paging']['next']??null,
                    'network_row_limit_enforced'=>count($rows)<=$limit,'reported_total'=>$page['totalCounters']['all']??null], 'session'=>[]];
        }finally{$http?->close();flock($lock,LOCK_UN);fclose($lock);}
    }
}
