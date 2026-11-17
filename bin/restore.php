<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(count($argv)!==2||!getenv('APP_DB_CONFIG_FILE')){fwrite(STDERR,"Usage: APP_DB_CONFIG_FILE=<separate empty DB config> php bin/restore.php private/backup.sql\n");exit(2);}
$p=App\DB::pdo();if($p->query('SHOW TABLES')->fetchColumn())throw new RuntimeException('Restore requires a separate empty database. Existing data is never overwritten.');
$file=realpath($argv[1]);if(!$file||!is_file($file))throw new RuntimeException('Backup not found');$receiptFile=$file.'.receipt.json';if(!is_file($receiptFile))throw new RuntimeException('Backup hash receipt required');$receipt=json_decode(file_get_contents($receiptFile),true,512,JSON_THROW_ON_ERROR);if(!hash_equals($receipt['sha256'],hash_file('sha256',$file)))throw new RuntimeException('Backup hash mismatch');
if($p->inTransaction())throw new RuntimeException('Restore requires its own database session.');
// The dump alternates CREATE TABLE and INSERT rows. MySQL DDL commits the
// preceding table; autocommit=0 batches its rows instead of committing each one.
// This is session-local, not an atomic whole-database restore: failed targets
// retain earlier DDL/tables and must remain incomplete, never resumed in place.
$p->exec('SET SESSION autocommit=0');
try {
    $p->exec(file_get_contents($file));
    $counts=[];
    foreach($receipt['tables'] as $table=>$expected){
        if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unexpected table name');
        $actual=(int)$p->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        if($actual!==$expected)throw new RuntimeException('Restore count mismatch: '.$table);
        $counts[$table]=$actual;
    }
    $p->exec('COMMIT');
} catch (Throwable $failure) {
    // Only the uncommitted tail can be rolled back after MySQL implicit DDL commits.
    try {$p->exec('ROLLBACK');} catch (Throwable $rollbackFailure) {
        throw new RuntimeException('Restore failed and tail rollback failed; target is incomplete.',0,$failure);
    }
    throw $failure;
}
echo json_encode(['restored'=>true,'sha256'=>$receipt['sha256'],'tables'=>$counts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
