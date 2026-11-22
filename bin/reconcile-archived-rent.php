<?php
declare(strict_types=1);

use App\DB;
use Modules\Rental\{ArchivedRentCorrection,Importer,Service};

if (PHP_SAPI!=='cli' || !getenv('APP_DB_CONFIG_FILE') || $argc!==5) {
    fwrite(STDERR,"Использование: reconcile-archived-rent.php <admin-id> <contract-id> <version> <command-key>\n");
    exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';

try {
    [, $adminId, $contractId, $version, $key]=$argv;
    foreach ([$adminId,$contractId,$version] as $value) {
        if (!ctype_digit($value) || (int)$value<=0) throw new RuntimeException('Укажите действительные ID и версию.');
    }
    $query=DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");
    $query->execute([(int)$adminId]);
    $actor=$query->fetch() ?: throw new RuntimeException('Укажите действующего администратора.');
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $result=Service::command(ArchivedRentCorrection::ACTION,[
        'id'=>(int)$contractId,'version'=>(int)$version,'command_key'=>$key,
    ]);
    echo Importer::json($result),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}
