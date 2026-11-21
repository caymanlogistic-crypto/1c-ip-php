<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, View};
use Modules\Integrations\Support;

final class YakovlevSyncReview
{
    private const REASONS = [
        'SOURCE_CHANGED'=>'Документ в 1С изменён. Сверить прежнюю редакцию и учёт в ERP.',
        'SOURCE_NOT_POSTED'=>'Проверить проведение, отмену и организацию в 1С.',
        'CONTRACT_AMBIGUOUS'=>'Подтвердить точную связь договора с оригиналом 1С.',
        'CONTRACT_NOT_ACTIVE'=>'Проверить применимость документа к текущему состоянию договора.',
        'PAYER_OR_CURRENCY_UNCONFIRMED'=>'Подтвердить контрагента и валюту по оригиналу.',
        'PAYEE_OR_OPERATION_UNCONFIRMED'=>'Подтвердить операцию и счёт получателя.',
        'PAYMENT_LINES_MISSING'=>'Нужна проведённая банковская расшифровка.',
        'PAYMENT_LINE_AMBIGUOUS'=>'Сверить договор и назначение каждой строки банковской расшифровки.',
        'PAYMENT_INVOICE_MISSING'=>'Подтвердить счёт из банковской расшифровки и его привязку к ERP.',
        'PAYMENT_TOTAL_CONFLICT'=>'Сверить сумму прихода с суммой банковских строк.',
        'POSSIBLE_MANUAL_PAYMENT'=>'Сверить с ранее внесённым вручную поступлением.',
        'EXISTING_PAYMENT_CONFLICT'=>'Сверить уже учтённый приход и его распределение.',
        'INVOICE_BALANCE_OR_KIND_CONFLICT'=>'Сверить вид начисления, остаток и распределения счёта.',
        'INVOICE_ID_AMBIGUOUS'=>'Один внешний счёт связан с несколькими записями ERP.',
        'EXISTING_INVOICE_CONFLICT'=>'Сверить реквизиты уже связанного счёта ERP с оригиналом 1С.',
        'INVOICE_WITHOUT_SOURCE_LINK'=>'Счёт с этим номером уже есть. Подтвердить внешний ключ.',
        'INVOICE_LINES_UNCONFIRMED'=>'Нужны подтверждённые строки счёта и НДС.',
        'CHARGE_KIND_UNCONFIRMED'=>'Уточнить вид начисления по оригиналу счёта.',
        'INVOICE_CONTRACT_TEXT_UNCONFIRMED'=>'Подтвердить договор в строке оригинала счёта.',
        'INVOICE_OUTSIDE_CONTRACT'=>'Сверить период счёта с условиями договора.',
        'INVOICE_OBJECT_UNCONFIRMED'=>'Нужны подтверждённый состав объектов и условия на период счёта.',
        'INVOICE_PERIOD_UNCONFIRMED'=>'Подтвердить период аренды по оригиналу счёта.',
        'INVOICE_TOTAL_CONFLICT'=>'Сверить строки и НДС с итогом счёта и условиями периода.',
        'COVERAGE_ALREADY_EXISTS'=>'Период может покрывать уже учтённый счёт. Сверить оригинал и объекты.',
        'COVERAGE_UNCONFIRMED'=>'Подтвердить покрытие ранее внесённых счетов по договору.',
        'OPERATING_ADMISSION_REQUIRED'=>'Нужна сверка применимости документа к действующему допуску учёта.',
        'UNCONFIRMED_AMOUNT'=>'Подтвердить денежную сумму по исходному документу.',
    ];

    public static function section(int $contractId): string
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? null,['accountant','admin'],true) || !Auth::can('finance.write')) return '';
        $records = Controller::query("SELECT fact_json FROM rental_facts
            WHERE namespace=? AND operative=1 AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.state'))='open'
            ORDER BY JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.date')) DESC,source_key",[YakovlevRentalSync::REVIEW]);
        $rows = [];
        foreach ($records as $record) {
            $fact = Support::decode($record['fact_json']);
            $ids = array_unique(array_filter(array_merge($fact['contracts'] ?? [],[$fact['contract_id'] ?? 0])));
            if ($contractId && !in_array($contractId,$ids,true)) continue;
            $links = [];
            foreach ($ids as $id) {
                $contract = Controller::query('SELECT number FROM contracts WHERE id=?',[$id])[0] ?? null;
                if ($contract) $links[] = Controller::link('/rental/contract?id='.(int)$id,'№'.$contract['number']);
            }
            $kind = ($fact['kind'] ?? '')==='invoices' ? 'Счёт' : 'Приход';
            $reason = self::REASONS[$fact['reason']] ?? 'Сверить оригинал и запись ERP.';
            if ($fact['reason']==='INVOICE_OBJECT_UNCONFIRMED' && !empty($fact['parking']))
                $reason .= ' Место в счёте 1С: '.implode(', ',$fact['parking']).'.';
            $rows[] = [implode(', ',$links) ?: 'Уточнить договор',View::e($kind.' '.$fact['number']),
                View::e($fact['date']),View::e(Controller::money($fact['amount'])),View::e($reason)];
        }
        if (!$rows) return '';
        return '<details class="table-card"><summary>Сверка счетов и оплат 1С · '.count($rows).'</summary>'
            .Controller::table(['Договор','Документ','Дата','Сумма, ₽','Причина'], $rows,['short','code','code','money','flex']).'</details>';
    }
}
