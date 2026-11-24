<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;
use IntlDateFormatter;
use RuntimeException;

final class InitialInvoicePrint
{
    public static function pdf(array $payer,array $payee,string $basis,string $issued,array $row,array $lines): string
    {
        $gross=$row['amount'];
        $total=InvoiceDocument::nativeBreakdown($gross,$payee['vat'] ?? null);
        if ($total===null || !$lines) throw new RuntimeException('Проверьте сумму и ставку НДС предварительного счёта.',409);
        $net='0.00';
        foreach ($lines as $line) $net=bcadd($net,$line['net'],2);
        if (bccomp($net,$total['net'],2)!==0) throw new RuntimeException('Проверьте суммы строк предварительного счёта.',409);

        $bank=$payee['bankDetails'] ?? [];
        $bank['correspondent_account'] ??= $bank['correspondentAccount'] ?? null;
        if (empty($payer['name']) || empty($payee['name']) || $basis==='') {
            throw new RuntimeException('Проверьте стороны и основание предварительного счёта.',409);
        }
        if (empty($bank['bank']) || !preg_match('/^[0-9]{9}$/D',(string)($bank['bik'] ?? ''))
            || !preg_match('/^[0-9]{20}$/D',(string)($bank['account'] ?? ''))
            || !preg_match('/^[0-9]{20}$/D',(string)($bank['correspondent_account'] ?? ''))) {
            throw new RuntimeException('Заполните основные банковские реквизиты получателя.',409);
        }

        $assets=APP_ROOT.'/.local/invoice-preview';
        $settings=is_readable($assets.'/signature.json')
            ?json_decode((string)file_get_contents($assets.'/signature.json'),true):null;
        if (empty($payee['inn']) || ($settings['inn'] ?? null)!==(string)$payee['inn'] || empty($settings['signer'])) {
            throw new RuntimeException('Подпись для ИНН получателя счёта не настроена.',409);
        }
        $signature=is_readable($assets.'/signature.png') ? file_get_contents($assets.'/signature.png'):false;
        if (!is_string($signature) || !str_starts_with($signature,"\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Файл подписи для предварительного счёта недоступен.',409);
        }
        $signatureUri='data:image/png;base64,'.base64_encode($signature);
        $signer=$settings['signer'];
        $withArial=is_readable($assets.'/arial.ttf') && is_readable($assets.'/arialbd.ttf');
        $titleDate=(new IntlDateFormatter('ru_RU',IntlDateFormatter::LONG,IntlDateFormatter::NONE))
            ->format(new DateTimeImmutable($issued));
        $vatRate=str_replace('.',',',rtrim(rtrim(bcadd($total['vatRate'],'0',4),'0'),'.'));
        $due=$row['due'] ? Documents::dateText($row['due']):'требует подтверждения';
        $words=InvoiceDocument::words($gross);

        ob_start();
        try {
            require __DIR__.'/templates/initial-invoice-preview.php';
            $html=(string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return Documents::renderPdf($html);
    }
}
