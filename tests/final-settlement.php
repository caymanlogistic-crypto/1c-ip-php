<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{FinalSettlement, Importer, Service};

$db=DB::pdo();
$connection=$db->query('SELECT DATABASE() db, CURRENT_USER() account')->fetch();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$connection['db'])
    || $connection['account']!==$connection['db'].'@127.0.0.1'
    || $receipt['database']!==$connection['db'] || empty($receipt['isolated_test_data'])
    || str_replace('\\','/',$receipt['app_root'])!==str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL')) throw new RuntimeException('Verified isolated task DB required.');

function rejectReview(PDO $db,array $contract,string $kind,string $question): void {
    try {
        FinalSettlement::assertReview($db,$contract,$kind);
    } catch (RuntimeException $e) {
        if ($e->getCode()===409 && str_contains($e->getMessage(),$question)) return;
        throw $e;
    }
    throw new RuntimeException('Expected refusal: '.$question);
}

$filePath=null;
$db->beginTransaction();
try {
    $actor=$db->query("SELECT id,auth_version FROM users WHERE role='lawyer' AND active=1 LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $party=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $contract=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM contracts')->fetchColumn();
    $invoice=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM invoices')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Final review test','{}')")->execute([$party]);
    $db->prepare("INSERT INTO contracts(id,number,party_id,status,version,source_json) VALUES(?,?,?,'reconciling',1,'{}')")
        ->execute([$contract,'TEST-'.bin2hex(random_bytes(5)),$party]);
    $db->prepare("INSERT INTO invoices(id,number,contract_id,kind,amount,paid,status,source_json)
        VALUES(?,'TEST-OPEN',?,'Аренда',1000,500,'active',?)")
        ->execute([$invoice,$contract,Importer::json(['regular_invoice'=>['period'=>'2026-10']])]);
    $row=Service::get($db,'contracts',$contract);
    FinalSettlement::assertReview($db,$row,'finance');
    FinalSettlement::start($db,$contract,['closing_scope'=>'1']);
    $model=FinalSettlement::read($db,$contract);
    $model['finance']=['debt'=>'0.00','penalty'=>'0.00','variable'=>'exempt','invoice_ids'=>[]];
    $db->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='rental_closing_basis' AND source_key=?")
        ->execute([Importer::json($model),(string)$contract]);
    rejectReview($db,$row,'finance','По действующему счёту № TEST-OPEN');

    $db->prepare("UPDATE invoices SET source_json='{}' WHERE id=?")->execute([$invoice]);
    rejectReview($db,$row,'finance','Сверьте исходные суммы и применимость счёта № TEST-OPEN');
    rejectReview($db,$row,'legal','Уточните, требуется ли отдельное итоговое соглашение');
    $file=bin2hex(random_bytes(16));
    $filePath=APP_ROOT.'/.local/documents/'.$file.'.pdf';
    file_put_contents($filePath,'Test signed lease '.$file);
    $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
        VALUES(?,'contract',?,1,'signed-contract','Test signed lease.pdf',?,?,'application/pdf')")
        ->execute([$file,$contract,basename($filePath),hash_file('sha256',$filePath)]);
    Service::command(FinalSettlement::AGREEMENT,['id'=>$contract,'version'=>1,'command_key'=>bin2hex(random_bytes(16)),
        'agreement_state'=>'not_required','basis_file_id'=>$file,'legal_basis'=>'Обычное истечение срока по договору','confirmed'=>'1']);
    FinalSettlement::assertReview($db,$row,'legal');
    echo "PASS: omitted unpaid invoice, historical uncertainty, legacy route, and signed lease without a separate agreement.\n";
} finally {
    $db->rollBack();
    if ($filePath!==null && is_file($filePath)) unlink($filePath);
}
