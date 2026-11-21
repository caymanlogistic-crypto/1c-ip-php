<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\PaymentDayCalendar;

function expectPreparation(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "OK $label\n";
}

$file = tmpfile();
if (!$file) throw new RuntimeException('Temporary working calendar unavailable');
$path = stream_get_meta_data($file)['uri'];
putenv('APP_STAND=calendar-test');
putenv('APP_RENTAL_WORKING_CALENDAR_FILE='.$path);
putenv('APP_RENTAL_WORKING_CALENDAR_SHA256');
try {
    expectPreparation(isset(PaymentDayCalendar::preparation('2026-12')['question']),'missing approval leaves a question');
    $register = static function (array $days) use ($path): void {
        $json = json_encode(['admitted'=>true,'unit'=>'working','source'=>'Учебный рабочий календарь',
            'valid_from'=>'2026-10-16','valid_to'=>'2026-11-18','days'=>$days],JSON_THROW_ON_ERROR);
        file_put_contents($path,$json);
        putenv('APP_RENTAL_WORKING_CALENDAR_SHA256='.hash('sha256',$json));
    };
    $register(['2026-10-16','2026-10-18','2026-11-17']);
    expectPreparation(PaymentDayCalendar::preparation('2026-12')['date']==='2026-11-17','ordinary 17th');
    expectPreparation(PaymentDayCalendar::preparation('2026-11')['date']==='2026-10-16','single known nonworking day uses yesterday');
    expectPreparation(isset(PaymentDayCalendar::preparation('2027-02')['question']),'uncovered year leaves a question');
    $register(['2026-10-16','2026-11-17']);
    $chain = PaymentDayCalendar::preparation('2026-11');
    expectPreparation(!isset($chain['date']) && str_contains($chain['question'],'несколько нерабочих дней'),'consecutive nonworking days leave a question');
} finally {
    fclose($file);
}
