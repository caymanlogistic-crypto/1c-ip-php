<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Importer, InvoiceDocument};

$db = DB::pdo();
if (PHP_SAPI !== 'cli' || !preg_match('/^ipphp_task_[a-z0-9_]+$/D', (string)$db->query('SELECT DATABASE()')->fetchColumn())) {
    throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
}
$contract = $db->query('SELECT id FROM contracts WHERE document_date IS NOT NULL LIMIT 1')->fetchColumn();
if (!$contract) throw new RuntimeException('SYNTHETIC_CONTRACT_WITH_DATE_REQUIRED');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$payer = ['name'=>'ООО «Покупатель»'];
$payee = ['name'=>'ИП Поставщик', 'bankDetails'=>[
    'bank'=>'Банк из счёта', 'account'=>'00000000000000006984', 'bik'=>'044525225', 'correspondent_account'=>'00000000000000000225',
]];
$print = [
    'number'=>'АЯИП-001014', 'issued'=>'2026-09-09',
    'basis'=>'Договор № 125 от 14.11.2025', 'purpose'=>'Аренда за октябрь',
    'lines'=>[
        ['description'=>'Первая услуга', 'unit'=>'мес', 'quantity'=>1, 'netPrice'=>'256000.00', 'netAmount'=>'256000.00', 'vatRate'=>5, 'vatAmount'=>'12800.00'],
        ['description'=>'Вторая услуга', 'unit'=>'ч', 'quantity'=>'2.5', 'netPrice'=>'288.80', 'netAmount'=>'722.00', 'vatRate'=>5, 'vatAmount'=>'36.10'],
    ],
];
$invoice = [
    'contract_id'=>$contract, 'number'=>$print['number'], 'issued'=>$print['issued'],
    'period'=>'2026-10', 'due'=>'2026-09-25', 'amount'=>'269558.10', 'vat'=>'5.0000', 'purpose'=>'Текущее назначение',
    'source_json'=>Importer::json(['id'=>1014, 'payerSnapshot'=>$payer, 'payeeSnapshot'=>$payee, 'printDetails'=>$print]),
];
$html = InvoiceDocument::html($db, $invoice);
foreach (['№ 1014', 'Банк из счёта', '06984', 'Договор № 125', 'Аренда за октябрь', 'Первая услуга', 'Вторая услуга',
    '2,5', '256 722,00', 'НДС 5%: 12 836,10', '269 558,10', 'восемь рублей 10 копеек', '25.09.2026'] as $text) {
    $check(str_contains($html, $text), 'Missing document text: '.$text);
}
$check(!str_contains($html, $invoice['number']) && !str_contains($html, 'Текущее назначение'), 'Historical presentation changed');
$check(InvoiceDocument::filename($invoice)==='Счёт № 1014 от 09.09.2026 — ООО «Покупатель».pdf', 'PDF filename');

$incomplete = array_replace($invoice, ['payee_snapshot'=>Importer::json(['name'=>'ИП Поставщик']), 'vat'=>null,
    'source_json'=>Importer::json(['id'=>1014, 'payerSnapshot'=>$payer])]);
$data = InvoiceDocument::data($db, $incomplete);
$check(in_array('банк получателя', $data['missing'], true) && in_array('подтверждённые строки услуг и НДС', $data['missing'], true), 'Missing evidence not indicated');
try {
    InvoiceDocument::html($db, $incomplete);
    throw new LogicException('Incomplete historical invoice was printed');
} catch (RuntimeException $error) {
    $check($error->getCode()===409, 'Missing data must block generated form');
}
$changedAmount = array_replace($invoice, ['amount'=>'269559.10']);
$check(in_array('согласованные суммы строк и итога', InvoiceDocument::data($db, $changedAmount)['missing'], true), 'Captured items and amount must reconcile');
$changedNumber = array_replace($invoice, ['number'=>'АЯИП-001015']);
$check(InvoiceDocument::data($db, $changedNumber)['lines']===null, 'Source rows require the same document number');

$native = array_replace($invoice, [
    'number'=>'Д-100014', 'payer_snapshot'=>Importer::json($payer), 'payee_snapshot'=>Importer::json($payee),
    'source_json'=>'{}', 'amount'=>'269558.10',
]);
$check(str_contains(InvoiceDocument::html($db, $native), 'Счёт на оплату № Д-100014'), 'Native invoice keeps own numbering');
echo "PASS captured lines, exact money, native numbering, and incomplete historical print guard\n";
