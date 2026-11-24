<?php
declare(strict_types=1);

use App\View;
use Modules\Rental\{Documents, InvoiceDocument};
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Предварительный счёт</title>
<style>
<?php if ($withArial): ?>
@font-face { font-family: Invoice Arial; src: url("/.local/invoice-preview/arial.ttf") format("truetype"); }
@font-face { font-family: Invoice Arial; src: url("/.local/invoice-preview/arialbd.ttf") format("truetype"); font-weight: bold; }
<?php endif; ?>
@page { margin: 49.5pt 80pt 45pt 33.5pt; }
body { margin: 0; font: 9.5pt/1.14 "Invoice Arial", "DejaVu Sans", sans-serif; color: #000; }
table { width: 100%; border-collapse: collapse; }
.bank { table-layout: auto; font-size: 9pt; line-height: 1.12; }
.bank td { vertical-align: top; border: .6pt solid #272727; padding: 1pt 2pt 0; }
.bank .bank-label { margin-top: 11pt; font-size: 8pt; }
.bank .correspondent { height: 16pt; }
.bank .bank-party { height: 26pt; }
.bank .bank-party .bank-label { margin-top: 9pt; }
.heading { border-bottom: 1.3pt solid #303030; margin: 10pt 0 5pt; }
.heading td { padding: 0 3pt 6pt; vertical-align: middle; }
h1 { margin: 0; font-size: 14.5pt; line-height: 1.32; }
.draft-label { width: 37%; white-space: nowrap; text-align: right; font-weight: bold; font-size: 8pt; }
.details { margin-bottom: 1pt; }
.details td { padding: 1.5pt 2pt 5pt; vertical-align: top; }
.details td:first-child { width: 71pt; }
.items { table-layout: fixed; border: 1.1pt solid #303030; }
.items th, .items td { border: .65pt solid #303030; padding: .4pt 2pt; vertical-align: top; }
.items th { line-height: 1.16; }
.items td { font-size: 8pt; line-height: 1.14; word-wrap: break-word; }
.items th:first-child, .items td:first-child { text-align: center; }
.items td:nth-child(3), .items td:nth-child(5), .items td:nth-child(6) { text-align: right; }
.money { white-space: nowrap; }
thead { display: table-header-group; }
tr, .summary, .signature { page-break-inside: avoid; }
.totals { margin: 6pt 0 0; font-weight: bold; }
.totals td { padding: 0 2pt 1pt; text-align: right; }
.totals td:last-child { width: 69pt; }
.total-words { margin: 0 2pt; }
.total-words strong { display: block; margin-top: 2pt; }
.due { margin: 9pt 2pt 7pt; font-size: 9pt; }
.signature { border-top: 1.5pt solid #303030; }
.signature td { vertical-align: bottom; }
.signature-role { width: 94pt; padding: 0 2pt 1pt; font-weight: bold; }
.signature-field { position: relative; height: 74pt; border-bottom: 1pt solid #303030; }
.signature-field img { position: absolute; top: 5pt; left: 182pt; width: 65.16pt; height: 67.08pt; }
.signature-name { position: absolute; right: 2pt; bottom: 1pt; font-size: 8pt; }
</style>
</head>
<body>
<table class="bank" aria-label="Банковские реквизиты получателя">
<tr>
  <td rowspan="2" colspan="2"><?= View::e($bank['bank']) ?><div class="bank-label">Банк получателя</div></td>
  <td style="width:7.5%">БИК</td><td style="width:37.5%"><?= View::e($bank['bik']) ?></td>
</tr>
<tr><td class="correspondent">Сч. №</td><td><?= View::e($bank['correspondent_account']) ?></td></tr>
<tr>
  <td style="width:30.8%">ИНН&nbsp;&nbsp; <?= View::e($payee['inn']) ?></td><td style="width:24.2%">КПП&nbsp;&nbsp; <?= View::e($payee['kpp'] ?? '') ?></td>
  <td rowspan="2">Сч. №</td><td rowspan="2"><?= View::e($bank['account']) ?></td>
</tr>
<tr><td class="bank-party" colspan="2"><?= View::e($payee['name']) ?><div class="bank-label">Получатель</div></td></tr>
</table>

<table class="heading"><tr>
  <td><h1>Счет на оплату от <?= View::e($titleDate) ?></h1></td>
  <td class="draft-label">Предварительный счёт · не для оплаты</td>
</tr></table>
<table class="details">
<tr><td>Поставщик<br>(Исполнитель):</td><td><strong><?= InvoiceDocument::details($payee,true) ?></strong></td></tr>
<tr><td>Покупатель<br>(Заказчик):</td><td><strong><?= InvoiceDocument::details($payer) ?></strong></td></tr>
<tr><td>Основание:</td><td><strong><?= View::e($basis) ?></strong></td></tr>
</table>

<table class="items">
<thead><tr><th style="width:5%">№</th><th style="width:51%">Товары (работы, услуги)</th><th style="width:8.5%">Кол-во</th><th style="width:6.6%">Ед.</th><th style="width:13.5%">Цена</th><th style="width:15.4%">Сумма</th></tr></thead>
<tbody>
<?php foreach ($lines as $index=>$line): ?>
<tr>
  <td><?= $index+1 ?></td><td><?= View::e($line['description']) ?></td>
  <td>1</td><td><?= View::e($line['unit']) ?></td>
  <td class="money"><?= Documents::amountText($line['net']) ?></td><td class="money"><?= Documents::amountText($line['net']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="summary">
<table class="totals">
<tr><td>Итого:</td><td class="money"><?= Documents::amountText($total['net']) ?></td></tr>
<tr><td>Сумма НДС <?= View::e($vatRate) ?>%:</td><td class="money"><?= Documents::amountText($total['tax']) ?></td></tr>
<tr><td>Всего к оплате:</td><td class="money"><?= Documents::amountText($gross) ?></td></tr>
</table>
<div class="total-words">
  Всего наименований <?= count($lines) ?>, на сумму <?= Documents::amountText($gross) ?> руб.
  <strong><?= View::e($words) ?></strong>
</div>
<div class="due">Срок оплаты: <?= View::e($due) ?></div>
<table class="signature">
<tr>
  <td class="signature-role">Предприниматель</td>
  <td class="signature-field">
    <img src="<?= View::e($signatureUri) ?>" alt="">
    <span class="signature-name"><?= View::e($signer) ?></span>
  </td>
</tr>
</table>
</div>
</body>
</html>
