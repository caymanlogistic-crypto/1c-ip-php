<?php
declare(strict_types=1);

use Modules\Integrations\{CabinetSchedule,CabinetSyncAdmission};

if (PHP_SAPI!=='cli'||getenv('APP_STAND')!=='ui'||$argc!==2||!in_array($argv[1],['due','claim'],true)) exit(2);
require dirname(__DIR__).'/app/bootstrap.php';

$db=App\DB::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') exit(2);
$admitted=array_values(array_filter(
    array_keys(CabinetSyncAdmission::configured()),
    static fn(string $provider):bool=>CabinetSyncAdmission::allows($db,$provider,'sync')
));
$schedule=CabinetSchedule::current();
$now=new DateTimeImmutable();
$slots=$argv[1]==='claim'?$schedule->claim($admitted,$now):$schedule->due($admitted,$now);
if ($slots) echo implode(' ',array_keys($slots)),PHP_EOL;
