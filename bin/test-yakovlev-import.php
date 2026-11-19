<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{Config,Registry,Support,YakovlevChanges,YakovlevImport};

$db = App\DB::pdo();
if (!str_starts_with($db->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_')) {
    throw new RuntimeException('ISOLATED_TASK_DB_REQUIRED');
}
$assert = static function(bool $ok,string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
};
$actor = (int)$db->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
$assert($actor>0,'ADMIN_FIXTURE_REQUIRED');
$key = Support::uuid();
$common = ['Ref_Key'=>$key,'DeletionMark'=>false];
$attributes = ['Date'=>'2026-09-01T00:00:00','Posted'=>true,'Number'=>'YAKOVLEV-TEST',
    'Организация_Key'=>'5b2b76f9-0ae3-11ea-80e4-005056953851','СуммаДокумента'=>'52093.13'];
$records = [];
foreach (['parties','bank_in','bank_out','invoices'] as $kind) {
    $record = $kind==='parties'?['Description'=>'Тестовый контрагент','IsFolder'=>false,'ИНН'=>'9999011015']:$attributes;
    $records[] = ['kind'=>$kind,'fields'=>['record'=>$common+$record]];
}
$bad = $records[3];
$bad['fields']['record']['Организация_Key'] = Support::uuid();
try {
    YakovlevImport::normalize($bad);
    throw new LogicException('FOREIGN_ORGANIZATION_ACCEPTED');
} catch (InvalidArgumentException $error) {
    $assert($error->getMessage()==='INVALID_YAKOVLEV_RECORD','WRONG_VALIDATION_ERROR');
}
$snapshot = static fn(array $rows): string => Support::json(['documents'=>$rows,'scope'=>['scope_limited'=>true]]);
$registry = new Registry($db,Config::store());
$provider = YakovlevImport::PROVIDER;
$batchKey = Support::uuid();
$first = $registry->import($provider,$snapshot($records),$actor,$batchKey);
$assert($first['coverage']['records_imported']===4,'EXPECTED_FOUR_KINDS');
$replay = $registry->import($provider,$snapshot($records),$actor,$batchKey);
$assert($replay['reused']&&$replay['id']===$first['id'],'REQUEST_REPLAY_NOT_REUSED');
$registry->import($provider,$snapshot($records),$actor,Support::uuid());
$records[3]['fields']['record']['СуммаДокумента'] = '52093.14';
$registry->import($provider,$snapshot($records),$actor,Support::uuid());
$records[3]['fields']['record']['DataVersion'] = 'UPDATED_SOURCE_STAMP';
$registry->import($provider,$snapshot($records),$actor,Support::uuid());
$read = $db->prepare("SELECT account_id,amount,version FROM external_documents WHERE provider=? AND source_id LIKE ? ORDER BY account_id");
$read->execute([$provider,'%:'.$key]);
$rows = $read->fetchAll(PDO::FETCH_ASSOC);
$assert(count($rows)===4 && array_column($rows,'account_id')===['bank_in','bank_out','invoices','parties'],'IDENTITY_OR_DUPLICATE');
$assert(array_map('intval',array_column($rows,'version'))===[1,1,2,1] && $rows[2]['amount']==='52093.14000000','VERSION_OR_DECIMAL_LOSS');
$originalInvoice = $records[3];
$originalInvoice['fields']['record']['СуммаДокумента'] = '52093.13';
unset($originalInvoice['fields']['record']['DataVersion']);
$assert(YakovlevChanges::fields($originalInvoice,$records[3])===[['Сумма документа','52093.13','52093.14']],'INCORRECT_FIELD_DIFF');
$renamedParty = $records[0];
$renamedParty['fields']['record']['Description'] = 'Новое наименование';
$registry->import($provider,$snapshot([$renamedParty]),$actor,Support::uuid());
$read->execute([$provider,'%:'.$key]);
$assert((int)$read->fetchAll(PDO::FETCH_ASSOC)[3]['version']===2
    && count(YakovlevChanges::fields($records[0],$renamedParty))===1,'COUNTERPARTY_CHANGE_NOT_RECORDED');
$fullInvoice = $records[3];
$fullInvoice['fields']['record']['Posted'] = false;
$fullInvoice['fields']['record']['Товары'] = [['Содержание'=>'Тест','Сумма'=>'52093.14']];
$fullParty = $renamedParty;
$fullParty['fields']['record']['DeletionMark'] = true;
$fullParty['fields']['record']['КонтактнаяИнформация'] = [['Представление'=>'Тестовый адрес']];
$registry->import($provider,$snapshot([$fullInvoice,$fullParty]),$actor,Support::uuid());
$stored = $db->prepare('SELECT account_id,status,raw_path,raw_sha256 FROM external_documents WHERE provider=? AND source_id LIKE ? AND account_id IN (\'invoices\',\'parties\') ORDER BY account_id');
$stored->execute([$provider,'%:'.$key]);
$details = $stored->fetchAll(PDO::FETCH_ASSOC);
$assert(array_column($details,'status')===['Не проведён','Помечен на удаление'],'SOURCE_STATUS_NOT_PRESERVED');
foreach ($details as $index=>$detail) {
    $archive = Support::decode(Config::store()->read($detail['raw_path'],$detail['raw_sha256']));
    $assert($archive===[$fullInvoice,$fullParty][$index],'NESTED_SOURCE_DATA_NOT_PRESERVED');
}
echo "PASS Yakovlev source separation, four kinds, exact decimals, update and replay\n";

if (getenv('APP_STAND')==='ui') throw new RuntimeException('TEST_REQUIRES_NO_LIVE_READ_ADMISSION');
$failureKey = Support::uuid();
$loader = new YakovlevImport($db,Config::store());
try {
    $loader->import('parties','','',$actor,$failureKey);
    throw new LogicException('LIVE_READ_ADMITTED_IN_TEST');
} catch (RuntimeException $error) {
    $assert($error->getMessage()==='YAKOVLEV_READ_NOT_ENABLED','UNEXPECTED_IMPORT_ERROR');
}
$failed = $loader->import('parties','','',$actor,$failureKey);
$assert($failed['reused'] && $failed['status']==='failed' && $failed['coverage']['records_imported']===0,'FAILED_RUN_NOT_REUSED');
$run = $db->prepare('SELECT created_at,finished_at,result_code FROM integration_runs WHERE id=?');
$run->execute([$failed['id']]);
$timing = $run->fetch(PDO::FETCH_ASSOC);
$assert($timing['result_code']==='YAKOVLEV_READ_NOT_ENABLED'&&$timing['finished_at']>=$timing['created_at'],'FAILURE_RUN_TIMING');

$pending = Support::uuid();
$requestKey = Support::uuid();
$scope = ['kind'=>'yakovlev-bank','scope_limited'=>true,'from'=>'2026-09-15','to'=>'2026-11-19'];
$db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json)
    VALUES(?,?,'yakovlev-load',?,?,1,?,'pending',?)")
    ->execute([$pending,$provider,$requestKey,$actor,hash('sha256',Support::json($scope)),Support::json(['scope'=>$scope])]);
$result = $registry->import($provider,Support::json(['documents'=>[$records[1],$records[2]],'scope'=>$scope,
    'coverage'=>['received_by_kind'=>['bank_in'=>1,'bank_out'=>1]]]),$actor,$requestKey,$pending);
$assert($result['id']===$pending&&$result['status']==='success','IMPORT_DID_NOT_COMPLETE_SAME_RUN');
$finished = $loader->import('bank','2026-09-15','2026-11-19',$actor,$requestKey);
$assert($finished['reused']&&$finished['coverage']['evidence']['received_by_kind']['bank_in']===1,'SUCCESS_RUN_NOT_REUSED');
$assert($finished['coverage']['changes']===['new'=>0,'changed'=>0,'unchanged'=>2],'REPEAT_COUNT_NOT_CORRECT');
echo "PASS Yakovlev run timing, failure, same-row atomic completion and replay\n";

$mirrorScope = ['kind'=>'yakovlev-parties','scope_limited'=>true,'trigger'=>'hourly','party_mirror'=>true];
$mirror = static function(array $rows,int $expected) use ($db,$registry,$provider,$mirrorScope): array {
    $id=Support::uuid();
    $key=Support::uuid();
    $db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json)
        VALUES(?,?,'yakovlev-load',?,0,1,?,'pending',?)")
        ->execute([$id,$provider,$key,hash('sha256',Support::json($mirrorScope)),Support::json(['scope'=>$mirrorScope])]);
    return $registry->import($provider,Support::json(['documents'=>$rows,'scope'=>$mirrorScope,
        'coverage'=>['received_by_kind'=>['parties'=>$expected]]]),0,$key,$id);
};
$partyId=$db->prepare("SELECT id,status FROM external_documents WHERE provider=? AND source_id=?");
$partyId->execute([$provider,'parties:'.$key]);
$prior=$partyId->fetch(PDO::FETCH_ASSOC);
try {
    $mirror([],1);
    throw new LogicException('INCOMPLETE_MIRROR_ACCEPTED');
} catch (RuntimeException $error) {
    $assert($error->getMessage()==='INCOMPLETE_PARTY_MIRROR','MIRROR_ERROR_NOT_EXPECTED');
}
$partyId->execute([$provider,'parties:'.$key]);
$assert($partyId->fetch(PDO::FETCH_ASSOC)===$prior,'PARTIAL_SNAPSHOT_CHANGED_PARTY');
$missing=$mirror([],0);
$partyId->execute([$provider,'parties:'.$key]);
$assert($missing['coverage']['changes']['missing']>=1
    &&$partyId->fetch(PDO::FETCH_ASSOC)===['id'=>$prior['id'],'status'=>YakovlevImport::PARTY_MISSING],'MISSING_PARTY_NOT_MARKED');
$returned=$mirror([$fullParty],1);
$partyId->execute([$provider,'parties:'.$key]);
$assert($returned['coverage']['changes']['returned']===1 && $partyId->fetch(PDO::FETCH_ASSOC)===$prior,'RETURNED_PARTY_NOT_RESTORED');
echo "PASS hourly mirror protects partial reads, marks missing and restores same archive identity\n";
