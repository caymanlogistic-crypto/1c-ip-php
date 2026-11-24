<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{Service,WordPack,WordGenerationData,OriginalDocuments,TemplateAssets,Importer,Evidence,DirectoryDetails,Documents,FileOrder,InitialInvoice,LegalHandoff,OwnerWordTemplates,WordDerivative,WordSecurityTerms};
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);});
$db=DB::pdo();$receipt=json_decode(file_get_contents(APP_ROOT.'/.local/copy-verification.json'),true,512,JSON_THROW_ON_ERROR);
if($db->query('SELECT DATABASE()')->fetchColumn()!==$receipt['database']||!str_starts_with($receipt['database'],'ipphp_task_')||realpath($receipt['app_root'])!==APP_ROOT||!$receipt['isolated_test_data'])throw new RuntimeException('Isolated verified data required');
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function actor(string $role):void{$q=DB::pdo()->prepare('SELECT u.id,u.auth_version FROM users u JOIN role_profiles p ON p.role_id=u.role WHERE p.kind=? AND u.active=1 ORDER BY u.id LIMIT 1');$q->execute([$role]);$u=$q->fetch();ok((bool)$u,'Role exists '.$role);$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];}
function reject(callable $f,string $part):void{try{$f();}catch(RuntimeException $e){ok(str_contains($e->getMessage(),$part),'Unexpected rejection: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$part);}
function command(string $action,array $input):array{return Service::command($action,$input+['command_key'=>bin2hex(random_bytes(16))]);}
function fixture(string $type,bool $withPlan=true,array $rates=['10500.01'],string $partyType='company',array $planFormats=[],
    bool $confirmSecurity=true,?string $documentDate=null):int{
    $db=DB::pdo();actor('manager');
    $pid=(int)$db->query('SELECT MAX(id)+1 FROM parties')->fetchColumn();
    $oid=(int)$db->query('SELECT MAX(id)+1 FROM rental_objects')->fetchColumn();
    $cid=(int)$db->query('SELECT MAX(id)+1 FROM cadastre')->fetchColumn();
    [$name,$inn,$attributes]=$partyType==='entrepreneur'
        ? ['ИП Петров Пётр Петрович','770123456789',['ogrnip'=>'326770000012345']]
        : ['ООО «Новый арендатор»','7701234560',['ogrn'=>'1027700123456','signer'=>'Иванов Иван Иванович',
            'signer_position'=>'Генеральный директор','signer_basis'=>'Устава']];
    $db->prepare("INSERT INTO parties(id,name,inn,address,source_json) VALUES(?,?,?,'Москва, ул. Примерная, д. 1','{}')")->execute([$pid,$name,$inn]);
    DirectoryDetails::store($db,'parties',$pid,['party_type'=>$partyType]+$attributes);
    $objects=[];
    $sum='0.00';
    foreach ($rates as $index=>$rate) {
        $db->prepare("INSERT INTO cadastre(id,number,area,type,source_json) VALUES(?,?,20,?,'{}')")->execute([$cid,'77:01:000000:'.$cid,$type]);
        $db->prepare("INSERT INTO rental_objects(id,name,type,area,cadastre_id,source_json) VALUES(?,?,?,20,?,'{}')")->execute([$oid,$type.' '.$oid,$type,$cid]);
        if ($type==='Офис' && $withPlan) {
            $format=$planFormats[$index]??'pdf';
            $bytes=match ($format) {
                'pdf'=>Documents::renderPdf('<p>Synthetic plan for '.$oid.'</p>'),
                'png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAFElEQVR42mP8//8/AwwwMSABFA4Aby0DA/PM5D8AAAAASUVORK5CYII='),
                'jpg'=>base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwc'
                    .'KDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
                    .'MjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEA'
                    .'AAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhED'
                    .'EQA/AL+AD//Z'),
            };
            $mime=match ($format) {'pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg'};
            DirectoryDetails::store($db,'object_plan',$oid,['name'=>'plan.'.$format,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'content'=>base64_encode($bytes)]);
        }
        $objects[$oid++]=$rate;
        $cid++;
        $sum=bcadd($sum,$rate,2);
    }
    $r=command('contract.create',['party_id'=>$pid,'start_date'=>'2026-12-01','end_date'=>'2027-11-30','amount'=>$sum,
        'objects'=>array_keys($objects),'term_amount'=>$objects,'intent'=>'submit']
        +($documentDate===null?[]:['document_date'=>$documentDate]));
    $id=(int)$r['id'];actor('lawyer');confirmChecks(Service::get($db,'contracts',$id),$confirmSecurity);return $id;
}
function confirmChecks(array $record,bool $confirmSecurity=true): void {
    foreach ([LegalHandoff::PARTY,LegalHandoff::TERMS] as $check) {
        command($check,['id'=>$record['id'],'version'=>$record['version'],
            'party_basis'=>LegalHandoff::partyBasis(DB::pdo(),$record)]);
    }
    if ($confirmSecurity) command(LegalHandoff::SECURITY_MULTIPLE,
        ['id'=>$record['id'],'version'=>$record['version'],'multiple'=>'1']);
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
    foreach ($plan['acts'] as $objectId=>$act) $hashes['act-'.$objectId]=$act['source']['sha256'];
    if ($plan['plans']) $hashes['office_plan']=hash('sha256',Importer::canonicalJson($plan['planProof']));
    return $hashes;
}
function wordText(PDO $db,array $file): string {
    $q=$db->prepare('SELECT path FROM rental_files WHERE id=?');
    $q->execute([$file['id']]);
    $zip=new ZipArchive();
    ok($zip->open(APP_ROOT.'/.local/documents/'.$q->fetchColumn())===true,'Generated Word opens');
    try {
        $parsed=new DOMDocument();
        ok($parsed->loadXML($zip->getFromName('word/document.xml')),'Valid Word document XML');
        return preg_replace('/[\p{Z}\s]+/u',' ',$parsed->textContent);
    } finally {$zip->close();}
}
function checkOfficeAct(string $text,string $unit): void {
    ok(str_contains($text,'пунктом 3.1.2. Договора аренды')&&str_contains($text,'п. 2.2. Договора'),
        'Office deed refers to the handover and purpose clauses');
    ok(str_contains($text,'Помещение № '.$unit)&&str_contains($text,'поэтажном плане (Приложение №1)'),
        'Office deed identifies the object and points to its plan');
    ok(str_contains($text,'следующее имущество:')&&str_contains($text,'Стоимость за единицу (руб.)'),
        'Office deed has the agreed inventory table');
    ok(!str_contains($text,'показания')&&!str_contains($text,'брелок'),
        'Office deed omits meter readings and parking accessories');
}
function stageGeneratedWord(PDO $db,array $contract,array $pack,array &$created,bool $zip): array {
    $draft=LegalHandoff::files($db,$contract);
    ok($draft===[],'Use a fresh synthetic contract attachment list');
    $query=$db->prepare('SELECT * FROM rental_files WHERE id=?');
    $query->execute([$pack['primary']]);
    $source=$query->fetch();
    $id=bin2hex(random_bytes(16));
    $path=Documents::directory().'/'.$id.($zip?'.zip':'.docx');
    $created[$id]=$path;
    if ($zip) {
        $archive=new ZipArchive();
        ok($archive->open($path,ZipArchive::CREATE)===true,'Create synthetic ZIP');
        ok($archive->addFile(Documents::directory().'/'.$source['path'],$source['filename']),'Use the generated primary as the first ZIP entry');
        ok($archive->close(),'Write synthetic ZIP');
    } else {
        ok(copy(Documents::directory().'/'.$source['path'],$path),'Copy generated Word to the lawyer attachment');
    }
    $filename=$zip?'Синтетический комплект.zip':$source['filename'];
    $sha256=hash_file('sha256',$path);
    $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
        VALUES(?,'contract',?,?,'lawyer-file',?,?,?,?)")
        ->execute([$id,$contract['id'],$contract['version'],$filename,basename($path),$sha256,$zip?'application/zip':$source['mime']]);
    FileOrder::assign($db,$id);
    $files=[compact('id','filename','sha256')];
    $db->prepare("UPDATE rental_facts SET fact_json=JSON_SET(fact_json,'$.files',CAST(? AS JSON))
        WHERE namespace='legal_handoff_draft' AND source_key=?")->execute([Importer::json($files),$contract['id']]);
    return ['id'=>$contract['id'],'version'=>$contract['version'],'packet_fingerprint'=>LegalHandoff::fingerprint($files)];
}

function assertWordSecurity(PDO $db,array &$created): void {
    foreach (['office'=>'Офис','parking'=>'Машиноместо'] as $kind=>$type) {
        $partyType=$kind==='office'?'company':'entrepreneur';
        $id=fixture($type,partyType:$partyType,confirmSecurity:false,documentDate:date('Y-m-d'));
        $contract=Service::get($db,'contracts',$id);
        $version=(int)$contract['version'];
        $selection=[$kind=>0];
        $input=['id'=>$id,'version'=>$version];
        reject(fn()=>WordPack::plan($db,'contract',$id,$version,$selection),'Сначала согласуйте кратность');
        reject(fn()=>Documents::export($db,'contract',$id,$version,'docx'),'Сначала согласуйте кратность');
        $priorRequest=null;
        foreach ([1,0,2] as $multiple) {
            command(LegalHandoff::SECURITY_MULTIPLE,$input+['multiple'=>(string)$multiple]);
            $plan=WordPack::plan($db,'contract',$id,$version,$selection);
            $data=inputFor($plan);
            $hashes=hashes($plan);
            if ($priorRequest!==null) {
                reject(fn()=>WordPack::produce($db,'contract',$id,$version,$selection,$data,$hashes,$priorRequest,$created),
                    'Ключ формы уже использован');
            }
            reject(fn()=>WordPack::produce($db,'contract',$id,$version,$selection,
                $data+['security_clause'=>'Подставная сумма'],$hashes,bin2hex(random_bytes(16)),$created),
                'Нельзя подменять');
            $request=bin2hex(random_bytes(16));
            $pack=WordPack::produce($db,'contract',$id,$version,$selection,$data,$hashes,$request,$created);
            ok($pack['primary']===WordPack::produce($db,'contract',$id,$version,$selection,$data,$hashes,$request,$created)['primary'],
                'Same calculation and request reuse the original generated file');
            $doc=wordText($db,$pack['files'][0]);
            $calc=InitialInvoice::preview($db,$contract,date('Y-m-d'));
            $amount=InitialInvoice::securityAmount('10500.01',$multiple);
            $security=array_values(array_filter($calc['rows'],static fn(array $row): bool =>
                $row['kind']==='Обеспечительный платёж'));
            ok(WordSecurityTerms::matches(['multiple'=>$multiple,'amount'=>$amount],$pack['security']),
                'Pack records effective security');
            ok(!str_contains($doc,'{{vat}}') && !str_contains($doc,'арендной платы за один месяц аренды'),
                'Replaced approved clause has no placeholder or hard-coded original amount');
            if ($multiple===0) {
                ok(!$security && str_contains($doc,'Обеспечительный платёж по настоящему Договору не предусмотрен.')
                    && str_contains($doc,'Условия Договора об обеспечительном платеже не применяются.'),
                    'Zero has no security invoice and neutralizes payment obligations in the contract');
            } else {
                $expected=preg_replace('/[\p{Z}\s]+/u',' ',Documents::amountText($amount));
                ok(count($security)===1 && $security[0]['amount']===$amount
                    && str_contains($doc,$expected.' руб. с НДС (кратность к месячной арендной плате — '.$multiple.')'),
                    'Word and first calculation report the same exact gross amount');
                ok(str_contains($doc,$kind==='office'?'в течение 2 (двух) рабочих дней с момента подписания настоящего Договора':
                    'одновременно с первым платежом вносит обеспечительный платёж'), 'Keep the original payment deadline');
            }
            if ($multiple===1) {
                $oldPack=$pack;
                $attach=stageGeneratedWord($db,$contract,$oldPack,$created,$kind==='parking');
            }
            $priorRequest=$request;
        }

        reject(fn()=>command('contract.legal',$attach+['security_multiple'=>2]),'Сформируйте договор заново');
        if ($kind==='office') {
            $old=LegalHandoff::files($db,$contract)[0];
            reject(fn()=>WordDerivative::produce($db,$old['id']),'Сформируйте договор заново');
        }
        $db->prepare("UPDATE rental_facts SET fact_json=JSON_SET(fact_json,'$.files',JSON_ARRAY())
            WHERE namespace='legal_handoff_draft' AND source_key=?")->execute([$id]);
        $attach=stageGeneratedWord($db,$contract,$pack,$created,$kind==='parking');
        ok(command('contract.legal',$attach+['security_multiple'=>2])['status']==='accounting',
            'Current generated Word and ZIP pass the normal handoff');
    }
}

function ownerSourceBytes(string $kind,string $label,array &$created): string {
    $temp=tempnam(APP_ROOT.'/runtime','owner-template-test-');
    $created[]=$temp;
    copy(OriginalDocuments::source($kind)['path'],$temp);
    $zip=new ZipArchive();
    ok($zip->open($temp)===true,'Open fixture DOCX');
    $doc=new DOMDocument();
    $doc->loadXML($zip->getFromName('word/document.xml'));
    $ns='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $body=$doc->getElementsByTagNameNS($ns,'body')->item(0);
    $paragraph=$doc->createElementNS($ns,'w:p');
    $paragraph->appendChild($doc->createElementNS($ns,'w:r'))->appendChild($doc->createElementNS($ns,'w:t'))->textContent=$label;
    $body->insertBefore($paragraph,$body->firstChild);
    $zip->addFromString('word/document.xml',$doc->saveXML());
    $zip->close();
    return file_get_contents($temp);
}

function assertOwnerTemplates(PDO $db,array &$created): void {
    $proofs=[];
    foreach (['office','parking','mixed','addendum'] as $kind) $proofs[$kind]=TemplateAssets::signature(TemplateAssets::active($db,$kind));
    $id=fixture('Офис',documentDate:date('Y-m-d'));
    $version=(int)Service::get($db,'contracts',$id)['version'];
    $before=WordPack::plan($db,'contract',$id,$version);
    $requestKey=bin2hex(random_bytes(16));
    $inputs=inputFor($before);
    $hashes=hashes($before);
    $old=WordPack::produce($db,'contract',$id,$version,[],$inputs,$hashes,$requestKey,$created);
    $beforeEvidence=Evidence::approvalSignature($db,$id,$old['primary']);
    $expected=OwnerWordTemplates::digest($db,'office');
    $replacement=ownerSourceBytes('office','Учебная проверка исходника А',$created);
    $newHash=hash('sha256',$replacement);
    $upload=['kind'=>'office','content'=>base64_encode($replacement),'expected_sha256'=>$expected,'command_key'=>bin2hex(random_bytes(16))];
    actor('director');
    reject(fn()=>Service::command(OwnerWordTemplates::REPLACE,$upload),'прав');
    actor('lawyer');
    $result=Service::command(OwnerWordTemplates::REPLACE,$upload);
    ok(Service::command(OwnerWordTemplates::REPLACE,$upload)===$result,'Exact upload replay is idempotent');
    ok(OriginalDocuments::source('office',$db)['bytes']===$replacement
        && OriginalDocuments::resolve($db,'office',0)['sha256']===$newHash,'Download and generation use exact replacement');
    reject(fn()=>WordPack::produce($db,'contract',$id,$version,[],$inputs,$hashes,$requestKey,$created),'Шаблоны изменились');
    $plan=WordPack::plan($db,'contract',$id,$version);
    $officePack=WordPack::produce($db,'contract',$id,$version,[],inputFor($plan),hashes($plan),bin2hex(random_bytes(16)),$created);
    $main=wordText($db,$officePack['files'][0]);
    ok(str_contains($main,'Учебная проверка исходника А') && str_contains($main,'кратность к месячной арендной плате — 1'),
        'Edited owner original still renders the legal security clause');
    ok(Importer::canonicalJson(WordPack::evidence($db,$old['primary'])['sources'])===Importer::canonicalJson($old['sources'])
        && Evidence::approvalSignature($db,$id,$old['primary'])===$beforeEvidence,'Frozen original package/proof is unchanged');

    $bad=ownerSourceBytes('office','{{unknown_task_field}}',$created);
    reject(fn()=>command(OwnerWordTemplates::REPLACE,['kind'=>'office','expected_sha256'=>$newHash,'content'=>base64_encode($bad)]),
        'Поля не поддерживаются');
    $second=ownerSourceBytes('office','Учебная проверка исходника Б',$created);
    reject(fn()=>command(OwnerWordTemplates::REPLACE,['kind'=>'office','expected_sha256'=>$expected,'content'=>base64_encode($second)]),
        'Шаблон уже заменили');
    ok(OwnerWordTemplates::digest($db,'office')===$newHash,'Rejected upload leaves the active bytes');
    command(OwnerWordTemplates::REPLACE,['kind'=>'office','expected_sha256'=>$newHash,'content'=>base64_encode($second)]);
    ok((int)$db->query("SELECT COUNT(*) FROM rental_facts WHERE namespace='owner_word_template' AND source_key='office'")->fetchColumn()===1
        && OriginalDocuments::source('office',$db)['bytes']===$second,'Second replacement overwrites the only stored source');

    $act=ownerSourceBytes('handover','Учебная проверка исходника акта',$created);
    command(OwnerWordTemplates::REPLACE,['kind'=>'handover','expected_sha256'=>OwnerWordTemplates::digest($db,'handover'),
        'content'=>base64_encode($act)]);
    $plan=WordPack::plan($db,'contract',$id,$version);
    $actPack=WordPack::produce($db,'contract',$id,$version,[],inputFor($plan),hashes($plan),bin2hex(random_bytes(16)),$created);
    ok(str_contains(wordText($db,$actPack['files'][1]),'Учебная проверка исходника акта'),'Generated act reads the replacement slot');
    foreach ($proofs as $kind=>$proof) {
        ok(TemplateAssets::signature(TemplateAssets::active($db,$kind))===$proof,'Legacy source signature unchanged: '.$kind);
    }
}

$created=[];$db->beginTransaction();
try{
    if (in_array('--owner-template-only',$argv,true)) {
        assertOwnerTemplates($db,$created);
        echo "PASS: owner replacement, permissions, replay, single slot, stale Word request, new act, frozen generated evidence and legacy signatures\n";
        return;
    }
    assertWordSecurity($db,$created);
    if (in_array('--security-only',$argv,true)) {
        echo "PASS: confirmed 0/1/2, exact first-invoice amounts, office/parking deadlines, regenerated Word, stale direct DOCX/ZIP/PDF and current handoff\n";
        return;
    }
    ok(WordGenerationData::floorWords('-1')==='минус первом'&&WordGenerationData::floorWords('0')==='нулевом'
        &&WordGenerationData::floorWords('2')==='втором'&&WordGenerationData::floorWords('')==='','Floor numerals include the levels below ground');
    foreach(['Офис','Машиноместо'] as $type){
        $selection=['office'=>0,'parking'=>0];
        $id=fixture($type);$c=Service::get($db,'contracts',$id);actor('lawyer');$plan=WordPack::plan($db,'contract',$id,(int)$c['version'],$selection);
        ok(str_contains(WordPack::action($c),'Сформировать пакет Word'),'Supplied subject uses new package');
        $mixed=array_replace($c,['template_type'=>'mixed']);$mixedAction=WordPack::action($mixed);
        ok(str_contains($mixedAction,'action="/rental/generate"')&&!str_contains($mixedAction,'original_pack'),'Unsupplied mixed subject retains approved legacy generation');
        $input=inputFor($plan);$hashes=hashes($plan);$key=bin2hex(random_bytes(16));
        ok(!array_intersect(['service_number','service_date','service_gross','meter_readings'],array_keys($input)),'No service or actual meter reading input');
        if ($type==='Офис') reject(fn()=>WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input,array_replace($hashes,['office_plan'=>str_repeat('0',64)]),$key,$created),'План изменился');
        reject(fn()=>WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,[],$hashes,$key,$created),'Заполните');
        $pack=WordPack::produce($db,'contract',$id,(int)$c['version'],$selection,$input,$hashes,$key,$created);
        ok(array_column($pack['files'],'kind')===($type==='Офис'?['office','handover','office-plan']:['parking','parking-handover']),'Lease, object act, and applicable plan');
        $deed=wordText($db,$pack['files'][1]);
        ok(str_contains($deed,'именуемое далее по тексту «Арендатор», в лице Генерального директора'),'Manual mode retains the corporate preamble');
        if ($type==='Офис') {
            $unit=$plan['terms'][0]['name'];
            checkOfficeAct($deed,$unit);
            $main=wordText($db,$pack['files'][0]);
            ok(!str_contains($main,'1. В соответствии с пунктом 3.1.2. Договора аренды')
                && str_contains($main,'оформляемый отдельным документом на каждый объект'),'Office contract references the separately generated act');
            foreach (['ПРИЛОЖЕНИЕ № 1','ПРИЛОЖЕНИЕ № 2','ПРИЛОЖЕНИЕ № 4','ПРИЛОЖЕНИЕ № 5','ПРИЛОЖЕНИЕ № 6'] as $appendix)
                ok(str_contains($main,$appendix),'Contract retains '.$appendix);
        } else {
            $main=wordText($db,$pack['files'][0]);
            ok(!str_contains($main,'приема-передачи машино-места (ФОРМА)')
                &&str_contains($main,'отдельным документом на каждое машино-место'),'Parking contract references its separate act');
        }
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
            ok(WordPack::currentDraft($db,$c)===$pack['primary'] && !LegalHandoff::files($db,$c),'Word projects remain distinct from the final handoff');
        }else reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version'],['mode'=>'room-change']),'предназначен для договора помещений');
        // A missing or changed companion invalidates the same signature used at legal approval.
        if ($type==='Офис') {
            $q=$db->prepare('SELECT path FROM rental_files WHERE id=?');$q->execute([$pack['files'][2]['id']]);$path=APP_ROOT.'/.local/documents/'.$q->fetchColumn();$bytes=file_get_contents($path);
            ok(hash('sha256',$bytes)===reset($plan['planProof']),'PDF plan unchanged');
            file_put_contents($path,$bytes.'x');
            reject(fn()=>Evidence::approvalSignature($db,$id,$pack['primary']),'целостности');file_put_contents($path,$bytes);
        }
    }
    foreach (['Офис'=>'office','Машиноместо'=>'parking'] as $type=>$kind) {
        $id=fixture($type,true,['10000.00','12500.00'],'entrepreneur',['png','jpg']);
        $contract=Service::get($db,'contracts',$id);
        $selection=[$kind=>0];
        $subjects=[];
        foreach (App\BillableTerms::forContract($db,$id) as $index=>$term) {
            $objectId=(int)$term['object_id'];
            $db->prepare('UPDATE rental_objects SET area=?,floor=? WHERE id=?')
                ->execute([['20.254','21.75'][$index],[-1,0][$index],$objectId]);
            $cadastre=$db->query('SELECT c.id,c.number FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id='.$objectId)->fetch();
            $building='I, '.(201+$index);
            $entry='77:01:000001-77/001/2026-0'.($index+1);
            $date=['2026-01-15','2026-02-20'][$index];
            DirectoryDetails::store($db,'rental_objects',$objectId,['building_room_number'=>$building]);
            DirectoryDetails::store($db,'cadastre',(int)$cadastre['id'],['ownership_record'=>$entry,'ownership_date'=>$date]);
            $subjects[]=['building'=>$building,'number'=>$cadastre['number'],'entry'=>$entry,'date'=>Documents::dateText($date)];
        }
        confirmChecks($contract);
        $plan=WordPack::plan($db,'contract',$id,(int)$contract['version'],$selection);
        $input=inputFor($plan);
        ok(!array_intersect(['rent_object_rates','party_signer_short','party_signer_genitive','party_signer_title_genitive','party_authority'],array_keys($input)),
            'Object rates are sourced from the contract and an IP needs no director data');
        $pack=WordPack::produce($db,'contract',$id,(int)$contract['version'],$selection,$input,hashes($plan),bin2hex(random_bytes(16)),$created);
        $fileKinds=array_column($pack['files'],'kind');
        ok($fileKinds===($kind==='office'?['office','handover','handover','office-plan','office-plan']:['parking','parking-handover','parking-handover']),
            'Different rates do not change the package structure');
        if ($kind==='office') {
            foreach (array_values($plan['plans']) as $index=>$officePlan) {
                $archivePlan=$pack['files'][$index+3];
                $lookup=$db->prepare('SELECT path,mime FROM rental_files WHERE id=?');
                $lookup->execute([$archivePlan['id']]);
                $saved=$lookup->fetch();
                ok(str_ends_with($archivePlan['filename'],'.'.$officePlan['extension'])
                    &&str_contains($archivePlan['filename'],$officePlan['name'])
                    &&$saved['mime']===$officePlan['mime']
                    &&hash_file('sha256',Documents::directory().'/'.$saved['path'])===$officePlan['sha256'],
                    'PNG or JPEG for each office has the original bytes and acts as its own appendix file');
            }
        }
        $main=wordText($db,$pack['files'][0]);
        ok(str_contains($main,'Перечень объектов аренды')
            &&str_contains($main,$kind==='office'?'нежилые помещения':'машино-места'),'Contract identifies the plural subject and its list');
        foreach (array_values($plan['terms']) as $index=>$term) {
            $amount=['10 000,00','12 500,00'][$index];
            ok(str_contains($main,$term['name'].' — '.$amount.' руб. с НДС'),'Object name and its own rate appear in Word');
            $subject=$term['name'].' — площадь '.['20,254 м², на -1 (минус первом) этаже','21,75 м², на 0 (нулевом) этаже'][$index];
            if ($kind==='office') {
                ['building'=>$building,'number'=>$number,'entry'=>$entry,'date'=>$date]=$subjects[$index];
                $subject.='№ по документам здания '.$building.', кадастровый номер '.$number.'Запись ЕГРН № '.$entry.' от '.$date;
            }
            ok(str_contains($main,$subject),'Subject prints the matching area, floor, and office registry details for each object');
            $act=wordText($db,$pack['files'][$index+1]);
            ok(str_contains($act,'Индивидуальный предприниматель Петров Пётр Петрович, ОГРНИП 326770000012345, именуемый далее по тексту «Арендатор»'),
                'IP act preamble identifies the independent party');
            ok(!str_contains($act,'«Арендатор», в лице')&&!str_contains($act,'Генерального директора')&&substr_count($act,'Петров Пётр Петрович')>=2,
                'IP signs independently under own full name');
            if ($kind==='office') checkOfficeAct($act,$term['name']);
            else ok(str_contains($act,'пунктом 3.1.1. Договора аренды машино-места')
                &&str_contains($act,'машино-место №'.$term['name']),'Parking deed retains its agreed clause and own parking spot');
        }
        ok(str_contains($main,'Всего в месяц с НДС — 22 500,00 руб.'),'Separate gross total is printed');
        ok(!str_contains($main,'Размер арендной платы в месяц за машино-место составляет:'),'Combined rent cannot be read as the price of one parking space');
        $missing=$plan['terms'][0];
        $db->prepare('UPDATE rental_objects SET floor=NULL WHERE id=?')->execute([$missing['object_id']]);
        confirmChecks($contract);
        reject(fn()=>WordPack::plan($db,'contract',$id,(int)$contract['version'],$selection,automatic:true),
            '«'.$missing['name'].'» — этаж');
        $db->prepare('UPDATE rental_objects SET floor=-1 WHERE id=?')->execute([$missing['object_id']]);
        if ($kind==='office') {
            $cadastre=(int)$db->query('SELECT cadastre_id FROM rental_objects WHERE id='.(int)$missing['object_id'])->fetchColumn();
            $registry=DirectoryDetails::read($db,'cadastre',$cadastre);
            DirectoryDetails::store($db,'cadastre',$cadastre,[]);
            confirmChecks($contract);
            reject(fn()=>WordPack::plan($db,'contract',$id,(int)$contract['version'],$selection,automatic:true),
                '«'.$missing['name'].'» — номер записи ЕГРН, дата записи ЕГРН');
            DirectoryDetails::store($db,'cadastre',$cadastre,$registry);
        }
        $db->prepare('UPDATE contracts SET amount=? WHERE id=?')->execute(['22500.01',$id]);
        confirmChecks(Service::get($db,'contracts',$id));
        reject(fn()=>WordPack::plan($db,'contract',$id,(int)$contract['version'],$selection),'Сумма ставок по объектам отличается');
    }
    $id=fixture('Машиноместо',true,['10000.00'],'entrepreneur');
    $contract=Service::get($db,'contracts',$id);
    $partyId=(int)$contract['party_id'];
    DirectoryDetails::store($db,'parties',$partyId,DirectoryDetails::read($db,'parties',$partyId)+[
        'signer'=>'Сидоров Сергей Сергеевич','signer_basis'=>'доверенности № 42']);
    confirmChecks($contract);
    $selection=['parking'=>0];
    $plan=WordPack::plan($db,'contract',$id,(int)$contract['version'],$selection);
    $input=inputFor($plan);
    ok(isset($input['party_signer_genitive'])&&!isset($input['party_signer_title_genitive']),'Known representative needs their name in the manual preamble');
    $input['party_signer_genitive']='Сидорова Сергея Сергеевича';
    $pack=WordPack::produce($db,'contract',$id,(int)$contract['version'],$selection,$input,hashes($plan),bin2hex(random_bytes(16)),$created);
    $act=wordText($db,$pack['files'][1]);
    ok(str_contains($act,'в лице представителя Сидорова Сергея Сергеевича, действующего на основании доверенности № 42')
        && str_contains($act,'Сидоров Сергей Сергеевич'),'Representative appears with their authority and signature');

    // An old contract has real persisted relations, independent of template_type.
    $id=fixture('Офис',documentDate:'2026-11-21');$c=Service::get($db,'contracts',$id);
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
    $update=['party_id'=>$c['party_id'],'document_date'=>'2026-11-21','start_date'=>$c['start_date'],'end_date'=>$c['end_date'],
        'amount'=>'15500.01','objects'=>[$office,$parking],'term_amount'=>[$office=>'10500.01',$parking=>'5000.00']];
    actor('manager');
    foreach (['draft','submit'] as $intent) {
        reject(fn()=>command('contract.create',['intent'=>$intent]+$update),Modules\Rental\ContractDraft::OBJECT_TYPE_ERROR);
    }
    actor('lawyer');
    confirmChecks($c);
    reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version'],automatic:true),'Парковка legacy» — этаж');
    $db->prepare('UPDATE rental_objects SET floor=2 WHERE id=?')->execute([$office]);
    $db->prepare('UPDATE rental_objects SET floor=-1 WHERE id=?')->execute([$parking]);
    confirmChecks($c);
    $mixedPlan=WordPack::plan($db,'contract',$id,(int)$c['version'],automatic:true);
    ok(array_keys($mixedPlan['plans'])===[$office] && count($mixedPlan['acts'])===2,
        'Mixed lease requires an office plan and one act for each object');
    ok(WordGenerationData::inputs($mixedPlan['fields'],$mixedPlan['values'],$mixedPlan['partyType'],true)===[],
        'Mixed ZIP uses the saved contract and counterparties');
    $mixedPack=WordPack::produce($db,'contract',$id,(int)$c['version'],[],[],hashes($mixedPlan),bin2hex(random_bytes(16)),$created,true);
    ok(array_column($mixedPack['files'],'kind')===['mixed','handover','parking-handover','office-plan'],
        'Mixed ZIP contains the approved contract, separate acts and the office plan');
    $mixedText=wordText($db,$mixedPack['files'][0]);
    ok(str_contains($mixedText,'10 500,01') && str_contains($mixedText,'5 000,00') && str_contains($mixedText,'15 500,01')
        && str_contains($mixedText,'Приложение № 1 — поэтажные планы помещений'), 'Mixed Word identifies its rates, total and appendix');
    foreach (array_slice($mixedPack['files'],1,2) as $act) {
        $text=wordText($db,$act);
        ok(str_contains($text,'«21» ноября 2026 года'),'Mixed acts use the contract date');
        ok(str_contains($text,'В соответствии с Договором аренды') && !preg_match('/3\.1\.[12]|п\. 2\.2\./u',$text),
            'Mixed acts reference the contract without clauses from the single-type templates');
    }
    ok(WordPack::currentDraft($db,$c)===$mixedPack['primary'],'The mixed archive can be downloaded again');
    $file=Documents::export($db,'contract',$id,(int)$c['version'],'docx');
    $created[]=APP_ROOT.'/.local/documents/'.basename($file['path']);
    confirmChecks($c);
    $files=[array_intersect_key($file,['id'=>true,'filename'=>true,'sha256'=>true])];
    $db->prepare("UPDATE rental_facts SET fact_json=JSON_SET(fact_json,'$.files',CAST(? AS JSON))
        WHERE namespace='legal_handoff_draft' AND source_key=?")->execute([Importer::json($files),$id]);
    command(LegalHandoff::SECURITY_MULTIPLE,['id'=>$id,'version'=>(int)$c['version'],'multiple'=>'1']);
    command('contract.legal',['id'=>$id,'version'=>(int)$c['version'],'security_multiple'=>1,'packet_fingerprint'=>LegalHandoff::fingerprint($files)]);
    ok(Service::get($db,'contracts',$id)['status']==='accounting','Legacy mixed Word can be approved again');

    $missing=fixture('Офис',false);$c=Service::get($db,'contracts',$missing);actor('lawyer');
    $preview=WordPack::plan($db,'contract',$missing,(int)$c['version'],[],true);
    ok(count($preview['missingPlans'])===1,'Word form identifies the object missing its plan');
    reject(fn()=>WordPack::produce($db,'contract',$missing,(int)$c['version'],[],[],[],bin2hex(random_bytes(16)),$created),'Добавьте план');

    // An approved, intact legacy source stays in the template library.
    $id=fixture('Офис');$c=Service::get($db,'contracts',$id);actor('lawyer');
    $oldId=901099;
    $oldAsset=TemplateAssets::store($db,$oldId,1,dirname(__DIR__).'/modules/rental/templates/office.docx','Архивный офис.docx');
    if ($oldAsset['created']) $created[]=$oldAsset['path'];
    command('template.edit',['id'=>$oldId,'new_template'=>1,'version'=>1,'name'=>'Архивный офис','generation_kind'=>'office','word_file_id'=>$oldAsset['id']]);
    actor('director');command('template.approve',['id'=>$oldId,'version'=>2]);actor('lawyer');
    $plan=WordPack::plan($db,'contract',$id,(int)$c['version'],[],true);
    ok(array_keys($plan['choices'])===[0],'The owner slot is the only office candidate');
    reject(fn()=>WordPack::plan($db,'contract',$id,(int)$c['version'],['office'=>$oldId]),'совместимый');
    ok(TemplateAssets::file($db,$oldId,$oldAsset['id'])['sha256']===$oldAsset['sha256'],
        'Previously approved file is retained for independent legacy evidence');

    echo "PASS: Word packages, object rates, IP signers, role/version guards, idempotency, exact money, plan integrity, compatible sources and legacy mixed review\n";
}finally{if($db->inTransaction())$db->rollBack();foreach(array_unique($created) as $path)if(is_file($path))unlink($path);}
