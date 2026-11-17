<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\ {
    Auth, DB
};
use RuntimeException;
final class FinanceFiles {
    public static function save(array $upload, string $entity, int $id, string $kind):array {
        Auth::requirePermission('rental.read');
        Access::finance(2);
        Auth::requirePermission('finance.write');
        Service::roles([
            'accountant'
        ]);
        if (!in_array($entity, [
            'invoice',
            'payment'
        ], true) || !in_array($kind, [
            'invoice-file',
            'payment-order'
        ], true))throw new RuntimeException('Неверный контекст вложения.');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) <= 0 || $upload['size']>20*1024*1024 || !is_uploaded_file($upload['tmp_name']))throw new RuntimeException('Выберите платёжный документ размером до 20 МБ.');
        $hash = hash_file('sha256', $upload['tmp_name']);
        $p = DB::pdo();
        FileOrder::lock($p);
        $row = Service::get($p, $entity === 'invoice'?'invoices':'payments', $id);
        $q = $p->prepare('SELECT id,path FROM rental_files WHERE entity_type=? AND entity_id=? AND kind=? AND sha256=? LIMIT 1');
        $q->execute([
            $entity,
            $id,
            $kind,
            $hash
        ]);
        if ($old = $q->fetch())return [
            'id' => $old['id'],
            'path' => APP_ROOT
            . '/.local/documents/'
            . basename($old['path']),
            'created' => false
        ];
        $name = mb_substr(basename($upload['name']), 0, 250);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]{1,10}$/D', $extension))$extension = 'bin';
        if (in_array($extension, [
            'pdf',
            'docx',
            'doc'
        ], true))Documents::validateFile($upload['tmp_name'], $extension);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name'])?:'application/octet-stream';
        $dir = APP_ROOT
        . '/.local/documents';
        if (!is_dir($dir))mkdir($dir, 0700, true);
        $fid = bin2hex(random_bytes(16));
        $path = $dir
        . '/'
        . $fid
        . '.'
        . $extension;
        if (!move_uploaded_file($upload['tmp_name'], $path))throw new RuntimeException('Не удалось сохранить файл.');
        $p->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
            $fid,
            $entity,
            $id,
            $row['version'],
            $kind,
            $name,
            basename($path),
            $hash,
            $mime
        ]);
        FileOrder::assign($p, $fid);
        if ($entity === 'payment')$p->prepare('UPDATE payments SET source_filename=? WHERE id=?')->execute([
            $name,
            $id
        ]);
        return [
            'id' => $fid,
            'path' => $path,
            'created' => true
        ];
    }
}
