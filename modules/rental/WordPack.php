<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,Http,View,Audit,PagePolicy};
use PDO;
use RuntimeException;

/** Immutable Word package; no changes to contractual terms or external dispatch. */
final class WordPack
{
    public static function plan(PDO $db,string $entity,int $id,int $version,array $selection=[],bool $forForm=false): array
    {
        $auxiliary=($selection['mode']??'')==='room-change';
        if(isset($selection['mode'])&&!$auxiliary)throw new RuntimeException('Неизвестный состав пакета.',422);
        $data=WordGenerationData::load($db,$entity,$id,$version,$auxiliary);
        if($entity!=='contract')throw new RuntimeException('Этот пакет формируется из договора аренды.',422);
        $primary=TemplateAssets::contractKind($db,$data['contract']);
        if($auxiliary&&$primary!=='office')throw new RuntimeException('Образец смены номера помещения предназначен для договора помещений. Для парковки или смешанного договора нужен соответствующий образец ДС.',422);
        if(!$auxiliary&&!in_array($primary,['office','parking'],true))throw new RuntimeException('Для смешанного договора выберите отдельные документы по предмету аренды.',422);
        if (!$auxiliary && $data['partyType']==='person') {
            $types=array_unique(array_column($data['terms'],'type'));
            $apartment=count($types)===1 && preg_match('/^апартамент/iu',reset($types));
            $primary=$apartment?'apartment-person':$primary.'-person';
        }
        $kind=$auxiliary?'room-change':$primary;
        $choices=OriginalDocuments::choices($db,$kind);
        if (!$choices) throw new RuntimeException('Для этого предмета аренды нужен утверждённый Word-шаблон для физлица без плана и ссылок на него. Согласованный файл можно приложить вручную.',422);
        $required=[];
        if ($kind==='office') $required[]='office_plan';
        if (!$auxiliary && $data['partyType']==='entrepreneur') $required[]='party_intro';
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
        $source=$resolved[$chosen]??OriginalDocuments::resolve($db,$kind,$chosen);
        $fields=array_values(array_diff($source['fields'],array_keys($source['template_values']??[])));
        if (in_array('party_intro',$fields,true) && !in_array($data['partyType'],['company','entrepreneur'],true)) {
            throw new RuntimeException('Проверьте тип контрагента в карточке клиента перед формированием Word.',422);
        }
        $plans=[];$missingPlans=[];
        if ($kind==='office') {
            $plans=WordAppendixPlan::load($db,$data['terms'],$missingPlans);
            if ($missingPlans && !$forForm) {
                throw new RuntimeException('Добавьте план в карточку объекта «'.reset($missingPlans).'» для приложения № 1.',422);
            }
        }
        $planProof=array_map(static fn($plan)=>$plan['sha256'],$plans);
        $sources=[$kind=>$source];
        $values=WordGenerationData::complete($data['values'],[],$fields,$data['partyType']);
        return array_replace($data,compact('sources','fields','values','plans','planProof','choices','excluded','missingPlans'));
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

    public static function form(): string
    {
        $entity=(string)($_GET['entity_type']??'contract');$id=(int)($_GET['id']??0);$version=(int)($_GET['version']??0);
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
            return RentalUI::modal('Сформировать пакет Word',$body.LegalReview::upload($plan['contract']),
                RentalUI::button('Перейти к проверке юристом','/rental/action-form?id='.$id.'&action=contract.legal'),'approvals');
        }
        if ($plan['excluded']) $body.=RentalUI::alert('Некоторые редакции пропущены',
            'Для этого пакета в них не хватает поля плана или поля преамбулы ИП. Исходники доступны в разделе «Шаблоны документов».');
        $body.='<form class="registry-ui-modal-grid" method="get" action="/rental/word-form">'.$common;
        if(isset($selection['mode']))$body.=self::hidden('mode',$selection['mode']);
        $body.=DirectoryUi::select('template['.$kind.']',OriginalDocuments::TITLES[$kind],$plan['choices'],$source['template_id']);
        $body.='<div class="demo-row"><button class="btn btn-secondary">Применить выбор шаблонов</button></div></form>';
        $body.='<form class="registry-ui-modal-grid" id="'.$form.'" method="post" action="/rental/generate">'.$common.self::hidden('_csrf',Http::csrf())
            .self::hidden('original_pack',1).self::hidden('request_key',bin2hex(random_bytes(16)));
        foreach($plan['sources'] as $kind=>$source)$body.=self::hidden('template['.$kind.']',$source['template_id'])
            .self::hidden('source_hash['.$kind.']',$source['sha256']);
        if($plan['plans'])$body.=self::hidden('source_hash[office_plan]',hash('sha256',Importer::canonicalJson($plan['planProof'])));
        if(isset($selection['mode']))$body.=self::hidden('template[mode]',$selection['mode']);
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType']);
        if($inputs)$body.=RentalUI::alert('Нужны дополнительные данные','Заполните отсутствующие сведения. Данные договора и расчётные суммы подставляются автоматически.');
        foreach($inputs as $key=>$field)$body.=Controller::input('data['.$key.']',$field['label'],'',$field['type'],true);
        $body.='</form>';
        return RentalUI::modal(isset($selection['mode'])?'Смена номера помещения · Word':'Сформировать пакет Word',$body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Сформировать документы</button>','approvals');
    }

    public static function produce(PDO $db,string $entity,int $id,int $version,array $selection,array $input,array $hashes,string $requestKey,array &$created): array
    {
        FileOrder::lock($db);
        $plan=self::plan($db,$entity,$id,$version,$selection);
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new RuntimeException('Откройте форму генерации повторно.',422);
        $fingerprint=hash('sha256',Importer::canonicalJson([$entity,$id,$version,$selection,$input,$hashes]));
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='word_request' AND source_key=?");$q->execute([$requestKey]);$previous=$q->fetchColumn();
        if($previous){$previous=json_decode($previous,true,512,JSON_THROW_ON_ERROR);if($previous['fingerprint']!==$fingerprint)throw new RuntimeException('Ключ формы уже использован.',409);return self::evidence($db,$previous['primary']);}
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values'],$plan['partyType']);
        if(array_diff_key($input,$inputs))throw new RuntimeException('Нельзя подменять данные договора или расчётные суммы.',422);
        $values=WordGenerationData::complete($plan['values'],$input,$plan['fields'],$plan['partyType']);
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
            $fid=bin2hex(random_bytes(16));$path=$dir.'/'.$fid.'.docx';$created[]=$path;
            OriginalDocuments::buildSource($source,$values,$path,$plan['plans']);Documents::validateFile($path,'docx');
            $filename=mb_substr(OriginalDocuments::TITLES[$kind].' №'.$plan['contract']['number'],0,220).'.docx';
            $fileKind=(!$files&&!$auxiliary)?'lawyer-file':'word-'.$kind;
            $hash=hash_file('sha256',$path);
            self::attach($db,$fid,$entity,$id,$version,$fileKind,$filename,$path,$hash,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $files[]=['id'=>$fid,'sha256'=>$hash,'filename'=>$filename,'kind'=>$kind];
            $proof[$kind]=array_intersect_key($source,array_flip(['template_id','template_version','sha256']));
        }
        foreach ($plan['plans'] as $image) {
            if ($image['mime']!=='application/pdf') continue;
            $fid=bin2hex(random_bytes(16));$path=$dir.'/'.$fid.'.pdf';$created[]=$path;
            if (file_put_contents($path,$image['bytes'])!==strlen($image['bytes'])) throw new RuntimeException('Не удалось сохранить PDF приложения № 1.');
            Documents::validateFile($path,'pdf');
            $filename=mb_substr('Приложение № 1 · план '.$image['name'].' к договору №'.$plan['contract']['number'],0,220).'.pdf';
            self::attach($db,$fid,$entity,$id,$version,'word-office-plan',$filename,$path,$image['sha256'],'application/pdf');
            $files[]=['id'=>$fid,'sha256'=>$image['sha256'],'filename'=>$filename,'kind'=>'office-plan'];
        }
        unset($values['party_passport']);
        $pack=['primary'=>$files[0]['id'],'entity'=>$entity,'entity_id'=>$id,'version'=>$version,'files'=>$files,'sources'=>$proof,'values'=>$values];
        if ($plan['planProof']) $pack['plan_sources']=$plan['planProof'];
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_pack',?,?,0)")->execute([$pack['primary'],Importer::json($pack)]);
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_request',?,?,0)")->execute([$requestKey,Importer::json(['fingerprint'=>$fingerprint,'primary'=>$pack['primary']])]);
        if(!$auxiliary)$db->prepare('DELETE FROM rental_approvals WHERE entity_type=? AND entity_id=?')->execute([$entity,$id]);
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
        });}catch(\Throwable $e){foreach($created as $path)if(is_file($path))unlink($path);throw $e;}
        Http::redirect('/rental/word-pack?file='.$pack['primary']);
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
