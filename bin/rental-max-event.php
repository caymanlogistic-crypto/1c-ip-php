<?php
declare(strict_types=1);
// Explicit bounded drain of ONE new event. This is never invoked by the general worker or web UI.
ini_set('zend.exception_ignore_args','1');
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$options=getopt('',['event:','group:','max-revision:','catalog-version:','config:','config-sha256:','expected-plan-sha256:','apply']);
$stage='cli_preflight';
try{
    $allowed=['event','group','max-revision','catalog-version','config','config-sha256','expected-plan-sha256','apply'];
    if(array_diff(array_keys($options),$allowed))throw new RuntimeException('RENTAL_MAX_OPTIONS_INVALID');
    foreach(['event','max-revision','catalog-version'] as $key){
        if(!is_string($options[$key]??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$options[$key]))throw new RuntimeException('RENTAL_MAX_SELECTOR_REQUIRED');
    }
    $config=$options['config']??null;$configSha=$options['config-sha256']??null;
    if(!is_string($config)||!is_string($configSha)||!preg_match('/^[a-f0-9]{64}$/D',$configSha)
        ||getenv('APP_DB_CONFIG_FILE')!==$config||!is_file($config)||is_link($config)
        ||!hash_equals($configSha,hash_file('sha256',$config)))throw new RuntimeException('RENTAL_MAX_CONFIG_PIN_REQUIRED');
    $actual=realpath($config);$private=realpath(dirname(__DIR__).'/.local');
    if(!$actual||!$private||dirname($actual)!==$private||realpath(dirname($config))!==$private)throw new RuntimeException('RENTAL_MAX_PROJECT_CONFIG_REQUIRED');
    if(strcasecmp(str_replace('\\','/',$actual),str_replace('\\','/',$config))!==0)throw new RuntimeException('RENTAL_MAX_CANONICAL_CONFIG_REQUIRED');
    for($parent=dirname($config);strlen($parent)>3;$parent=dirname($parent))
        if(is_link($parent))throw new RuntimeException('RENTAL_MAX_LINKED_CONFIG_PARENT');
    $settings=require $config;
    if(is_array($settings['database']??null))$settings=$settings['database'];
    if(!is_array($settings))throw new RuntimeException('RENTAL_MAX_CONFIG_SHAPE_INVALID');
    if(!is_string($settings['username']??$settings['user']??null)||($settings['username']??$settings['user'])===''
        ||!array_key_exists('password',$settings)||!is_string($settings['password']))throw new RuntimeException('RENTAL_MAX_EXPLICIT_CREDENTIAL_CONFIG_REQUIRED');
    if(isset($settings['dsn'])){
        $parts=[];
        if(!is_string($settings['dsn'])||!str_starts_with($settings['dsn'],'mysql:'))throw new RuntimeException('RENTAL_MAX_LOCAL_R2_ONLY');
        foreach(explode(';',substr($settings['dsn'],6)) as $field){
            if($field==='')continue;
            $pair=explode('=',$field,2);
            if(count($pair)!==2||isset($parts[$pair[0]])||!in_array($pair[0],['host','port','dbname','charset'],true))throw new RuntimeException('RENTAL_MAX_LOCAL_R2_ONLY');
            $parts[$pair[0]]=$pair[1];
        }
    }else{
        if(getenv('DB_DSN')!==false)throw new RuntimeException('RENTAL_MAX_INHERITED_DSN_DENIED');
        $parts=['host'=>$settings['host']??null,'port'=>(string)($settings['port']??''),
            'dbname'=>$settings['name']??$settings['database']??$settings['dbname']??null];
    }
    if(($parts['host']??null)!=='127.0.0.1'||($parts['port']??null)!=='43061'
        ||($parts['dbname']??null)!=='ip_ya_php_scratch_core_f6e21e3c_d6dc6bc7_r2')throw new RuntimeException('RENTAL_MAX_LOCAL_R2_ONLY');
    if(isset($options['apply'])&&(!is_string($options['expected-plan-sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$options['expected-plan-sha256'])))
        throw new RuntimeException('RENTAL_MAX_PLAN_PIN_REQUIRED');
    define('APP_ROOT',dirname(__DIR__));
    date_default_timezone_set('Europe/Moscow');
    if(is_file(APP_ROOT.'/vendor/autoload.php'))require APP_ROOT.'/vendor/autoload.php';
    spl_autoload_register(static function(string $class):void{
        foreach(['App\\'=>'app/','Modules\\Rental\\'=>'modules/rental/','Modules\\Integrations\\'=>'modules/integrations/'] as $prefix=>$directory){
            if(str_starts_with($class,$prefix)){require APP_ROOT.'/'.$directory.substr($class,strlen($prefix)).'.php';return;}
        }
    });
    $stage='entrypoint_ca';
    // Same approved project trust initialization as app/bootstrap.php; this process only.
    if(!getenv('APP_INTEGRATION_CAFILE')&&($projectCa=App\Runtime::localCaFile())){
        putenv('APP_INTEGRATION_CAFILE='.$projectCa);
    }
    $stage='plan';
    $db=App\DB::pdo();
    if((int)$db->query('SELECT @@port')->fetchColumn()!==43061
        ||$db->query('SELECT DATABASE()')->fetchColumn()!=='ip_ya_php_scratch_core_f6e21e3c_d6dc6bc7_r2')
        throw new RuntimeException('RENTAL_MAX_LOCAL_R2_ONLY');
    $bridge=new Modules\Integrations\WorkflowMaxEvent($db);
    $plan=$bridge->plan((int)$options['event'],(string)($options['group']??''),(int)$options['max-revision'],(int)$options['catalog-version']);
    $output=['mode'=>isset($options['apply'])?'apply':'plan','event_id'=>$plan['event']['event_id'],
        'event_kind'=>$plan['event']['event_kind'],'actor_id'=>$plan['event']['actor_id'],
        'catalog_key'=>$plan['catalog']['binding']['source_key'],'catalog_version'=>$plan['catalog']['binding']['version'],
        'max_revision'=>$plan['revision'],'target_name'=>'ТЕСТОВАЯ','attachments'=>0,
        'plan_sha256'=>$plan['sha256'],'message_sha256'=>hash('sha256',$plan['catalog']['body']),
        'historical_scan'=>false,'automatic_retry'=>false,'delivered_or_human_read_proven'=>false];
    if(isset($options['apply'])){
        $stage='dispatch';
        $attempts=$bridge->dispatch($plan,$options['expected-plan-sha256']);
        $output['attempts']=array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['id','status','result_code','revision'])),$attempts);
    }
    echo json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    $code=$error->getMessage();
    if(!preg_match('/^[A-Z][A-Z0-9_]{1,79}$/D',$code))$code='RENTAL_MAX_LOCAL_REFUSAL';
    fwrite(STDERR,json_encode(['status'=>'refused','code'=>$code,'stage'=>$stage,
        'exception_class'=>in_array(get_class($error),['RuntimeException','InvalidArgumentException','PDOException','JsonException','TypeError','Error','Exception'],true)?get_class($error):'Throwable',
        'exception_code'=>is_int($error->getCode())?$error->getCode():null],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
}
