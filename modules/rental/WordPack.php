<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,Http,View,Audit,PagePolicy};
use PDO;
use RuntimeException;

/** Immutable Word package; no changes to contractual terms or external dispatch. */
final class WordPack
{
    private const LAYOUT_VERSION=2;

    public static function plan(PDO $db,string $entity,int $id,int $version,array $selection=[],bool $forForm=false,bool $automatic=false): array
    {
        $auxiliary=($selection['mode']??'')==='room-change';
        if(isset($selection['mode'])&&!$auxiliary)throw new RuntimeException('Неизвестный состав пакета.',422);
        $data=WordGenerationData::load($db,$entity,$id,$version,$auxiliary);
        if (!$auxiliary && LegalReview::assigned($data['contract']) && !LegalHandoff::ready($db,$data['contract']))
            throw new RuntimeException('Сначала подтвердите контрагента и условия договора.',409);
        if($entity!=='contract')throw new RuntimeException('Этот пакет формируется из договора аренды.',422);
        $primary=$automatic
            ? TemplateAssets::objectKind($db,$id)
            : TemplateAssets::contractKind($db,$data['contract']);
        if($auxiliary&&$primary!=='office')throw new RuntimeException('Образец смены номера помещения предназначен для договора помещений. Для парковки или смешанного договора нужен соответствующий образец ДС.',422);
        if (!$auxiliary && $primary==='mixed' && !$automatic) throw new RuntimeException('Смешанный договор доступен через скачивание ZIP.',422);
        if (!$auxiliary && $primary!=='mixed' && $data['partyType']==='person') {
            $types=array_unique(array_column($data['terms'],'type'));
            $apartment=count($types)===1 && preg_match('/^апартамент/iu',reset($types));
            $primary=$apartment?'apartment-person':$primary.'-person';
        }
        $kind=$auxiliary?'room-change':$primary;
        $mixedTemplate=$kind==='mixed'?TemplateAssets::active($db,'mixed'):null;
        $choices=$mixedTemplate
            ? [(int)$mixedTemplate['id']=>$mixedTemplate['name'].' · версия '.$mixedTemplate['version']]
            : OriginalDocuments::choices($db,$kind);
        if (!$choices) throw new RuntimeException('Для этого предмета аренды нужен утверждённый Word-шаблон для физлица без плана и ссылок на него. Согласованный файл можно приложить вручную.',422);
        $required=[];
        if ($kind==='office') $required[]='office_plan';
        if (!$auxiliary && $kind!=='mixed' && $data['partyType']==='entrepreneur') $required[]='party_intro';
        $pricedObjects=in_array($kind,['office','parking','mixed'],true);
        if ($pricedObjects && $kind!=='mixed' && count($data['terms'])>1) $required[]='rent_object_rates';
        $resolved=[];$excluded=[];
        if ($required) {
            foreach ($choices as $candidate=>$title) {
                $layout=OriginalDocuments::resolve($db,$kind,$candidate);
                if (array_diff($required,$layout['fields'])) $excluded[$candidate]=$title;
                else $resolved[$candidate]=$layout;
            }
            $choices=array_diff_key($choices,$excluded);
        }
        $requested=$selection[$kind]??null;
        if ($requested!==null && (!is_scalar($requested) || !ctype_digit((string)$requested))) {
            throw new RuntimeException('Выберите действующий шаблон.',422);
        }
        $chosen=$requested===null?array_key_first($choices):(int)$requested;
        if ($forForm && isset($excluded[$chosen])) $chosen=array_key_first($choices);
        if (!isset($choices[$chosen])) {
            throw new RuntimeException('Выберите совместимый утверждённый шаблон и откройте форму заново.',422);
        }
        $source=$mixedTemplate
            ? $mixedTemplate['asset']+[
                'kind'=>'mixed','template_id'=>$chosen,'template_version'=>(int)$mixedTemplate['version'],
                'fields'=>DocxTemplate::fields($mixedTemplate['asset']['path']),
                'template_values'=>['base'=>'','custom_clauses'=>$mixedTemplate['body']??'',
                    'template_version'=>(string)$mixedTemplate['version']],
            ]
            : ($resolved[$chosen]??OriginalDocuments::resolve($db,$kind,$chosen));
        $fields=array_values(array_diff($source['fields'],array_keys($source['template_values']??[])));
        if (in_array('party_intro',$fields,true) && !in_array($data['partyType'],['company','entrepreneur'],true)) {
            throw new RuntimeException('Проверьте тип контрагента в карточке клиента перед формированием Word.',422);
        }
        $plans=[];$missingPlans=[];
        if (in_array($kind,['office','mixed'],true)) {
            $officeTerms=array_filter($data['terms'],static fn(array $term): bool => !in_array($term['type'],TemplateAssets::PARKING_TYPES,true));
            $plans=WordAppendixPlan::load($db,$officeTerms,$missingPlans);
            if ($missingPlans && !$forForm) {
                throw new RuntimeException('Добавьте план в карточку объекта «'.reset($missingPlans).'» для приложения № 1.',422);
            }
        }
        $planProof=array_map(static fn($plan)=>$plan['sha256'],$plans);
        $sources=[$kind=>$source];
        $acts=$auxiliary?[]:WordHandoverDrafts::plan($db,$data['terms']);
        $fields=array_values(array_unique([...$fields,...WordHandoverDrafts::commonFields($acts)]));
        $values=$data['values'];
        if ($automatic && $kind==='office' && empty($values['handover_deadline_words'])) {
            $values['handover_deadline_words']='«___» ____________ 20___ года';
        }
        if ($pricedObjects) {
            $rates=[];
            $sum='0.00';
            foreach ($data['terms'] as $term) {
                $rates[]=$term['name'].' — '.Documents::amountText($term['amount']).' руб. с НДС';
                $sum=bcadd($sum,$term['amount'],2);
            }
            if (bccomp($sum,$data['record']['amount'],2)!==0) {
                throw new RuntimeException('Сумма ставок по объектам отличается от общей аренды. Проверьте условия договора.',422);
            }
            $values['rent_object_rates']=implode("\n",$rates);
        }
        $values=WordGenerationData::complete($values,[],$fields,$data['partyType'],$automatic);
        $leaseTerms=[];
        if ($mixedTemplate) {
            [$values,$leaseTerms]=self::mixedValues($values,$data['terms'],$data['contract']);
        }
        return array_replace($data,compact('sources','acts','fields','values','plans','planProof','choices','excluded','missingPlans','leaseTerms'));
    }

    private static function mixedValues(array $values,array $terms,array $contract): array
    {
        $rows=[];
        foreach ($terms as $term) {
            $type=in_array($term['type'],TemplateAssets::PARKING_TYPES,true)?'Машино-место':'Помещение';
            $from=Documents::dateText($term['start_date']??$contract['start_date']);
            $to=Documents::dateText($term['end_date']??$contract['end_date']);
            $rows[]=[$type.' № '.$term['name'],$term['area']===null?'—':DirectoryUi::area((string)$term['area']),
                $from.' — '.$to,Documents::amountText($term['amount'])];
        }
        $notes="Приложение № 1 — поэтажные планы помещений.\nНа каждый объект составляется отдельный акт приёма-передачи.";
        $values['terms']=implode("\n",array_map(static fn(array $row): string => implode(' · ',$row),$rows))."\n".$notes;
        $values['effective']='Срок аренды: '.Documents::dateText($contract['start_date']).' — '.Documents::dateText($contract['end_date']);
        $values['vat']='В том числе НДС '.$values['vat'].'%.';
        $values['total']=rtrim($values['total'],'.');
        if (!empty($values['party_intro'])) {
            $values['party']=$values['party_intro'].'. ИНН '.$values['party_inn'].', '.$values['party_address'];
        }
        return [$values,['rows'=>$rows,'notes'=>$notes]];
    }

    private static function hidden(string $name,mixed $value): string
    {
        return '<input type="hidden" name="'.View::e($name).'" value="'.View::e($value).'">';
    }

    public static function action(array $contract): string
    {
        if(TemplateAssets::contractKind(DB::pdo(),$contract)!=='mixed')return RentalUI::button('Сформировать пакет Word','/rental/word-form?id='.$contract['id'].'&version='.$contract['version']);
        // No mixed owner sample was supplied; preserve its existing approved Word path.
        return '<form method="post" action="/rental/generate">'.self::hidden('_csrf',Http::csrf())
            .self::hidden('id',$contract['id']).self::hidden('version',$contract['version'])
            .'<button class="btn btn-secondary" name="format" value="docx">Сформировать Word смешанного договора</button></form>';
    }

    public static function form(?array $review=null): string
    {
        $entity=$review?'contract':(string)($_GET['entity_type']??'contract');
        $id=(int)($review['id']??$_GET['id']??0);$version=(int)($review['version']??$_GET['version']??0);
        $selection=is_array($_GET['template']??null)?$_GET['template']:[];
        if(($_GET['mode']??'')==='room-change')$selection['mode']='room-change';
        $db=DB::pdo();$plan=self::plan($db,$entity,$id,$version,$selection,true);$form='word-pack-'.bin2hex(random_bytes(6));
        $kind=array_key_first($plan['sources']);
        $source=$plan['sources'][$kind];
        $common=self::hidden('entity_type',$entity).self::hidden('id',$id).self::hidden('version',$version);
        $body=RentalUI::pairs([['Договор',$plan['contract']['number']],['Арендатор',$plan['party']['name']],
            ['Помещения',$plan['values']['room_numbers']],['Аренда с НДС',Documents::amountText($plan['record']['amount']).' ₽'],
            ['Выбранный шаблон Word',$plan['choices'][$source['template_id']]]]);
        if ($plan['missingPlans']) {
            $body.=RentalUI::alert('Добавьте план для приложения № 1',
                'Менеджер может загрузить PDF, PNG или JPEG в карточке объекта через «Редактировать». Если договор уже согласован в Word или PDF, загрузите готовый файл ниже.','warning');
            foreach ($plan['missingPlans'] as $objectId=>$name) {
                $body.=PagePolicy::level('rental.objects',Auth::user())>=1
                    ? '<div class="demo-row">'.RentalUI::button('Карточка объекта «'.$name.'»','/rental/object?id='.$objectId).'</div>'
                    : RentalUI::pairs([['Объект',$name]]);
            }
            if ($review) return $body;
            return RentalUI::modal('Сформировать пакет Word',$body.LegalReview::upload($plan['contract']),
                RentalUI::button('Перейти к проверке юристом','/rental/action-form?id='.$id.'&action=contract.legal'),'approvals');
        }
        if ($plan['excluded']) $body.=RentalUI::alert('Некоторые редакции пропущены',
            'Для этого пакета нужны поля плана, преамбулы ИП или ставок по объектам. Исходники доступны в разделе «Шаблоны документов».');
        $body.='<form class="registry-ui-modal-grid" method="get" action="'.($review?'/rental/contract':'/rental/word-form').'">'
            .$common.($review?self::hidden('word',1):'');
        if(isset($selection['mode']))$body.=self::hidden('mode',$selection['mode']);
        $body.=DirectoryUi::select('template['.$kind.']',OriginalDocuments::TITLES[$kind],$plan['choices'],$source['template_id']);
        $body.='<div class="demo-row"><button class="btn btn-secondary">Применить выбор шаблонов</button></div></form>';
        $body.='<form class="registry-ui-modal-grid" id="'.$form.'" method="post" action="/rental/generate">'.$common.self::hidden('_csrf',Http::csrf())
            .self::hidden('original_pack',1).self::hidden('request_key',bin2hex(random_bytes(16)));
        foreach($plan['sources'] as $kind=>$source)$body.=self::hidden('template['.$kind.']',$source['template_id'])
            .self::hidden('source_hash['.$kind.']',$source['sha256']);
        foreach ($plan['acts'] as $objectId=>$act) $body.=self::hidden('source_hash[act-'.$objectId.']',$act['source']['sha256']);
        if ($review) $body.=self::hidden('review_workspace',1);
        if($plan['plans'])$body.=self::hidden('source_hash[office_plan]',hash('sha256',Importer::canonicalJson($plan['planProof'])));
        if(isset($selection['mode']))$body.=self::hidden('template[mode]',$selection['mode']);
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType']);
        if($inputs)$body.=RentalUI::alert('Нужны дополнительные данные','Заполните отсутствующие сведения. Данные договора и расчётные суммы подставляются автоматически.');
        foreach($inputs as $key=>$field)$body.=Controller::input('data['.$key.']',$field['label'],'',$field['type'],true);
        if ($review) return $body.'<div class="demo-row"><button class="btn btn-secondary" type="submit">'
            .'Сформировать документы</button></div></form>';
        $body.='</form>';
        return RentalUI::modal(isset($selection['mode'])?'Смена номера помещения · Word':'Сформировать пакет Word',$body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Сформировать документы</button>','approvals');
    }

    public static function produce(PDO $db,string $entity,int $id,int $version,array $selection,array $input,array $hashes,string $requestKey,array &$created,bool $automatic=false): array
    {
        FileOrder::lock($db);
        $plan=self::plan($db,$entity,$id,$version,$selection,automatic:$automatic);
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new RuntimeException('Откройте форму генерации повторно.',422);
        $request=[$entity,$id,$version,$selection,$input,$hashes];
        if ($automatic) $request[]='automatic';
        $fingerprint=hash('sha256',Importer::canonicalJson($request));
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='word_request' AND source_key=?");$q->execute([$requestKey]);$previous=$q->fetchColumn();
        if($previous){$previous=json_decode($previous,true,512,JSON_THROW_ON_ERROR);if($previous['fingerprint']!==$fingerprint)throw new RuntimeException('Ключ формы уже использован.',409);return self::evidence($db,$previous['primary']);}
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType'],$automatic);
        if(array_diff_key($input,$inputs))throw new RuntimeException('Нельзя подменять данные договора или расчётные суммы.',422);
        $values=WordGenerationData::complete($plan['values'],$input,$plan['fields'],$plan['partyType'],$automatic);
        if ($plan['plans'] && (!is_string($hashes['office_plan']??null) ||
            !hash_equals(hash('sha256',Importer::canonicalJson($plan['planProof'])),$hashes['office_plan']))) {
            throw new RuntimeException('План изменился. Откройте форму заново.',409);
        }
        foreach($plan['sources'] as $kind=>$source) {
            if(!is_string($hashes[$kind]??null)||!hash_equals($source['sha256'],$hashes[$kind]))throw new RuntimeException('Шаблон изменился. Откройте форму заново.',409);
            $optional=OriginalDocuments::optionalFields($plan['partyType']);
            foreach(array_diff($source['fields'],array_keys($source['template_values']??[]),$optional,['office_plan']) as $field)if(trim((string)($values[$field]??''))==='')throw new RuntimeException('Заполните: '.OriginalDocuments::label($field),422);
        }
        $dir=APP_ROOT.'/.local/documents';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Хранилище документов недоступно.');
        $files=[];$proof=[];$auxiliary=isset($selection['mode']);
        foreach($plan['sources'] as $kind=>$source) {
            $fid=bin2hex(random_bytes(16));$path=$dir.'/'.$fid.'.docx';$created[$fid]=$path;
            if ($kind==='mixed') {
                $inlinePlans=in_array('office_plan',$source['fields'],true)?$plan['plans']:[];
                DocxTemplate::build($source['path'],array_replace($values,$source['template_values']),$kind,$path,null,$inlinePlans,$plan['leaseTerms']);
            } else {
                OriginalDocuments::buildSource($source,$values,$path,$plan['plans']);
            }
            Documents::validateFile($path,'docx');
            $filename=mb_substr(OriginalDocuments::TITLES[$kind].' №'.$plan['contract']['number'],0,220).'.docx';
            $fileKind='word-'.$kind;
            $hash=hash_file('sha256',$path);
            self::attach($db,$fid,$entity,$id,$version,$fileKind,$filename,$path,$hash,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $files[]=['id'=>$fid,'sha256'=>$hash,'filename'=>$filename,'kind'=>$kind];
            $proof[$kind]=array_intersect_key($source,array_flip(['template_id','template_version','sha256']));
        }
        foreach ($plan['acts'] as $objectId=>$act) {
            $key='act-'.$objectId;
            $source=$act['source'];
            if (!is_string($hashes[$key]??null) || !hash_equals($hashes[$key],$source['sha256'])) {
                throw new RuntimeException('Состав актов изменился. Откройте форму заново.',409);
            }
            $fileId=bin2hex(random_bytes(16));
            $path=$dir.'/'.$fileId.'.docx';
            $created[$fileId]=$path;
            $filename=WordHandoverDrafts::build($db,$plan['contract'],$act,$values,$path);
            $sha256=hash_file('sha256',$path);
            self::attach($db,$fileId,$entity,$id,$version,'word-handover',$filename,$path,$sha256,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $files[]=['id'=>$fileId,'sha256'=>$sha256,'filename'=>$filename,'kind'=>$source['kind']];
            $proof[$key]=array_intersect_key($source,array_flip(['template_id','template_version','sha256']));
        }
        foreach ($plan['plans'] as $officePlan) {
            $extension=$officePlan['extension'];
            $fid=bin2hex(random_bytes(16));$path=$dir.'/'.$fid.'.'.$extension;$created[$fid]=$path;
            if (file_put_contents($path,$officePlan['bytes'])!==strlen($officePlan['bytes'])) {
                throw new RuntimeException('Не удалось сохранить поэтажный план объекта «'.$officePlan['name'].'».');
            }
            if ($extension==='pdf') Documents::validateFile($path,$extension);
            $filename=mb_substr('Приложение № 1 · поэтажный план '.$officePlan['name'].' к договору №'.$plan['contract']['number'],0,220).'.'.$extension;
            self::attach($db,$fid,$entity,$id,$version,'word-office-plan',$filename,$path,$officePlan['sha256'],$officePlan['mime']);
            $files[]=['id'=>$fid,'sha256'=>$officePlan['sha256'],'filename'=>$filename,'kind'=>'office-plan'];
        }
        unset($values['party_passport']);
        $pack=['primary'=>$files[0]['id'],'entity'=>$entity,'entity_id'=>$id,'version'=>$version,
            'layout_version'=>self::LAYOUT_VERSION,'files'=>$files,'sources'=>$proof,'values'=>$values];
        if ($plan['planProof']) $pack['plan_sources']=$plan['planProof'];
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_pack',?,?,0)")->execute([$pack['primary'],Importer::json($pack)]);
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_request',?,?,0)")->execute([$requestKey,Importer::json(['fingerprint'=>$fingerprint,'primary'=>$pack['primary']])]);
        Audit::record('document.generate-package',$entity,$id,null,['files'=>$files,'sources'=>$proof]);
        return $pack;
    }

    private static function attach(PDO $db,string $fileId,string $entity,int $id,int $version,string $kind,string $filename,string $path,string $hash,string $mime): void
    {
        $db->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$fileId,$entity,$id,$version,$kind,$filename,basename($path),$hash,$mime]);
        FileOrder::assign($db,$fileId);
    }

    public static function generate(): never
    {
        Http::checkCsrf();$created=[];
        try{$pack=DB::transaction(function($db)use(&$created){
            foreach(['template','data','source_hash'] as $key)if(!is_array($_POST[$key]??[]))throw new RuntimeException('Некорректная форма.',422);
            return self::produce($db,(string)($_POST['entity_type']??''),(int)($_POST['id']??0),(int)($_POST['version']??0),
                $_POST['template']??[],$_POST['data']??[],$_POST['source_hash']??[],(string)($_POST['request_key']??''),$created);
        });}catch(\Throwable $e){Documents::removeUncommitted(DB::pdo(),$created);throw $e;}
        if (isset($_POST['review_workspace'])) {
            Http::redirect('/rental/contract?id='.$pack['entity_id'].'&word_file='.$pack['primary']);
        }
        Http::redirect('/rental/word-pack?file='.$pack['primary']);
    }

    public static function currentDraft(PDO $db,array $contract): string
    {
        $query=$db->prepare("SELECT id FROM rental_files WHERE entity_type='contract' AND entity_id=? AND version=?
            AND kind IN ('word-mixed','word-office','word-parking','word-office-person','word-parking-person','word-apartment-person')
            ORDER BY upload_order DESC LIMIT 1");
        $query->execute([$contract['id'],$contract['version']]);
        return (string)$query->fetchColumn();
    }

    public static function evidence(PDO $db,string $primary): array
    {
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='word_pack' AND source_key=?");$q->execute([$primary]);$raw=$q->fetchColumn();
        if(!$raw)return [];$pack=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        foreach($pack['files'] as $item){$q=$db->prepare('SELECT * FROM rental_files WHERE id=?');$q->execute([$item['id']]);$f=$q->fetch();
            if(!$f||$f['entity_type']!==$pack['entity']||(int)$f['entity_id']!==$pack['entity_id']||(int)$f['version']!==$pack['version']||!hash_equals($item['sha256'],$f['sha256']))throw new RuntimeException('Состав пакета изменился.',409);
            Evidence::integrity($f);
        }
        return $pack;
    }

    public static function links(array $pack): string
    {
        $body='';foreach($pack['files']??[] as $file)$body.='<div class="demo-row"><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">'.View::e($file['filename']).'</a></div>';
        return $body;
    }

    public static function inherit(PDO $db,array $file): void
    {
        $q=$db->prepare("SELECT id FROM rental_files WHERE entity_type=? AND entity_id=? AND version=? AND kind='lawyer-file' AND id<>? ORDER BY upload_order DESC LIMIT 1");
        $q->execute([$file['entity_type'],$file['entity_id'],$file['version'],$file['id']]);$previous=$q->fetchColumn();
        if(!$previous||!($pack=self::evidence($db,$previous)))return;
        $pack['primary']=$file['id'];$pack['files'][0]=array_replace($pack['files'][0],array_intersect_key($file,array_flip(['id','sha256','filename'])));
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_pack',?,?,0)")->execute([$file['id'],Importer::json($pack)]);
    }

    public static function archive(): never
    {
        if ((Auth::user()['role']??null)!=='lawyer') throw new RuntimeException('Архив доступен юристу.',403);
        Auth::requirePermission('rental.approve.legal');
        PagePolicy::requireCommand('contract.legal');
        $db=DB::pdo();
        $prepare=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
        if ($prepare) {
            Http::checkCsrf();
            $id=(int)($_POST['id']??0);
            $created=[];
            try {
                [$contract,$pack]=DB::transaction(function (PDO $db) use ($id,&$created): array {
                    FileOrder::lock($db);
                    $contract=self::archiveContract($db,$id);
                    Service::version($contract,$_POST);
                    $pack=self::evidence($db,self::currentDraft($db,$contract));
                    $dated=$contract['document_date']
                        && ($pack['values']['act_date_words']??'')===WordGenerationData::dateWords($contract['document_date']);
                    $oldLayout=in_array($pack['files'][0]['kind']??null,['office','parking','mixed'],true)
                        && ($pack['layout_version']??0)!==self::LAYOUT_VERSION;
                    if (!$dated || $oldLayout) $pack=self::prepareArchive($db,$contract,(string)($_POST['request_key']??''),$created);
                    return [$contract,$pack];
                });
            } catch (\Throwable $error) {
                Documents::removeUncommitted($db,$created);
                throw $error;
            }
        } else {
            $contract=self::archiveContract($db,(int)($_GET['id']??0));
            $pack=self::evidence($db,self::currentDraft($db,$contract));
        }
        if (!$pack || ($pack['entity']??null)!=='contract' || $pack['entity_id']!==(int)$contract['id']
            || $pack['version']!==(int)$contract['version']) {
            throw new RuntimeException('Сначала подготовьте заполненные шаблоны для этой версии договора.',404);
        }

        $archive=tempnam(sys_get_temp_dir(),'lease-word-');
        if ($archive===false) throw new RuntimeException('Не удалось открыть временный файл для архива.');
        try {
            $zip=new \ZipArchive();
            if ($zip->open($archive,\ZipArchive::OVERWRITE)!==true) {
                throw new RuntimeException('Не удалось создать ZIP.');
            }
            $lookup=$db->prepare('SELECT path FROM rental_files WHERE id=?');
            $names=[];
            foreach ($pack['files'] as $index=>$file) {
                $lookup->execute([$file['id']]);
                $path=$lookup->fetchColumn();
                $original=basename(str_replace('\\','/',$file['filename']));
                $name=$original;
                $suffix=$index+1;
                while (isset($names[$name])) $name=($suffix++).' · '.$original;
                $names[$name]=true;
                if (!$path || !$zip->addFile(Documents::directory().'/'.basename($path),$name)) {
                    throw new RuntimeException('Не удалось включить файл в архив.');
                }
            }
            if (!$zip->close()) throw new RuntimeException('Не удалось сохранить ZIP.');
            $filename='Договор №'.$contract['number'].' · заполненные шаблоны.zip';
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"contract-templates.zip\"; filename*=UTF-8''".rawurlencode($filename));
            header('Content-Length: '.filesize($archive));
            header('Cache-Control: private, no-store');
            readfile($archive);
        } finally {
            unset($zip);
            unlink($archive);
        }
        exit;
    }

    private static function archiveContract(PDO $db,int $id): array
    {
        $contract=Access::contract($id,2);
        if (!LegalReview::assigned($contract) || !LegalHandoff::ready($db,$contract)) {
            throw new RuntimeException('Сначала подтвердите контрагента и условия договора.',409);
        }
        return $contract;
    }

    private static function prepareArchive(PDO $db,array $contract,string $key,array &$created): array
    {
        $id=(int)$contract['id'];
        $version=(int)$contract['version'];
        $plan=self::plan($db,'contract',$id,$version,automatic:true);
        $missing=WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType'],automatic:true);
        if ($missing) {
            throw new RuntimeException('Не хватает данных для Word: '.implode(', ',array_column($missing,'label')).'.',422);
        }
        $selection=array_map(static fn(array $source):int=>$source['template_id'],$plan['sources']);
        $hashes=array_map(static fn(array $source):string=>$source['sha256'],$plan['sources']);
        foreach ($plan['acts'] as $objectId=>$act) $hashes['act-'.$objectId]=$act['source']['sha256'];
        if ($plan['plans']) $hashes['office_plan']=hash('sha256',Importer::canonicalJson($plan['planProof']));
        return self::produce($db,'contract',$id,$version,$selection,[],$hashes,$key,$created,automatic:true);
    }

    public static function card(): string
    {
        $id=(string)($_GET['file']??'');$q=DB::pdo()->prepare('SELECT * FROM rental_files WHERE id=?');$q->execute([$id]);
        $file=$q->fetch()?:throw new RuntimeException('Файл не найден.',404);Access::file($file);
        $pack=self::evidence(DB::pdo(),$id);if(!$pack)throw new RuntimeException('Пакет не найден.',404);
        $auxiliary=$pack['files'][0]['kind']==='room-change';
        return RentalUI::modal('Документы Word сформированы',self::links($pack),RentalUI::button($auxiliary?'Вернуться к договору':'Вернуться к согласованию',
            $auxiliary?'/rental/contract?id='.$pack['entity_id']:'/rental/action-form?id='.$pack['entity_id'].'&action=contract.legal',true),'approvals');
    }
}
