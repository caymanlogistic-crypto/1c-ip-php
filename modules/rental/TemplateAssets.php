<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy};
use PDO;
use RuntimeException;

/** Actual immutable Word sources; pending edits never replace the active source. */
final class TemplateAssets
{
    public const PARKING_TYPES=['Машиноместо','Машино-место'];
    public const DEBT_SAMPLE='debt-letter-d28';
    public const SAMPLE_TITLES=[self::DEBT_SAMPLE=>'Письмо о задолженности · образец D28'];

    public const KINDS=[
        'office'=>['id'=>1,'name'=>'Договор аренды офиса','sha256'=>'17e0fd81583d20bce7923f567121794c95c5da401117aa0ffa88a3caa6d61a96'],
        'parking'=>['id'=>2,'name'=>'Договор аренды машиноместа','sha256'=>'ae726f6b0fb6e95d2bc384bc97caa9bc8297e86c57a06e63df77cc877eba8184'],
        'mixed'=>['id'=>900001,'name'=>'Смешанный договор аренды','sha256'=>'75d3d4fcefb20fb3ca5b726cdad2e74bee9d439d36cee1ab9509c9d157a9d7e6'],
        'addendum'=>['id'=>900002,'name'=>'Допсоглашение о продлении','sha256'=>'bc384aa7b9a75b2b0fbe6fe8707970ab722b28bfd688278465c893ee27c5d459'],
    ];

    public static function file(PDO $db,int $template,string $fileId): array
    {
        $query=$db->prepare("SELECT * FROM rental_files WHERE id=? AND entity_type='template' AND entity_id=? AND kind='template-source'");
        $query->execute([$fileId,$template]);
        $file=$query->fetch() ?: throw new RuntimeException('Word-файл шаблона не найден.',409);
        Evidence::integrity($file);
        $path=APP_ROOT.'/.local/documents/'.basename($file['path']);
        DocxTemplate::validate($path);
        return ['path'=>$path,'sha256'=>$file['sha256'],'file_id'=>$file['id'],'filename'=>$file['filename']];
    }

    public static function source(PDO $db,array $template): array
    {
        if ($template['word_file_id']) return self::file($db,(int)$template['id'],$template['word_file_id']);
        foreach (self::KINDS as $kind=>$definition) {
            if ((int)$template['id']!==$definition['id'] || (int)$template['version']!==1) continue;
            $path=__DIR__.'/templates/'.$kind.'.docx';
            if (!hash_equals($definition['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Исходный Word-шаблон изменился.');
            return ['path'=>$path,'sha256'=>$definition['sha256'],'file_id'=>null,'filename'=>$kind.'.docx'];
        }
        throw new RuntimeException('Нет утверждённого Word-источника этой версии.',409);
    }

    public static function active(PDO $db,string $kind): array
    {
        $definition=self::KINDS[$kind] ?? throw new RuntimeException('Неизвестный вид Word-шаблона.',422);
        $query=$db->prepare('SELECT * FROM rental_templates WHERE name=? ORDER BY id LIMIT 1');
        $query->execute([$definition['name']]);
        $template=$query->fetch();
        if (!$template || (int)$template['version']<1) throw new RuntimeException('Нет утверждённой версии выбранного шаблона.',409);
        $template['asset']=self::source($db,$template);
        return $template;
    }

    public static function approvedSamples(PDO $db,string $kind): array
    {
        $query=$db->prepare("SELECT t.id,t.name,t.version,t.word_file_id FROM rental_templates t
            JOIN rental_template_versions v ON v.template_id=t.id AND v.version=t.version
                AND v.status='approved' AND v.word_file_id=t.word_file_id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(t.source_json,'$.generation_kind'))=?
            ORDER BY t.approved_at DESC,t.id DESC");
        $query->execute([$kind]);
        return array_column($query->fetchAll(),null,'id');
    }

    public static function signature(array $template): array
    {
        return [
            'id'=>(int)$template['id'],'version'=>(int)$template['version'],'body'=>$template['body'] ?? '',
            'source_id'=>$template['asset']['file_id'],'source_sha256'=>$template['asset']['sha256'],
        ];
    }

    public static function contractKind(PDO $db,array $contract): string
    {
        $type=$contract['template_type'] ?? '';
        if ($type!=='') {
            if (!in_array($type,['office','parking','mixed'],true)) throw new RuntimeException('Неизвестный вид шаблона договора.',422);
            return $type;
        }
        return self::objectKind($db,(int)$contract['id']);
    }

    public static function objectKind(PDO $db,int $contractId): string
    {
        $query=$db->prepare('SELECT DISTINCT o.type FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=?');
        $query->execute([$contractId]);
        $types=$query->fetchAll(PDO::FETCH_COLUMN);
        if (array_intersect($types,self::PARKING_TYPES)) return array_diff($types,self::PARKING_TYPES) ? 'mixed' : 'parking';
        return 'office';
    }

    public static function store(PDO $db,int $template,int $version,string $source,string $name): array
    {
        Auth::requirePermission('rental.approve.legal');
        Service::roles(['lawyer']);
        PagePolicy::requireCommand('template.edit');
        FileOrder::lock($db);
        if (strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='docx') throw new RuntimeException('Для шаблона нужен Word .docx.',422);
        DocxTemplate::validate($source);
        $sha=hash_file('sha256',$source);
        $query=$db->prepare("SELECT id FROM rental_files WHERE entity_type='template' AND entity_id=? AND kind='template-source' AND sha256=? LIMIT 1");
        $query->execute([$template,$sha]);
        if ($id=$query->fetchColumn()) return ['id'=>$id,'created'=>false]+self::file($db,$template,$id);
        $id=bin2hex(random_bytes(16));
        $directory=APP_ROOT.'/.local/documents';
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Не удалось сохранить Word-шаблон.');
        $path=$directory.'/'.$id.'.docx';
        try {
            if (!copy($source,$path) || !hash_equals($sha,hash_file('sha256',$path))) throw new RuntimeException('Не удалось сохранить точный Word-шаблон.');
            $db->prepare(
                "INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,'template',?,?,'template-source',?,?,?,'application/vnd.openxmlformats-officedocument.wordprocessingml.document')"
            )->execute([$id,$template,$version,mb_substr(basename($name),0,250),basename($path),$sha]);
            FileOrder::assign($db,$id);
        } catch (\Throwable $error) {
            if (is_file($path)) unlink($path);
            throw $error;
        }
        return ['id'=>$id,'created'=>true,'path'=>$path,'sha256'=>$sha];
    }
}
