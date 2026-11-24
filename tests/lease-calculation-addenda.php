<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Importer,LeaseCalculationAddenda};

$db=DB::pdo();
$schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
$copy=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
if (PHP_SAPI!=='cli' || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$schema)
    || $db->query('SELECT CURRENT_USER()')->fetchColumn()!==$schema.'@127.0.0.1'
    || ($copy['database'] ?? '')!==$schema || empty($copy['isolated_test_data'])
    || realpath($copy['app_root'])!==realpath(APP_ROOT) || getenv('IPPHP_ALLOW_EXTERNAL')) {
    throw new RuntimeException('Verified isolated task DB required.');
}

$db->beginTransaction();
try {
    $id=(int)$db->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();
    $documentId=(int)($db->query('SELECT MAX(id)+1 FROM rental_documents')->fetchColumn() ?: 1);
    $object=$db->query('SELECT id,name FROM rental_objects ORDER BY id LIMIT 1')->fetch();
    $party=$db->query('SELECT id FROM parties ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO contracts(id,party_id,number,document_date,status,source_json)
        VALUES(?,?,'TEST-ADDENDUM','2026-01-01','active','{}')")->execute([$id,$party]);
    $db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$id,$object['id']]);
    $term=$db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,superseded_on,source_json)
        VALUES(?,?,?,?,?,?,?,?)');
    $term->execute([$id,$object['id'],'2026-01-01','2026-12-31','100.00','2026-01-01','2026-06-30','{}']);
    $term->execute([$id,$object['id'],'2026-07-01','2026-12-31','105.00','2026-07-01',null,'{}']);
    $create=$db->prepare("INSERT INTO rental_documents(id,contract_id,number,kind,status,effective_date,applied,source_json)
        VALUES(?,?,?,'renewal',?,?,?,'{}')");
    $documentTerm=$db->prepare('INSERT INTO document_terms(document_id,object_id,start_date,end_date,amount) VALUES(?,?,?,?,?)');
    foreach ([
        ['ДС №7','signed','2026-07-01',1,'105.00'],
        ['ДС8','cancelled','2026-07-01',0,'105.00'],
        ['ДС9','signed','2026-07-01',0,'105.00'],
        ['ДС10','signed','2026-08-01',1,'105.00'],
        ['ДС11','signed','2026-07-01',1,'110.00'],
    ] as $offset=>[$number,$status,$effective,$applied,$amount]) {
        $document=$documentId+$offset;
        $create->execute([$document,$id,$number,$status,$effective,$applied]);
        $documentTerm->execute([$document,$object['id'],'2026-07-01','2026-12-31',$amount]);
    }
    $record=['id'=>$id,'source_json'=>'{}'];
    $assertMap=static function(string $date,array $expected,array $record) use ($db,$id): void {
        $result=LeaseCalculationAddenda::byContract($db,[$record],$date);
        if (($result[$id] ?? [])!==$expected) throw new RuntimeException('Unexpected addendum for '.$date);
    };
    $assertMap('2026-06-30',[],$record);
    $assertMap('2026-07-01',['ДС7'],$record);
    $assertMap('2026-08-01',['ДС7'],$record);

    $condition=[
        'kind'=>'RENT','contract_number'=>'TEST-ADDENDUM','contract_date'=>'2026-01-01','party_id'=>'tenant',
        'condition_id'=>'first','rooms'=>[$object['name']],'start'=>'2026-01-01','end'=>'2026-03-31',
        'monthly_gross'=>'100.00','ds'=>['ДС №3'],'outcome'=>'ACTIVE','issues'=>[],
    ];
    $db->prepare('UPDATE contract_terms SET source_json=? WHERE contract_id=? AND effective_date=?')->execute([
        Importer::json(['sourceConditions'=>[$condition,array_replace($condition,[
            'condition_id'=>'other','rooms'=>['Другой объект'],'ds'=>['ДС №4'],
        ])]]),$id,'2026-01-01',
    ]);
    $record['source_json']=Importer::json(['imported'=>true,'sourceLegalMapping'=>['party1CRef_Key'=>'tenant']]);
    $assertMap('2026-03-31',['ДС3'],$record);
    $assertMap('2026-04-01',[],$record);
    echo 'PASS: current applied or sourced addendum only, object-specific match, expired, future, cancelled and unapproved excluded.',PHP_EOL;
} finally {
    $db->rollBack();
}
