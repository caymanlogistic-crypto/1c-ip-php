<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,Audit,DB,Http,View};
use PDO;
use RuntimeException;

/** SHA-bound PDF derivative of the actual current Word file, including an uploaded approved DOCX. */
final class WordDerivative
{
    private static function source(PDO $db,string $fileId): array
    {
        Auth::requirePermission('rental.read');
        $query=$db->prepare('SELECT * FROM rental_files WHERE id=? FOR UPDATE');
        $query->execute([$fileId]);
        $file=$query->fetch() ?: throw new RuntimeException('Word-файл не найден.',404);
        Access::file($file);
        if (!in_array($file['entity_type'],['contract','document'],true) || $file['kind']!=='lawyer-file'
            || !in_array(strtolower(pathinfo($file['filename'],PATHINFO_EXTENSION)),['docx','pdf'],true)) throw new RuntimeException('Для этого файла преобразование Word PDF недоступно.',422);
        $entity=$file['entity_type']; $id=(int)$file['entity_id'];
        $record=$entity==='contract' ? Access::contract($id,2) : Access::document($id,2);
        $latest=Evidence::latest($db,$entity,$id,'lawyer-file');
        if ($latest['id']!==$file['id']) throw new RuntimeException('Выбранный Word уже заменён новой версией.',409);
        $query=$db->prepare("SELECT * FROM rental_approvals WHERE entity_type=? AND entity_id=? AND role='lawyer'");
        $query->execute([$entity,$id]);
        $approval=$query->fetch();
        $approved=$approval && $approval['file_id']===$fileId;
        if ($approved) {
            $baseline=$entity==='contract' ? Evidence::approvalSignature($db,$id,$fileId) : Evidence::documentSignature($db,$record,$fileId);
            $approved=hash_equals($approval['baseline_hash'],$baseline);
        }
        $legalStage=$record['status']==='legal' || ($record['status']==='returned' && $record['return_to']==='lawyer');
        $editing=$legalStage && in_array(Auth::user()['role'],['admin','lawyer'],true) && (int)$file['version']===(int)$record['version'];
        if (!$approved && !$editing) throw new RuntimeException('Word не подтверждён для текущих условий документа.',409);
        Evidence::integrity($file);
        if ($editing && $entity==='contract') WordSecurityTerms::verifyFile($db,$record,$file);
        return [$file,$record];
    }

    public static function produce(PDO $db,string $fileId,?WordRenderer $renderer=null): array
    {
        FileOrder::lock($db);
        [$file,$record]=self::source($db,$fileId);
        if (strtolower(pathinfo($file['filename'],PATHINFO_EXTENSION))==='pdf') {
            Documents::validateFile(APP_ROOT.'/.local/documents/'.basename($file['path']),'pdf');
            return $file;
        }
        $query=$db->prepare("SELECT * FROM rental_files WHERE entity_type=? AND entity_id=? AND version=? AND kind='word-pdf' AND baseline_hash=? ORDER BY upload_order DESC LIMIT 1");
        $query->execute([$file['entity_type'],$file['entity_id'],$record['version'],$file['sha256']]);
        if ($cached=$query->fetch()) {
            Evidence::integrity($cached);
            return $cached;
        }
        $source=APP_ROOT.'/.local/documents/'.basename($file['path']);
        $rendered=($renderer ?? WordRenderer::configured())->render($source,$file['sha256']);
        $bytes=filesize($rendered['path']);
        if (disk_free_space(APP_ROOT)-$bytes<512*1024*1024) throw new RuntimeException('Недостаточно места для сохранения PDF.');
        $id=bin2hex(random_bytes(16));
        $path=APP_ROOT.'/.local/documents/'.$id.'.pdf';
        try {
            if (!copy($rendered['path'],$path) || !hash_equals($rendered['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Не удалось сохранить точную копию PDF.');
            $db->prepare(
                "INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime,baseline_hash) VALUES(?,?,?,?,'word-pdf',?,?,?,'application/pdf',?)"
            )->execute([$id,$file['entity_type'],$file['entity_id'],$record['version'],pathinfo($file['filename'],PATHINFO_FILENAME).'.pdf',basename($path),$rendered['sha256'],$file['sha256']]);
            FileOrder::assign($db,$id);
            if ($file['entity_type']==='contract') WordSecurityTerms::rememberDerivative($db,$record,$file['sha256'],$rendered['sha256']);
            Audit::record('document.word-pdf',$file['entity_type'],$file['entity_id'],null,[
                'source_file_id'=>$fileId,'source_sha256'=>$file['sha256'],'record_version'=>(int)$record['version'],
                'file_id'=>$id,'sha256'=>$rendered['sha256'],'renderer_receipt'=>$rendered['receipt_path'],
            ]);
        } catch (\Throwable $error) {
            if (is_file($path)) unlink($path);
            throw $error;
        }
        $query=$db->prepare('SELECT * FROM rental_files WHERE id=?'); $query->execute([$id]);
        return $query->fetch();
    }

    public static function endpoint(): never
    {
        Http::checkCsrf();
        $fileId=(string)($_POST['file_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$fileId)) throw new RuntimeException('Word-файл не найден.',404);
        $pdf=DB::transaction(static fn(PDO $db)=>self::produce($db,$fileId));
        Http::redirect('/rental/download?id='.$pdf['id']);
    }

    public static function form(string $fileId): string
    {
        return '<form method="post" action="/rental/word-pdf"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="file_id" value="'.View::e($fileId).'"><button class="btn btn-secondary" type="submit">Скачать PDF</button></form>';
    }
}
