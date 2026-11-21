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

$source['Услуги'][0]['Содержание'] = 'Аренда офиса за IV квартал 2026 г.';
try {
    MonthlyRentReconciliation::sourceMonths($source, '122.01');
    throw new RuntimeException('A quarterly total was assigned to months without a breakdown.');
} catch (DomainException $error) {
    if ($error->getMessage() !== 'INVOICE_PERIOD_UNCONFIRMED') throw $error;
}
echo "Monthly rent reconciliation source totals OK\n";
