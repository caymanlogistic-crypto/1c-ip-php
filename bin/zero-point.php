<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\ZeroPoint;

if (PHP_SAPI!=='cli') exit(2);
$mode=$argv[1] ?? '';
if ($mode==='reference' && isset($argv[2])) {
    $db=DB::pdo();$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $data=ZeroPoint::capture($db);
        $result=['schema'=>1,'kind'=>'COMMERCIAL_REFERENCE','project_id'=>ZeroPoint::PROJECT,
            'database'=>$db->query('SELECT DATABASE()')->fetchColumn(),'created_at'=>date(DATE_ATOM),
            'table_schema'=>ZeroPoint::schema($db),'data'=>$data];
        $db->rollBack();$sha=ZeroPoint::write($argv[2],$result);
        echo json_encode(['reference'=>$argv[2],'sha256'=>$sha],JSON_PRETTY_PRINT).PHP_EOL;
    } catch(Throwable $e) {if ($db->inTransaction()) $db->rollBack();throw $e;}
} elseif ($mode==='install' && isset($argv[2])) {
    $document=\Modules\Rental\Importer::decodeExact(file_get_contents($argv[2]));
    echo json_encode(ZeroPoint::install($document),JSON_PRETTY_PRINT).PHP_EOL;
} else {
    fwrite(STDERR,"Usage: php bin/zero-point.php reference <private-output.json> | install <verified-point.json>\n");exit(2);
}
