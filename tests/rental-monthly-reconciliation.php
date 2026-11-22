<?php
declare(strict_types=1);

use Modules\Rental\{MonthlyInvoiceArchive, MonthlyRentReconciliation, PhysicalRentScope};

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

$source['Услуги'] = [
    ['Содержание'=>'Аренда офиса за ноябрь 2026г.', 'Сумма'=>'100.00'],
    ['Содержание'=>'Переменная часть арендной платы за ноябрь 2026г.', 'Сумма'=>'30.00'],
    ['Содержание'=>'Арендная плата (переменная часть) за ноябрь 2026г.', 'Сумма'=>'40.00'],
    ['Содержание'=>'Предоставление ключа доступа', 'Сумма'=>'20.00'],
    ['Содержание'=>'Услуги клининга за ноябрь 2026г.', 'Сумма'=>'10.00'],
    ['Содержание'=>'Обеспечительный платёж по договору аренды', 'Сумма'=>'60.00'],
];
if (MonthlyRentReconciliation::rentLines($source) !== [$source['Услуги'][0]]
    || MonthlyRentReconciliation::sourceMonths($source, '260.00', '2026-10-19') !== ['2026-11'=>'100.00']) {
    throw new RuntimeException('Only fixed rent should contribute to the reconciliation.');
}
$source['Услуги'] = [
    ['Содержание'=>'Услуги по аренде нежилых помещений по Договору №108 от 01.05.2025г. за сентябрь 2026г.', 'Сумма'=>'674520.00'],
];
if (MonthlyRentReconciliation::sourceMonths($source, '674520.00', '2026-08-17') !== ['2026-09'=>'674520.00']) {
    throw new RuntimeException('The fixed office rent described as rental services is still rent.');
}
$mixed = ['amount'=>'260.00','rent_amount'=>'100.00'];
if (MonthlyInvoiceArchive::rentPayment($mixed,'260.00') !== '100.00'
    || MonthlyInvoiceArchive::rentPayment($mixed,'0.00') !== '0.00'
    || MonthlyInvoiceArchive::rentPayment($mixed,'100.00') !== null) {
    throw new RuntimeException('A partial payment of a mixed invoice must not be presumed to cover rent.');
}

$source = ['СуммаВключаетНДС'=>true, 'Услуги'=>[
    ['Содержание'=>'Аренда офиса за ноябрь 2026 г.', 'Сумма'=>'100.00'],
    ['Содержание'=>'Аренда офиса (юр) за ноябрь 2026 г.', 'Сумма'=>'50.00'],
    ['Содержание'=>'Аренда машино–места за ноябрь 2026 г.', 'Сумма'=>'20.00'],
    ['Содержание'=>'Арендная плата за оборудование для офиса за ноябрь 2026 г.', 'Сумма'=>'15.00'],
    ['Содержание'=>'Клининг за ноябрь 2026 г.', 'Сумма'=>'10.00'],
    ['Содержание'=>'Услуги по аренде апартаментов за ноябрь 2026 г.', 'Сумма'=>'30.00'],
    ['Содержание'=>'Арендная плата за парк.место №2.33 за ноябрь 2026 г.', 'Сумма'=>'40.00'],
    ['Содержание'=>'Обеспечительный платёж за парковочное место №2.33.', 'Сумма'=>'25.00'],
    ['Содержание'=>'Переменная часть арендной платы за офис.', 'Сумма'=>'10.00'],
]];
$rentalLines = PhysicalRentScope::lines($source);
if ($rentalLines !== array_values(array_intersect_key($source['Услуги'],array_flip([0,2,5,6])))
    || MonthlyRentReconciliation::sourceMonths($source, '300.00', '2026-10-19', $rentalLines) !== ['2026-11'=>'190.00']) {
    throw new RuntimeException('A mixed invoice must contribute only fixed rent of physical objects.');
}
if (!PhysicalRentScope::explicitlyPhysical([$source['Услуги'][6]])) {
    throw new RuntimeException('An unmatched parking invoice must remain visible for review.');
}
$source['Услуги'] = [$source['Услуги'][0]];
if (PhysicalRentScope::lines($source + ['Комментарий'=>'офис (юр) ноябрь 2026']) !== []) {
    throw new RuntimeException('A legal address marked in the original comment must stay outside the rent report.');
}
$lease = ['source_json'=>'{}'];
foreach (['office', 'office-person', 'parking', 'parking-person', 'apartment-person'] as $template) {
    if (!PhysicalRentScope::contract($lease + ['template_type'=>$template])) {
        throw new RuntimeException('The rent scope must include every physical lease template.');
    }
}
$office = $lease + ['template_type'=>'office'];
if (PhysicalRentScope::contract($lease + ['template_type'=>'services'])
    || PhysicalRentScope::contract(array_replace($office, ['source_json'=>'{"archivedRentRestoration":{"use":"billing-only"}}']))
    || PhysicalRentScope::contract(array_replace($office, ['source_json'=>'{"archivedRentRestoration":{"use":"legal"}}']))) {
    throw new RuntimeException('Service, legal address and historical-only contracts must stay outside the rent report.');
}
$terms = [
    ['target_kind'=>'physical-object', 'type'=>'Офис', 'physical_occupancy'=>1],
    ['target_kind'=>'physical-object', 'type'=>'Машино-место', 'physical_occupancy'=>null],
    ['target_kind'=>'physical-object', 'type'=>'Апартаменты', 'physical_occupancy'=>1],
    ['target_kind'=>'physical-object', 'type'=>'Офис', 'physical_occupancy'=>0],
    ['target_kind'=>'billing-part', 'type'=>'LEGAL_ADDRESS', 'physical_occupancy'=>0],
];
if (PhysicalRentScope::terms($terms) !== array_slice($terms,0,3)) {
    throw new RuntimeException('Only fixed rent terms for physical objects belong in the plan.');
}
echo "Monthly rent reconciliation source totals OK\n";
