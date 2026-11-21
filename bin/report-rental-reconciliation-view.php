<?php
declare(strict_types=1);

use App\View;
use Modules\Rental\Controller;

$money = static fn(?string $value): string => $value === null ? 'На сверке' : Controller::money($value);
$total = static fn(array $values): string => array_reduce(
    $values,
    static fn(string $sum, ?string $number): string => bcadd($sum, $number ?? '0.00', 2),
    '0.00',
);
$difference = static fn(array $plans, array $billed): ?string => !$plans || in_array(null, $plans, true)
    ? null : bcsub($total($plans), $total($billed), 2);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Помесячная сверка аренды</title>
    <style>
        :root { color-scheme: light; font: 15px/1.45 system-ui, sans-serif; color: #26231e; background: #f0ede6; }
        body { margin: 0; }
        main { margin: 32px auto; width: min(1120px, calc(100% - 32px)); }
        h1, h2, h3 { line-height: 1.15; }
        h1 { margin-bottom: 12px; }
        h2 { font-size: 1.25rem; }
        h3 { font-size: 1.05rem; }
        p { max-width: 78ch; color: #524d42; }
        details { background: #fffdf8; border: 1px solid #c9c0af; border-radius: 8px; margin: 10px 0; }
        summary { cursor: pointer; padding: 14px 18px; font-weight: 650; }
        summary:hover { background: #f8f3e8; }
        summary:focus-visible { outline: 2px solid #825024; outline-offset: -2px; }
        summary span { display: inline-block; margin-right: 16px; }
        summary .title { min-width: 7em; }
        summary small { color: #524d42; font-weight: 400; }
        .content { padding: 0 16px 10px; overflow-x: auto; }
        .client { border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #dfd7c9; text-align: left; vertical-align: top; }
        th { background: #f5f0e6; }
        .number { white-space: nowrap; font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<main>
    <h1>Помесячная сверка аренды</h1>
    <p>Снимок сохранён <?= View::e($report['generated']) ?>. Для действующих договоров сравниваются суммы с НДС за месяц аренды. Разница — сумма по договорам минус подтверждённые счета 1С. Счета к сроку сгруппированы по дате оплаты из ERP.</p>
    <p>Последняя успешная загрузка из 1С по журналу ERP. Счета <?= View::e($report['archive']['invoices'] ?? 'пока отсутствуют') ?> · Банк <?= View::e($report['archive']['bank'] ?? 'пока отсутствует') ?>. Документы без подтверждённой разбивки показаны в сверке.</p>
    <?php foreach ($report['months'] as $month => $clients):
        $plans = $billed = $due = $balance = [];
        foreach ($clients as $entry) {
            array_push($plans, ...array_values($entry['planned']));
            array_push($billed, ...array_column($entry['billed'], 'amount'));
            array_push($due, ...array_column($entry['due'], 'amount'));
            array_push($balance, ...array_column($entry['due'], 'balance'));
        }
    ?>
        <details>
            <summary>
                <span class="title"><?= View::e($month) ?></span>
                <span>По договорам <?= $money($total($plans)) ?><?= in_array(null, $plans, true) ? ' <small>и на сверке</small>' : '' ?></span>
                <span>Подтверждено в 1С <?= $money($total($billed)) ?></span>
                <span>Разница <?= $money($difference($plans, $billed)) ?></span>
                <span>Счета к сроку <?= $money($total($due)) ?></span>
                <span>Остаток по учёту <?= $money($total($balance)) ?><?= in_array(null, $balance, true) ? ' <small>и на сверке</small>' : '' ?></span>
            </summary>
            <div class="content">
                <?php if (!$clients): ?><p>За этот месяц нет строк в доступных данных.</p><?php endif; ?>
                <?php foreach ($clients as $client): ?>
                    <details class="client">
                        <summary>
                            <span><?= View::e($client['name']) ?></span>
                            <span>По договорам <?= $money($total($client['planned'])) ?><?= in_array(null, $client['planned'], true) ? ' <small>и на сверке</small>' : '' ?></span>
                            <span>В 1С <?= $money($total(array_column($client['billed'], 'amount'))) ?></span>
                            <span>Разница <?= $money($difference($client['planned'], array_column($client['billed'], 'amount'))) ?></span>
                            <span>Счетов к сроку <?= count($client['due']) ?></span>
                        </summary>
                        <div class="content">
                            <?php if ($client['planned'] || $client['billed']): ?>
                                <h3>Аренда за <?= View::e($month) ?></h3>
                                <table>
                                    <thead><tr><th>Договор</th><th>Основание</th><th>Сумма за месяц</th><th>Срок счёта</th><th>Учтённые оплаты из 1С</th><th>Остаток по счёту целиком</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($client['planned'] as $contract => $amount):
                                            $bills = array_filter($client['billed'], static fn(array $item): bool => $item['contract'] === (string)$contract);
                                            $gap = $difference([$amount], array_column($bills, 'amount'));
                                        ?>
                                            <tr>
                                                <td>№<?= View::e((string)$contract) ?></td>
                                                <td>Расчёт по договору<?= $gap !== null && $gap !== '0.00' ? ' · разница '.$money($gap) : '' ?></td>
                                                <td class="number"><?= $money($amount) ?></td>
                                                <td>—</td><td>—</td><td>—</td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php foreach ($client['billed'] as $item): ?>
                                            <?php $facts = $report['invoice_facts'][$item['invoice_id']] ?? []; ?>
                                            <tr>
                                                <td>№<?= View::e($item['contract']) ?></td>
                                                <td>Счёт 1С <?= View::e($item['number']) ?></td>
                                                <td class="number"><?= $money($item['amount']) ?></td>
                                                <td><?= View::e($facts['due'] ?? 'Уточнить') ?></td>
                                                <td class="number"><?= $money($facts['paid_from_1c'] ?? null) ?></td>
                                                <td class="number"><?= $money($facts['balance'] ?? null) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                            <?php if ($client['due']): ?>
                                <h3>Счета со сроком оплаты в <?= View::e($month) ?></h3>
                                <table>
                                    <thead><tr><th>Счёт</th><th>Договор</th><th>Оплатить до</th><th>Сумма</th><th>Оплачено по ERP</th><th>Из 1С учтено</th><th>Остаток</th><th>Состояние</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($client['due'] as $invoice): ?>
                                            <tr>
                                                <td><?= View::e($invoice['number']) ?></td>
                                                <td>№<?= View::e($invoice['contract']) ?></td>
                                                <td class="number"><?= View::e($invoice['due']) ?></td>
                                                <td class="number"><?= $money($invoice['amount']) ?></td>
                                                <td class="number"><?= $money($invoice['paid']) ?></td>
                                                <td class="number"><?= $money($invoice['paid_from_1c']) ?></td>
                                                <td class="number"><?= $money($invoice['balance']) ?></td>
                                                <td><?= $invoice['overdue'] ? 'Просрочка подтверждена' : ($invoice['verified'] ? 'Счёт связан с 1С' : 'Подтвердить связь с 1С') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
    <?php if ($report['issues']): ?>
        <details>
            <summary>Требуют сверки · <?= count($report['issues']) ?></summary>
            <div class="content">
                <table>
                    <thead><tr><th>Месяц аренды</th><th>Клиент</th><th>Документ</th><th>Что уточнить</th></tr></thead>
                    <tbody>
                        <?php foreach ($report['issues'] as $issue): ?>
                            <tr><?php foreach ($issue as $cell): ?><td><?= View::e($cell) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</main>
</body>
</html>
