<?php
declare(strict_types=1);
namespace App;
final class Audit {
    public static function record(string $action,string $entityType,string|int|null $entityId,mixed $before=null,mixed $after=null):void {
        DB::pdo()->prepare('INSERT INTO audit_log(user_id,action,entity_type,entity_id,before_json,after_json) VALUES(?,?,?,?,?,?)')->execute([Auth::user()['id']??null,$action,$entityType,$entityId,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
