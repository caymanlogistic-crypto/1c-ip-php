<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Importer,ZeroPoint,ZeroPointCorrection};
if (PHP_SAPI!=='cli') exit(2);
$mode=$argv[1] ?? '';$actor=(int)($argv[2] ?? 0);
$q=DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);
$u=$q->fetch() ?: throw new RuntimeException('Explicit active administrator required');
$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
if ($mode==='plan' && isset($argv[3],$argv[4])) {
    $result=ZeroPointCorrection::prepare(Importer::decodeExact(file_get_contents($argv[3])));
    $sha=ZeroPoint::write($argv[4],$result);echo json_encode(['plan_sha256'=>$sha,'next_sha256'=>$result['next_sha256'],'date_count'=>16]).PHP_EOL;
} elseif ($mode==='apply' && isset($argv[3])) {
    echo json_encode(ZeroPointCorrection::apply(Importer::decodeExact(file_get_contents($argv[3])))).PHP_EOL;
} elseif ($mode==='recover') echo json_encode(ZeroPointCorrection::recover()).PHP_EOL;
else {fwrite(STDERR,"Usage: plan <admin-id> <private-evidence.json> <private-plan.json> | apply <admin-id> <private-plan.json> | recover <admin-id>\n");exit(2);}
