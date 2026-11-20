<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Service,WordPack,WordGenerationData,OriginalDocuments,TemplateAssets,Importer,Evidence,DirectoryDetails,Documents};
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
$db=DB::pdo();$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
if($db->query('SELECT DATABASE()')->fetchColumn()!==$receipt['database']||!str_starts_with($receipt['database'],'ipphp_task_')||realpath($receipt['app_root'])!==APP_ROOT||!$receipt['isolated_test_data'])throw new RuntimeException('Isolated verified data required');
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function actor(string $role):void{$q=DB::pdo()->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 LIMIT 1');$q->execute([$role]);$u=$q->fetch();ok((bool)$u,'Role exists '.$role);$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];}
function reject(callable $f,string $part):void{try{$f();}catch(RuntimeException $e){ok(str_contains($e->getMessage(),$part),'Unexpected rejection: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$part);}
function command(string $action,array $input):array{return Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);}
function fixture(string $type,bool $withPlan=true):int{
    $db=DB::pdo();actor('manager');$pid=(int)$db->query('SELECT MAX(id)+1 FROM parties')->fetchColumn();$oid=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();$cid=(int)$db->query('SELECT MAX(id)+1 FROM cadastre')->fetchColumn();
    $db->prepare("INSERT INTO parties(id,name,inn,address,source_json) VALUES(?,'ООО «Новый арендатор»','7701234560','Москва, ул. Примерная, д. 1','{}')")->execute([$pid]);
    DirectoryDetails::store($db,'parties',$pid,['party_type'=>'company','ogrn'=>'1027700123456','signer'=>'Иванов Иван Иванович',
        'signer_position'=>'Генеральный директор','signer_basis'=>'Устава']);
    $db->prepare("INSERT INTO cadastre(id,number,area,type,source_json) VALUES(?,?,20,?,'{}')")->execute([$cid,'77:01:000000:'.$cid,$type]);
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) VALUES(?,?,?,20,?,'{}')")->execute([$oid,'Офис '.$oid,$type,$cid]);
    if ($type==='Офис' && $withPlan) {
        $pdf=Documents::renderPdf('<p>Synthetic plan</p>');
        DirectoryDetails::store($db,'object_plan',$oid,['name'=>'plan.pdf','mime'=>'application/pdf','sha256'=>hash('sha256',$pdf),'content'=>base64_encode($pdf)]);
    }
    $r=command('contract.create',['party_id'=>$pid,'start_date'=>'2026-12-01','end_date'=>'2027-11-30','amount'=>'10500.01','objects'=>[$oid],'term_amount'=>[$oid=>'10500.01'],'intent'=>'submit']);return(int)$r['id'];
}
function inputFor(array $plan):array{
    $sample=['party_postal_address'=>'Москва, ул. Примерная, д. 1','party_email'=>'lawyer@example.test','party_kpp'=>'770101001','party_ogrn'=>'1027700123456',
        'party_bank_line'=>'40702810000000000001 в АО Банк','party_correspondent'=>'30101810000000000001','party_bik'=>'044525001',
        'party_signer_title'=>'Генеральный директор','party_signer_title_genitive'=>'Генерального директора','party_signer_genitive'=>'Иванова Ивана Ивановича',
        'party_signer_short'=>'Иванов И.И.','party_authority'=>'Устава','floor'=>'2','floor_words'=>'втором','building_room_numbers'=>'I, 201',
        'ownership_record'=>'77-77-01/001/2026-1','ownership_date'=>'2026-01-15','organization_signer_genitive'=>'Яковлева Андрея Анатольевича',
        'organization_registration_date'=>'08.11.2019','contract_document_date'=>'2026-11-18','handover_deadline'=>'2026-12-01','act_date'=>'2026-12-01',
        'service_date'=>'2026-11-18','service_number'=>'У-2026-201','service_gross'=>'1050.01','meter_readings'=>'Счётчик №123: 15,25 кВт·ч',
        'passes_count'=>'2','parking_passes_count'=>'1','addendum_date_words'=>'«18» ноября 2026 года','clause_reference'=>'2.1',
        'purpose'=>'Размещение офиса'];
    $result=[];foreach(WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType']) as $key=>$field){if(!isset($sample[$key]))throw new RuntimeException('Need explicit sample '.$key);$result[$key]=$sample[$key];}return$result;
}
function hashes(array $plan): array {
    $hashes=array_map(static fn($s)=>$s['sha256'],$plan['sources']);
    if ($plan['plans']) $hashes['office_plan']=hash('sha256',Importer::canonicalJson($plan['planProof']));
    return $hashes;
}
$created=[];$db->beginTransaction();
try{
    foreach(['Офис','Машиноместо'] as $type){
        $selection=['office'=>0,'parking'=>0];
        $id=fixture($type);$c=Service::get($db,'contracts',$id);actor('lawyer');$plan=WordPack::plan($db,'contract',$id,(int)$c['version'],$selection);
        ok(str_contains(WordPack::action($c),'Сформировать пакет Word'),'Supplied subject uses new package');
        $mixed=array_replace($c,['template_type'=>'mixed']);$mixedAction=WordPack::action($mixed);
        ok(str_contains($mixedAction,'action="/rental/generate"')&&!str_contains($mixedAction,'original_pack'),'Unsupplied mixed subject retains approved legacy generation');
        $input=inputFor($plan);$hashes=hashes($plan);$key=bin2hex(random_bytes(16));
        ok(!array_intersect(['service_number','service_date','service_gross','act_date','meter_readings'],array_keys($input)),'No service or actual handover input');
        if ($type==='Офис') reject(fn()=>WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input,array_replace($hashes,['office_plan'=>str_repeat('0',64)]),$key,$created),'План изменился');
        reject(fn()=>WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,[],$hashes,$key,$created),'Заполните');
        $pack=WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input,$hashes,$key,$created);
        ok(array_column($pack['files'],'kind')===($type==='Офис'?['office','office-plan']:['parking']),'Lease and only applicable plan');
        ok(WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input,$hashes,$key,$created)['primary']===$pack['primary'],'Repeat does not duplicate');
        reject(fn()=>WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input+['rent_net'=>'1'],$hashes,bin2hex(random_bytes(16)),$created),'Нельзя подменять');
        reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version']-1),'Запись изменена');
        $proof=Evidence::approvalSignature($db,$id,$pack['primary']);ok(strlen($proof)===64,'Package signature');
        $values=$pack['values'];ok(bcadd($values['rent_net'].'.'.$values['rent_net_kopecks'],str_replace([' ',','],['','.'],$values['rent_vat']),2)==='10500.01','Exact net + tax equals gross');
        $out=APP_ROOT.'/.local/generated';if(!is_dir($out))mkdir($out,0700,true);
        foreach($pack['files'] as $f){$q=$db->prepare('SELECT path FROM rental_files WHERE id=?');$q->execute([$f['id']]);$file=$q->fetchColumn();copy(APP_ROOT.'/.local/documents/'.$file,$out.'/'.$f['kind'].'.'.pathinfo($file,PATHINFO_EXTENSION));}
        actor('manager');reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version']),'Недостаточно');actor('lawyer');
        if($type==='Офис'){
            $selection=['mode'=>'room-change'];$p=WordPack::plan($db,'contract',$id,(int)$c['version'],$selection);$h=array_map(static fn($s)=>$s['sha256'],$p['sources']);
            $a=WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,inputFor($p),$h,bin2hex(random_bytes(16)),$created);
            $q=$db->prepare('SELECT path FROM rental_files WHERE id=?');$q->execute([$a['primary']]);copy(APP_ROOT.'/.local/documents/'.$q->fetchColumn(),$out.'/room-change.docx');
            ok(Evidence::latest($db,'contract',$id,'lawyer-file')['id']===$pack['primary'],'Auxiliary DS never replaces legal lease');
        }else reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version'],['mode'=>'room-change']),'предназначен для договора помещений');
        // A missing or changed companion invalidates the same signature used at legal approval.
        if ($type==='Офис') {
            $q=$db->prepare('SELECT path FROM rental_files WHERE id=?');$q->execute([$pack['files'][1]['id']]);$path=APP_ROOT.'/.local/documents/'.$q->fetchColumn();$bytes=file_get_contents($path);
            ok(hash('sha256',$bytes)===reset($plan['planProof']),'PDF plan unchanged');
            file_put_contents($path,$bytes.'x');
            reject(fn()=>Evidence::approvalSignature($db,$id,$pack['primary']),'целостности');file_put_contents($path,$bytes);
        }
    }
    // An old contract has real persisted relations, independent of template_type.
    $id=fixture('Офис');$c=Service::get($db,'contracts',$id);
    $office=(int)$db->query("SELECT object_id FROM contract_objects WHERE contract_id=$id")->fetchColumn();
    $parking=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();
    $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) SELECT ?,'Парковка legacy','Машино-место',area,cadastre_id,'{}' FROM rental_objects WHERE id=?")->execute([$parking,$office]);
    $db->prepare("INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)")->execute([$id,$parking]);
    $db->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,source_json) VALUES(?,?,?,?,?,'{}')")->execute([$id,$parking,$c['start_date'],$c['end_date'],'5000.00']);
    $db->prepare("UPDATE contracts SET template_type=NULL,amount='15500.01' WHERE id=?")->execute([$id]);
    $c=Service::get($db,'contracts',$id);
    actor('lawyer');
    ok(TemplateAssets::contractKind($db,$c)==='mixed','An untyped legacy contract resolves to mixed from its real objects');
    ok(str_contains(WordPack::action($c),'Сформировать Word смешанного договора'),'Untyped legacy uses the mixed Word route');
    command('contract.return',['id'=>$id,'version'=>(int)$c['version'],'reason'=>'Уточнить редакцию']);
    $c=Service::get($db,'contracts',$id);
    $update=['party_id'=>$c['party_id'],'document_date'=>'2026-11-21','start_date'=>$c['start_date'],'end_date'=>$c['end_date'],
        'amount'=>'15500.01','objects'=>[$office,$parking],'term_amount'=>[$office=>'10500.01',$parking=>'5000.00']];
    actor('manager');
    command('contract.edit',['id'=>$id,'version'=>(int)$c['version'],'number'=>$c['number'],'intent'=>'submit']+$update);
    $c=Service::get($db,'contracts',$id);
    ok($c['status']==='legal' && $c['template_type']==='mixed','The edited legacy mixed contract returns to legal review');
    reject(fn()=>command('contract.create',['intent'=>'submit']+$update),'отдельными договорами');
    $draftId=(int)command('contract.create',['intent'=>'draft']+$update)['id'];
    $draft=Service::get($db,'contracts',$draftId);
    reject(fn()=>command('contract.edit',['id'=>$draftId,'version'=>(int)$draft['version'],'number'=>$draft['number'],'intent'=>'submit']+$update),'отдельными договорами');
    actor('lawyer');$file=Documents::export($db,'contract',$id,(int)$c['version'],'docx');
    $created[]=APP_ROOT.'/.local/documents/'.basename($file['path']);
    command('contract.legal',['id'=>$id,'version'=>(int)$c['version'],'counterparty_confirmed'=>1,'financial_confirmed'=>1,
        'document_confirmed'=>1,'legal_file_id'=>$file['id']]);
    ok(Service::get($db,'contracts',$id)['status']==='accounting','Legacy mixed Word can be approved again');

    $missing=fixture('Офис',false);$c=Service::get($db,'contracts',$missing);actor('lawyer');
    $preview=WordPack::plan($db,'contract',$missing,(int)$c['version'],[],true);
    ok(count($preview['missingPlans'])===1,'Word form identifies the object missing its plan');
    reject(fn()=>WordPack::produce($db,'contract',$missing,(int)$c['version'],[],[],[],bin2hex(random_bytes(16)),$created),'Добавьте план');

    // An approved, intact legacy source stays in the template library.
    $id=fixture('Офис');$c=Service::get($db,'contracts',$id);actor('lawyer');
    $previousChoice=WordPack::plan($db,'contract',$id,(int)$c['version'])['sources']['office']['template_id'];
    $oldId=901099;
    $oldAsset=TemplateAssets::store($db,$oldId,1,dirname(__DIR__).'/modules/rental/templates/office.docx','Архивный офис.docx');
    if ($oldAsset['created']) $created[]=$oldAsset['path'];
    command('template.edit',['id'=>$oldId,'new_template'=>1,'version'=>1,'name'=>'Архивный офис','generation_kind'=>'office','word_file_id'=>$oldAsset['id']]);
    actor('director');command('template.approve',['id'=>$oldId,'version'=>2]);
    actor('lawyer');
    $plan=WordPack::plan($db,'contract',$id,(int)$c['version'],[],true);
    ok($plan['sources']['office']['template_id']===$previousChoice && isset($plan['excluded'][$oldId]),'Incompatible upload leaves the existing compatible default intact');
    $builtin=WordPack::plan($db,'contract',$id,(int)$c['version'],['office'=>0],true);
    ok($builtin['sources']['office']['template_id']===0,'The compatible bundled source remains selectable');

    // A new uploaded source is unavailable while pending, then selected after director approval.
    actor('lawyer');$templateId=900999;$custom=tempnam(APP_ROOT.'/runtime','word-template-');$created[]=$custom;
    copy(OriginalDocuments::source('office')['path'],$custom);$zip=new ZipArchive();$zip->open($custom);
    $zip->addFromString('word/document.xml',str_replace('{{floor_words}}','{{purpose}}',$zip->getFromName('word/document.xml')));$zip->close();
    $stored=TemplateAssets::store($db,$templateId,1,$custom,'Новое оформление.docx');$created[]=$stored['path'];
    command('template.edit',['id'=>$templateId,'new_template'=>1,'version'=>1,'name'=>'Word QA','generation_kind'=>'office','word_file_id'=>$stored['id']]);
    ok(!isset(OriginalDocuments::choices($db,'office')[$templateId]),'Pending source cannot generate');
    actor('director');command('template.approve',['id'=>$templateId,'version'=>2]);
    $db->prepare('UPDATE rental_templates SET approved_at=CURRENT_DATE + INTERVAL 1 DAY WHERE id=?')->execute([$templateId]);
    $db->prepare('UPDATE rental_templates SET approved_at=CURRENT_DATE + INTERVAL 2 DAY WHERE id=?')->execute([$oldId]);
    ok(isset(OriginalDocuments::choices($db,'office')[$templateId]),'Approved upload is selectable');
    actor('lawyer');$source=OriginalDocuments::resolve($db,'office',$templateId);ok($source['sha256']===$stored['sha256'],'Uploaded source used exactly');
    $id=fixture('Офис');actor('lawyer');$c=Service::get($db,'contracts',$id);$plan=WordPack::plan($db,'contract',$id,(int)$c['version']);
    ok(array_key_first(OriginalDocuments::choices($db,'office'))===$oldId,'Fixture presents the incompatible template first');
    ok($plan['sources']['office']['template_id']===$templateId && !isset($plan['choices'][$oldId]),'Newest compatible upload selected despite first incompatible template');
    $stale=WordPack::plan($db,'contract',$id,(int)$c['version'],['office'=>$oldId],true);
    ok($stale['sources']['office']['template_id']===$templateId,'A stale Word URL presents the compatible choice');
    reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version'],['office'=>$oldId]),'совместимый');
    ok(OriginalDocuments::resolve($db,'office',$oldId)['sha256']===$oldAsset['sha256'],'Legacy template bytes still available');
    ok(isset(WordGenerationData::inputs($plan['fields'],$plan['values'])['purpose']),'New custom token is requested');
    $pack=WordPack::produce($db,'contract',$id,(int)$c['version'],[],inputFor($plan),hashes($plan),bin2hex(random_bytes(16)),$created);
    $q=$db->prepare('SELECT path FROM rental_files WHERE id=?');$q->execute([$pack['primary']]);$zip=new ZipArchive();$zip->open(APP_ROOT.'/.local/documents/'.$q->fetchColumn());
    $text=$zip->getFromName('word/document.xml');$zip->close();ok(str_contains($text,'Размещение офиса')&&!str_contains($text,'{{purpose}}'),'Uploaded custom slot substituted');
    echo "PASS: Word packages, role/version guards, idempotency, exact money, plan integrity, compatible sources and legacy mixed review\n";
}finally{if($db->inTransaction())$db->rollBack();foreach(array_unique($created) as $path)if(is_file($path))unlink($path);}
