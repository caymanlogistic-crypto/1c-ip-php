<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Billing, DirectoryDetails, Importer};

$db=DB::pdo();
$connection=$db->query('SELECT DATABASE() db, CURRENT_USER() account')->fetch();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$connection['db'])
    || $connection['account']!==$connection['db'].'@127.0.0.1' || $receipt['database']!==$connection['db']
    || empty($receipt['isolated_test_data']) || str_replace('\\','/',$receipt['app_root'])!==str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL')) throw new RuntimeException('Verified isolated task DB required.');

$db->beginTransaction();
try {
    $party=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $contract=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM contracts')->fetchColumn();
    $id=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM invoices')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Snapshot test','{}')")->execute([$party]);
    $db->prepare("INSERT INTO contracts(id,number,party_id,status,source_json) VALUES(?,? ,?,'active','{}')")
        ->execute([$contract,'SNAPSHOT-TEST-'.$contract,$party]);
    $db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,status,source_json)
        VALUES(?,? ,?,'Аренда','2026-11',100000,0,'active','{}')")->execute([$id,'SNAPSHOT-TEST-'.$id,$contract]);

    DirectoryDetails::store($db,'parties',$party,['party_type'=>'company']);
    Billing::snapshotInvoice($db,$id);
    $q=$db->prepare('SELECT payer_snapshot FROM invoices WHERE id=?');
    $q->execute([$id]);
    $issued=Importer::decodeExact($q->fetchColumn());
    if (($issued['party_type'] ?? null)!=='company') throw new RuntimeException('Payer type was lost at invoice issue.');
    DirectoryDetails::store($db,'parties',$party,['party_type'=>'entrepreneur']);
    $q->execute([$id]);
    if (Importer::decodeExact($q->fetchColumn())!==$issued)
        throw new RuntimeException('Editing the directory unexpectedly rewrote the invoice snapshot.');
    echo "PASS issued payer type comes from directory_parties and remains frozen.\n";
} finally {
    $db->rollBack();
}
