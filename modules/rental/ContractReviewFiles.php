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

    private array $written=[];

    private function __construct(private array $uploads) {}

    public static function fromRequest(array $files): ?self
    {
        $group=$files[self::FIELD] ?? [];
        $errors=$group['error'] ?? [];
        if (!is_array($errors)) throw new RuntimeException('Проверьте формат вложений.',422);
        $uploads=[];
        foreach ($errors as $kind=>$error) {
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            $path=$group['tmp_name'][$kind] ?? '';
            $name=$group['name'][$kind] ?? '';
            $size=$group['size'][$kind] ?? 0;
            if (!isset(ContractRevision::KINDS[$kind]) || $error!==UPLOAD_ERR_OK || !is_string($path)
                || !is_string($name) || $size<1 || $size>20*1024*1024 || !is_uploaded_file($path)) {
                throw new RuntimeException('Выберите файлы Word или PDF размером до 20 МБ каждый.',422);
            }
            $name=basename(str_replace('\\','/',$name));
            $filename=mb_substr(pathinfo($name,PATHINFO_FILENAME),0,245).'.'.pathinfo($name,PATHINFO_EXTENSION);
            $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
            $mime=match($extension) {
                'pdf'=>'application/pdf',
                'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'doc'=>'application/msword',
                default=>throw new RuntimeException('Материалы должны быть в формате PDF, DOC или DOCX.',422),
            };
            Documents::validateFile($path,$extension);
            $sha=hash_file('sha256',$path);
            if ($sha===false) throw new RuntimeException('Не удалось прочитать материал к договору.',422);
            $uploads[$kind]=['filename'=>$filename,'sha256'=>$sha,'mime'=>$mime,'tmp'=>$path,'extension'=>$extension];
        }
        ksort($uploads);
        return $uploads ? new self($uploads) : null;
    }

    public function fingerprint(): array
    {
        return array_map(static fn(array $upload): array=>array_intersect_key($upload,['filename'=>true,'sha256'=>true]), $this->uploads);
    }

    private static function selection(array $contract): array
    {
        return json_decode($contract['source_json'],true,512,JSON_THROW_ON_ERROR)['review_materials'] ?? [];
    }

    public static function selectedIds(array $contract): array
    {
        return array_values(self::selection($contract)['files'] ?? []);
    }

    private static function files(PDO $db,array $contract): array
    {
        $files=[];
        $selection=self::selection($contract);
        if (!$selection) return $files;
        $query=$db->prepare("SELECT id,kind,filename,path,sha256 FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind=? AND id=?");
        foreach ($selection['files'] as $kind=>$id) {
            if (!isset(ContractRevision::KINDS[$kind])) throw new RuntimeException('Неизвестный вид материала к договору.',409);
            $query->execute([$contract['id'],$kind,$id]);
            $files[$kind]=$query->fetch() ?: throw new RuntimeException('Материал к договору не найден.',409);
        }
        return $files;
    }

    public static function save(PDO $db,int $id,?self $request): void
    {
        $contract=Service::get($db,'contracts',$id);
        if (!in_array($contract['status'],['draft','returned','legal'],true)) return;
        $files=self::files($db,$contract);
        foreach ($request->uploads ?? [] as $kind=>$upload) {
            $previous=$files[$kind] ?? null;
            if ($previous && $previous['sha256']===$upload['sha256'] && $previous['filename']===$upload['filename']) continue;
            $fileId=bin2hex(random_bytes(16));
            $path=Documents::directory().'/'.$fileId.'.'.$upload['extension'];
            if (!move_uploaded_file($upload['tmp'],$path)) throw new RuntimeException('Материал к договору не сохранён.');
            $request->written[$fileId]=$path;
            $db->prepare("INSERT INTO rental_files (id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
                VALUES (?,'contract',?,?,?,?,?,?,?)")->execute([
                    $fileId,$id,$contract['version'],$kind,$upload['filename'],basename($path),$upload['sha256'],$upload['mime'],
                ]);
            FileOrder::assign($db,$fileId);
            $files[$kind]=['id'=>$fileId,'path'=>basename($path),'sha256'=>$upload['sha256']];
            Audit::record('document.upload','contract',$id,null,['file'=>$fileId,'kind'=>$kind,'sha256'=>$upload['sha256']]);
        }
        if (!$files) return;
        foreach ($files as $file) Evidence::integrity($file);
        $selection=['version'=>(int)$contract['version'],'files'=>array_map(static fn(array $file): string=>$file['id'],$files),
            'submitted'=>$contract['status']==='legal'];
        $db->prepare("UPDATE contracts SET source_json=JSON_SET(source_json,'$.review_materials',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json($selection),$id]);
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
        $html='<section class="registry-ui-modal-grid" aria-label="Материалы для юриста"><h3 class="registry-ui-modal-summary">Материалы для юриста</h3>'
            .($contract ? self::presentation($contract) : '');
        foreach (['third-party-draft'=>'Проект договора']+ContractRevision::KINDS as $kind=>$label) {
            $html.='<label class="field"><span class="field-label">'.View::e($label).'</span>'
                .'<input class="field-input" type="file" name="'.self::FIELD.'['.$kind.']" accept=".doc,.docx,.pdf"></label>';
        }
        return $html.'<p class="registry-ui-modal-help">Необязательно. Word или PDF до 20 МБ на файл. Новый файл заменяет материал этого вида в следующей редакции.</p></section>';
    }

    public static function presentation(array $contract): string
    {
        $files=self::files(DB::pdo(),$contract);
        if (!$files) return '';
        $selection=self::selection($contract);
        $label=$selection['submitted']?'Материалы менеджера':'Сохранённые материалы проекта';
        $html='<p class="registry-ui-modal-summary">'.View::e($label).' · версия '.(int)$selection['version'].'</p><div class="demo-row">';
        foreach ($files as $file) {
            $html.='<a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">'
                .View::e(ContractRevision::KINDS[$file['kind']].' · '.$file['filename']).'</a>';
        }
        return $html.'</div>';
    }
}
