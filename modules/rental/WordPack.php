<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,Http,View,Audit};
use PDO;
use RuntimeException;

/** Immutable Word package; no changes to contractual terms or external dispatch. */
final class WordPack
{
    public static function plan(PDO $db,string $entity,int $id,int $version,array $selection=[]): array
    {
        $auxiliary=($selection['mode']??'')==='room-change';
        if(isset($selection['mode'])&&!$auxiliary)throw new RuntimeException('Неизвестный состав пакета.',422);
        $data=WordGenerationData::load($db,$entity,$id,$version,$auxiliary);
        if($entity!=='contract')throw new RuntimeException('Этот пакет формируется из договора аренды.',422);
        $primary=TemplateAssets::contractKind($db,$data['contract']);
        if($auxiliary&&$primary!=='office')throw new RuntimeException('Образец смены номера помещения предназначен для договора помещений. Для парковки или смешанного договора нужен соответствующий образец ДС.',422);
        if(!$auxiliary&&!in_array($primary,['office','parking'],true))throw new RuntimeException('Для смешанного договора выберите отдельные документы по предмету аренды.',422);
        $kinds=$auxiliary?['room-change']:[$primary,'services',$primary==='parking'?'parking-handover':'handover'];
        $sources=[];$fields=[];
        foreach($kinds as $kind) {
            $choices=OriginalDocuments::choices($db,$kind);
            $chosen=$selection[$kind]??array_key_first($choices);
            if(!is_scalar($chosen)||!ctype_digit((string)$chosen)||!array_key_exists((int)$chosen,$choices))throw new RuntimeException('Выберите действующий шаблон.',422);
            $sources[$kind]=OriginalDocuments::resolve($db,$kind,(int)$chosen);
            $fields=array_merge($fields,array_diff($sources[$kind]['fields'],array_keys($sources[$kind]['template_values']??[])));
        }
        $fields=array_values(array_unique($fields));
        $values=WordGenerationData::complete($data['values'],[],$fields);
        return array_replace($data,compact('sources','fields','values'));
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
        $db=DB::pdo();$plan=self::plan($db,$entity,$id,$version,$selection);$form='word-pack-'.bin2hex(random_bytes(6));
        $common=self::hidden('entity_type',$entity).self::hidden('id',$id).self::hidden('version',$version);
        $body=RentalUI::pairs([['Договор',$plan['contract']['number']],['Арендатор',$plan['party']['name']],
            ['Помещения',$plan['values']['room_numbers']],['Аренда с НДС',Documents::amountText($plan['record']['amount']).' ₽']]);
        $body.='<form class="registry-ui-modal-grid" method="get" action="/rental/word-form">'.$common;
        if(isset($selection['mode']))$body.=self::hidden('mode',$selection['mode']);
        foreach($plan['sources'] as $kind=>$source)$body.=DirectoryUi::select('template['.$kind.']',OriginalDocuments::TITLES[$kind],OriginalDocuments::choices($db,$kind),$source['template_id']);
        $body.='<div class="demo-row"><button class="btn btn-secondary">Применить выбор шаблонов</button></div></form>';
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values']);
        $body.='<form class="registry-ui-modal-grid" id="'.$form.'" data-word-demo-values="'.View::e(Importer::json(WordGenerationDemo::values($inputs,$plan))).'" method="post" action="/rental/generate">'.$common.self::hidden('_csrf',Http::csrf())
            .self::hidden('original_pack',1).self::hidden('request_key',bin2hex(random_bytes(16)));
        foreach($plan['sources'] as $kind=>$source)$body.=self::hidden('template['.$kind.']',$source['template_id'])
            .self::hidden('source_hash['.$kind.']',$source['sha256']);
        if(isset($selection['mode']))$body.=self::hidden('template[mode]',$selection['mode']);
        if($inputs)$body.=RentalUI::alert('Нужны дополнительные данные','Заполните отсутствующие сведения. Данные договора и расчётные суммы подставляются автоматически. Если счётчиков или пропусков нет, укажите это явно.');
        foreach($inputs as $key=>$field)$body.=Controller::input('data['.$key.']',$field['label'],'',$field['type'],true);
        $body.='</form>';
        return RentalUI::modal(isset($selection['mode'])?'Смена номера помещения · Word':'Сформировать пакет Word',$body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Сформировать документы</button>','approvals',false,
            $inputs?'<button class="btn btn-danger" type="button" form="'.$form.'" data-word-demo-fill>Заполнить данные Демо</button>':'');
    }

    public static function produce(PDO $db,string $entity,int $id,int $version,array $selection,array $input,array $hashes,string $requestKey,array &$created): array
    {
        FileOrder::lock($db);
        $plan=self::plan($db,$entity,$id,$version,$selection);
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new RuntimeException('Откройте форму генерации повторно.',422);
        $fingerprint=hash('sha256',Importer::canonicalJson([$entity,$id,$version,$selection,$input,$hashes]));
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='word_request' AND source_key=?");$q->execute([$requestKey]);$previous=$q->fetchColumn();
        if($previous){$previous=json_decode($previous,true,512,JSON_THROW_ON_ERROR);if($previous['fingerprint']!==$fingerprint)throw new RuntimeException('Ключ формы уже использован.',409);return self::evidence($db,$previous['primary']);}
        $inputs=WordGenerationData::inputs($plan['fields'],$plan['values']);
        if(array_diff_key($input,$inputs))throw new RuntimeException('Нельзя подменять данные договора или расчётные суммы.',422);
        $values=WordGenerationData::complete($plan['values'],$input,$plan['fields']);
        foreach($plan['sources'] as $kind=>$source) {
            if(!is_string($hashes[$kind]??null)||!hash_equals($source['sha256'],$hashes[$kind]))throw new RuntimeException('Шаблон изменился. Откройте форму заново.',409);
            foreach(array_diff($source['fields'],array_keys($source['template_values']??[])) as $field)if(trim((string)($values[$field]??''))==='')throw new RuntimeException('Заполните: '.OriginalDocuments::label($field),422);
        }
        $dir=APP_ROOT.'/.local/documents';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Хранилище документов недоступно.');
        $files=[];$proof=[];$auxiliary=isset($selection['mode']);
        foreach($plan['sources'] as $kind=>$source) {
            $fid=bin2hex(random_bytes(16));$path=$dir.'/'.$fid.'.docx';$created[]=$path;
            OriginalDocuments::buildSource($source,$values,$path);Documents::validateFile($path,'docx');
            $filename=mb_substr(OriginalDocuments::TITLES[$kind].' №'.$plan['contract']['number'],0,220).'.docx';
            $fileKind=(!$files&&!$auxiliary)?'lawyer-file':'word-'.$kind;
            $hash=hash_file('sha256',$path);
            $db->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
                $fid,$entity,$id,$version,$fileKind,$filename,basename($path),$hash,'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
            FileOrder::assign($db,$fid);
            $files[]=['id'=>$fid,'sha256'=>$hash,'filename'=>$filename,'kind'=>$kind];
            $proof[$kind]=array_intersect_key($source,array_flip(['template_id','template_version','sha256']));
        }
        $pack=['primary'=>$files[0]['id'],'entity'=>$entity,'entity_id'=>$id,'version'=>$version,'files'=>$files,'sources'=>$proof,'values'=>$values];
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_pack',?,?,0)")->execute([$pack['primary'],Importer::json($pack)]);
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('word_request',?,?,0)")->execute([$requestKey,Importer::json(['fingerprint'=>$fingerprint,'primary'=>$pack['primary']])]);
        if(!$auxiliary)$db->prepare('DELETE FROM rental_approvals WHERE entity_type=? AND entity_id=?')->execute([$entity,$id]);
        Audit::record('document.generate-package',$entity,$id,null,['files'=>$files,'sources'=>$proof]);
        return $pack;
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
