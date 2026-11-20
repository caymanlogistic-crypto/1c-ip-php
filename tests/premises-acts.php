<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Evidence,FileOrder,PremisesActs,PremisesInventory,Service};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().' @ '.$error->getLine().PHP_EOL);
    exit(1);
});

$db=DB::pdo();
$connection=$db->query('SELECT DATABASE() db,CURRENT_USER() account')->fetch();
$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$connection['db'])
    || $connection['account']!==$connection['db'].'@127.0.0.1' || $receipt['database']!==$connection['db']
    || empty($receipt['isolated_test_data']) || str_replace('\\','/',$receipt['app_root'])!==str_replace('\\','/',APP_ROOT)
    || getenv('IPPHP_ALLOW_EXTERNAL')) throw new RuntimeException('Verified isolated task DB required.');

function verify(bool $ok,string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function actor(PDO $db,string $role): void
{
    $q=$db->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');
    $q->execute([$role]);
    $user=$q->fetch();
    verify((bool)$user,'Test role required.');
    $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['auth_version']];
}
function refuse(callable $call,int $code): void
{
    try {$call();} catch (RuntimeException $error) {
        if ($error->getCode()!==$code) throw $error;
        return;
    }
    throw new RuntimeException('Expected refusal '.$code);
}
function key19(): string
{
    return bin2hex(random_bytes(16));
}

$files=[];
$db->beginTransaction();
try {
    $newId=static fn(string $table):int=>(int)$db->query('SELECT COALESCE(MAX(id),900000)+1 FROM '.$table)->fetchColumn();
    $party=$newId('parties');$office=$newId('rental_objects');$destination=$office+1;$contract=$newId('contracts');
    $addendum=$newId('rental_documents');$cadastre=$newId('cadastre');
    $from=date('Y-m-d',strtotime('-5 days'));$today=date('Y-m-d');$end=date('Y-m-d',strtotime('+60 days'));
    $db->prepare("INSERT INTO parties(id,name,source_json) VALUES(?,'Учебный контрагент акта','{}')")->execute([$party]);
    foreach ([$office,$destination] as $id) {
        $db->prepare("INSERT INTO cadastre(id,number,area,floor,type,source_json) VALUES(?,?,30,'2','Офис','{}')")
            ->execute([$cadastre,'77:01:000000:'.$cadastre]);
        $db->prepare("INSERT INTO rental_objects(id,name,type,area,floor,cadastre_id,source_json) VALUES(?,?,'Офис',25,'2',?,'{}')")
            ->execute([$id,'Учебный офис '.$id,$cadastre++]);
    }
    $db->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,signed,source_json)
        VALUES(?,?,?,?,?,?,7500.01,'active',1,'{}')")->execute([$contract,'ACT19-'.key19(),$party,$from,$from,$end]);
    $db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$contract,$office]);
    $db->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,source_json) VALUES(?,?,?,?,7500.01,'{}')")
        ->execute([$contract,$office,$from,$end]);
    $contractBefore=Service::get($db,'contracts',$contract);

    actor($db,'manager');
    $itemInput=['id'=>$office,'version'=>1,'item_name'=>'Стол','condition'=>'Исправен','quantity'=>'2','unit_value'=>'2500.13','valued_on'=>$today,'command_key'=>key19()];
    $first=Service::command(PremisesInventory::SAVE,$itemInput);
    verify($first===Service::command(PremisesInventory::SAVE,$itemInput),'Inventory replay must preserve the receipt.');
    $items=PremisesInventory::items($db,$office);
    verify(count($items)===1,'Exact replay inserted a duplicate item.');
    $itemId=array_key_first($items);
    $mime=['image/png'=>'png','application/pdf'=>'pdf'];
    $asset=static function(string $kind,int $owner,string $type,string $media,?string $baseline=null) use($db,$mime,&$files): array {
        $id=key19();$name=$id.'.'.$mime[$media];
        $path=APP_ROOT.'/.local/documents/'.$name;$files[]=$path;
        file_put_contents($path,'Local immutable fixture '.$id);
        $sha=hash_file('sha256',$path);
        $db->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime,baseline_hash)
            VALUES(?,?,?,1,?,?,?,?,?,?)')->execute([$id,$type,$owner,$kind,$name,$name,$sha,$media,$baseline]);
        FileOrder::assign($db,$id);
        return ['id'=>$id,'sha256'=>$sha];
    };
    FileOrder::lock($db);
    $photo=$asset(PremisesInventory::PHOTO,$office,'object','image/png');
    PremisesInventory::addPhoto($db,$itemId,$items[$itemId],$photo['id'],$photo['sha256']);
    $file=$asset(PremisesActs::FILE_KINDS['transfer'],$contract,'contract','application/pdf',Evidence::signature($db,$contract));
    PremisesActs::pinUpload($db,$file['id'],$office);
    $items=PremisesInventory::items($db,$office);
    $input=['id'=>$contract,'version'=>1,'object_id'=>$office,'act_kind'=>'transfer','act_date'=>$today,'file_id'=>$file['id'],
        'inventory_hash'=>PremisesActs::inventoryDigest($items),'command_key'=>key19()];
    refuse(fn()=>Service::command(PremisesActs::CONFIRM,$input),422);
    actor($db,'lawyer');
    refuse(fn()=>Service::command(PremisesActs::CONFIRM,$input+['both_signed'=>1]),403);
    actor($db,'manager');
    $receipt=Service::command(PremisesActs::CONFIRM,$input+['both_signed'=>1]);
    verify($receipt===Service::command(PremisesActs::CONFIRM,$input+['both_signed'=>1]),'Act replay changed the receipt.');
    verify(Service::get($db,'contracts',$contract)===$contractBefore,'Transfer changed the contractual record.');
    $history=PremisesActs::history($db,$contract);
    verify(count($history)===1 && $history[0]['inventory']===$items,'Act did not pin the reviewed inventory.');
    Service::command(PremisesInventory::MOVE,['id'=>$office,'version'=>1,'item_id'=>$itemId,'item_version'=>$items[$itemId]['version'],
        'destination_id'=>$destination,'command_key'=>key19()]);
    verify(!PremisesInventory::items($db,$office) && isset(PremisesInventory::items($db,$destination)[$itemId]),'Live inventory did not move.');
    $db->prepare("INSERT INTO rental_documents(id,contract_id,number,kind,status,effective_date,start_date,end_date,amount,base_hash,source_json)
        VALUES(?,?,?,'change','signed',?,?,?,7500.01,?,'{}')")
        ->execute([$addendum,$contract,'DS-'.key19(),$today,$from,$end,Evidence::documentBase($db,$contract)]);
    $db->prepare('INSERT INTO document_terms(document_id,object_id,start_date,end_date,amount) VALUES(?,?,?,?,7500.01)')
        ->execute([$addendum,$destination,$today,$end]);
    actor($db,'accountant');
    Service::command('document.apply',['id'=>$addendum,'version'=>1,'command_key'=>key19()]);
    verify(array_keys(PremisesActs::subjects($db,$contract))===[$destination],'Addendum did not replace the premises.');
    verify(PremisesActs::history($db,$contract)===$history && PremisesActs::includesPhoto($db,$contract,$photo),'Historical act or photo changed after replacement.');
    actor($db,'manager');
    refuse(fn()=>Service::command(PremisesActs::CONFIRM,array_replace($input,[
        'version'=>(int)Service::get($db,'contracts',$contract)['version'],'both_signed'=>1,'command_key'=>key19(),
    ])),422);
    echo "PASS: signed inventory snapshot, permission and signature guards, exact replay, movement and real addendum replacement.\n";
} finally {
    $db->rollBack();
    foreach ($files as $path) if (is_file($path)) unlink($path);
}
