<?php
declare(strict_types=1);
namespace App;
final class SafeErrors {
    public static function record(\Throwable $error):string {$reference=bin2hex(random_bytes(6));$code=(string)$error->getCode();if(!preg_match('/^[a-zA-Z0-9]{0,12}$/D',$code))$code='';$entry=['time'=>date(DATE_ATOM),'reference'=>$reference,'exception_class'=>get_class($error),'code'=>$code];file_put_contents(APP_ROOT.'/runtime/logs/errors.log',json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);return $reference;}
}
