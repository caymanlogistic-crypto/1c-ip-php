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
echo "Yakovlev rental source evidence OK\n";
