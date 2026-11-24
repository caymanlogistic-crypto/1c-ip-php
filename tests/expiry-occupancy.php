<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Availability,DirectoryScope,ExpiryContinuation,Importer,Occupancy,Service};

$db=DB::pdo();
$schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
$copy=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$schema)
    || $db->query('SELECT CURRENT_USER()')->fetchColumn()!==$schema.'@127.0.0.1'
    || ($copy['database'] ?? '')!==$schema || empty($copy['isolated_test_data'])
    || str_replace('\\','/',$copy['app_root'])!==str_replace('\\','/',APP_ROOT) || getenv('IPPHP_ALLOW_EXTERNAL'))
    throw new RuntimeException('Verified isolated task DB required.');

function expiryCheck(bool $value,string $reason): void
{
    if (!$value) throw new RuntimeException($reason);
}

$db->beginTransaction();
try {
    $current=$db->query('SELECT CURRENT_DATE')->fetchColumn();
    $now=new DateTimeImmutable($current);
    $yesterday=$now->modify('-1 day')->format('Y-m-d');
    $earlier=$now->modify('-1 month')->format('Y-m-d');
    $later=$now->modify('+1 month')->format('Y-m-d');
    $party=(int)$db->query('SELECT MAX(id)+1 FROM parties')->fetchColumn();
    $object=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();
    $contract=(int)$db->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();
    $parents=$db->query('SELECT id,number,source_json FROM cadastre WHERE rentable=1 ORDER BY id')->fetchAll();
    $cadastre=array_values(array_filter($parents,DirectoryScope::realCadastre(...)))[0]['id'];
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Тест истечения','{}')")->execute([$party]);
    $db->prepare("INSERT INTO rental_objects(id,name,type,cadastre_id,active,source_json) VALUES(?,'Тест истечения','Офис',?,1,'{}')")
        ->execute([$object,$cadastre]);
    $put=$db->prepare("INSERT INTO contracts(id,number,party_id,start_date,end_date,amount,status,signed,source_json)
        VALUES(?,?,?,?,?,?,'active',1,'{}')");
    $relation=$db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)');
    $term=$db->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,effective_date,amount,source_json)
        VALUES(?,?,?,?,?,?,'{}')");
    $put->execute([$contract,'TEST-OLD-'.$contract,$party,$earlier,$yesterday,'50000.00']);
    $relation->execute([$contract,$object]);
    $term->execute([$contract,$object,$earlier,$yesterday,$earlier,'50000.00']);
    $available=static fn(): array=>array_column(Availability::items($current,$later),null,'id')[$object];
    expiryCheck(!$available()['available'] && str_contains($available()['description'],'Ожидается акт возврата'),'expired object must remain occupied');
    expiryCheck((int)Occupancy::leasesOn($current,$object)[$object]['id']===$contract,'expired original remains visible');
    try {
        Service::available($db,[$object],$current,$later);
        throw new RuntimeException('repeat rental was not blocked');
    } catch (RuntimeException $e) {
        expiryCheck($e->getCode()===409 && str_contains($e->getMessage(),'Срок прежней аренды истёк'),'write guard must block the repeat lease');
    }
    $next=$contract+1;
    $put->execute([$next,'TEST-NEXT-'.$contract,$party,$current,$later,'53500.00']);
    $relation->execute([$next,$object]);
    $term->execute([$next,$object,$current,$later,$current,'53500.00']);
    $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
        ->execute([ExpiryContinuation::NS,$contract.':'.$yesterday,Importer::json(['kind'=>'contract','id'=>$next])]);
    expiryCheck((int)Occupancy::leasesOn($yesterday,$object)[$object]['id']===$contract,'old lease on last day');
    expiryCheck((int)Occupancy::leasesOn($current,$object)[$object]['id']===$next,'successor begins on first day');
    expiryCheck(!$available()['available'],'no day of vacancy after the confirmed handover');

    $rateChange=$now->modify('+10 days')->format('Y-m-d');
    $beforeRateChange=$now->modify('+9 days')->format('Y-m-d');
    $db->prepare('UPDATE contract_terms SET superseded_on=? WHERE contract_id=? AND object_id=?')->execute([$beforeRateChange,$next,$object]);
    $term->execute([$next,$object,$rateChange,$later,$rateChange,'56000.00']);
    foreach ([[$current,$current],[$beforeRateChange,$rateChange],[$rateChange,$rateChange]] as [$from,$through]) {
        $item=array_column(Availability::items($from,$through),null,'id')[$object];
        expiryCheck(!$item['available'] && $item['disabled'],'rate revision must not free the object');
        expiryCheck((int)Occupancy::leasesOn($from,$object)[$object]['id']===$next,'daily contract matches availability');
        try {
            Service::available($db,[$object],$from,$through);
            throw new RuntimeException('overlapping rental was not blocked');
        } catch (RuntimeException $e) {
            expiryCheck($e->getMessage()==='Объект занят договором на выбранный период.','write guard must block overlapping rental');
        }
        Service::available($db,[$object],$from,$through,$next,null,false,null,false,$contract);
    }
    echo "PASS expired active lease lock, exact old and new daily occupancy; fixtures rolled back\n";
} finally {
    $db->rollBack();
}
