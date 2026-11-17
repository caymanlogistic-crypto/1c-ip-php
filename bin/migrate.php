<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try{echo json_encode(['applied'=>App\Migrator::run()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,'Migration failed: '.$e->getMessage().PHP_EOL);exit(1);}
