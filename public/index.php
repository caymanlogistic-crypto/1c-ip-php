<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{Auth,DB,Http,Identity,View};
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header("Content-Security-Policy: default-src 'self'; font-src 'self' data:; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/')?:'/';
try {
    if($_SERVER['REQUEST_METHOD']==='POST'&&(int)($_SERVER['CONTENT_LENGTH']??0)>83886080)throw new RuntimeException('Размер запроса превышает допустимый предел.',413);
    if($path==='/health'){try{$p=DB::pdo();$p->query('SELECT 1');$m=(int)$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();Http::json(['status'=>'ok','database'=>true,'migrations'=>$m,'live_sends'=>'disabled']);}catch(Throwable){Http::json(['status'=>'not_ready','database'=>false],503);}}
    if($path==='/')Http::redirect(Auth::user()?Auth::landing():'/login');
    if($path!=='/login'&&!Auth::user())Http::redirect('/login');
    if($path==='/design/demo')Http::redirect('/design/demo/index.html');
    if(preg_match('~^/design/demo/[^/]+\.html$~D',$path))\App\Showcase::page($path);
    if($path==='/api/menu-counts'){
        Auth::requirePermission('rental.read');
        $control=($_GET['control']??null)==='read'
            ? ['state'=>'READ_ONLY']
            : \Modules\Rental\InvoiceSignals::refreshForRequest(DB::pdo());
        Http::json(\App\Menu::counts(Auth::user())+['invoice_control'=>$control]);
    }
    if($_SERVER['REQUEST_METHOD']==='POST')Http::checkCsrf();
    \App\PagePolicy::guard($path,$_SERVER['REQUEST_METHOD']);
    $routes=Identity::routes();
    $routes[\App\InterfaceDesign::URL]=[\App\InterfaceDesignPage::class,'handle'];
    foreach(glob(APP_ROOT.'/modules/*/module.php') as $module){$definition=(static fn(string $file)=>require $file)($module);$routes=array_merge($routes,$definition['routes']??[]);}
    if(!isset($routes[$path]))throw new RuntimeException('Страница не найдена',404);
    echo $routes[$path]();
}catch(Throwable $e){$code=in_array($e->getCode(),[400,401,403,404,405,409,413,422,429],true)?(int)$e->getCode():500;http_response_code($code);if($code===500){$ref=\App\SafeErrors::record($e);$message='Не удалось выполнить операцию. Код обращения: '.$ref;}else $message=$e->getMessage();echo View::page('Ошибка',View::heading('Операция не выполнена').'<p role="alert">'.View::e($message).'</p><a class="btn" href="javascript:history.back()">Вернуться</a>');}
