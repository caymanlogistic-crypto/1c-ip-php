<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{MaxWorkingDestination,Support};

if (PHP_SAPI!=='cli') exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

try {
    $args=getopt('',['database:','apply']);
    $db=DB::pdo();
    if (($args['database']??null)!==$db->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('EXPLICIT_MAX_DESTINATION_DATABASE_REQUIRED');
    }
    $base=MaxWorkingDestination::settings($db);
    if (!$base || !empty($base['via_relay'])) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
    echo Support::json(MaxWorkingDestination::prepareGroup($db,$base,array_key_exists('apply',$args))),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}
