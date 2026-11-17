<?php
declare(strict_types=1);
namespace App;
/** Human projection only; workflow authority remains Auth/SQL role grants. */
final class People {
 private static ?array $names=null;
 public static function roleLabel(?string $kind):string{return match($kind){'manager'=>'Менеджер по аренде','lawyer'=>'Юрист','accountant'=>'Бухгалтер','director'=>'Директор','admin'=>'Администратор','viewer'=>'Наблюдатель',default=>'Ответственный не назначен'};}
 public static function workflowNames():array {if(self::$names!==null)return self::$names;$grouped=[];foreach(DB::pdo()->query("SELECT u.name,p.kind FROM users u JOIN role_profiles p ON p.role_id=u.role WHERE u.active=1 AND p.kind IN('manager','lawyer','accountant','director') ORDER BY u.id")as $u){$name=trim($u['name']);if($name!=='')$grouped[$u['kind']][]=$name;}self::$names=[];foreach(['manager','lawyer','accountant','director']as $kind)self::$names[$kind]=count($grouped[$kind]??[])===1?$grouped[$kind][0]:self::roleLabel($kind);return self::$names;}
 public static function workflowName(?string $kind):string{return self::workflowNames()[$kind]??self::roleLabel($kind);}
 public static function reset():void{self::$names=null;}
}
