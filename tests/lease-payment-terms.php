<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{LeasePaymentTerms,PaymentDayCalendar};

function expect(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) throw new RuntimeException(var_export(['expected'=>$expected,'actual'=>$actual],true));
}

$contract = ['number'=>'ТЕСТ','document_date'=>'2026-09-25','end_date'=>'2026-11-20'];
$base = ['base'=>['form'=>'D09','gross'=>'9000.00']];
$first = LeasePaymentTerms::period($contract,$base,'2026-11','2026-11-16');
expect($first['days'],5);
expect($first['segments'][0]['to'],'2026-11-20');
expect($first['gross'],'1500.00');
expect($first['due']['trigger'],'2026-11-16');

$contract['end_date'] = '2027-01-31';
$newRate = ['number'=>'ТЕСТ-ДС','document_id'=>1,'rate_from'=>'2026-12-01','net'=>'10000.00','vat_rate'=>'5'];
$period = LeasePaymentTerms::period($contract,$base+['rate:1'=>$newRate],'2026-12','2026-11-16');
expect($period['gross'],'10500.00');
expect($period['vat'],'500.00');
expect($period['due']['date'],'2026-11-25');
expect(str_contains($period['purpose'],'в т. ч. НДС 5 %'),true);

$newRate['rate_from'] = '2026-12-16';
$split = LeasePaymentTerms::period($contract,$base+['rate:1'=>$newRate],'2026-12','2026-11-16');
expect(array_column($split['segments'],'to'),['2026-12-15','2026-12-31']);
expect(array_column($split['segments'],'monthly'),['9000.00','10000.00']);
expect(isset($split['payment_question']),true);
expect(isset($split['purpose']),false);
expect($split['issue_ready'],false);
$unspecified = LeasePaymentTerms::period($contract,$base,'2026-12','2026-11-16',
    [['id'=>1,'number'=>'ТЕСТ-ДС','effective_date'=>'2026-12-16']]);
expect(isset($unspecified['question']),true);
expect($unspecified['issue_ready'],false);

putenv('APP_RENTAL_BANKING_CALENDAR_FILE');
putenv('APP_RENTAL_BANKING_CALENDAR_SHA256');
expect(isset(LeasePaymentTerms::firstDue('D09','2026-11-01',null)['question']),true);
expect(isset(LeasePaymentTerms::firstDue('D09','2026-11-01','2026-11-16')['question']),true);
expect(LeasePaymentTerms::firstDue('D03','2026-11-02',null)['trigger'],'2026-11-02');

$path = tempnam(sys_get_temp_dir(),'lease-calendar-');
if ($path === false) throw new RuntimeException('Could not create test calendar.');
try {
    $bytes = json_encode([
        'unit'=>'banking','admitted'=>true,'source'=>'Synthetic test calendar',
        'valid_from'=>'2026-11-16','valid_to'=>'2026-11-22',
        'days'=>['2026-11-17','2026-11-18','2026-11-19','2026-11-20','2026-11-21'],
    ],JSON_THROW_ON_ERROR);
    file_put_contents($path,$bytes);
    putenv('APP_RENTAL_BANKING_CALENDAR_FILE='.$path);
    putenv('APP_RENTAL_BANKING_CALENDAR_SHA256='.hash('sha256',$bytes));
    expect(PaymentDayCalendar::after('2026-11-16',5,'banking')['date'],'2026-11-21');
    expect(isset(PaymentDayCalendar::after('2026-11-17',5,'banking')['question']),true);
    expect(isset(PaymentDayCalendar::after('2026-11-15',5,'banking')['question']),true);
    expect(PaymentDayCalendar::after('2026-11-16',5,'calendar')['date'],'2026-11-21');
} finally {
    unlink($path);
}

echo "Lease payment terms: PASS\n";
