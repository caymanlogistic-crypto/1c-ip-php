<?php
declare(strict_types=1);
namespace App;
final class Migrator {
    public static function run(?MainScopeAdmission $mainAdmission = null):array {
        if(PHP_SAPI!=='cli')throw new \RuntimeException('Migrations are CLI-only',403);
        $source=MigrationSource::capture();$p=DB::pdo();$hasTable=(bool)$p->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'")->fetchColumn();$receipts=$hasTable?$p->query('SELECT name,sha256 FROM schema_migrations')->fetchAll(\PDO::FETCH_KEY_PAIR):[];$applied=[];
        foreach($receipts as $name=>$hash)if(!isset($source[$name]))throw new \RuntimeException('Applied migration is missing from committed source: '.$name);
        if(($source[Migration037::ORIGINAL]['sha256']??null)===Migration037::ORIGINAL_SHA&&($source[Migration037::NAME]['sha256']??null)!==Migration037::FORWARD_SHA)throw new \RuntimeException('Historical029 requires the exact mandatory037 forward migration');
        $pending=[];foreach($source as $name=>$captured){$hash=$captured['sha256'];$old=$receipts[$name]??null;if(!MigrationHashes::accepts($name,$hash,$old)&&!Migration037::variant($name,$hash,$old,$source))throw new \RuntimeException('Applied migration changed or unknown source hash: '.$name);if($old===null)$pending[]=[$captured['bytes'],$name,$hash];}
        ScopeMigrationHold::validate(array_column($pending,1),(string)$p->query('SELECT DATABASE()')->fetchColumn(),getenv('APP_DB_CONFIG_FILE')?:null,getenv('APP_SCOPE_COPY_TEST')?:null,$mainAdmission,$p);
        if(isset($source[Migration037::NAME]))Migration037::validate($p,!isset($receipts[Migration037::NAME]));
        // Validate the entire bounded inventory before executing pending DDL.
        if(!$hasTable)$p->exec('CREATE TABLE schema_migrations(name VARCHAR(191) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        foreach($pending as [$bytes,$name,$hash]){
            if($name===Migration037::NAME)Migration037::run($p,$bytes);else $p->exec($bytes);$p->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute([$name,$hash]);$applied[]=$name;
        }return $applied;
    }
}
