<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{ReservationMaxRelay,Support};

try {
    $args=getopt('', ['booking:','batch:']);
    $events=(new ReservationMaxRelay(DB::pdo()))->queueExamples((string)($args['booking']??''),(string)($args['batch']??''));
    echo Support::json(['queued'=>count($events),'events'=>array_keys($events)]),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}
