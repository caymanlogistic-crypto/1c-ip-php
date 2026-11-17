<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Download only exact source-owner bindings from completed captures; no lookup by filename/hash alone. */
final class CaptureFiles
{
    public static function directory(): string {return Config::root().'/private/integrations/capture-archive';}
    public static function forDocument(\PDO $db,string $documentId): array {
        $q=$db->prepare("SELECT r.source_id,r.capture_id,r.original_name,r.content_type,r.role,b.sha256,b.byte_size,b.private_path,b.available,b.source_status FROM integration_capture_file_refs r JOIN integration_capture_blobs b ON b.capture_id=r.capture_id AND b.sha256=r.blob_sha256 JOIN integration_captures c ON c.id=r.capture_id WHERE r.target_document_id=? AND r.namespace='external_documents' AND r.link_status='source-owner-exact' AND c.status='complete' ORDER BY r.role,r.source_id");$q->execute([$documentId]);return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public static function download(\PDO $db,string $provider,string $captureId,string $referenceId): never {
        if(!preg_match('/^[a-f0-9]{64}$/D',$captureId)||!preg_match('/^[a-f0-9]{32}$/Di',$referenceId))throw new \RuntimeException('Файл не найден',404);
        $q=$db->prepare("SELECT r.*,b.sha256,b.byte_size,b.private_path,b.available FROM integration_capture_file_refs r JOIN integration_capture_blobs b ON b.capture_id=r.capture_id AND b.sha256=r.blob_sha256 JOIN integration_captures c ON c.id=r.capture_id JOIN external_documents d ON d.id=r.target_document_id WHERE r.capture_id=? AND r.source_id=? AND r.namespace='external_documents' AND r.link_status='source-owner-exact' AND c.status='complete' AND d.provider=?");$q->execute([$captureId,$referenceId,$provider]);$f=$q->fetch(\PDO::FETCH_ASSOC);if(!$f||!$f['available']||$f['private_path']!=='blobs/'.$f['sha256'])throw new \RuntimeException('Файл недоступен',404);
        $root=self::directory();$path=$root.'/'.$f['private_path'];if(is_link($root)||is_link($root.'/blobs')||is_link($path)||!is_file($path)||filesize($path)!==(int)$f['byte_size']||!hash_equals($f['sha256'],hash_file('sha256',$path)))throw new \RuntimeException('Не удалось проверить файл',409);
        $mime=$f['content_type'];if(!preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~iD',$mime))$mime='application/octet-stream';$filename=basename(str_replace('\\','/',$f['original_name']));$filename=preg_replace('/[\x00-\x1f\x7f]/','',$filename)?:'document';header('X-Content-Type-Options: nosniff');header('Content-Type: '.$mime);header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($filename));header('Content-Length: '.$f['byte_size']);readfile($path);exit;
    }
    public static function links(\PDO $db,string $documentId,string $provider): string {$h='';foreach(self::forDocument($db,$documentId) as $f){$name=Support::e($f['original_name']);if($f['available'])$h.='<a class="btn btn-secondary" href="/integrations/file?provider='.$provider.'&amp;capture='.$f['capture_id'].'&amp;id='.$f['source_id'].'">'.$name.'</a>';else $h.='<span class="registry-ui-secondary">'.$name.' · содержимое не получено из источника</span>';}return $h;}
}
