<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Integrations\OnecPartySupplement;
use Modules\Rental\{Importer,ZeroPoint,ZeroPointPartyEnrichment,ZeroPointRevision};
if(PHP_SAPI!=='cli')exit(2);
try{
    $mode=$argv[1]??'';$actor=(int)($argv[2]??0);$q=DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);$u=$q->fetch()?:throw new RuntimeException('Explicit active admin required');$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
    if($mode==='capture')echo json_encode(OnecPartySupplement::capture()).PHP_EOL;
    elseif($mode==='plan' && isset($argv[3],$argv[4])){$plan=ZeroPointPartyEnrichment::prepare(['capture_sha256'=>$argv[3]]);$sha=ZeroPoint::write($argv[4],$plan);echo json_encode(['plan_sha256'=>$sha,'next_sha256'=>$plan['next_sha256'],'report'=>$plan['report']]).PHP_EOL;}
    elseif($mode==='apply' && isset($argv[3]))echo json_encode(ZeroPointPartyEnrichment::apply(Importer::decodeExact(file_get_contents($argv[3])))).PHP_EOL;
    elseif($mode==='recover')echo json_encode(ZeroPointRevision::recover()).PHP_EOL;
    else throw new RuntimeException('Usage: capture <admin-id> | plan <admin-id> <capture-sha> <absolute-plan.json> | apply <admin-id> <plan.json> | recover <admin-id>');
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
