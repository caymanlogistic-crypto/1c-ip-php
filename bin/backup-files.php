<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';echo json_encode(App\FileBackup::create(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
