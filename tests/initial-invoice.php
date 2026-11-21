<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\InitialInvoice;

function check(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) throw new RuntimeException(var_export(['expected'=>$expected,'actual'=>$actual],true));
}

$early = InitialInvoice::periods('2026-10-15','2026-11-30');
check(array_column($early['rows'],'period'),['2026-10']);
check(InitialInvoice::prorate('100000.00',$early['rows'][0]['from'],$early['rows'][0]['to']),'54838.71');

$late = InitialInvoice::periods('2026-10-16','2026-11-30');
check(array_column($late['rows'],'period'),['2026-10','2026-11']);
check(InitialInvoice::prorate('100000.00',$late['rows'][0]['from'],$late['rows'][0]['to']),'51612.90');
check(InitialInvoice::prorate('100000.00',$late['rows'][1]['from'],$late['rows'][1]['to']),'100000.00');

$insideOctober = InitialInvoice::periods('2026-10-16','2026-10-20');
check(InitialInvoice::prorate('100000.00',$insideOctober['rows'][0]['from'],$insideOctober['rows'][0]['to']),'16129.03');

$insideNovember = InitialInvoice::periods('2026-10-16','2026-11-02');
check(array_column($insideNovember['rows'],'period'),['2026-10']);
check(str_contains($insideNovember['question'],'02.11.2026'),true);

$contract = ['document_date'=>'2026-10-12'];
check(InitialInvoice::dateError($contract,'2026-10-12'),null);
check(InitialInvoice::dateError($contract,'2026-10-13'),null);
check(InitialInvoice::dateError($contract,'2026-10-11'),'Дата первого счёта не может быть раньше даты договора.');

use Modules\Rental\PaymentDayCalendar;

putenv('APP_RENTAL_WORKING_CALENDAR_FILE');
putenv('APP_RENTAL_WORKING_CALENDAR_SHA256');
putenv('APP_RENTAL_BANKING_CALENDAR_FILE');
putenv('APP_RENTAL_BANKING_CALENDAR_SHA256');
foreach (['D17','D03'] as $form) {
    $pending = InitialInvoice::ruleDeadline($form,'security',null,null);
    check($pending['rule'],'Обеспечение: 2 рабочих дня от подписания договора.');
    check(isset($pending['date']),false);
    check(InitialInvoice::ruleDeadline($form,'security','2026-10-14',null)['question'],
        'Нужен подтверждённый рабочий календарь.');
    check(InitialInvoice::ruleDeadline($form,'rent','2026-10-14',null)['question'],
        'Нужен подтверждённый банковский календарь.');
}
check(InitialInvoice::ruleDeadline('D09','security','2026-10-14',null)['trigger'],null);
check(InitialInvoice::ruleDeadline('D09','security','2026-10-14','2026-10-16')['trigger'],'2026-10-16');

$paths = [];
try {
    foreach (['working','banking'] as $unit) {
        $file = tempnam(sys_get_temp_dir(),'initial-clock-');
        if ($file===false) throw new RuntimeException('Could not create a test calendar.');
        $paths[] = $file;
        $json = json_encode(['unit'=>$unit,'admitted'=>true,'source'=>'Synthetic '.$unit,
            'valid_from'=>'2026-10-14','valid_to'=>'2026-10-22','days'=>[
                '2026-10-15','2026-10-16','2026-10-19','2026-10-20','2026-10-21',
            ]],JSON_THROW_ON_ERROR);
        file_put_contents($file,$json);
        $prefix = 'APP_RENTAL_'.strtoupper($unit).'_CALENDAR_';
        putenv($prefix.'FILE='.$file);
        putenv($prefix.'SHA256='.hash('sha256',$json));
    }
    foreach (['D17','D03'] as $form) {
        check(InitialInvoice::ruleDeadline($form,'rent','2026-10-14',null)['date'],'2026-10-21');
        check(InitialInvoice::ruleDeadline($form,'security','2026-10-14',null)['date'],'2026-10-16');
    }
    check(isset(InitialInvoice::ruleDeadline('D17','security','2026-10-21',null)['question']),true);
    check(PaymentDayCalendar::after('2026-10-14',2,'calendar')['date'],'2026-10-16');
} finally {
    foreach ($paths as $file) unlink($file);
}

echo "Initial invoice calculation: PASS\n";
