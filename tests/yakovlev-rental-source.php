<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\YakovlevInvoiceCoverage;
use Modules\Rental\YakovlevRentalSync;

$source=['Услуги'=>[
    ['Содержание'=>' Арендная плата за парковочное место 2.40, по договору №24 за ноябрь 2026 г.'],
    ['Содержание'=>'Аренда парковочного места 2.40, по договору №24 за декабрь 2026 г.'],
]];
if (!YakovlevInvoiceCoverage::hasRentLine($source) || YakovlevInvoiceCoverage::parkingReferences($source)!==['2.40'])
    throw new RuntimeException('Parking evidence should identify the same object once.');
if (YakovlevInvoiceCoverage::hasRentLine(['Товары'=>[['Содержание'=>'Поставка оборудования']]]))
    throw new RuntimeException('Unrelated sale must not be classified as lease.');
if (YakovlevRentalSync::cents('451648.05000000')!=='451648.05'
    || YakovlevRentalSync::cents('0.00000000',true)!=='0.00')
    throw new RuntimeException('Exact amount changed.');
try {
    YakovlevRentalSync::cents('0.001');
    throw new RuntimeException('Fraction of a kopek was accepted.');
} catch (DomainException $error) {
    if ($error->getMessage()!=='UNCONFIRMED_AMOUNT') throw $error;
}
if (YakovlevInvoiceCoverage::period('Аренда помещения с 18.09.26 по 30.09.26','2026-09-17')
    !==['2026-09','2026-09-18','2026-09-30'])
    throw new RuntimeException('Numeric rent coverage was not recognized.');
$grouped=['Услуги'=>[['Содержание'=>'Арендная плата за парковочные места 1.39, 1.40, 1.12 по договору №46.']]];
if (YakovlevInvoiceCoverage::parkingReferences($grouped)!==['1.39','1.40','1.12'])
    throw new RuntimeException('A grouped parking line must retain every listed object.');
$mentioned=YakovlevRentalSync::invoiceNumbersInPurpose('Оплата по счету № 1099 от 17.09.2026');
if (YakovlevRentalSync::purposeMatchesInvoiceNumbers($mentioned,['АЯИП-001136'])
    || !YakovlevRentalSync::purposeMatchesInvoiceNumbers($mentioned,['АЯИП-001099']))
    throw new RuntimeException('An explicit invoice number in the bank memo must agree with the statement breakdown.');
echo "Yakovlev rental source evidence OK\n";
