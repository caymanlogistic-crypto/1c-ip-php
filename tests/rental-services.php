<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{CleaningTerms,RentalServices};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function same(mixed $expected,mixed $actual,string $case): void
{
    if ($expected!==$actual) throw new RuntimeException('Unexpected service terms: '.$case);
}

foreach ([
    ['75','304.50','22837.50'],['120','252.00','30240.00'],['200','210.00','42000.00'],
    ['50.0000','304.50','15225.00'],['99.0000','304.50','30145.50'],['250','210.00','52500.00'],
] as [$area,$rate,$amount]) {
    same($rate,CleaningTerms::rate($area),$area.' rate');
    same($amount,CleaningTerms::amount($area,$rate),$area.' monthly amount');
}
foreach (['49.9999','99.5','149.5','250.0001'] as $area) same(null,CleaningTerms::rate($area),$area.' no automatic rate');
same('2487.53',CleaningTerms::amount('7.5','331.67'),'round half up to kopecks');

$base=['from'=>'2026-09-01','until'=>'2026-11-30','area'=>'75.0000','rate'=>'304.50','monthly'=>'22837.50'];
$agreement=['status'=>'active','terms'=>$base];
$increase=array_replace($base,['from'=>'2026-10-01','rate'=>'325.00','monthly'=>'24375.00']);
$extension=array_replace($increase,['from'=>'2026-12-01','until'=>'2027-02-28']);
$changes=[
    ['status'=>'draft','effective'=>'2026-09-01','kind'=>'tariff','terms'=>$increase],
    ['status'=>'active','effective'=>'2026-10-01','kind'=>'tariff','terms'=>$increase],
    ['status'=>'active','effective'=>'2026-12-01','kind'=>'renew','terms'=>$extension],
    ['status'=>'active','effective'=>'2027-01-01','kind'=>'terminate','terms'=>null],
];
same($base,CleaningTerms::monthTerms($agreement,$changes,'2026-09'),'older signed tariff');
same($increase,CleaningTerms::monthTerms($agreement,$changes,'2026-10'),'new signed tariff');
same($extension,CleaningTerms::monthTerms($agreement,$changes,'2026-12'),'renewal retains the new rate');
same(null,CleaningTerms::monthTerms($agreement,$changes,'2027-01'),'ended service');
same(null,CleaningTerms::monthTerms(['status'=>'draft','terms'=>$base],[],'2026-09'),'draft has no operative tariff');
$midMonth=$changes;
$midMonth[1]['effective']='2026-10-15';
$midMonth[1]['terms']['from']='2026-10-15';
same(null,CleaningTerms::monthTerms($agreement,$midMonth,'2026-10'),'no invented proration on mid-month change');
$ended=['status'=>'ended','start_date'=>'2026-09-01','end_date'=>'2026-10-31'];
same(true,RentalServices::mayAmend($ended,'terminate'),'signed termination can be recorded after the rent ended');
same(false,RentalServices::mayAmend($ended,'renew'),'no renewal based on an ended lease');
same(false,RentalServices::mayAmend($ended,'cleaning'),'no new cleaning based on an ended lease');
echo "PASS: D23 ranges and sums, signed tariff dates, renewal, and termination after lease end.\n";
