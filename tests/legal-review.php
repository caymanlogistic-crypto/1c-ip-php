<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,'FAIL: '.$error->getMessage().PHP_EOL);
    exit(1);
});

use App\{Auth, DB};
use Modules\Rental\{Documents, FileOrder, Importer, LegalHandoff, LegalReview, PartyLegalReview, Service, SourceCards, WorkflowEvents};

// Run only against a verified, separately restored local worktree copy.
$db=DB::pdo();
$name=$db->query('SELECT DATABASE()')->fetchColumn();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$name) || $receipt['database']!==$name
    || realpath($receipt['app_root'])!==realpath(APP_ROOT)
    || !$receipt['isolated_test_data'] || getenv('IPPHP_ALLOW_EXTERNAL') || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) {
    throw new RuntimeException('Verified isolated local database required.');
}
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function actor(string $role): void {
    $q=DB::pdo()->prepare('SELECT u.id,u.auth_version FROM users u JOIN role_profiles p ON p.role_id=u.role WHERE p.kind=? AND u.active=1 ORDER BY u.id LIMIT 1');
    $q->execute([$role]);$user=$q->fetch();
    check((bool)$user,'Missing test role '.$role);
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
}
function command(string $action,array $input): array {
    return Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);
}
function denied(callable $fn,string $message,?int $code=null): void {
    try { $fn(); } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(),$message),'Unexpected rejection: '.$e->getMessage());
        if ($code!==null) check($e->getCode()===$code,'Wrong rejection status');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}
function record(int $id): array { return Service::get(DB::pdo(),'contracts',$id); }
function approveInput(int $id): array {
    $c=record($id);
    return ['id'=>$id,'version'=>$c['version'],'packet_fingerprint'=>LegalHandoff::fingerprint(LegalHandoff::files(DB::pdo(),$c))];
}
function stageFiles(PDO $db,array $contract,int $amount,array &$paths): void {
    $data=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='legal_handoff_draft' AND source_key=?");
    $data->execute([$contract['id']]);$draft=Importer::decodeExact($data->fetchColumn());
    $draft['version']=(int)$contract['version'];$draft['files']=[];
    for ($i=0;$i<$amount;$i++) {
        $id=bin2hex(random_bytes(16));$name='test-'.$id.'.pdf';$path=Documents::directory().'/'.$name;
        $paths[]=$path;
        file_put_contents($path,"%PDF-1.4\n% synthetic file ".$i."\n%%EOF\n");
        $hash=hash_file('sha256',$path);
        $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
            VALUES(?,'contract',?,?,'lawyer-file',?,?,?,'application/pdf')")
            ->execute([$id,$contract['id'],$contract['version'],$name,$name,$hash]);
        FileOrder::assign($db,$id);
        $draft['files'][]=['id'=>$id,'filename'=>$name,'sha256'=>$hash];
    }
    $db->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='legal_handoff_draft' AND source_key=?")
        ->execute([Importer::json($draft),$contract['id']]);
}
function fixture(): int {
    $db=DB::pdo();actor('manager');
    $pid=(int)$db->query('SELECT MAX(id)+1 FROM parties')->fetchColumn();
    $oid=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();
    $cid=(int)$db->query('SELECT MAX(id)+1 FROM cadastre')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,inn,address,source_json) VALUES(?,'Тест согласования договоров','7701234560','Тестовый адрес','{}')")->execute([$pid]);
    $db->prepare("INSERT INTO cadastre(id,number,area,type,source_json) VALUES(?,'77:01:000000:999999',20,'Офис','{}')")->execute([$cid]);
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) VALUES(?,'Тестовое помещение юриста','Офис',20,?,'{}')")->execute([$oid,$cid]);
    $r=command('contract.create',['party_id'=>$pid,'start_date'=>'2026-12-01','end_date'=>'2027-11-30',
        'amount'=>'10000.00','objects'=>[$oid],'term_amount'=>[$oid=>'10000.00'],'intent'=>'submit']);
    return (int)$r['id'];
}
$files=[];$db->beginTransaction();
try {
    $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE');
    $id=fixture();$c=record($id);check($c['status']==='legal','Manager handoff');
    $party=Service::get($db,'parties',(int)$c['party_id']);
    check(PartyLegalReview::evidenceLink($party)==='','A missing attachment has no download link');
    actor('lawyer');
    check(!LegalHandoff::files($db,$c),'No historical file reuse');
    denied(fn()=>command('contract.legal',approveInput($id)),'подтвердите контрагента',409);
    command(LegalHandoff::PARTY,['id'=>$id,'version'=>$c['version']]);
    command(LegalHandoff::TERMS,['id'=>$id,'version'=>$c['version']]);
    denied(fn()=>command('contract.legal',approveInput($id)),'Загрузите итоговый файл',422);
    stageFiles($db,$c,2,$files);
    $input=approveInput($id);
    actor('manager');denied(fn()=>command('contract.legal',$input),'Недостаточно прав',403);
    actor('accountant');denied(fn()=>command('contract.legal',$input),'Недостаточно прав',403);
    actor('lawyer');stageFiles($db,$c,1,$files);
    denied(fn()=>command('contract.legal',$input),'Комплект изменился',409);
    $input=approveInput($id);$input['command_key']=bin2hex(random_bytes(16));
    $r=Service::command('contract.legal',$input);check($r['status']==='accounting','Legal handoff');
    $count=(int)$db->query('SELECT COUNT(*) FROM rental_events')->fetchColumn();
    check(Service::command('contract.legal',$input)===$r,'Idempotent receipt');
    check((int)$db->query('SELECT COUNT(*) FROM rental_events')->fetchColumn()===$count,'No duplicate event');
    denied(fn()=>command('contract.legal',approveInput($id)),'Договор не у юриста');
    actor('accountant');command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Уточнить текст оплаты']);
    actor('lawyer');$c=record($id);check(LegalReview::assigned($c),'Returned to lawyer');
    actor('manager');denied(fn()=>command('contract.edit',['id'=>$id,'version'=>$c['version'],'number'=>$c['number']]),'Исправление назначено юристу',409);
    actor('lawyer');
    $_GET=['id'=>$id];check(str_contains(SourceCards::show(),'Уточнить текст оплаты'),'Returned card action');
    check(LegalHandoff::ready($db,$c),'The two confirmed checks survive an accounting return with unchanged data');
    check(!LegalHandoff::files($db,$c),'Return requires current-version files');
    stageFiles($db,$c,2,$files);
    $stale=approveInput($id);$stale['version']--;
    denied(fn()=>command('contract.legal',$stale),'Запись изменена другим сотрудником',409);
    $r=command('contract.legal',approveInput($id));check($r['status']==='accounting','Reapproval after accountant return');
    $history=LegalHandoff::history($db,$id);
    check(count($history)===2 && count($history[0]['files'])===2 && count($history[1]['files'])===1,
        'Current and prior accounting handoffs are separate');
    check(WorkflowEvents::latest($db,'contract',$id)['target_role']==='accountant','Accountant notification target');
    actor('accountant');command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Нужна правка менеджера']);
    actor('lawyer');command('contract.forward',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Уточнить срок']);
    denied(fn()=>command('contract.legal',approveInput($id)),'Договор не у юриста');
    actor('manager');command('contract.submit',['id'=>$id,'version'=>record($id)['version']]);
    actor('lawyer');denied(fn()=>command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'']),'Укажите причину');
    command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Исправить условия']);
    check(record($id)['return_to']==='manager','Legal return recipient');
    check((int)$db->query('SELECT COUNT(*) FROM invoices WHERE contract_id='.$id)->fetchColumn()===0,'Legal approval never bills');
    actor('manager');
    $prior=record($id);
    $object=(int)$db->query("SELECT object_id FROM contract_objects WHERE contract_id=".$id." LIMIT 1")->fetchColumn();
    $training=['party_id'=>$prior['party_id'],'start_date'=>'2028-01-01','end_date'=>'2028-01-31',
        'amount'=>'10000.00','objects'=>[$object],'term_amount'=>[$object=>'10000.00'],'intent'=>'submit'];
    $deny=command('contract.create',$training)['id'];
    $before=$training;
    $before['start_date']='2028-03-01';$before['end_date']='2028-03-31';
    $existing=command('contract.create',$before)['id'];
    actor('lawyer');
    denied(fn()=>command(LegalHandoff::BLOCK,['id'=>$deny,'version'=>1,'reason'=>'']),'Укажите причину');
    command(LegalHandoff::BLOCK,['id'=>$deny,'version'=>1,'reason'=>'Учебный отказ']);
    check(record($deny)['return_to']==='manager' && record($existing)['status']==='legal',
        'Only the selected review is returned, existing review continues');
    command(LegalHandoff::PARTY,['id'=>$existing,'version'=>1]);
    actor('manager');
    denied(fn()=>command('contract.create',$training),'заблокирована');
    echo "PASS: handoff, packet, role denial, file replacement, replay, accountant return, new-contract block, no invoices\n";
} finally {
    $db->rollBack();
    foreach ($files as $path) if (is_file($path)) unlink($path);
}
