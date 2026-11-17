<?php
declare(strict_types=1);
namespace App;
final class Http {
    public static function json(mixed $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    public static function redirect(string $url):never {header('Location: '.$url,true,303);exit;}
    public static function csrf():string {return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
    public static function checkCsrf():void {if(!hash_equals(self::csrf(),(string)($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new \RuntimeException('Сессия формы истекла. Обновите страницу.',403);}
}
