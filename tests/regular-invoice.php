<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{InitialInvoice, InvoiceDeliveryPreference, RegularInvoice};

function expect(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) throw new RuntimeException(var_export(['actual'=>$actual,'expected'=>$expected],true));
}

expect(RegularInvoice::due('D09','2026-12',['due'=>['date'=>'2026-11-25']]),['date'=>'2026-11-25']);
expect(RegularInvoice::due('D09','2026-12',['due'=>['question'=>'Сверьте подписанный ДС.']]),['date'=>'2026-11-25']);
expect(\Modules\Rental\InvoiceDueDate::firstRent('2026-11-30'),'2026-12-02');
expect(RegularInvoice::due('D17','2026-12',[]),['date'=>'2026-11-25']);
expect(RegularInvoice::due('D03','2027-01',[]),['date'=>'2026-12-25']);
expect(isset(RegularInvoice::due('','2026-12',[])['question']),true);
expect(InitialInvoice::dateError(['document_date'=>'2026-08-20'],'2026-08-19'),
    'Дата первого счёта не может быть раньше даты договора.');

$invoice = ['version'=>2,'sent'=>1];
$accepted = ['status'=>'accepted','receipt_json'=>'{"accepted":true}','entity_version'=>1];
expect(InvoiceDeliveryPreference::status($invoice,$accepted),'Транспорт подтвердил отправку по почте');
expect(InvoiceDeliveryPreference::status(['version'=>4,'sent'=>1],$accepted),'Транспорт подтвердил отправку прежней версии по почте');
expect(InvoiceDeliveryPreference::status($invoice,array_replace($accepted,['receipt_json'=>'{"prepared":true}'])),
    'Счёт сохранён. Отправка не подтверждена');
expect(InvoiceDeliveryPreference::status($invoice,array_replace($accepted,['status'=>'prepared'])),
    'Счёт сохранён. Отправка не подтверждена');
expect(InvoiceDeliveryPreference::status($invoice,null),'Счёт сохранён. Отправка не подтверждена');
echo "Regular invoice deadline and transport states: PASS\n";
