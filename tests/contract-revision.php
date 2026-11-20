<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{ContractRevision,FileOrder,LegalReview,Service};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function verify(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$db=DB::pdo();
$database=$db->query('SELECT DATABASE()')->fetchColumn();
$proof=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
verify(PHP_SAPI==='cli' && preg_match('/^ipphp_task_[a-z0-9_]+$/D',$database)
    && $proof['database']===$database && $proof['isolated_test_data']===true
    && realpath($proof['app_root'])===realpath(APP_ROOT)
    && !getenv('IPPHP_ALLOW_EXTERNAL') && !getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'),'Verified isolated test copy required.');
$id=(int)($argv[1] ?? 0);
$contract=Service::get($db,'contracts',$id);
verify($contract['status']==='approved' && !(bool)$contract['signed'],'Use an unsigned synthetic approved contract.');
$invoiceQuery=$db->prepare('SELECT id,amount,vat,coverage_key FROM invoices WHERE contract_id=? ORDER BY id');
$invoiceQuery->execute([$id]);
$invoices=$invoiceQuery->fetchAll();
$actors=$db->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');
$act=static function(string $role)use($actors): void {
    $actors->execute([$role]);$actor=$actors->fetch();
    verify((bool)$actor,'Missing local role '.$role);
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
};

$db->beginTransaction();
try {
    FileOrder::lock($db);
    $staged=bin2hex(random_bytes(16));
    $file=$db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
        SELECT ?,'contract',?,?,'tenant-amendments',f.filename,f.path,f.sha256,f.mime
        FROM rental_approvals a JOIN rental_files f ON f.id=a.file_id
        WHERE a.entity_type='contract' AND a.entity_id=? AND a.role='lawyer'");
    $file->execute([$staged,$id,$contract['version'],$id]);
    verify($file->rowCount()===1,'Need a previously approved file.');
    FileOrder::assign($db,$staged);
    $input=['id'=>$id,'version'=>$contract['version'],'command_key'=>bin2hex(random_bytes(16)),
        'reason'=>'Учебный повтор пересогласования','revision_files'=>['tenant-amendments'=>$staged]];
    $act('accountant');
    try {
        Service::command(ContractRevision::ACTION,$input);
        throw new LogicException('An accountant was able to request a new revision.');
    } catch (RuntimeException $error) {
        verify($error->getCode()===403,'Expected role denial.');
    }
    $act('manager');
    $first=Service::command(ContractRevision::ACTION,$input);
    verify(Service::command(ContractRevision::ACTION,$input)===$first,'Command replay changed the receipt.');
    $current=Service::get($db,'contracts',$id);
    verify($current['status']==='legal' && (int)$current['version']===(int)$contract['version']+1,'Incorrect stage/version.');
    verify(LegalReview::file($current)===null,'A new revision inherited the previous approved file.');
    $history=$db->prepare('SELECT snapshot_text FROM rental_contract_versions WHERE contract_id=? AND version=?');
    $history->execute([$id,$contract['version']]);
    $review=json_decode($history->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['review'];
    verify(count($review['legal'])===1 && $review['accounting']!==null,'The earlier decisions did not survive.');
    $invoiceQuery->execute([$id]);
    verify($invoiceQuery->fetchAll()===$invoices,'Revision altered previously issued invoices.');
    echo "PASS: role denial, exact replay, archived decisions, no stale file reuse and no change to invoices.\n";
} finally {
    $db->rollBack();
}
