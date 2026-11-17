<?php
declare(strict_types=1);
// Private provision by stdin, never command-line secrets. This command performs no network operation.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
use Modules\Integrations\{Config,Support};
try{$raw=stream_get_contents(STDIN,65537);if(!is_string($raw)||strlen($raw)>65536)throw new RuntimeException('CONFIG_LIMIT');$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);unset($raw);if(!is_array($v)||($v['project_id']??'')!=='f6e21e3c-d974-4604-82fb-333da66559d2'||($v['version']??null)!==1)throw new RuntimeException('PROJECT_CONFIG_REQUIRED');$p=Support::provider((string)($v['provider']??''));$r=Config::reference((string)($v['reference']??''));$c=$v['credentials']??null;if(!is_array($c))throw new RuntimeException('CREDENTIALS_REQUIRED');
    if(in_array($p,['email','max'],true)){if(!is_string($c['secret']??null)||$c['secret']===''||strlen($c['secret'])>8192||preg_match('/[\x00-\x1f\x7f]/',$c['secret']))throw new RuntimeException('CREDENTIAL_SHAPE');Config::saveSecrets($p,$c);}
    else {foreach(['username','password'] as $k)if(!is_string($c[$k]??null)||$c[$k]===''||strlen($c[$k])>($k==='username'?320:1024)||preg_match('/[\x00-\x1f\x7f]/',$c[$k]))throw new RuntimeException('CREDENTIAL_SHAPE');if($p==='diadoc'&&!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$c['box_id']??''))throw new RuntimeException('BOX_REQUIRED');unset($c['session']);Config::saveProfile($p,$r,$c);}
    unset($c,$v);echo Support::json(['ok'=>true,'provider'=>$p,'reference'=>$r,'network_calls'=>0,'secret_values_disclosed'=>0])."\n";
}catch(Throwable){fwrite(STDERR,"Private config rejected; no secret values disclosed.\n");exit(1);}
