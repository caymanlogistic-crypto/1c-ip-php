<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{Config,Registry,Support,YakovlevImport};

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
$read = $db->prepare("SELECT account_id,amount,version FROM external_documents WHERE provider=? AND source_id LIKE ? ORDER BY account_id");
$read->execute([$provider,'%:'.$key]);
$rows = $read->fetchAll(PDO::FETCH_ASSOC);
$assert(count($rows)===4 && array_column($rows,'account_id')===['bank_in','bank_out','invoices','parties'],'IDENTITY_OR_DUPLICATE');
$assert(array_map('intval',array_column($rows,'version'))===[1,1,2,1] && $rows[2]['amount']==='52093.14000000','VERSION_OR_DECIMAL_LOSS');
echo "PASS Yakovlev source separation, four kinds, exact decimals, update and replay\n";
