<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{Importer,InvoiceReport};

function invoiceReportCheck(bool $condition,string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "OK $label\n";
}

$items = [
    ['description'=>'Офис','unit'=>'мес','quantity'=>'1','netPrice'=>'10.00','netAmount'=>'10.00',
        'vatRate'=>'5','vatAmount'=>'0.50','rentalCategory'=>'office','rentalPeriod'=>'2026-10'],
    ['description'=>'Апартаменты','unit'=>'мес','quantity'=>'1','netPrice'=>'20.00','netAmount'=>'20.00',
        'vatRate'=>'5','vatAmount'=>'1.00','rentalCategory'=>'apartment','rentalPeriod'=>'2026-10'],
    ['description'=>'Машино-место','unit'=>'мес','quantity'=>'1','netPrice'=>'30.00','netAmount'=>'30.00',
        'vatRate'=>'0','vatAmount'=>'0.00','rentalCategory'=>'parking','rentalPeriod'=>'2026-11'],
];
$invoice = ['id'=>1,'number'=>'АЯИП-000001','issued'=>'2026-09-09','amount'=>'61.50','vat'=>null];
$proof = static fn(array $lines): string => Importer::json([
    'imported'=>true,'printDetails'=>['number'=>$invoice['number'],'issued'=>$invoice['issued'],'lines'=>$lines],
]);
$confirmed = $invoice + ['source_json'=>$proof($items)];
$oct = InvoiceReport::summarize([$confirmed,$confirmed],'2026-10');
invoiceReportCheck($oct['total']===['net'=>'30.00','tax'=>'1.50','gross'=>'31.50'] && count($oct['included'])===1,
    'mixed invoice contributes selected lines once');
$nov = InvoiceReport::summarize([$confirmed],'2026-11');
invoiceReportCheck($nov['total']===['net'=>'30.00','tax'=>'0.00','gross'=>'30.00'],
    'another month uses only its confirmed line including explicit zero tax');

$withoutTax = $items;
unset($withoutTax[0]['vatAmount']);
$missing = $invoice + ['source_json'=>$proof($withoutTax)];
invoiceReportCheck(str_contains(InvoiceReport::evidence($missing)['reason'],'НДС'),
    'unknown tax stays out of totals');
$withoutKind = $items;
unset($withoutKind[0]['rentalCategory']);
$missing = $invoice + ['source_json'=>$proof($withoutKind)];
invoiceReportCheck(str_contains(InvoiceReport::evidence($missing)['reason'],'предмет аренды'),
    'free text and current contract cannot supply rental subject');

$raw = Importer::decodeExact($confirmed['source_json']);
$native = $invoice + ['source_json'=>Importer::json(['regular_invoice'=>['reportDetails'=>$raw['printDetails']]])];
invoiceReportCheck(InvoiceReport::summarize([$native],'2026-10')['total']===$oct['total'],
    'native issued snapshot uses the same line totals');
$older = $invoice + ['source_json'=>Importer::json(['regular_invoice'=>['rate'=>['label'=>'Old basis']]])];
invoiceReportCheck(str_contains(InvoiceReport::evidence($older)['reason'],'прежнем снимке выпуска'),
    'native invoice without issued lines stays out of totals');

$mismatch = array_replace($confirmed,['amount'=>'61.51']);
invoiceReportCheck(str_contains(InvoiceReport::evidence($mismatch)['reason'],'не совпадает'),
    'inconsistent source totals are rejected');
$wrongNumber = array_replace($confirmed,['number'=>'АЯИП-000002']);
invoiceReportCheck(str_contains(InvoiceReport::evidence($wrongNumber)['reason'],'оригинал'),
    'captured lines stay bound to the original invoice');
