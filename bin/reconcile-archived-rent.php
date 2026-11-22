<?php
declare(strict_types=1);

use App\DB;
use Modules\Rental\{ArchivedRentCorrection,Importer,Service};

if (PHP_SAPI!=='cli' || !getenv('APP_DB_CONFIG_FILE') || !in_array($argc,[5,6],true)) {
    fwrite(STDERR,"Использование: reconcile-archived-rent.php <admin-id> <contract-id> <version> <command-key> [archive-proof.json]\n");
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
    $input=[
        'id'=>(int)$contractId,'version'=>(int)$version,'command_key'=>$key,
    ];
    if (isset($argv[5])) {
        $size=is_file($argv[5]) && is_readable($argv[5]) ? filesize($argv[5]) : false;
        if (!$size || $size>16384) throw new RuntimeException('Укажите файл подтверждения из архива.');
        $proof=json_decode(file_get_contents($argv[5]),true,32,JSON_THROW_ON_ERROR);
        $input+=isset($proof['rent_credit']) ? ['rent_credit'=>$proof['rent_credit']] : ['payer_agreement'=>$proof];
    }
    $result=Service::command(ArchivedRentCorrection::ACTION,$input);
    echo Importer::json($result),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}
