<?php
declare(strict_types=1);
namespace App;
use PDO;
final class DB {
    private static ?PDO $connection=null;
    public static function pdo():PDO {
        if(self::$connection) return self::$connection;
        $file=getenv('APP_DB_CONFIG_FILE')?:APP_ROOT.'/.local/database.php';
        $c=is_file($file)?require $file:[];
        if(!is_array($c)) throw new \RuntimeException('Некорректный формат приватной конфигурации БД');
        if(isset($c['database'])&&is_array($c['database'])) $c=$c['database'];
        $dsn=$c['dsn']??getenv('DB_DSN');
        if(!$dsn) $dsn='mysql:host='.($c['host']??'127.0.0.1').';port='.($c['port']??3306).';dbname='.($c['name']??$c['database']??$c['dbname']??getenv('DB_NAME')).';charset=utf8mb4';
        self::$connection=new PDO($dsn,$c['username']??$c['user']??getenv('DB_USER'),$c['password']??getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        self::$connection->exec("SET time_zone='+03:00'");
        return self::$connection;
    }
    public static function transaction(callable $fn):mixed {
        $p=self::pdo(); if($p->inTransaction()) return $fn($p);
        $p->beginTransaction(); try{$v=$fn($p);$p->commit();return $v;}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
}
