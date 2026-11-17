<?php
declare(strict_types=1);
// Exercise the real front-controller dispatch with isolated boundary doubles, no DB.
namespace App {
    final class Auth {
        public static function requirePermission(string $permission):void {
            if($permission!=='rental.read')throw new \RuntimeException('Unexpected permission');
            $GLOBALS['permission_checked']=true;
            if(($GLOBALS['argv'][1]??'')==='denied'){
                if(!empty($GLOBALS['control_calls'])||!empty($GLOBALS['menu_calls']))throw new \RuntimeException('Read before authorization');
                echo "PASS denied before menu/control\n";exit;
            }
        }
        public static function user():array{return ['id'=>1,'role'=>'accountant'];}
    }
    final class DB {public static function pdo():object{return new \stdClass();}}
    final class Menu {public static function counts(array $user):array {
        if(empty($GLOBALS['permission_checked']))throw new \RuntimeException('Missing permission guard');
        $GLOBALS['menu_calls']=($GLOBALS['menu_calls']??0)+1;
        return ['pages'=>['approvals'=>[0,1,0,0]],'notices'=>['fixture'=>true]];
    }}
    final class Http {public static function json(array $data):never {
        $read=($GLOBALS['argv'][1]??'')==='read';
        if(($GLOBALS['control_calls']??0)!==($read?0:1))throw new \RuntimeException('Unexpected payment-control writes');
        if(($GLOBALS['menu_calls']??0)!==1||$data['notices']!==['fixture'=>true]||$data['pages']['approvals']!==[0,1,0,0])throw new \RuntimeException('Menu projection changed');
        if($data['invoice_control']['state']!==($read?'READ_ONLY':'REFRESHED'))throw new \RuntimeException('Wrong mode');
        echo 'PASS '.($read?'read-only without payment control':'ordinary request retains payment control')."\n";exit;
    }}
}
namespace Modules\Rental {
    final class InvoiceSignals {public static function refreshForRequest(object $db):array {
        if(empty($GLOBALS['permission_checked']))throw new \RuntimeException('Missing permission guard');
        $GLOBALS['control_calls']=($GLOBALS['control_calls']??0)+1;return ['state'=>'REFRESHED'];
    }}
}
namespace {
    $_SERVER['REQUEST_URI']='/api/menu-counts';$_SERVER['REQUEST_METHOD']='GET';
    $_GET=($argv[1]??'')==='read'?['control'=>'read']:[];
    require dirname(__DIR__).'/public/index.php';
    throw new \RuntimeException('Endpoint did not complete');
}
