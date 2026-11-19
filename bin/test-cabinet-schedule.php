<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\CabinetSchedule;

$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ipphp-schedule-'.bin2hex(random_bytes(12));
$schedule=new CabinetSchedule($dir);
$assert=static function(bool $valid,string $case): void {
    if (!$valid) throw new RuntimeException($case);
};
$at=static fn(string $date): DateTimeImmutable=>new DateTimeImmutable($date,new DateTimeZone('Europe/Moscow'));
try {
    $assert($schedule->due(['pik','onec'],$at('2026-11-20 08:59'))===[],'STARTED_BEFORE_TIME');
    $assert($schedule->claim(['pik','onec'],$at('2026-11-20 09:00'))===['pik'=>'2026-11-20 09:00'],'FIRST_TIME_OR_ALLOWLIST');
    $assert($schedule->claim(['pik'],$at('2026-11-20 09:01'))===[],'SLOT_CLAIMED_TWICE');
    $assert($schedule->claim(['pik'],$at('2026-11-20 14:26'))===['pik'=>'2026-11-20 14:00'],'SECOND_TIME_OR_DELAY');
    $assert($schedule->claim(['pik'],$at('2026-11-21 14:00'))===['pik'=>'2026-11-21 14:00'],'NEXT_DAY_BLOCKED');
    $future=$at('tomorrow 11:00');
    $schedule->save('mosvodokanal','10:00','11:00',true,0);
    $schedule->save('pik','09:00','14:00',false,0);
    $assert($schedule->claim(['pik','mosvodokanal'],$future)===['mosvodokanal'=>$future->format('Y-m-d H:i')],'CUSTOM_OR_PAUSE');
    foreach ([
        ['pik','24:00','14:00',true,1],
        ['pik','14:00','14:00',true,1],
        ['onec','09:00','14:00',true,0],
    ] as $input) {
        try {
            $schedule->save(...$input);
            throw new LogicException('INVALID_SCHEDULE_ACCEPTED');
        } catch (InvalidArgumentException) {}
    }
    try {
        $schedule->save('pik','10:00','',true,0);
        throw new LogicException('STALE_VERSION_ACCEPTED');
    } catch (RuntimeException $error) {
        $assert($error->getCode()===409,'STALE_VERSION_WRONG_STATUS');
    }
    echo "PASS Moscow defaults, per-provider times, pause, stale revisions and durable slot deduplication\n";
} finally {
    foreach (['cabinet-schedule.json','cabinet-schedule.lock'] as $file) {
        if (is_file($dir.DIRECTORY_SEPARATOR.$file)) unlink($dir.DIRECTORY_SEPARATOR.$file);
    }
    if (is_dir($dir)) rmdir($dir);
}
