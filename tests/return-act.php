<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Audit, DB};
use Modules\Rental\{Evidence, FileOrder, FinalReview, OccupiedBilling, PremisesActs, ReconciliationRegistry, ReturnAct, Service};

$db = DB::pdo();
$connection = $db->query('SELECT DATABASE() db, CURRENT_USER() account')->fetch();
$receipt = json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'), true, 32, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', $connection['db'])
    || $connection['account'] !== $connection['db'].'@127.0.0.1'
    || $receipt['database'] !== $connection['db'] || empty($receipt['isolated_test_data'])
    || str_replace('\\','/',$receipt['app_root']) !== str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL')) throw new RuntimeException('Verified isolated task DB required.');

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function expectConflict(callable $command, string $message): void {
    try {
        $command();
    } catch (RuntimeException $e) {
        check($e->getCode() === 409 && str_contains($e->getMessage(), $message), $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}

$files = [];
$db->beginTransaction();
try {
    FileOrder::lock($db);
    $actor = $db->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();
    check((bool)$actor, 'Manager test account required.');
    $_SESSION = ['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $party = (int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $id = (int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM contracts')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Учебный контрагент','{}')")->execute([$party]);
    $insert = $db->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,finish_date,status,version,source_json)
        VALUES(?,?,?,CURRENT_DATE - INTERVAL 1 MONTH,CURRENT_DATE,CURRENT_DATE,'reconciling',4,'{}')");
    foreach ([$id,$id+1] as $contractId) {
        $insert->execute([$contractId,'TEST-RETURN-'.bin2hex(random_bytes(6)),$party]);
    }
    $dir = APP_ROOT.'/.local/documents';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $add = static function(int $owner, int $version) use($db,$dir,&$files): string {
        $id = bin2hex(random_bytes(16));
        $name = $id.'.pdf';
        $path = $dir.'/'.$name;
        $files[] = $path;
        file_put_contents($path, 'Test payload for confirmation '.$id);
        $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime,baseline_hash)
            VALUES(?,'contract',?,?,?,?,?,?,'application/pdf',?)")->execute([$id,$owner,$version,ReturnAct::KIND,
                'Учебный акт.pdf',$name,hash_file('sha256',$path),Evidence::signature($db,$owner)]);
        FileOrder::assign($db,$id);
        Audit::record('document.upload','contract',$owner,null,['file'=>$id,'return_act_pending'=>true]);
        return $id;
    };
    $previous = $add($id,4);
    $foreign = $add($id+1,4);
    $current = $add($id,4);
    $base = ['id'=>$id, 'version'=>4, 'both_signed'=>'1', 'return_date'=>date('Y-m-d')];
    foreach ([$previous,$foreign] as $wrong) {
        expectConflict(fn()=>Service::command('contract.return-act',$base+['file_id'=>$wrong,
            'command_key'=>bin2hex(random_bytes(16))]), 'не относится');
    }
    $command = $base+['file_id'=>$current,'command_key'=>bin2hex(random_bytes(16))];
    $result = Service::command('contract.return-act',$command);
    check($result===Service::command('contract.return-act',$command), 'Exact repeat receipt changed.');
    $record = Service::get($db,'contracts',$id);
    check((int)$record['version']===5 && $record['return_confirmed']===$base['return_date'], 'Return state changed more than once.');
    expectConflict(fn()=>Service::command('contract.return-act',array_replace($command,[
        'version'=>5,'command_key'=>bin2hex(random_bytes(16))])), 'уже подтверждён');
    $add($id,5);
    check(ReturnAct::confirmed($db,$record)['id']===$current, 'Confirmed file replaced by later upload.');
    $db->prepare("UPDATE contracts SET return_confirmed=CURRENT_DATE WHERE id=?")->execute([$id+1]);
    check(ReturnAct::confirmed($db,Service::get($db,'contracts',$id+1))===null, 'New upload treated as a historical confirmation.');
    $calc=$id+2;
    $today=date('Y-m-d');
    $insert->execute([$calc,'TEST-BILLING-ONLY-'.bin2hex(random_bytes(6)),$party]);
    $object=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM rental_objects')->fetchColumn();
    $db->prepare("INSERT INTO rental_objects(id,name,type,source_json) VALUES(?,'Учебный офис','Офис','{}')")->execute([$object]);
    $db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$calc,$object]);
    OccupiedBilling::store($db,$calc,[$object=>['reason'=>'Учебное основание','until'=>$today,'occupier_id'=>$id]]);
    $billing=Service::get($db,'contracts',$calc);
    check(!PremisesActs::subjects($db,$calc) && !empty(ReturnAct::state($db,$billing)['not_applicable']),
        'A billing-only office must not require an act.');
    expectConflict(fn()=>Service::command('contract.return-act',array_replace($base,['id'=>$calc,
        'command_key'=>bin2hex(random_bytes(16))])), 'Физический возврат не применяется');
    check(!Evidence::complete($db,$calc), 'Missing approvals must still block closing.');
    $review=$db->prepare("INSERT INTO rental_reviews(contract_id,kind,baseline_hash,actor_id) VALUES(?,?,?,?)");
    foreach (['finance','legal'] as $kind) {
        $review->execute([$calc,$kind,Evidence::signature($db,$calc),$actor['id']]);
        if ($kind==='finance') check(!Evidence::complete($db,$calc), 'One approval must not close billing-only rent.');
    }
    check(Evidence::complete($db,$calc), 'Billing-only contract with both reviews did not close.');
    $billing=Service::get($db,'contracts',$calc);
    $query=$db->prepare("SELECT after_json FROM audit_log WHERE action='contract.completed' AND entity_type='contract' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $query->execute([(string)$calc]);
    $proof=json_decode($query->fetchColumn(),true)['closure_proof'];
    check($billing['return_confirmed']===null && ReconciliationRegistry::closedProof($billing,
        FinalReview::approvals($db,$calc),null,$proof,Evidence::signature($db,$calc),true),
        'Registry must verify closure without recording a physical return.');
    echo "PASS: contract ownership, replacement, exact replay, rebinding refusal and historical upload isolation.\n";
} finally {
    $db->rollBack();
    foreach ($files as $path) if (is_file($path)) unlink($path);
}
