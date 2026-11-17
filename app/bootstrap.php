<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
ini_set('display_errors','0');
ini_set('log_errors','0');
define('APP_ROOT', dirname(__DIR__));
if(is_file(APP_ROOT.'/vendor/autoload.php')) require APP_ROOT.'/vendor/autoload.php';
date_default_timezone_set('Europe/Moscow');
spl_autoload_register(static function(string $class):void {
    foreach (['App\\'=>APP_ROOT.'/app/','Modules\\Rental\\'=>APP_ROOT.'/modules/rental/','Modules\\Integrations\\'=>APP_ROOT.'/modules/integrations/','Modules\\Auxiliary\\'=>APP_ROOT.'/modules/auxiliary/'] as $prefix=>$path) {
        if (str_starts_with($class,$prefix)) { $file=$path.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if(is_file($file)) require $file; return; }
    }
});
foreach(['runtime','runtime/sessions','runtime/logs','private','private/imports','uploads'] as $dir) if(!is_dir(APP_ROOT.'/'.$dir)) mkdir(APP_ROOT.'/'.$dir,0700,true);
// Project-local trust only; explicit operator overrides and Linux system trust remain intact.
if (!getenv('APP_INTEGRATION_CAFILE') && ($projectCa = \App\Runtime::localCaFile())) {
    putenv('APP_INTEGRATION_CAFILE='.$projectCa);
}
if(PHP_SAPI!=='cli') {
    ini_set('session.save_path',APP_ROOT.'/runtime/sessions');
    session_name('IPPHPSESSID');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','path'=>'/','secure'=>!empty($_SERVER['HTTPS'])]);
    session_start();
}
