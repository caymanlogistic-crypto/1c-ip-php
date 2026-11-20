<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

final class ObjectPlan
{
    /** Validate the uploaded file before including its exact contents in the command fingerprint. */
    public static function upload(?array $file): ?array
    {
        if ($file===null || $file['error']===UPLOAD_ERR_NO_FILE) return null;
        if ($file['error']!==UPLOAD_ERR_OK || $file['size']>2*1024*1024 || !is_uploaded_file($file['tmp_name']))
            throw new RuntimeException('Выберите план размером до 2 МБ.',422);
        $bytes=file_get_contents($file['tmp_name']);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension=match($mime) {
            'application/pdf'=>'pdf',
            'image/jpeg'=>'jpg',
            'image/png'=>'png',
            default=>throw new RuntimeException('План должен быть в формате PDF, JPEG или PNG.',422)
        };
        return ['name'=>'plan.'.$extension,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'content'=>base64_encode($bytes)];
    }

    public static function output(array $plan): never
    {
        $bytes=base64_decode($plan['content'],true);
        if ($bytes===false || !hash_equals($plan['sha256'],hash('sha256',$bytes)))
            throw new RuntimeException('Проверка целостности плана не пройдена.',409);
        header('Content-Type: '.$plan['mime']);
        header('Content-Disposition: inline; filename="'.$plan['name'].'"');
        header('Content-Length: '.strlen($bytes));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
