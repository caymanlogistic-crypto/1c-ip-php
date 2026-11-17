<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $s=json_decode(file_get_contents(APP_ROOT.'/.local/runtime-settings.json'),true,512,JSON_THROW_ON_ERROR);
    // The preserved T001 DB is allowed only in its original checkout, never another worktree.
    $legacy=$s['database']==='ip_ya_t001_20261117'&&$s['database_user']==='ipya_t001_20261117'
        &&is_dir(APP_ROOT.'/.git');
    $task=isset($s['database'],$s['database_user'])&&preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$s['database'])&&$s['database_user']===$s['database'];
    if($s['project_id']!=='f6e21e3c-d974-4604-82fb-333da66559d2'
        ||strtolower(str_replace('\\','/',$s['app_root']))!==strtolower(str_replace('\\','/',APP_ROOT))
        ||(!$legacy&&!$task)
        ||$s['max_enabled']!==false||$s['billing_part_admission_sha256']!==null
        ||getenv('IPPHP_ALLOW_EXTERNAL')||getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'))throw new RuntimeException('T001_BINDING_OR_EXTERNAL_MODE');
    foreach(['pdo_mysql','bcmath','mbstring','dom','xml','zip','openssl','curl','fileinfo','intl'] as $e)
        if(!extension_loaded($e))throw new RuntimeException('MISSING_EXTENSION_'.$e);
    $p=App\DB::pdo();
    if($p->query('SELECT DATABASE()')->fetchColumn()!==$s['database']
        ||$p->query('SELECT CURRENT_USER()')->fetchColumn()!==$s['database_user'].'@127.0.0.1')throw new RuntimeException('T001_DB_BINDING');
    $grants=$p->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    if(count($grants)!==2||!str_contains($grants[0],'GRANT USAGE ON *.*')
        ||!str_contains($grants[1],'ON `'.$s['database'].'`.*'))throw new RuntimeException('T001_DB_GRANTS');
    $v=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
    if($v['database']!==$s['database']||$v['exact_copy_before_login']!==true
        ||($task&&(($v['isolated_test_data']??false)!==true||strtolower(str_replace('\\','/',$v['app_root']??''))!==strtolower(str_replace('\\','/',APP_ROOT)))))throw new RuntimeException('T001_COPY_NOT_VERIFIED');
    foreach(['schema_migrations','users','roles','role_profiles','role_permissions','role_page_grants'] as $t)
        $p->query('SELECT 1 FROM `'.$t.'` LIMIT 1');
    echo "Local worktree binding/extensions/DB grants OK; external access and AutoMAX disabled\n";
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage()."\n");exit(1);}
