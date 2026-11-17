<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';if(count($argv)!==3){fwrite(STDERR,"Usage: php bin/restore-files.php <private backup> <separate EMPTY directory>\n");exit(2);}echo json_encode(App\FileBackup::restore($argv[1],$argv[2]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
