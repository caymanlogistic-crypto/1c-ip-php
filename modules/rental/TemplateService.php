<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\Audit;
use PDO;
use RuntimeException;

final class TemplateService
{
    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        Access::pages(['templates'],2);
        $id=(int)($input['id'] ?? 0);
        $query=$db->prepare('SELECT * FROM rental_templates WHERE id=? FOR UPDATE');
        $query->execute([$id]);
        $template=$query->fetch() ?: null;
        if (!$template) {
            Service::roles(['lawyer']);
            if ($action!=='template.edit' || empty($input['new_template']) || $id<1) throw new RuntimeException('Шаблон не найден.',404);
            $db->prepare("INSERT INTO rental_templates(id,name,version,status,source_json) VALUES(?,?,0,'draft','{}')")->execute([$id,trim((string)($input['name'] ?? ''))]);
            $template=Service::get($db,'rental_templates',$id);
        }
        if ((int)($input['version'] ?? 0)!==(int)$template['record_version']) throw new RuntimeException('Шаблон уже изменён. Откройте актуальную версию.',409);
        if ($action==='template.edit') {
            Service::roles(['lawyer']);
            $name=trim((string)($input['name'] ?? $template['name']));
            $body=(string)($input['body'] ?? '');
            if ($name==='' || mb_strlen($name)>255 || mb_strlen($body)>30000 || str_contains($body,"\0")) throw new RuntimeException('Проверьте название и дополнительные условия шаблона.',422);
            $fileId=(string)($input['word_file_id'] ?? $template['pending_file_id'] ?? $template['word_file_id'] ?? '');
            if ($fileId==='') {
                $source=TemplateAssets::source($db,$template);
                $stored=TemplateAssets::store($db,$id,(int)$template['version'],$source['path'],$source['filename']);
                $fileId=$stored['id'];
            }
            $source=TemplateAssets::file($db,$id,$fileId);
            $metadata=json_decode($template['source_json'],true)?:[];
            $kind=(string)($input['generation_kind']??$metadata['pending_generation_kind']??$metadata['generation_kind']??'');
            if($kind!==''&&!isset(OriginalDocuments::TITLES[$kind]))throw new RuntimeException('Выберите допустимый вид документа.',422);
            if($kind!==''&&!DocxTemplate::fields($source['path']))throw new RuntimeException('В Word нет полей {{...}}. Скачайте основу или обозначьте места подстановки, например {{number}} и {{party_full_name}}.',422);
            $metadata['pending_generation_kind']=$kind;
            $pending=(int)$template['version']+1;
            $db->prepare("UPDATE rental_templates SET name=?,pending_version=?,pending_body=?,pending_file_id=?,status='pending',reason=NULL,record_version=record_version+1 WHERE id=?")
                ->execute([$name,$pending,$body,$fileId,$id]);
            $db->prepare('UPDATE rental_templates SET source_json=? WHERE id=?')->execute([Importer::json($metadata),$id]);
            Audit::record($action,'template',$id,null,['pending_version'=>$pending,'record_version'=>(int)$template['record_version']+1,'source_sha256'=>$source['sha256'],'author_id'=>$actor['id']]);
            $message='Новая версия направлена руководителю. Действующая версия сохранена.';
        } elseif ($action==='template.approve') {
            Service::roles(['director']);
            if ($template['status']!=='pending' || !$template['pending_version'] || !$template['pending_file_id']) throw new RuntimeException('Нужен Word-файл новой версии и решение руководителя.',409);
            $source=TemplateAssets::file($db,$id,$template['pending_file_id']);
            $metadata=json_decode($template['source_json'],true)?:[];
            $metadata['generation_kind']=$metadata['pending_generation_kind']??$metadata['generation_kind']??'';
            unset($metadata['pending_generation_kind']);
            if ((int)$template['version']>0) {
                $db->prepare("INSERT IGNORE INTO rental_template_versions(template_id,version,body,status,word_file_id) VALUES(?,?,?,'approved',?)")
                    ->execute([$id,$template['version'],$template['body'],$template['word_file_id']]);
            }
            $db->prepare("INSERT INTO rental_template_versions(template_id,version,body,status,approver_id,word_file_id) VALUES(?,?,?,'approved',?,?)")
                ->execute([$id,$template['pending_version'],$template['pending_body'],$actor['id'],$template['pending_file_id']]);
            $db->prepare("UPDATE rental_templates SET version=pending_version,body=pending_body,word_file_id=pending_file_id,pending_version=NULL,pending_body=NULL,pending_file_id=NULL,status='approved',reason=NULL,approved_by=?,approved_at=CURRENT_DATE,record_version=record_version+1 WHERE id=?")
                ->execute([$actor['name'],$id]);
            $db->prepare('UPDATE rental_templates SET source_json=? WHERE id=?')->execute([Importer::json($metadata),$id]);
            Audit::record($action,'template',$id,null,['version'=>(int)$template['pending_version'],'source_sha256'=>$source['sha256'],'approver_id'=>$actor['id']]);
            $message='Новая версия Word-шаблона утверждена.';
        } elseif ($action==='template.return') {
            Service::roles(['director']);
            $reason=trim((string)($input['reason'] ?? ''));
            if ($template['status']!=='pending' || !$template['pending_version'] || $reason==='') throw new RuntimeException('Укажите замечания к новой версии шаблона.',422);
            $db->prepare("UPDATE rental_templates SET status='returned',reason=?,record_version=record_version+1 WHERE id=?")->execute([$reason,$id]);
            Audit::record($action,'template',$id,null,['reason'=>$reason,'pending_version'=>$template['pending_version']]);
            $message='Новая версия возвращена юристу. Действующая версия сохранена.';
        } else throw new RuntimeException('Неизвестное действие шаблона.',404);
        Service::event($db,$actor,$action,'Шаблон '.$template['name'].': '.$message);
        return ['id'=>$id,'message'=>$message];
    }
}
