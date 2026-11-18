<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Importer,Service,WorkspaceReset,ZeroPoint};
if(PHP_SAPI!=='cli')exit(2);
try {
    $mode=$argv[1] ?? '';$actor=(int)($argv[2] ?? 0);
    $q=DB::pdo()->prepare("SELECT id,auth_version FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);$u=$q->fetch() ?: throw new RuntimeException('Explicit active admin required');$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
    if($mode==='preview' && isset($argv[3])) {
        $plan=WorkspaceReset::preview();$sha=ZeroPoint::write($argv[3],$plan);echo json_encode(['sha256'=>$sha,'changes'=>array_column($plan['changes'],'label'),'blocked'=>$plan['blocked']],JSON_UNESCAPED_UNICODE).PHP_EOL;
    } elseif($mode==='reset' && isset($argv[3],$argv[4]))echo json_encode(WorkspaceReset::run(Importer::decodeExact(file_get_contents($argv[3])),$argv[4],'ОЧИСТИТЬ'),JSON_UNESCAPED_UNICODE).PHP_EOL;
    elseif($mode==='remove-test-object' && isset($argv[3]))echo json_encode(Service::command('workspace.remove-test-object',['command_key'=>$argv[3]]),JSON_UNESCAPED_UNICODE).PHP_EOL;
    else throw new RuntimeException('Usage: preview <admin-id> <plan.json> | reset <admin-id> <plan.json> <command-key> | remove-test-object <admin-id> <command-key>');
} catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
