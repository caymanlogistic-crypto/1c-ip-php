<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,'FAIL: '.$error->getMessage().PHP_EOL);
    exit(1);
});

use App\{Auth, DB};
use Modules\Rental\{Documents, Evidence, LegalReview, Service, SourceCards, WorkflowEvents};

// Run only against a verified, separately restored local worktree copy.
$db=DB::pdo();
$name=$db->query('SELECT DATABASE()')->fetchColumn();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$name) || $receipt['database']!==$name
    || (int)$db->query('SELECT @@port')->fetchColumn()!==43061
    || str_replace('\\','/',$receipt['app_root'])!==str_replace('\\','/',APP_ROOT)
    || !$receipt['isolated_test_data'] || getenv('IPPHP_ALLOW_EXTERNAL') || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) {
    throw new RuntimeException('Verified isolated local database required.');
}
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function actor(string $role): void {
    $q=DB::pdo()->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');
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
    $c=record($id);$file=LegalReview::file($c);
    return ['id'=>$id,'version'=>$c['version'],'counterparty_confirmed'=>'1','financial_confirmed'=>'1',
        'document_confirmed'=>'1','legal_file_id'=>$file['id'] ?? ''];
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
    actor('lawyer');check(LegalReview::file($c)===null,'No historical file reuse');
    denied(fn()=>command('contract.legal',approveInput($id)),'Нужен проверенный актуальный файл');
    $file=Documents::produce($db,'contract',$id,(int)$c['version']);$files[]=$file['path'];
    $input=approveInput($id);
    $missing=$input;unset($missing['document_confirmed']);
    denied(fn()=>command('contract.legal',$missing),'Подтвердите проверку');
    foreach (['counterparty_confirmed','financial_confirmed'] as $field) {
        $missing=$input;unset($missing[$field]);denied(fn()=>command('contract.legal',$missing),'Подтвердите контрагента');
    }
    actor('manager');denied(fn()=>command('contract.legal',$input),'Недостаточно прав',403);
    actor('accountant');denied(fn()=>command('contract.legal',$input),'Недостаточно прав',403);
    actor('lawyer');
    $replacement=Documents::produce($db,'contract',$id,(int)$c['version']);$files[]=$replacement['path'];
    denied(fn()=>command('contract.legal',$input),'Файл договора изменился',409);
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
    $_GET=['id'=>$id];check(str_contains(SourceCards::show(),'Проверить и согласовать'),'Returned card action');
    check(LegalReview::file($c)===null,'Return requires current-version file');
    $file=Documents::produce($db,'contract',$id,(int)$c['version']);$files[]=$file['path'];
    $stale=approveInput($id);$stale['version']--;
    denied(fn()=>command('contract.legal',$stale),'Запись изменена другим сотрудником',409);
    $r=command('contract.legal',approveInput($id));check($r['status']==='accounting','Reapproval after accountant return');
    check(WorkflowEvents::latest($db,'contract',$id)['target_role']==='accountant','Accountant notification target');
    actor('accountant');command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Нужна правка менеджера']);
    actor('lawyer');command('contract.forward',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Уточнить срок']);
    denied(fn()=>command('contract.legal',approveInput($id)),'Договор не у юриста');
    actor('manager');command('contract.submit',['id'=>$id,'version'=>record($id)['version']]);
    actor('lawyer');denied(fn()=>command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'']),'Укажите причину');
    command('contract.return',['id'=>$id,'version'=>record($id)['version'],'reason'=>'Исправить условия']);
    check(record($id)['return_to']==='manager','Legal return recipient');
    check((int)$db->query('SELECT COUNT(*) FROM invoices WHERE contract_id='.$id)->fetchColumn()===0,'Legal approval never bills');
    echo "PASS: handoff, Word, required checks, role denial, file replacement, replay, accountant return/reapproval, manager return, event target, no invoices\n";
} finally {
    $db->rollBack();
    foreach ($files as $path) if (is_file($path)) unlink($path);
}
