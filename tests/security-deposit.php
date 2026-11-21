<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Importer, SecurityDeposit, SecurityDepositEvidence};

function check(bool $result,string $message): void {
    if (!$result) throw new RuntimeException($message);
}

check(SecurityDepositEvidence::refundPurpose('Возврат обеспечительного платежа по договору № D20-56 от 10.10.2026','D20-56'),
    'Recognize an explicit refund of deposit for the exact contract.');
check(!SecurityDepositEvidence::refundPurpose('Возврат обеспечительного платежа по договору № D20-560','D20-56'),
    'A similar contract number is not evidence.');
check(!SecurityDepositEvidence::refundPurpose('Оплата клининга по договору № D20-56','D20-56'),
    'An unrelated transfer to the same counterparty is not a refund.');
check(!SecurityDepositEvidence::refundPurpose('Возврат обеспечительного платежа по договору № D20-56 и договору № D20-62','D20-56'),
    'A payment for several contracts requires reconciliation.');

$act=['act_id'=>'old-act','act_sha256'=>hash('sha256','old act'),'act_signed_on'=>'2026-11-02'];
$agreement=hash('sha256','signed agreement');
$deadline=['date'=>'2026-11-23','source'=>'Confirmed test calendar','revision'=>hash('sha256','calendar')];
$plan=['evidence'=>$act+['agreement_id'=>'saved closing attachment','agreement_sha256'=>$agreement],'deadline'=>$deadline];
check(SecurityDepositEvidence::returnDeadline($act,$agreement,$plan)===['deadline'=>$deadline,'saved'=>true],
    'Reuse the saved deadline for the same signature and signed original.');
check(!SecurityDepositEvidence::returnDeadline($act,hash('sha256','other agreement'),$plan)['saved'],
    'A different agreement must not inherit the saved deadline.');
check(!SecurityDepositEvidence::returnDeadline(array_replace($act,['act_id'=>'new-act']),$agreement,$plan)['saved'],
    'A different act must not inherit the saved deadline.');

$db=DB::pdo();
$connection=$db->query('SELECT DATABASE() db, CURRENT_USER() account')->fetch();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$connection['db'])
    || $connection['account']!==$connection['db'].'@127.0.0.1'
    || $receipt['database']!==$connection['db'] || empty($receipt['isolated_test_data'])
    || str_replace('\\','/',$receipt['app_root'])!==str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL')) throw new RuntimeException('Verified isolated task DB required.');

$paths=[];
$db->beginTransaction();
try {
    $party=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $source=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM contracts')->fetchColumn();
    $target=$source+1;
    $invoice=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM invoices')->fetchColumn();
    $payment=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM payments')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Deposit test','{}')")->execute([$party]);
    $add=$db->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,status,signed,source_json)
        VALUES(?,?,?,?,?,?,1,'{}')");
    $add->execute([$source,'D20-FROM-'.$source,$party,'2020-01-01','2020-01-30','reconciling']);
    $add->execute([$target,'D20-TO-'.$target,$party,'2020-02-04','2020-12-31','active']);
    $old=['id'=>$source,'number'=>'D20-FROM-'.$source,'party_id'=>$party,'start_date'=>'2020-01-01','signed'=>1];
    $new=['id'=>$target,'number'=>'D20-TO-'.$target,'party_id'=>$party,'start_date'=>'2020-02-04','signed'=>1,'status'=>'active'];
    $makeFile=static function(string $entity,int $id,string $kind) use($db,&$paths): void {
        $file=bin2hex(random_bytes(16));
        $name=$file.'.pdf';
        $path=APP_ROOT.'/.local/documents/'.$name;
        file_put_contents($path,'Test evidence '.$file);
        $paths[]=$path;
        $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
            VALUES(?,?,?,1,?,'Evidence.pdf',?,?,'application/pdf')")
            ->execute([$file,$entity,$id,$kind,$name,hash_file('sha256',$path)]);
    };
    $makeFile('contract',$target,'signed-contract');
    SecurityDepositEvidence::successor($db,$old,$new,['source_number'=>$old['number'],'target_number'=>$new['number']]);
    try {
        SecurityDepositEvidence::successor($db,$old,$new,['source_number'=>$old['number'],'target_number'=>'unrelated']);
        throw new LogicException('Wrong signed contract reference was accepted.');
    } catch (RuntimeException $e) {
        check($e->getCode()===409,'Unconfirmed signed D20 reference must stop the transfer.');
    }
    $db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,status,source_json)
        VALUES(?,'DEPOSIT-TEST',?,?,'Обеспечение',100000,100000,'active','{}')")->execute([$invoice,$source,SecurityDeposit::KIND]);
    $db->prepare("INSERT INTO payments(id,party_id,amount,payment_date,source_json) VALUES(?,?,100000,'2020-01-02',?)")
        ->execute([$payment,$party,Importer::json(['bank_source'=>'test','bank_external_id'=>'draft'])]);
    $db->prepare('INSERT INTO payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,100000)')->execute([$payment,$invoice]);
    $makeFile('payment',$payment,'payment-order');
    $proof=SecurityDeposit::read($db,$old);
    check($proof['received']==='0.00' && $proof['unverified'] && $proof['due']===null && $proof['rows'][0]['order'],
        'Uploaded payment order and declared paid amount do not prove incoming funds.');
    $key=hash('sha256',"test\0draft");
    $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('rental_bank_payment_identity',?,?,1)")
        ->execute([$key,Importer::json(['payment_id'=>$payment,'sha256'=>hash('sha256','test bank receipt')])]);
    $proof=SecurityDeposit::read($db,$old);
    check($proof['received']==='100000.00' && !$proof['unverified'] && $proof['free']==='100000.00',
        'An identified, allocated bank receipt is real coverage.');
    echo "PASS deposit evidence, exact refund purpose and D20 linkage across a gap.\n";
} finally {
    $db->rollBack();
    foreach ($paths as $path) if (is_file($path)) unlink($path);
}
