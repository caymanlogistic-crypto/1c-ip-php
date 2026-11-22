<?php
declare(strict_types=1);

use Modules\Rental\MonthlyRentReconciliation;

require dirname(__DIR__).'/app/bootstrap.php';

$source = ['СуммаВключаетНДС'=>false,'Услуги'=>[
    ['Содержание'=>'Аренда офиса за октябрь 2026 г.','Сумма'=>'100.01','СуммаНДС'=>'22.00'],
    ['Содержание'=>'Аренда офиса за ноябрь 2026 г.','Сумма'=>'130','СуммаНДС'=>'28.60'],
    ['Содержание'=>'Клининг за ноябрь 2026 г.','Сумма'=>'50','СуммаНДС'=>'11'],
]];
if (MonthlyRentReconciliation::sourceMonths($source, '341.61') !== ['2026-10'=>'122.01','2026-11'=>'158.60']) {
    throw new RuntimeException('The rent total must count each priced monthly line once and exclude cleaning.');
}

$source['СуммаВключаетНДС'] = true;
$source['Услуги'] = [['Содержание'=>'Аренда офиса за ноябрь 2026 г.','Сумма'=>'122.01','СуммаНДС'=>'22']];
if (MonthlyRentReconciliation::sourceMonths($source, '122.01') !== ['2026-11'=>'122.01']) {
    throw new RuntimeException('Included VAT must not be counted twice.');
}

$source['Услуги'][0]['Содержание'] = 'Арендная плата за парк.место, по Договору №59Ф за ноябрь 26г.';
if (MonthlyRentReconciliation::sourceMonths($source, '122.01', '2026-10-19') !== ['2026-11'=>'122.01']) {
    throw new RuntimeException('The abbreviated year in a posted invoice must be read in the report.');
}
foreach ([null, '2021-10-19'] as $issued) {
    try {
        MonthlyRentReconciliation::sourceMonths($source, '122.01', $issued);
        throw new RuntimeException('An abbreviated year without matching issue date was accepted.');
    } catch (DomainException $error) {
        if ($error->getMessage() !== 'INVOICE_PERIOD_UNCONFIRMED') throw $error;
    }
}

$source['Услуги'][0]['Содержание'] = 'Аренда парковочного места за период с 01 октября по 08 октября 2026г.';
if (MonthlyRentReconciliation::sourceMonths($source, '122.01', '2026-09-17') !== ['2026-10'=>'122.01']) {
    throw new RuntimeException('A repeated month name must not hide an invoice.');
}
foreach (['Аренда офиса за IV квартал 2026 г.', 'Аренда места за период с 30 сентября по 08 октября 2026г.',
    'Аренда места за период с 15 сентября 2025г. по 30 сентября 2026г.'] as $text) {
    $source['Услуги'][0]['Содержание'] = $text;
    try {
        MonthlyRentReconciliation::sourceMonths($source, '122.01', '2026-09-17');
        throw new RuntimeException('A multi-month total was assigned to one month without a breakdown.');
    } catch (DomainException $error) {
        if ($error->getMessage() !== 'INVOICE_PERIOD_UNCONFIRMED') throw $error;
    }
}
$source['Услуги'] = [
    ['Содержание'=>'Аренда парковочного места за период с 15 сентября 2026г. по 30 сентября 2026г.', 'Сумма'=>'13440.00'],
    ['Содержание'=>'Аренда парковочного места за октябрь 2026г.', 'Сумма'=>'25200.00'],
];
if (MonthlyRentReconciliation::sourceMonths($source, '38640.00', '2026-09-11')
    !== ['2026-09'=>'13440.00','2026-10'=>'25200.00']) {
    throw new RuntimeException('The separately priced September and October lines must keep their own amounts.');
}
echo "Monthly rent reconciliation source totals OK\n";
