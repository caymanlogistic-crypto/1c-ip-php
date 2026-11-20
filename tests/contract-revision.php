<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth,DB};
use Modules\Rental\{ContractRevision,FileOrder,LegalReview,NativePacket,Service};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function verify(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$db=DB::pdo();
$database=$db->query('SELECT DATABASE()')->fetchColumn();
$proof=json_decode(file_get_contents(getenv('APP_TEST_COPY_PROOF')?:APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
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
    $file=$db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
        SELECT ?,'contract',?,?,?,filename,path,sha256,mime FROM rental_files WHERE id=? AND entity_type='contract' AND entity_id=?");
    $archiveFile=$db->prepare("SELECT file_id FROM rental_approvals WHERE entity_type='contract' AND entity_id=? AND role='lawyer'");
    $archiveFile->execute([$id]);
    $template=$archiveFile->fetchColumn();
    verify(is_string($template),'Need a previously approved file.');
    $stage=static function(string $kind,array $record)use($file,$db,$id,$template): string {
        $idOfFile=bin2hex(random_bytes(16));
        $file->execute([$idOfFile,$id,$record['version'],$kind,$template,$id]);
        verify($file->rowCount()===1,'Could not stage an independent synthetic file.');
        FileOrder::assign($db,$idOfFile);
        return $idOfFile;
    };
    $staged=$stage('tenant-amendments',$contract);
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
    $firstArchive=$history->fetchColumn();
    $review=json_decode($firstArchive,true,512,JSON_THROW_ON_ERROR)['review'];
    verify(count($review['legal'])===1 && $review['accounting']['legal_file_id']===$template,'Revision A lost its accounting decision.');

    $confirmChecks=['counterparty_confirmed'=>1,'financial_confirmed'=>1,'document_confirmed'=>1];
    $legalApprove=static function(array $record)use($act,$stage,$id,$confirmChecks): string {
        $act('lawyer');
        $fileId=$stage('lawyer-file',$record);
        Service::command('contract.legal',['id'=>$id,'version'=>$record['version'],
            'command_key'=>bin2hex(random_bytes(16)),'legal_file_id'=>$fileId]+$confirmChecks);
        return $fileId;
    };
    $secondFile=$legalApprove($current);
    $second=Service::get($db,'contracts',$id);
    verify($second['status']==='accounting','Revision B did not reach accounting.');
    $act('manager');
    $thirdInput=array_replace($input,['version'=>$second['version'],'command_key'=>bin2hex(random_bytes(16)),
        'revision_files'=>['tenant-amendments'=>$stage('tenant-amendments',$second)]]);
    Service::command(ContractRevision::ACTION,$thirdInput);
    $history->execute([$id,$second['version']]);
    $review=json_decode($history->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['review'];
    verify($review['legal'][0]['file_id']===$secondFile && $review['accounting']===null,'Revision B inherited the accounting decision from A.');
    $history->execute([$id,$contract['version']]);
    verify($history->fetchColumn()===$firstArchive,'Revision A history changed.');

    $thirdFile=$legalApprove(Service::get($db,'contracts',$id));
    $act('accountant');
    $preview=NativePacket::preview($db,'contract',$id,Auth::user());
    verify($preview['view']['context']['file']['id']===$thirdFile,'Accountant did not receive revision C.');
    $result=Service::command('contract.packet',['id'=>$id,'version'=>$preview['record']['version'],
        'command_key'=>bin2hex(random_bytes(16)),'packet_confirmed'=>1,'expected_packet_hash'=>$preview['expected_packet_hash']]+$confirmChecks);
    verify($result['status']==='approved','Revision C could not be approved.');
    $proofAfter=NativePacket::current($db,'contract',$id,Auth::user(),'prepare');
    verify($proofAfter['state']==='confirmed','Revision C approval proof is unavailable.');
    $invoiceQuery->execute([$id]);
    verify($invoiceQuery->fetchAll()===$invoices,'Revision altered previously issued invoices.');
    echo "PASS: A retained its accounting decision, B had none, C received its own approval; roles, replay and invoices verified.\n";
} finally {
    $db->rollBack();
}
