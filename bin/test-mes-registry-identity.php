<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{Config,MesClient,Registry,Support};

$db = App\DB::pdo();
if (!str_starts_with((string)$db->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_') || Config::liveAllowed()) {
    throw new RuntimeException('ISOLATED_FIXTURE_REQUIRED');
}
$assert = static function(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$account = (string)random_int(1000000000,9999999999);
$document = Support::uuid();
$row = static function(string $type,?string $report) use ($account,$document): array {
    $value = ['documentId'=>$document,'documentType'=>$type,'documentDate'=>'2026-10-31','documentNum'=>'1','amount'=>'101.23'];
    if ($report!==null) $value['reportId']=$report;
    return ['account_id'=>$account,'fields'=>['record'=>['type'=>'Документ','value'=>$value],'account'=>['id'=>$account]]];
};
$types = ['Счет на оплату','Счет-фактура','Акт приема-передачи'];
$legacy = $row($types[0],null);
$invoice = $row('Счёт  на оплату','PAY');
$vat = $row($types[1],'VAT');
$act = $row($types[2],'ACT');
$id = static fn(array $data): string => Registry::normalize('mosenergo',$data)['source_id'];
$assert($id($legacy)===Support::json([$account,$document]) && $id($invoice)===$id($legacy),'PAYMENT_ID_CHANGED');
$assert($id($vat)===Support::json([$account,$document,'VAT']) && $id($act)===Support::json([$account,$document,'ACT']),
    'REPORT_IDS_COLLIDE');
$assert($id($row($types[1],null))===$id($legacy),'UNTYPED_LEGACY_SNAPSHOT_CHANGED');
$registry = new Registry($db,Config::store());
$import = static fn(array $records): array => $registry->import('mosenergo',
    Support::json(['provider'=>'mosenergo','records'=>$records]),0,Support::uuid());
$import([$legacy]);
$q = $db->prepare("SELECT id,source_id,amount,version FROM external_documents WHERE provider='mosenergo' AND account_id=?");
$q->execute([$account]);
$before = $q->fetch(PDO::FETCH_ASSOC);
$result = $import([$row($types[0],'PAY'),$vat,$act]);
$assert($result['coverage']['records_imported']===3,'INCOMPLETE_REPORT_IMPORT');
$q->execute([$account]);
$rows = array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'source_id');
$assert(count($rows)===3 && $rows[$id($legacy)]['id']===$before['id'] &&
    (int)$rows[$id($legacy)]['version']===2 && $rows[$id($vat)]['amount']==='101.23000000','LEGACY_ROW_REPLACED_OR_PRECISION_LOST');
$q = $db->prepare("SELECT x.document_type FROM external_document_details x JOIN external_documents d ON d.id=x.document_id
    WHERE d.provider='mosenergo' AND d.account_id=?");
$q->execute([$account]);
$storedTypes=$q->fetchAll(PDO::FETCH_COLUMN);
sort($types);
sort($storedTypes);
$assert($storedTypes===$types,'SOURCE_DOCUMENT_TYPE_NOT_PRESERVED');
$wire=[];
$client=new MesClient(['auth'=>'fixture'],static function($method,$path,$body)use(&$wire,$account) {
    $wire[]=[$method,$path,$body];
    return ['status'=>200,'raw'=>$method==='GET'?Support::json([['id'=>$account]]):'%PDF-1.4 fixture'];
});
$record=$act['fields']['record'];
$record['canDownload']=true;
$record['value']['system']='BILLING';
$record['value']['documentNum']=null;
$bytes=$client->pdf($account,$record);
$assert(str_starts_with($bytes,'%PDF-')&&count($wire)===2&&$wire[1][1]==='/api/v6/accounts/'.$account.'/documents/download'
    && $wire[1][2]['document']['reportId']==='ACT' && array_key_exists('documentNumber',$wire[1][2]['document'])
    && $wire[1][2]['document']['documentNumber']===null
    && $wire[1][2]['document']['documentType']==='акт приема-передачи','NULL_DOCUMENT_NUMBER_NOT_PRESERVED');
unset($record['value']['documentNum']);
try {
    $client->pdf($account,$record);
    throw new LogicException('MISSING_DOCUMENT_NUMBER_KEY_ACCEPTED');
} catch (RuntimeException $error) {
    $assert($error->getMessage()==='FILE_BINDING_FAILED'&&count($wire)===2,'UNBOUND_REPORT_DOWNLOADED');
}
echo "PASS MES legacy billing identity, three variants per documentId, imported document types and exact amount\n";
