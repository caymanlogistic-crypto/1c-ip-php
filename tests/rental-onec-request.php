<?php
declare(strict_types=1);

use App\{Auth, DB};
use Modules\Integrations\CurrentContractMaxProof;
use Modules\Rental\{InitialInvoice, OnecRequest, PartyLegalReview, Service, WorkflowEvents};

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
    || getenv('IPPHP_ALLOW_EXTERNAL') || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'))
    throw new RuntimeException('Verified isolated local database required.');

function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function actor(string $kind): array {
    $q=DB::pdo()->prepare('SELECT u.id,u.auth_version FROM users u JOIN role_profiles rp ON rp.role_id=u.role WHERE rp.kind=? AND u.active=1 ORDER BY u.id LIMIT 1');
    $q->execute([$kind]);
    $user=$q->fetch();
    check((bool)$user,'Missing active user for '.$kind);
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
    return Auth::user();
}

function runCommand(string $action,array $input): array {
    return Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);
}

function reject(callable $action,int $status): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        check($error->getCode()===$status,'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected a rejection.');
}

$files=[];
$db->beginTransaction();
try {
    actor('manager');
    $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE');
    $party=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM parties')->fetchColumn();
    $object=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM rental_objects')->fetchColumn();
    $cadastre=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM cadastre')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,inn,address,source_json) VALUES(?,'Учебный клиент для 1С','7701234560','Учебный адрес','{}')")->execute([$party]);
    $db->prepare("INSERT INTO cadastre(id,number,area,type,source_json) VALUES(?,?,20,'Офис','{}')")->execute([$cadastre,'77:01:000000:'.$cadastre]);
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) VALUES(?,'Учебный офис для 1С','Офис',20,?,'{}')")->execute([$object,$cadastre]);
    $today=date('Y-m-d');
    $start=new DateTimeImmutable('first day of next month');
    $contractId=(int)runCommand('contract.create',['party_id'=>$party,'document_date'=>$today,'start_date'=>$start->format('Y-m').'-16',
        'end_date'=>$start->modify('+2 months')->format('Y-m-t'),'amount'=>'100000.00','objects'=>[$object],
        'term_amount'=>[$object=>'100000.00'],'intent'=>'submit'])['id'];
    $partyInput=static fn()=>['id'=>$party,'version'=>Service::get($db,'parties',$party)['version']];
    $contract=static fn()=>Service::get($db,'contracts',$contractId);

    $attach=static function(string $entity,int $id,int $version,string $kind,string $name) use ($db,&$files): string {
        $folder=APP_ROOT.'/.local/documents';
        if (!is_dir($folder)) mkdir($folder,0700,true);
        $fid=bin2hex(random_bytes(16));
        $path=$folder.'/'.$fid.'.pdf';
        $files[]=$path;
        file_put_contents($path,"%PDF-1.4\n% Synthetic fixture\n%%EOF\n");
        $db->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$fid,$entity,$id,$version,$kind,$name,$path,hash_file('sha256',$path),'application/pdf']);
        return $fid;
    };

    $partyFile=$attach('party',$party,$partyInput()['version'],'party-source','Карточка предприятия.pdf');
    runCommand(PartyLegalReview::SUBMIT,$partyInput()+['file_id'=>$partyFile]);
    actor('lawyer');
    runCommand(PartyLegalReview::ACCEPT,$partyInput()+['file_id'=>$partyFile,'verified'=>'1']);
    $attach('contract',$contractId,(int)$contract()['version'],'lawyer-file','Учебный проект договора.pdf');
    runCommand(InitialInvoice::TERMS,['id'=>$contractId,'version'=>$contract()['version'],'lease_form'=>'D17','multiple'=>'1',
        'confirmed'=>'1','reason'=>'Учебный договор']);
    $reviewer=actor('accountant');
    actor('manager');
    $snapshot=OnecRequest::snapshot($db,$contract(),$today);
    check(count($snapshot['documents'])===2 && count($snapshot['invoices'])===3,'Missing documents or charges');
    $submission=['id'=>$contractId,'version'=>0,'reviewer_id'=>$reviewer['id'],'issued'=>$today,'snapshot_sha256'=>OnecRequest::hash($snapshot)];
    reject(fn()=>runCommand(OnecRequest::SUBMIT,array_replace($submission,['snapshot_sha256'=>'wrong'])),409);
    $key=bin2hex(random_bytes(16));
    $result=Service::command(OnecRequest::SUBMIT,$submission+['command_key'=>$key]);
    check(Service::command(OnecRequest::SUBMIT,$submission+['command_key'=>$key])['request_version']===$result['request_version'],'Duplicate submit');
    reject(fn()=>runCommand(OnecRequest::SUBMIT,array_replace($submission,['version'=>1])),409);
    $approval=['id'=>$contractId,'version'=>1,'checked'=>'1'];
    reject(fn()=>runCommand(OnecRequest::APPROVE,$approval),403);
    $q=$db->prepare('SELECT id,source_json FROM rental_events WHERE contract_id=? AND action=?');
    $q->execute([$contractId,OnecRequest::SUBMIT]);
    $events=$q->fetchAll();
    check(count($events)===1,'Submit must make one event');
    $event=WorkflowEvents::decode($events[0]);
    check($event['staff_handoff']['targets'][0]['id']===(int)$reviewer['id'],'MAX addressed to wrong accountant');
    check(str_contains(CurrentContractMaxProof::staffValues($event)['text'],OnecRequest::route($contractId)),'MAX opens the wrong request');

    actor('accountant');
    reject(fn()=>runCommand(OnecRequest::APPROVE,array_replace($approval,['checked'=>'0'])),422);
    $db->prepare("UPDATE parties SET email='changed@example.invalid' WHERE id=?")->execute([$party]);
    reject(fn()=>runCommand(OnecRequest::APPROVE,$approval),409);
    runCommand(OnecRequest::RETURN,$approval+['reason'=>'Сверить реквизиты']);
    check(OnecRequest::read($db,$contractId)['state']==='returned','Return not saved');
    $db->prepare("UPDATE parties SET email=NULL WHERE id=?")->execute([$party]);

    actor('manager');
    $resubmitted=runCommand(OnecRequest::SUBMIT,array_replace($submission,['version'=>2]));
    actor('accountant');
    runCommand(OnecRequest::APPROVE,array_replace($approval,['version'=>$resubmitted['request_version']]));
    check(OnecRequest::read($db,$contractId)['state']==='approved','Approval not saved');
    reject(fn()=>runCommand(OnecRequest::APPROVE,array_replace($approval,['version'=>4])),409);
    check(!$contract()['signed'] && $contract()['status']==='legal' && !InitialInvoice::issuedFor($db,$contractId),
        'The approval must preserve the unsigned project and create no invoices');
    echo 'PASS submit, review, return, stale requisites, rights, deduplication and MAX request link',PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
        foreach ($files as $path) if (is_file($path)) unlink($path);
    }
}
