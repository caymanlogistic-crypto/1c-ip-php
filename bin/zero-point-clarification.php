<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Importer,ZeroPoint,ZeroPointClarification,ZeroPointRevision};
if(PHP_SAPI!=='cli')exit(2);
try{
    $mode=$argv[1] ?? '';$actor=(int)($argv[2] ?? 0);
    $q=DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);$u=$q->fetch() ?: throw new RuntimeException('Explicit active admin required');$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
    if($mode==='plan' && isset($argv[3])){$plan=ZeroPointClarification::prepare(ZeroPointClarification::evidence());$sha=ZeroPoint::write($argv[3],$plan);echo json_encode(['plan_sha256'=>$sha,'next_sha256'=>$plan['next_sha256']]).PHP_EOL;}
    elseif($mode==='apply' && isset($argv[3]))echo json_encode(ZeroPointClarification::apply(Importer::decodeExact(file_get_contents($argv[3])))).PHP_EOL;
    elseif($mode==='recover')echo json_encode(ZeroPointRevision::recover()).PHP_EOL;
    else throw new RuntimeException('Usage: plan <admin-id> <absolute-plan.json> | apply <admin-id> <plan.json> | recover <admin-id>');
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
