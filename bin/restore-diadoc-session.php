<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || getenv('APP_STAND')!=='ui' || $argc!==3) exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{DiadocSessionRestore,Support};

try {
    $db=App\DB::pdo();
    if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') throw new RuntimeException('DIADOC_RESTORE_NOT_ALLOWED');
    $result=(new DiadocSessionRestore($db))->execute($argv[1],$argv[2]);
} catch (Throwable $error) {
    $result=['status'=>'failed','code'=>DiadocSessionRestore::safeCode($error)];
}
echo Support::json($result),PHP_EOL;
exit(in_array($result['status'],['success','awaiting_confirmation'],true)
    || ($argv[1]==='reconcile' && $result['code']===DiadocSessionRestore::STATUS_RECONCILED)?0:1);
