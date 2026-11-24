<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit, DB, View};
use PDO;
use RuntimeException;
use Throwable;

final class ContractReviewFiles
{
    public const ACTIONS=['contract.create','contract.edit','contract.submit'];
    private const FIELD='contract_review_files';
    public const DESCRIPTIONS='contract_review_descriptions';
    private const KIND='review-attachment';
    private const LIMIT=10;
    private const DESCRIPTION_LIMIT=160;

    private array $written=[];

    private function __construct(private array $uploads) {}

    public static function fromRequest(array $files,array $input,bool $legal=false): ?self
    {
        $group=$files[$legal?'legal_files':self::FIELD] ?? [];
        $errors=$group['error'] ?? [];
        $descriptions=$input[self::DESCRIPTIONS] ?? [];
        if (!is_array($errors) || !is_array($descriptions) || count($errors)>self::LIMIT) {
            throw new RuntimeException('За один раз можно добавить до '.self::LIMIT.' файлов.',422);
        }
        foreach ($descriptions as $slot=>$text) {
            if (!is_int($slot) || $slot<0 || !is_string($text) ||
                (trim($text)!=='' && ($errors[$slot] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)) {
                throw new RuntimeException('Выберите файл для каждого указанного описания.',422);
            }
        }
        $uploads=[];
        foreach ($errors as $slot=>$error) {
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            $kind=is_int($slot) && $slot>=0 ? self::KIND : $slot;
            $path=$group['tmp_name'][$slot] ?? '';
            $name=$group['name'][$slot] ?? '';
            $size=$group['size'][$slot] ?? 0;
            if (($kind!==self::KIND && !isset(ContractRevision::KINDS[$kind])) || $error!==UPLOAD_ERR_OK || !is_string($path)
                || !is_string($name) || $size<1 || $size>20*1024*1024 || !is_uploaded_file($path)) {
                throw new RuntimeException($legal
                    ? 'Выберите файлы Word, PDF или ZIP размером до 20 МБ каждый.'
                    : 'Выберите файлы Word или PDF размером до 20 МБ каждый.',422);
            }
            $description=$legal?'Документ юриста':($kind===self::KIND
                ? DirectoryDetails::text($descriptions,(string)$slot,self::DESCRIPTION_LIMIT)
                : ContractRevision::KINDS[$kind]);
            if ($description==='' || preg_match('/\R/u',$description)) {
                throw new RuntimeException('Введите короткое описание для каждого файла.',422);
            }
            $name=basename(str_replace('\\','/',$name));
            $filename=mb_substr(pathinfo($name,PATHINFO_FILENAME),0,245).'.'.pathinfo($name,PATHINFO_EXTENSION);
            $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
            $mime=match($extension) {
                'pdf'=>'application/pdf',
                'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'doc'=>'application/msword',
                'zip'=>$legal?'application/zip':null,
                default=>null,
            };
            if ($mime===null) throw new RuntimeException($legal
                ? 'Комплект должен содержать файлы PDF, DOC, DOCX или ZIP.'
                : 'Материалы должны быть в формате PDF, DOC или DOCX.',422);
            Documents::validateFile($path,$extension);
            $sha=hash_file('sha256',$path);
            if ($sha===false) throw new RuntimeException('Не удалось прочитать материал к договору.',422);
            $uploads[$slot]=compact('kind','description','filename','sha','mime','path','extension');
        }
        ksort($uploads);
        return $uploads ? new self($uploads) : null;
    }

    public function fingerprint(): array
    {
        return array_map(static fn(array $upload): array=>[
            'filename'=>$upload['filename'],'sha256'=>$upload['sha'],
        ]+($upload['kind']===self::KIND?['description'=>$upload['description']]:[]),$this->uploads);
    }

    private static function selection(array $contract): array
    {
        $selection=json_decode($contract['source_json'],true,512,JSON_THROW_ON_ERROR)['review_materials'] ?? [];
        if ($selection && !array_is_list($selection['files'])) {
            $entries=[];
            foreach ($selection['files'] as $kind=>$id) {
                $description=ContractRevision::KINDS[$kind] ?? throw new RuntimeException('Неизвестный вид материала к договору.',409);
                $entries[]=compact('kind','id','description');
            }
            $selection['files']=$entries;
        }
        return $selection;
    }

    public static function selectedIds(array $contract): array
    {
        return array_column(self::selection($contract)['files'] ?? [],'id');
    }

    private static function files(PDO $db,array $contract): array
    {
        $files=[];
        $selection=self::selection($contract);
        if (!$selection) return $files;
        $query=$db->prepare("SELECT id,kind,filename,path,sha256,created_at FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind=? AND id=?");
        foreach ($selection['files'] as $entry) {
            if ($entry['kind']!==self::KIND && !isset(ContractRevision::KINDS[$entry['kind']])) {
                throw new RuntimeException('Неизвестный вид материала к договору.',409);
            }
            $query->execute([$contract['id'],$entry['kind'],$entry['id']]);
            $file=$query->fetch() ?: throw new RuntimeException('Материал к договору не найден.',409);
            $files[]=$file+['description'=>$entry['description']];
        }
        return $files;
    }

    public static function save(PDO $db,int $id,?self $request): void
    {
        $contract=Service::get($db,'contracts',$id);
        if (!in_array($contract['status'],['draft','returned','legal'],true)) return;
        $files=self::files($db,$contract);
        foreach ($request->uploads ?? [] as $upload) {
            $kind=$upload['kind'];
            $replace=null;
            $duplicate=false;
            foreach ($files as $index=>$prior) {
                if ($prior['kind']!==$kind) continue;
                $duplicate=$prior['sha256']===$upload['sha'] && $prior['filename']===$upload['filename']
                    && $prior['description']===$upload['description'];
                if ($kind!==self::KIND) $replace=$index;
                if ($duplicate || $replace!==null) break;
            }
            if ($duplicate) continue;
            $file=$request->write($db,$contract,$upload,$kind);
            if ($replace===null) $files[]=$file; else $files[$replace]=$file;
        }
        if (!$files) return;
        foreach ($files as $file) Evidence::integrity($file);
        $selection=['version'=>(int)$contract['version'],'files'=>array_map(static fn(array $file): array=>
            array_intersect_key($file,['id'=>true,'kind'=>true,'description'=>true]),$files),
            'submitted'=>$contract['status']==='legal'];
        $db->prepare("UPDATE contracts SET source_json=JSON_SET(source_json,'$.review_materials',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json($selection),$id]);
    }

    private function write(PDO $db,array $contract,array $upload,string $kind): array
    {
        $id=bin2hex(random_bytes(16));
        $path=Documents::directory().'/'.$id.'.'.$upload['extension'];
        if (!move_uploaded_file($upload['path'],$path)) throw new RuntimeException('Файл договора не сохранён.');
        $this->written[$id]=$path;
        $filename=$upload['filename'];
        $sha256=$upload['sha'];
        $db->prepare("INSERT INTO rental_files (id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
            VALUES (?,'contract',?,?,?,?,?,?,?)")
            ->execute([$id,$contract['id'],$contract['version'],$kind,$filename,basename($path),$sha256,$upload['mime']]);
        FileOrder::assign($db,$id);
        Audit::record('document.upload','contract',$contract['id'],null,['file'=>$id,'kind'=>$kind,'sha256'=>$sha256]);
        return compact('id','kind','sha256','filename')+['path'=>basename($path),'description'=>$upload['description']];
    }

    public function saveForLegal(PDO $db,array $contract): array
    {
        $files=[];
        foreach ($this->uploads as $upload) {
            $file=$this->write($db,$contract,$upload,'lawyer-file');
            $files[]=array_intersect_key($file,['id'=>true,'sha256'=>true,'filename'=>true]);
        }
        return $files;
    }

    public static function rememberSubmission(PDO $db,array $contract,array $actor,string $commandKey): void
    {
        $selection=self::selection($contract);
        if (!empty($selection['submitted']) && $selection['version']===(int)$contract['version']) {
            ContractHistory::remember($db,$contract,$actor,$commandKey);
        }
    }

    public function removeUncommitted(PDO $db): void
    {
        if (!$this->written) return;
        try {
            $query=$db->prepare('SELECT 1 FROM rental_files WHERE id=?');
            foreach ($this->written as $id=>$path) {
                $query->execute([$id]);
                if (!$query->fetchColumn()) @unlink($path);
            }
        } catch (Throwable) {
            // Preserve the original command error. Keep files if the commit status cannot be checked.
        }
    }

    public static function fields(?array $contract): string
    {
        $html='<section class="registry-ui-modal-grid registry-ui-review-section" aria-label="Материалы для юриста"><h3 class="modal-title">Материалы для юриста</h3>'
            .($contract ? self::presentation($contract) : '');
        return $html.'<div class="registry-ui-modal-grid" data-described-files data-file-name="'.self::FIELD
            .'" data-description-name="'.self::DESCRIPTIONS.'" data-description-limit="'.self::DESCRIPTION_LIMIT
            .'" data-file-limit="'.self::LIMIT.'">'
            .'<label class="file-dropzone registry-ui-review-upload" data-file-dropzone>'
            .'<span class="dz-title">Перетащите сюда файлы</span><span class="btn btn-secondary">Выбрать файлы</span>'
            .'<span class="dz-note">Необязательно. Word или PDF, до 20 МБ на файл.</span>'
            .'<input type="file" accept=".doc,.docx,.pdf" aria-label="Выбрать материалы для юриста" multiple data-pick-described-files></label>'
            .'<p class="field-msg is-error" data-described-file-error role="status" hidden></p>'
            .'<div class="registry-ui-modal-grid" data-described-file-list></div></div></section>';
    }

    private static function priorPresentation(array $contract): string
    {
        $db=DB::pdo();
        $seen=array_fill_keys(self::selectedIds($contract),true);
        $body='';
        $query=$db->prepare("SELECT created_at,snapshot_text,snapshot_sha256 FROM rental_contract_versions
            WHERE contract_id=? ORDER BY version DESC");
        $query->execute([$contract['id']]);
        foreach ($query as $row) {
            if (!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_text']))) continue;
            $prior=Importer::decodeExact($row['snapshot_text'])['contract'] ?? [];
            $files=array_filter(self::files($db,$prior),static fn(array $file):bool=>!isset($seen[$file['id']]));
            if (!$files) continue;
            $body.='<section class="registry-ui-form-section"><h4 class="registry-ui-modal-summary">'
                .View::e((new \DateTimeImmutable($row['created_at']))->format('d.m.Y H:i')).'</h4>'.self::list($files).'</section>';
            $seen+=array_fill_keys(array_column($files,'id'),true);
        }
        return $body?'<details class="registry-ui-form-disclosure"><summary class="btn btn-secondary">Предыдущие материалы</summary>'
            .'<div class="registry-ui-form-section">'.$body.'</div></details>':'';
    }

    public static function presentation(array $contract): string
    {
        $files=self::files(DB::pdo(),$contract);
        if (!$files) return '';
        $selection=self::selection($contract);
        $label=$selection['submitted']?'Материалы менеджера':'Сохранённые материалы проекта';
        return '<p class="registry-ui-modal-help">'.View::e($label).' · версия '.(int)$selection['version'].'</p>'.self::list($files);
    }

    public static function submittedPresentation(array $contract): string
    {
        if (empty(self::selection($contract)['submitted'])) return '';
        $files=self::files(DB::pdo(),$contract);
        return $files?self::list($files,inline:true):'';
    }

    public static function legalPresentation(array $contract,array $party): string
    {
        $db=DB::pdo();
        $files=self::files($db,$contract);
        $source=PartyLegalReview::contractSource($db,$party,PartyLegalReview::state($db,(int)$party['id']));
        if ($source) array_unshift($files,$source+['description'=>'Исходная карточка контрагента']);
        $body=($files?self::list($files,inline:true):'').self::priorPresentation($contract);
        return $body?'<section class="registry-ui-form-section" aria-label="Приложенные файлы">'.$body.'</section>':'';
    }

    private static function list(array $files,bool $inline=false): string
    {
        $layout=$inline?' registry-ui-review-files--inline':'';
        $metadata=$inline?' class="registry-ui-form-section-heading"':'';
        $linkClass=$inline?'':' class="btn btn-secondary"';
        $html='<ul class="registry-ui-form-section registry-ui-review-files'.$layout.'">';
        foreach ($files as $file) {
            $html.='<li><div'.$metadata.'><div class="registry-ui-modal-text">'.View::e($file['description']).'</div>'
                .'<div class="registry-ui-modal-help">'.View::e((new \DateTimeImmutable($file['created_at']))->format('d.m.Y H:i'))
                .'</div></div><a'.$linkClass.' href="/rental/download?id='.View::e($file['id']).'">'
                .View::e($file['filename']).'</a></li>';
        }
        return $html.'</ul>';
    }
}
