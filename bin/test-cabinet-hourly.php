<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): never {
    fwrite(STDERR,get_class($error).': '.$error->getMessage().' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
});
require_once APP_ROOT.'/modules/integrations/worker.php';

use Modules\Integrations\{CabinetSyncAdmission,Config,ProviderService,Support};

$db=App\DB::pdo();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_cabinets'||Config::liveAllowed()) {
    throw new RuntimeException('ISOLATED_CABINET_DATABASE_REQUIRED');
}
$assert=static function(bool $ok,string $reason): void {
    if(!$ok) throw new RuntimeException($reason);
};
$marker=APP_ROOT.'/.local/cabinet-hourly-read.json';
if(file_exists($marker)) throw new RuntimeException('TEST_MARKER_ALREADY_EXISTS');
$oldStand=getenv('APP_STAND');
putenv('APP_STAND=ui');
$profiles=[];
foreach(CabinetSyncAdmission::PROVIDERS as $provider) {
    $current=Config::get($db,$provider);
    $config=['access_reference'=>'cabinet-test'];
    Config::save($db,$provider,$config,(int)$current['revision'],true);
    $profiles[$provider]=['reference'=>'cabinet-test','revision'=>(int)$current['revision']+1];
}
file_put_contents($marker,Support::json(['scope'=>'hourly-cabinet-read','profiles'=>$profiles]));
try {
    foreach ($profiles as $provider=>$profile) {
        $assert(CabinetSyncAdmission::allows($db,$provider,'records','cabinet-test'),'RECORDS_NOT_ADMITTED');
        $assert(!CabinetSyncAdmission::allows($db,$provider,'authenticate','cabinet-test'),'AUTH_ADMITTED');
        $assert(!CabinetSyncAdmission::allows($db,$provider,'records','other'),'OTHER_PROFILE_ADMITTED');
    }
    $assert(!CabinetSyncAdmission::allows($db,'onec','records'),'OTHER_PROVIDER_ADMITTED');
    try {
        \IpyaAccess\run(['provider'=>'diadoc','operation'=>'authenticate','cabinet_profile'=>'cabinet-test','remaining_ms'=>1000]);
        throw new LogicException('SCHEDULED_LOGIN_ACCEPTED');
    } catch(RuntimeException $error) {
        $assert($error->getMessage()==='ACCESS_DENIED','BAD_WORKER_DENIAL');
    }
    $id=Support::uuid();
    $fixture=static function(string $provider,string $operation,?array $secret,array $params) use($id): array {
        if(in_array($provider,['GET','POST','PUT'],true)) {
            if($provider!=='GET') throw new RuntimeException('UNEXPECTED_WRITE');
            $rows=match($operation){
                '/api/v8/accounts'=>[['id'=>101]],
                '/api/v7/accounts/101/documents'=>[['value'=>['documentId'=>$id,'date'=>'2026-10-05','amount'=>'10.29']]],
                default=>throw new RuntimeException('UNEXPECTED_PATH'),
            };
            return ['status'=>200,'raw'=>Support::json($rows)];
        }
        if($operation==='accounts') {
            $account=$provider==='pik'?['_uid'=>'cabinet-test-account']:['ID'=>'101'];
            return ['data'=>['items'=>[$account],'next'=>null,'total'=>1]];
        }
        if($operation!=='records') throw new RuntimeException('UNEXPECTED_CABINET_OPERATION');
        $current=match($provider) {
            'diadoc'=>['message_id'=>$id,'document_id'=>'current','document_date'=>'2026-10-05','name'=>'fixture'],
            'pik'=>['_uid'=>$id,'period'=>'2026-10','number'=>'fixture'],
            'mosvodokanal'=>['ID'=>$id,'FL_DOC'=>'fixture','period'=>'2026-10'],
        };
        $old=array_replace($current,['document_date'=>'2026-09-30','period'=>'2026-09','document_id'=>'old','_uid'=>'old-'.$id,'ID'=>'old-'.$id]);
        return ['data'=>['items'=>[$old,$current],'next'=>null,'total'=>$provider==='diadoc'?null:2]];
    };
    Config::saveProfile('mosenergo','cabinet-test',['session'=>['auth'=>'test-only-fixture']]);
    $service=new ProviderService($db,Config::store(),$fixture);
    foreach (CabinetSyncAdmission::PROVIDERS as $provider) {
        $run=$service->execute($provider,'sync',0,Support::uuid(),['trigger'=>'hourly','from'=>'2026-10-01','to'=>'2026-11-19']);
        $assert($run['status']==='success' && $run['coverage']['records_imported']===1,'CABINET_IMPORT_FAILED_'.$provider.'_'.$run['result_code']);
        $q=$db->prepare('SELECT coverage_json FROM integration_runs WHERE id=?');
        $q->execute([$run['id']]);
        $coverage=Support::decode($q->fetchColumn());
        $assert($coverage['command_context']['trigger']==='hourly','SCHEDULE_SOURCE_NOT_JOURNALED');
    }
    Config::save($db,'pik',['access_reference'=>'cabinet-test'],(int)Config::get($db,'pik')['revision'],true);
    $assert(!CabinetSyncAdmission::allows($db,'pik','sync'),'STALE_ADMISSION_ACCEPTED');
    echo "PASS four read-only cabinet imports, scoped profiles, dates and journal\n";
} finally {
    unlink($marker);
    putenv($oldStand===false?'APP_STAND':'APP_STAND='.$oldStand);
}
