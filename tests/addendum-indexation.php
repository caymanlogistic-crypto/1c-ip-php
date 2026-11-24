<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{AddendumController,AddendumIndexation,Availability,DocumentCreator,Occupancy,Service};

$db=DB::pdo();
if (PHP_SAPI!=='cli' || getenv('APP_CONTRACT_DRAFT_TEST')!=='1'
    || !preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) {
    throw new RuntimeException('Explicit isolated task DB required');
}

function check(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function payload(string $html): array
{
    $document=new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath=new DOMXPath($document);
    $pairs=[];
    foreach ($xpath->query('//form[@id="rental-addendum-form"]//input[@name][not(ancestor::template)]') as $input) {
        $pairs[]=urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
    }
    parse_str(implode('&',$pairs),$result);
    $result['number']='LOCAL-'.bin2hex(random_bytes(6));
    $result['intent']='draft';
    unset($result['_csrf']);
    return $result;
}

$db->beginTransaction();
try {
    $user=$db->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
    $today=date('Y-m-d');
    $from=date('Y-m-d',strtotime('-5 days'));
    $later=date('Y-m-d',strtotime('+1 week'));
    $until=date('Y-m-d',strtotime('+1 month'));
    $available=array_column(array_filter(Availability::items($from,$until),static fn(array $room): bool => $room['available']),'id');
    check(count($available)>=3,'Need three available fixture rooms');
    [$first,$second,$third]=$available;
    $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
    $cid=(int)$db->query('SELECT COALESCE(MAX(id),900000)+1 FROM contracts')->fetchColumn();
    $party=(int)$db->query('SELECT id FROM parties WHERE blocked=0 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,signed,source_json)
            VALUES(?,?,?,CURRENT_DATE,?,?,'350.40','active',1,'{}')")
        ->execute([$cid,'LOCAL-BASE-'.bin2hex(random_bytes(6)),$party,$from,$until]);
    $term=$db->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json) VALUES(?,?,?,?,?,?,'{}')");
    foreach ([$first=>'100.05',$second=>'250.35'] as $room=>$amount) {
        Occupancy::insert($db,'contract_objects',['contract_id'=>$cid,'object_id'=>$room]);
        $term->execute([$cid,$room,$from,$until,$amount,$from]);
    }
    $term->execute([$cid,$first,$from,$until,'500.55',$later]);
    $base=Service::get($db,'contracts',$cid);
    $_GET=['contract_id'=>$cid];
    check(str_contains(AddendumController::editor(),'Прежний состав помещений'),'Direct entry must show the scope choice');
    $_GET=['documentType'=>'addendum','baseContract'=>$cid,'object_scope'=>'same','indexation'=>'1','index_percent'=>'10'];
    $in=payload(DocumentCreator::next());
    check($in['term_amount']===[$first=>'110.06',$second=>'275.39'] && $in['amount']==='385.45',
        'Ten percent must use the valid base rate and round each term before adding');
    $futureDate=date('Y-m-d',strtotime('+8 days'));
    $future=AddendumIndexation::quote($db,$base,$futureDate,'10');
    check($future['rates'][$first]===['base'=>'500.55','amount'=>'550.61'],'Explicit later date must use the rate then in force');

    $db->exec('SAVEPOINT missing_rate');
    $db->prepare('UPDATE contract_terms SET amount=NULL WHERE contract_id=? AND object_id=? AND effective_date=?')
        ->execute([$cid,$first,$from]);
    try { AddendumIndexation::quote($db,$base,$today,'10'); throw new LogicException('Missing base rate was accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(),'Уточните исходную месячную ставку'),'Expected missing-rate warning'); }
    $db->exec('ROLLBACK TO SAVEPOINT missing_rate');

    $wrong=$in;
    $wrong['term_amount'][$first]='110.07';
    $wrong['amount']='385.46';
    try { Service::command('document.create',$wrong); throw new LogicException('Invalid rate was accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(),'Расчёт индексации изменился'),'Expected recalculation warning'); }
    $missing=$in;
    $missing['objects']=[$second];
    try { Service::command('document.create',$missing); throw new LogicException('Changed scope was accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(),'Для прежнего состава'),'Expected changed-scope warning'); }

    $created=Service::command('document.create',$in);
    $id=(int)$created['id'];
    check(Service::command('document.create',$in)===$created,'Retry must return the same draft');
    $_GET=['id'=>$id];
    $saved=payload(AddendumController::editor());
    check($saved['index_percent']==='10' && $saved['term_amount']===$in['term_amount'],'Reopened draft must preserve its rate and price');
    $edited=$saved;
    $edited['index_percent']='13,5';
    $edited['term_amount']=[$first=>'113.56',$second=>'284.15'];
    $edited['amount']='397.71';
    Service::command('document.edit',$edited);
    $_GET=['id'=>$id];
    $saved=payload(AddendumController::editor());
    check($saved['index_percent']==='13.5' && $saved['term_amount']===$edited['term_amount'],'New percent must use the original rates');

    Service::command('document.cancel',['id'=>$id,'version'=>$saved['version'],'reason'=>'Тест','command_key'=>bin2hex(random_bytes(16))]);
    $_GET=['documentType'=>'addendum','baseContract'=>$cid,'object_scope'=>'same'];
    $unindexed=payload(DocumentCreator::next());
    check(!isset($unindexed['indexation']) && $unindexed['object_scope']==='same','Without indexation must keep the regular price editor');
    $result=Service::command('document.create',$unindexed);
    $regular=Service::get($db,'rental_documents',(int)$result['id']);
    check($regular['amount']===$unindexed['amount'],'Regular renewal must save its submitted prices');
    Service::command('document.cancel',['id'=>$regular['id'],'version'=>$regular['version'],'reason'=>'Тест','command_key'=>bin2hex(random_bytes(16))]);

    $_GET=['documentType'=>'addendum','baseContract'=>$cid,'object_scope'=>'change'];
    $html=DocumentCreator::next();
    check(str_contains($html,'data-rental-picker="room"'),'Changed scope must keep the usual room picker');
    $changed=payload($html);
    $changed['objects']=[$second,$third];
    $changed['term_amount']=[$second=>'250.35',$third=>'99.65'];
    $changed['amount']='350.00';
    $result=Service::command('document.create',$changed);
    $draft=Service::get($db,'rental_documents',(int)$result['id']);
    check($draft['amount']==='350.00','Changed composition must save its submitted prices');
    check(Service::get($db,'contracts',$cid)===$base,'Creating or editing a draft must leave the active contract unchanged');
    echo "PASS: 10%, 13.5%, rounding, dates, same/change scope, tampering, reopen and idempotency.\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().' at '.$error->getLine().PHP_EOL);
    $failed=true;
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
if (!empty($failed)) exit(1);
