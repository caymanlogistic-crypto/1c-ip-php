<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\View;
use PDO;
use RuntimeException;

final class InvoiceDocument
{
    public static function number(string $number): string
    {
        return preg_match('/^АЯИП-0*([1-9][0-9]*)$/uiD', $number, $match) ? $match[1] : $number;
    }

    public static function filename(array $invoice, string $buyer = '', string $extension = 'pdf'): string
    {
        $source = self::json($invoice['source_json'] ?? null);
        $payer = self::snapshot($invoice, $source, 'payer');
        $buyer = self::text($payer['name'] ?? null) ?: $buyer;
        $name = 'Счёт № '.self::number($invoice['number']).' от '.Documents::dateText($invoice['issued'])
            .($buyer === '' ? '' : ' — '.mb_substr($buyer, 0, 65));
        return trim(preg_replace('~[<>:"/\\\\|?*\x00-\x1f\x7f]+~u', ' ', $name)).'.'.$extension;
    }

    public static function original(PDO $db, int $id): ?array
    {
        $query = $db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND kind='invoice-file'");
        $query->execute([$id]);
        if (!$query->fetchColumn()) return null;
        try {
            $file = Evidence::latest($db, 'invoice', $id, 'invoice-file');
        } catch (\PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            throw new RuntimeException($error->getMessage(), 409, $error);
        }
        return $file['mime'] === 'application/pdf' ? $file : null;
    }

    public static function originalFilename(PDO $db, array $invoice, array $file): string
    {
        if ((int)$invoice['version'] !== (int)$file['version']) {
            $query = $db->prepare('SELECT snapshot_json FROM rental_invoice_versions WHERE invoice_id=? AND version=?');
            $query->execute([$invoice['id'], $file['version']]);
            $snapshot = $query->fetchColumn();
            $invoice = self::json($snapshot === false ? null : $snapshot);
        }
        if (empty($invoice['number']) || empty($invoice['issued'])) return $file['filename'];
        return self::filename($invoice);
    }

    private static function json(?string $json): array
    {
        $value = $json === null ? [] : Importer::decodeExact($json);
        return is_array($value) ? $value : [];
    }

    private static function snapshot(array $invoice, array $source, string $side): array
    {
        // Legacy imports may carry the same snapshots only in the original source record.
        if (isset($invoice[$side.'_snapshot'])) return self::json($invoice[$side.'_snapshot']);
        $snapshot = $source[$side.'Snapshot'] ?? null;
        return is_array($snapshot) ? $snapshot : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function decimal(mixed $value, int $scale): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $value = (string)$value;
        if (!preg_match('/^[0-9]{1,18}(?:\.[0-9]{1,8})?$/D', $value)
            || bccomp($value, bcadd($value, '0', $scale), 8) !== 0) return null;
        return bcadd($value, '0', $scale);
    }

    private static function rate(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private static function lines(mixed $source, string $description, ?string $gross, ?string $vat): ?array
    {
        if ($source === null) {
            if ($gross === null || $vat === null || $description === '') return null;
            $net = Billing::round(bcdiv($gross, bcadd('1', bcdiv($vat, '100', 8), 8), 10));
            return [[
                'description'=>$description, 'quantity'=>'', 'unit'=>'', 'price'=>null, 'net'=>$net,
                'vatRate'=>$vat, 'tax'=>bcsub($gross, $net, 2),
            ]];
        }
        if (!is_array($source) || !array_is_list($source) || !$source || count($source)>100) return null;
        $lines = [];
        foreach ($source as $row) {
            if (!is_array($row)) return null;
            $description = self::text($row['description'] ?? null);
            $unit = self::text($row['unit'] ?? null);
            $quantity = self::decimal($row['quantity'] ?? null, 8);
            $price = self::decimal($row['netPrice'] ?? null, 8);
            $net = self::decimal($row['netAmount'] ?? null, 2);
            $vatRate = self::decimal($row['vatRate'] ?? null, 4);
            $tax = self::decimal($row['vatAmount'] ?? null, 2);
            if ($description === '' || $unit === '' || $quantity === null || bccomp($quantity, '0', 8) <= 0
                || $price === null || $net === null || $vatRate === null || $tax === null
                || bccomp($vatRate, '100', 4) > 0
                || (bccomp($vatRate, '0', 4) === 0 && $tax !== '0.00')) return null;
            $lines[] = compact('description', 'unit', 'quantity', 'price', 'net', 'vatRate', 'tax');
        }
        return $lines;
    }

    public static function capturedPrint(array $invoice): ?array
    {
        $print = self::json($invoice['source_json'] ?? null)['printDetails'] ?? null;
        return is_array($print) && ($print['number'] ?? null) === $invoice['number']
            && ($print['issued'] ?? null) === $invoice['issued'] ? $print : null;
    }

    public static function nativeBreakdown(string $gross,?string $rate): ?array
    {
        $amount = self::decimal($gross,2);
        $vat = self::decimal($rate,4);
        if ($amount === null || $vat === null || bccomp($vat,'100',4)>0) return null;
        return self::lines(null,'Аренда',$amount,$vat)[0];
    }

    public static function capturedLines(array $print): ?array
    {
        return self::lines($print['lines'] ?? [], '', null, null);
    }

    public static function data(PDO $db, array $invoice): array
    {
        $query = $db->prepare('SELECT number,document_date FROM contracts WHERE id=?');
        $query->execute([$invoice['contract_id']]);
        $contract = $query->fetch() ?: throw new RuntimeException('Договор для счёта не найден.', 404);
        $source = self::json($invoice['source_json'] ?? null);
        $payer = self::snapshot($invoice, $source, 'payer');
        $payee = self::snapshot($invoice, $source, 'payee');
        $bank = is_array($payee['bankDetails'] ?? null) ? $payee['bankDetails'] : [];
        $bank['correspondent_account'] ??= $bank['correspondentAccount'] ?? null;
        $print = $source['printDetails'] ?? null;
        $historical = $print !== null || isset($source['id']) || self::number($invoice['number']) !== $invoice['number'];
        $description = self::text($invoice['purpose'] ?? null) ?: (self::text($invoice['title'] ?? null) ?: self::text($invoice['kind'] ?? null));
        $purpose = $description;
        $basis = $contract['number'] && $contract['document_date']
            ? 'Договор № '.$contract['number'].' от '.Documents::dateText($contract['document_date']) : '';
        $gross = self::decimal($invoice['amount'] ?? null, 2);
        $vat = self::decimal($invoice['vat'] ?? null, 4);
        if ($vat !== null && bccomp($vat, '100', 4)>0) $vat = null;
        $lines = null;
        if (!$historical) {
            $lines = self::lines(null, $description, $gross, $vat);
        } elseif ($print = self::capturedPrint($invoice)) {
            $purpose = self::text($print['purpose'] ?? null) ?: $purpose;
            $basis = self::text($print['basis'] ?? null) ?: $basis;
            $lines = self::capturedLines($print);
        }
        $net = '0.00';
        $taxes = [];
        foreach ($lines ?? [] as $line) {
            $net = bcadd($net, $line['net'], 2);
            $rate = $line['vatRate'];
            $taxes[$rate] = bcadd($taxes[$rate] ?? '0', $line['tax'], 2);
        }
        $missing = [];
        if (self::text($payer['name'] ?? null) === '') $missing[] = 'юридическое наименование покупателя';
        if (self::text($payee['name'] ?? null) === '') $missing[] = 'наименование поставщика';
        if (self::text($bank['bank'] ?? null) === '') $missing[] = 'банк получателя';
        foreach (['account'=>[20,'расчётный счёт'], 'bik'=>[9,'БИК'], 'correspondent_account'=>[20,'корреспондентский счёт']] as $key=>[$length,$label]) {
            if (!preg_match('/^[0-9]{'.$length.'}$/D', self::text($bank[$key] ?? null))) $missing[] = $label.' получателя';
        }
        if ($basis === '') $missing[] = 'основание платежа';
        if ($purpose === '') $missing[] = 'назначение платежа';
        if (empty($invoice['issued'])) $missing[] = 'дата выставления';
        if (empty($invoice['due'])) $missing[] = 'срок оплаты';
        if ($gross === null) $missing[] = 'сумма счета';
        if ($historical && $lines === null) $missing[] = 'подтверждённые строки услуг и НДС';
        if (!$historical && $vat === null) $missing[] = 'ставка НДС';
        if ($lines !== null && $gross !== null) {
            $lineTotal = $net;
            foreach ($taxes as $tax) $lineTotal = bcadd($lineTotal, $tax, 2);
            if ($lineTotal !== $gross) $missing[] = 'согласованные суммы строк и итога';
            if ($vat !== null && count($taxes) === 1 && !isset($taxes[$vat])) $missing[] = 'согласованная ставка НДС';
        }
        return compact('payer', 'payee', 'bank', 'purpose', 'basis', 'gross', 'lines', 'net', 'taxes', 'missing');
    }

    private static function details(array $party): string
    {
        $parts = [$party['name']];
        foreach (['inn'=>'ИНН ', 'kpp'=>'КПП '] as $key=>$label) {
            if (!empty($party[$key])) $parts[] = $label.$party[$key];
        }
        $address = self::text($party['legal_address'] ?? $party['legalAddress'] ?? null) ?: self::text($party['address'] ?? null);
        if ($address !== '') $parts[] = $address;
        return View::e(implode(', ', $parts));
    }

    private static function plural(string $value, array $forms): string
    {
        $lastTwo = (int)substr($value, -2);
        if ($lastTwo >= 11 && $lastTwo <= 14) return $forms[2];
        return match ($lastTwo % 10) {
            1=>$forms[0], 2,3,4=>$forms[1], default=>$forms[2],
        };
    }

    private static function words(string $gross): string
    {
        [$rubles, $kopecks] = explode('.', $gross);
        $formatter = new \NumberFormatter('ru_RU', \NumberFormatter::SPELLOUT);
        $text = $formatter->format((int)$rubles);
        if ($text === false) throw new RuntimeException('Не удалось представить сумму прописью.');
        return mb_ucfirst($text).' '.self::plural($rubles, ['рубль','рубля','рублей']).' '
            .$kopecks.' '.self::plural($kopecks, ['копейка','копейки','копеек']);
    }

    public static function html(PDO $db, array $invoice): string
    {
        $data = self::data($db, $invoice);
        if ($data['missing']) {
            throw new RuntimeException('Для печати счёта проверьте: '.implode(', ', $data['missing']).'.', 409);
        }
        $title = 'Счёт на оплату № '.self::number($invoice['number']).' от '.Documents::dateText($invoice['issued']);
        $bank = $data['bank'];
        $body = '<table class="bank"><tbody><tr><th>Банк получателя</th><td>'.View::e($bank['bank'])
            .'</td><th>БИК</th><td>'.View::e($bank['bik']).'</td></tr>'
            .'<tr><th>Корреспондентский счёт</th><td>'.View::e($bank['correspondent_account'])
            .'</td><th>Счёт получателя</th><td>'.View::e($bank['account']).'</td></tr></tbody></table>'
            .'<h1>'.View::e($title).'</h1>'
            .'<p><strong>Поставщик:</strong> '.self::details($data['payee']).'</p>'
            .'<p><strong>Покупатель:</strong> '.self::details($data['payer']).'</p>'
            .'<p><strong>Основание:</strong> '.View::e($data['basis']).'</p>'
            .'<p><strong>Назначение платежа:</strong> '.View::e($data['purpose']).'</p>';
        if (!empty($invoice['period'])) $body .= '<p><strong>Период:</strong> '.View::e($invoice['period']).'</p>';
        $body .= '<table><thead><tr><th>№</th><th>Товары, работы, услуги</th><th>Кол-во</th><th>Ед.</th>'
            .'<th>Цена без НДС</th><th>Сумма без НДС</th></tr></thead><tbody>';
        foreach ($data['lines'] as $index=>$line) {
            $quantity = $line['quantity'] === '' ? '—' : str_replace('.', ',', self::rate($line['quantity']));
            $price = '—';
            if ($line['price'] !== null) {
                [$whole, $fraction] = explode('.', $line['price']);
                $fraction = str_pad(rtrim($fraction, '0'), 2, '0');
                $price = preg_replace('/\B(?=(\d{3})+(?!\d))/u', ' ', $whole).','.$fraction.' ₽';
            }
            $body .= '<tr><td>'.($index+1).'</td><td>'.View::e($line['description']).'</td><td class="amount">'.$quantity
                .'</td><td>'.View::e($line['unit'] ?: '—').'</td><td class="amount">'
                .$price
                .'</td><td class="amount">'.Controller::money($line['net']).'</td></tr>';
        }
        $body .= '</tbody></table><div class="totals"><p>Итого без НДС: '.Controller::money($data['net']).'</p>';
        foreach ($data['taxes'] as $rate=>$tax) {
            $body .= '<p>НДС '.str_replace('.', ',', self::rate($rate)).'%: '.Controller::money($tax).'</p>';
        }
        $body .= '<p><strong>Всего к оплате: '.Controller::money($data['gross']).'</strong></p></div>'
            .'<p>'.View::e(self::words($data['gross'])).'</p>'
            .'<p><strong>Оплатить до '.Documents::dateText($invoice['due']).'</strong></p>';
        return '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><title>'.View::e($title).'</title>'
            .'<style>@page{margin:16mm}body{font:10pt "DejaVu Sans",sans-serif;line-height:1.45;color:#181818}'
            .'h1{font-size:16pt;margin:16px 0 10px}p{margin:5px 0}table{border-collapse:collapse;width:100%;margin:8px 0}'
            .'tr{page-break-inside:avoid}th,td{border:1px solid #555;padding:5px;vertical-align:top}'
            .'th{text-align:left;background:#f5f5f5}.amount{white-space:nowrap;text-align:right}'
            .'.totals{text-align:right;page-break-inside:avoid;margin:8px 0}.bank th{width:18%}'
            .'@media screen{body{max-width:850px;margin:24px auto;padding:0 16px}}</style></head><body>'.$body.'</body></html>';
    }
}
