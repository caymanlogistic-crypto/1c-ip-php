<?php
declare(strict_types=1);
namespace App;
final class MigrationHashes {
    public static function inventory():array{return require __DIR__.'/migration-eol-compat.php';}
    public static function accepts(string $name,string $current,?string $stored):bool {
        $known=self::inventory()[$name]??null;
        if($known&&!hash_equals($known['canonical'],$current))return false;
        if($stored===null)return true;
        if(hash_equals($current,$stored))return true;
        return $known!==null&&hash_equals($known['historical'],$stored);
    }
}
