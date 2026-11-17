<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
echo json_encode(App\Runtime::phpFlags(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
