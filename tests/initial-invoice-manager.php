<?php
declare(strict_types=1);

use App\{Auth, DB};
use Modules\Rental\{InitialInvoice, InitialInvoiceCard, PartyLegalReview, Service};

require dirname(__DIR__).'/app/bootstrap.php';

set_exception_handler(static function(Throwable $error): never {
    fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});

$db=DB::pdo();
$proof=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
$name=$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$name)
    || $proof['database']!==$name || empty($proof['isolated_test_data'])
    || str_replace('\\','/',$proof['app_root'])!==str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL') || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) {
    throw new RuntimeException('Verified isolated local database required.');
}

function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function actor(string $kind): void {
    $q=DB::pdo()->prepare('SELECT u.id,u.auth_version FROM users u JOIN role_profiles rp ON rp.role_id=u.role WHERE rp.kind=? AND u.active=1 ORDER BY u.id LIMIT 1');
    $q->execute([$kind]);
    $user=$q->fetch();
    check((bool)$user,'Missing active test user for '.$kind);
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
}

function command(string $action,array $input): array {
    return Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);
}

function expectDenied(callable $action,string $message): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(),$message),'Unexpected error: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

$returned=in_array('--returned',$argv,true);
$files=[];
$db->beginTransaction();
try {
    actor('manager');
    check(!Auth::can('finance.write'),'Manager has finance.write in this fixture');
    $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE');
    $party=(int)$db->query('SELECT MAX(id)+1 FROM parties')->fetchColumn();
    $object=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();
    $cadastre=(int)$db->query('SELECT MAX(id)+1 FROM cadastre')->fetchColumn();
    $label='Тест первого счёта менеджером';
    $db->prepare("INSERT INTO parties(id,name,inn,address,source_json) VALUES(?,?,'7701234560','Учебный адрес','{}')")->execute([$party,$label]);
    $db->prepare("INSERT INTO cadastre(id,number,area,type,source_json) VALUES(?,?,20,'Офис','{}')")->execute([$cadastre,'77:01:000000:'.$cadastre]);
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) VALUES(?,?, 'Офис',20,?,'{}')")->execute([$object,$label,$cadastre]);
    $start=new DateTimeImmutable('first day of next month');
    $id=(int)command('contract.create',['party_id'=>$party,'start_date'=>$start->format('Y-m').'-16','end_date'=>$start->modify('+3 months')->format('Y-m-t'),
        'document_date'=>date('Y-m-d'),'amount'=>'100000.00','objects'=>[$object],'term_amount'=>[$object=>'100000.00'],'intent'=>'submit'])['id'];
    $contract=static fn()=>Service::get($db,'contracts',$id);
    $partyInput=static fn()=>['id'=>$party,'version'=>Service::get($db,'parties',$party)['version']];
    $contractInput=static fn()=>['id'=>$id,'version'=>$contract()['version']];
    $issued=max(date('Y-m-d'),$contract()['document_date'] ?? '');
    check($contract()['status']==='legal' && !$contract()['signed'],'Expected unsigned project at lawyer stage');
    check(InitialInvoice::can($contract(),InitialInvoice::ISSUE),'Manager must have access to the narrow issue action');
    check(!InitialInvoice::can($contract(),InitialInvoice::DUE),'Manager cannot set the due date');
    check(!InitialInvoice::preview($db,$contract(),$issued)['rows'],'Missing legal terms block the plan');

    $filename='company-card-'.bin2hex(random_bytes(8)).'.pdf';
    $folder=APP_ROOT.'/.local/documents';
    if (!is_dir($folder)) mkdir($folder,0700,true);
    $path=$folder.'/'.$filename;
    $files[]=$path;
    file_put_contents($path,"%PDF-1.4\n% Local legal-review evidence\n%%EOF\n");
    $fileId=bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,'party',?,?,'party-source',?,?,?,'application/pdf')")
        ->execute([$fileId,$party,$partyInput()['version'],'Учебная карточка предприятия.pdf',$path,hash_file('sha256',$path)]);

    actor('lawyer');
    $terms=$contractInput()+['lease_form'=>'D17','multiple'=>'3','confirmed'=>'1','reason'=>'Учебный договор, три месячные ставки'];
    expectDenied(fn()=>command(InitialInvoice::TERMS,array_replace($terms,['multiple'=>'-1'])),'кратность');
    expectDenied(fn()=>command(InitialInvoice::TERMS,array_replace($terms,['multiple'=>'1.5'])),'кратность');
    command(InitialInvoice::TERMS,$terms);

    actor('manager');
    $plan=InitialInvoice::preview($db,$contract(),$issued);
    check(count($plan['rows'])===3,'Expected rent and security: '.implode(' ',$plan['questions']));
    check(array_column($plan['rows'],'amount')[2]==='300000.00','Three monthly rates must yield 300000.00');
    $issue=$contractInput()+['issued'=>$issued,'plan_token'=>$plan['token'],'confirmed'=>'1','command_key'=>bin2hex(random_bytes(16))];
    expectDenied(fn()=>Service::command(InitialInvoice::ISSUE,$issue),'Юрист должен отдельно согласовать');
    $_GET=['id'=>$id,'issued'=>$issued];
    $page=InitialInvoiceCard::card();
    check(str_contains($page,'Юрист должен отдельно согласовать') && !str_contains($page,'Зарегистрировать первый счёт'),'Blocked card should explain the missing approval');

    command(PartyLegalReview::SUBMIT,$partyInput()+['file_id'=>$fileId]);
    expectDenied(fn()=>Service::command(InitialInvoice::ISSUE,$issue),'Юрист должен отдельно согласовать');
    actor('lawyer');
    expectDenied(fn()=>Service::command(InitialInvoice::ISSUE,$issue),'Недостаточно прав');
    command(PartyLegalReview::ACCEPT,$partyInput()+['file_id'=>$fileId,'verified'=>'1']);
    if ($returned) {
        command('contract.return',$contractInput()+['reason'=>'Уточнить условия подписания']);
        $issue['version']=$contract()['version'];
        $issue['plan_token']=InitialInvoice::preview($db,$contract(),$issued)['token'];
    }

    actor('manager');
    $db->prepare("UPDATE parties SET email='different@example.invalid' WHERE id=?")->execute([$party]);
    expectDenied(fn()=>Service::command(InitialInvoice::ISSUE,$issue),'Юрист должен отдельно согласовать');
    $db->prepare("UPDATE parties SET email=NULL WHERE id=?")->execute([$party]);
    $original=file_get_contents($path);
    file_put_contents($path,'changed file');
    expectDenied(fn()=>Service::command(InitialInvoice::ISSUE,$issue),'целостности документа');
    file_put_contents($path,$original);
    check(!InitialInvoice::issuedFor($db,$id),'Rejected commands must not create invoices');
    check(str_contains(InitialInvoiceCard::card(),'Зарегистрировать первый счёт'),'Manager registration button absent after valid approval');

    $result=Service::command(InitialInvoice::ISSUE,$issue);
    check(count($result['invoice_ids'])===3,'Expected three initial invoices');
    check(Service::command(InitialInvoice::ISSUE,$issue)['invoice_ids']===$result['invoice_ids'],'Exact command replay should return the original invoices');
    $issue['command_key']=bin2hex(random_bytes(16));
    check(Service::command(InitialInvoice::ISSUE,$issue)['invoice_ids']===$result['invoice_ids'],'New click must return existing invoices');
    check(count(InitialInvoice::issuedFor($db,$id))===3,'No duplicate invoices');
    $new=$contract();
    check($new['status']===($returned?'returned':'legal') && !$new['signed'],'Billing must preserve the unsigned agreement stage');
    check(!str_contains(InitialInvoiceCard::card(),'Зарегистрировать первый счёт'),'Hide registration after success');
    echo 'PASS manager issue with legal approval, denied roles and stale evidence, dates/security, idempotency',PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
        foreach ($files as $path) if (is_file($path)) unlink($path);
    }
}
