<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function serverDiagnostics(): array {
    $stand=getenv('APP_STAND');
    if(!in_array($stand,['ui','integrations'],true))throw new RuntimeException('SERVER_STAND_REQUIRED');
    foreach(['pdo_mysql','bcmath','mbstring','dom','xml','zip','openssl','curl','fileinfo','intl','gd'] as $e)
        if(!extension_loaded($e))throw new RuntimeException('MISSING_EXTENSION_'.$e);
    $s=json_decode(file_get_contents(APP_ROOT.'/.local/server-settings.json'),true,512,JSON_THROW_ON_ERROR);
    if($s['project_id']!=='f6e21e3c-d974-4604-82fb-333da66559d2'||$s['app_root']!==APP_ROOT
        ||$s['stand']!==$stand||$s['external_access']!==false||$s['automated_sends']!==false)
        throw new RuntimeException('SERVER_SETTINGS_BINDING');
    if(Modules\Integrations\Config::liveAllowed()||getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')
        ||getenv('APP_BILLING_PART_ADMISSION_SHA256'))throw new RuntimeException('EXTERNAL_ADMISSION_UNEXPECTED');
    $transportFile=APP_ROOT.'/.local/onec-dev-transport.json';
    if(!is_file($transportFile)||json_decode(file_get_contents($transportFile),true)['mode']!=='direct')
        throw new RuntimeException('ONEC_EXPLICIT_TRANSPORT_REQUIRED');
    $p=App\DB::pdo();$database=$p->query('SELECT DATABASE()')->fetchColumn();
    $onecRead=Modules\Integrations\OnecReadAdmission::settings();
    $email=Modules\Integrations\EmailAdmission::settings();
    if($email!==null&&!Modules\Integrations\EmailAdmission::allows(Modules\Integrations\Support::decode(Modules\Integrations\Config::get($p,'email')['config_json'])))throw new RuntimeException('EMAIL_PROFILE_BINDING');
    if($onecRead!==null&&!Modules\Integrations\OnecReadAdmission::allows($p,'onec','check'))throw new RuntimeException('ONEC_READ_PROFILE_BINDING');
    if($database!==$s['database']||$p->query('SELECT CURRENT_USER()')->fetchColumn()!==$s['db_user'].'@%')
        throw new RuntimeException('SERVER_DB_BINDING');
    foreach(['schema_migrations','users','roles','role_profiles','role_permissions','role_page_grants','contracts','parties','rental_objects'] as $t)
        $p->query('SELECT 1 FROM `'.$t.'` LIMIT 1');
    $release=json_decode(file_get_contents(APP_ROOT.'/release.json'),true,512,JSON_THROW_ON_ERROR);
    $deployment=json_decode(file_get_contents(APP_ROOT.'/deployment.json'),true,512,JSON_THROW_ON_ERROR);
    if($deployment['commit']!==$release['commit']||$deployment['stand']!==$stand)throw new RuntimeException('DEPLOYMENT_VERSION_BINDING');
    return ['stand'=>$stand,'commit'=>$release['commit'],'image_id'=>$deployment['image_id'],
        'deployed_at'=>$deployment['deployed_at'],'database'=>$database,'database_ready'=>true,
        'project_id'=>$s['project_id'],'external_access'=>false,'automated_sends'=>false,
        'workers'=>'not configured','inherited_queue_replay'=>'not configured',
        'manual_email'=>$email!==null,
        'object_activity_max'=>\Modules\Integrations\ServerObjectMax::settings()!==null,
        'onec_read_check'=>$onecRead!==null,'onec_transport'=>$onecRead!==null?'direct (bounded OneC check only)':'direct (external gate closed)','php'=>PHP_VERSION];
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    try{$d=serverDiagnostics();echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
    catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
}

