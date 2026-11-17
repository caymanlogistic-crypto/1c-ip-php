<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Source-reviewed CREATE-only mapping; no posting/update/contact-format guessing. */
final class OnecMapping {
    public const PROFILE_FILE_SHA = '6adaf08c5779c03dca7d5893e556ce0cf26e6ef8a821332cb222f1d1d10522cb';
    public const METADATA_SHA = '94fda9e6b6bc0d5f0dd422c99463cfa556ec0f7443e30bb9ef8e8b5963ea913c';
    public const ORGANIZATION = '5b2b76f9-0ae3-11ea-80e4-005056953851';
    public const COLLECTIONS = ['counterparty' => 'Catalog_Контрагенты',
        'customer_invoice' => 'Document_СчетНаОплатуПокупателю'];
    public const REFS = ['organization' => 'Catalog_Организации',
        'counterparty' => 'Catalog_Контрагенты',
        'contract' => 'Catalog_ДоговорыКонтрагентов',
        'currency' => 'Catalog_Валюты',
        'item' => 'Catalog_Номенклатура'];
    /** The reviewed metadata has no IsFolder on organizations/currencies. Never invent it. */
    public static function referenceFlags(string $type,array $facts): bool {
        if(!in_array($type,array_values(self::REFS),true)||($facts['DeletionMark']??null)!==false)return false;
        if(array_key_exists('IsFolder',$facts))return $facts['IsFolder']===false;
        return in_array($type,[self::REFS['organization'],self::REFS['currency']],true);
    }
    public static function customerContract(array $facts,string $organization,string $counterparty,string $currency): bool {
        return ($facts['Организация_Key']??null)===$organization&&($facts['Owner_Key']??null)===$counterparty
            &&($facts['ВалютаВзаиморасчетов_Key']??null)===$currency&&($facts['ВидДоговора']??null)==='СПокупателем'
            // Закрыт is absent in the reviewed metadata. An explicit closed/null flag still denies.
            &&(!array_key_exists('Закрыт',$facts)||$facts['Закрыт']===false);
    }
    public static function guid(mixed $v): string {
        if (!is_string($v) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',
            $v) || $v === '00000000-0000-0000-0000-000000000000')throw new \InvalidArgumentException('ONEC_GUID_INVALID');
        return $v;
    }
    private static function fields(array $v,
        array $allowed): void {
        if (array_is_list($v) || array_diff(array_keys($v),
            $allowed))throw new \InvalidArgumentException('ONEC_UNSUPPORTED_FIELDS');
    }
    private static function text(mixed $v,
        int $limit = 2000,
        bool $required = false): string {
        if (!is_string($v) || mb_strlen($v) > $limit || $required && trim($v) === '' || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',
            $v))throw new \InvalidArgumentException('ONEC_TEXT_INVALID');
        return $v;
    }
    private static function decimal(mixed $v,
        int $precision,
        bool $positive = false): string {
        if (!is_string($v) || strlen($v) > 30 || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',
            $v) || isset(explode('.',
            $v,
            2)[1]) && strlen(explode('.',
            $v,
            2)[1]) > $precision || $positive && bccomp($v,
            '0',
            30) <= 0)throw new \InvalidArgumentException('ONEC_PRECISION_INVALID');
        return $v;
    }
    public static function reference(mixed $v,
        string $field): string {
        if (!is_array($v))throw new \InvalidArgumentException('ONEC_REFERENCE_REQUIRED');
        self::fields($v,
            ['type',
                'key',
                'version',
                'organization_key',
                'counterparty_key']);
        if (($v['type']??'') !== (self::REFS[$field]??null))throw new \InvalidArgumentException('ONEC_REFERENCE_TYPE');
        self::text($v['version']??null,
            250,
            true);
        return self::guid($v['key']??null);
    }
    public static function profile(array $currencyRules = []): array {
        if (!hash_equals(self::PROFILE_FILE_SHA,
            (string)hash_file('sha256',
            __DIR__ . '/defaults/onec-write-profile.json')))throw new \RuntimeException('ONEC_PROFILE_FILE_CHANGED');
        $p = json_decode((string)file_get_contents(__DIR__ . '/defaults/onec-write-profile.json'),
            true,
            64,
            JSON_THROW_ON_ERROR);
        if (count($currencyRules) > 1)throw new \InvalidArgumentException('ONEC_CURRENCY_POLICY');
        foreach ($currencyRules as $key => $rule) {
            if (self::guid($key) !== $key || !is_array($rule) || count($rule) !== 2
                || array_diff(array_keys($rule), ['rate', 'multiplicity'])
                || ($rule['rate'] ?? null) !== '1' || ($rule['multiplicity'] ?? null) !== '1') {
                throw new \InvalidArgumentException('ONEC_CURRENCY_POLICY');
            }
            $currencyRules[$key] = ['rate' => '1', 'multiplicity' => '1'];
        }
        $p['currency_rules'] = $currencyRules;
        return $p;
    }
    public static function assertProfile(array $p): void {
        self::checkProfile($p);
    }
    public static function profileHash(array $p): string {
        self::checkProfile($p);
        $value = $p;
        $value['contact_rules'] = (object)$p['contact_rules'];
        $value['currency_rules'] = (object)($p['currency_rules']??[]);
        return hash('sha256',
            self::json($value));
    }
    private static function checkProfile(array $p): void {
        $currency = $p['currency_rules']??[];
        $expected = self::profile($currency);
        if (Support::canonical($p) !== Support::canonical($expected))throw new \RuntimeException('ONEC_PROFILE_CHANGED');
    }
    private static function round(string $v): string {
        return bcadd($v,
            '0.005',
            2);
    }
    public static function build(string $kind,
        array $data,
        string $key,
        array $profile): array {
        self::guid($key);
        self::checkProfile($profile);
        if ($kind === 'counterparty'){
            self::fields($data,
                ['entity_type',
                    'name',
                    'full_name',
                    'inn',
                    'kpp',
                    'legal_address',
                    'postal_address',
                    'email',
                    'phone']);
            $type = $profile['entity_types'][$data['entity_type']??'']??null;
            if (!is_array($type))throw new \InvalidArgumentException('ONEC_ENTITY_TYPE');
            $inn = self::text($data['inn']??'',
                12);
            $kpp = self::text($data['kpp']??'',
                9);
            if ($inn !== '' && !preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',
                $inn) || $kpp !== '' && !preg_match('/^[0-9A-Z]{9}$/D',
                $kpp))throw new \InvalidArgumentException('ONEC_TAX_REFERENCE_INVALID');
            foreach (['legal_address',
                    'postal_address',
                    'email',
                    'phone'] as $field)if (($data[$field]??'') !== '')throw new \InvalidArgumentException('ONEC_CONTACT_MAPPING_UNAVAILABLE');
            return ['Ref_Key' => $key,
                'IsFolder' => false,
                'Description' => self::text($data['name']??null,
                    500,
                    true),
                'НаименованиеПолное' => self::text($data['full_name']??null,
                    500,
                    true),
                'ИНН' => $inn,
                'КПП' => $kpp,
                'ЮридическоеФизическоеЛицо' => $type['value'],
                'ИндивидуальныйПредприниматель' => $type['entrepreneur']];
        }
        if ($kind !== 'customer_invoice')throw new \InvalidArgumentException('ONEC_KIND_UNSUPPORTED');
        self::fields($data,
            ['organization',
                'counterparty',
                'contract',
                'currency',
                'date',
                'prices_include_vat',
                'comment',
                'lines',
                'total',
                'vat_total']);
        if (!is_bool($data['prices_include_vat']??null))throw new \InvalidArgumentException('ONEC_VAT_EXPLICIT_REQUIRED');
        $refs = [];
        foreach (['organization',
                'counterparty',
                'contract',
                'currency'] as $field)$refs[$field] = self::reference($data[$field]??null,
            $field);
        if ($refs['organization'] !== self::ORGANIZATION || self::guid($data['contract']['organization_key']??null) !== $refs['organization'] || self::guid($data['contract']['counterparty_key']??null) !== $refs['counterparty'])throw new \InvalidArgumentException('ONEC_CONTRACT_OWNERSHIP');
        $currency = $profile['currency_rules'][$refs['currency']]??null;
        if (!is_array($currency) || ($currency['rate'] ?? null) !== '1'
            || ($currency['multiplicity'] ?? null) !== '1')throw new \InvalidArgumentException('ONEC_CURRENCY_POLICY_REQUIRED');
        $date = $data['date']??null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',
            $date) || Support::day($date) !== $date)throw new \InvalidArgumentException('ONEC_DATE_INVALID');
        $lines = $data['lines']??null;
        if (!is_array($lines) || !array_is_list($lines) || count($lines) < 1 || count($lines) > 100)throw new \InvalidArgumentException('ONEC_LINES_POLICY');
        $body = ['Ref_Key' => $key,
            'Date' => $date . 'T00:00:00',
            'Организация_Key' => $refs['organization'],
            'Контрагент_Key' => $refs['counterparty'],
            'ДоговорКонтрагента_Key' => $refs['contract'],
            'ВалютаДокумента_Key' => $refs['currency'],
            'СуммаВключаетНДС' => $data['prices_include_vat'],
            'ВидОперации' => $profile['invoice_operation'],
            'Комментарий' => self::text($data['comment']??''),
            'Товары' => [],
            'КурсВзаиморасчетов' => new OnecNumber('1'),
            'КратностьВзаиморасчетов' => new OnecNumber('1')];
        $total = '0.00';
        $vatTotal = '0.00';
        $without = true;
        foreach ($lines as $i => $line){
            if (!is_array($line))throw new \InvalidArgumentException('ONEC_LINE_INVALID');
            self::fields($line,
                ['item',
                    'description',
                    'quantity',
                    'price',
                    'vat_code',
                    'amount',
                    'vat_amount']);
            $qty = self::decimal($line['quantity']??null,
                3,
                true);
            $price = self::decimal($line['price']??null,
                2);
            $tax = $profile['vat_rates'][$line['vat_code']??'']??null;
            if (!is_array($tax))throw new \InvalidArgumentException('ONEC_VAT_MAPPING');
            $product = bcmul($qty,
                $price,
                30);
            if (bccomp($product,
                '999999999999.99',
                30) > 0)throw new \InvalidArgumentException('ONEC_AMOUNT_POLICY');
            $base = self::round($product);
            $vat = self::round(bcdiv(bcmul($base,
                $tax['rate'],
                30),
                $data['prices_include_vat']?bcadd('100',
                $tax['rate'],
                0):'100',
                30));
            $gross = $data['prices_include_vat']?$base:bcadd($base,
                $vat,
                2);
            foreach (['amount' => $gross,
                    'vat_amount' => $vat] as $field => $derived)if (isset($line[$field]) && bccomp(self::decimal($line[$field],
                30),
                $derived,
                30) !== 0)throw new \InvalidArgumentException('ONEC_LINE_TOTAL_MISMATCH');
            $total = bcadd($total,
                $gross,
                2);
            $vatTotal = bcadd($vatTotal,
                $vat,
                2);
            if (bccomp($gross,
                '999999999999.99',
                2) > 0 || bccomp($total,
                '999999999999.99',
                2) > 0)throw new \InvalidArgumentException('ONEC_AMOUNT_POLICY');
            $without = $without && $tax['without_vat'];
            $body['Товары'][] = ['LineNumber' => (string)($i+1),
                'Номенклатура' => self::reference($line['item']??null,
                    'item'),
                'Номенклатура_Type' => 'StandardODATA.Catalog_Номенклатура',
                'Содержание' => self::text($line['description']??null,
                    1000,
                    true),
                'Количество' => new OnecNumber($qty),
                'Цена' => new OnecNumber($price),
                'Сумма' => new OnecNumber($base),
                'СтавкаНДС' => $tax['value'],
                'СуммаНДС' => new OnecNumber($vat),
                'ПроцентСкидки' => new OnecNumber('0'),
                'СуммаСкидки' => new OnecNumber('0')];
        }
        foreach (['total' => $total,
                'vat_total' => $vatTotal] as $field => $derived)if (isset($data[$field]) && bccomp(self::decimal($data[$field],
            30),
            $derived,
            30) !== 0)throw new \InvalidArgumentException('ONEC_TOTAL_MISMATCH');
        $body['СуммаДокумента'] = new OnecNumber($total);
        $body['ДокументБезНДС'] = $without;
        return $body;
    }
    public static function json(mixed $v): string {
        if ($v instanceof OnecNumber)return $v->decimal;
        if ($v instanceof \stdClass){
            $items = get_object_vars($v);
            ksort($items,
                SORT_STRING);
            $pairs = [];
            foreach ($items as $key => $value)$pairs[] = self::json((string)$key) . ':' . self::json($value);
            return '{' . implode(',',
                $pairs) . '}';
        }
        if (is_array($v)){
            if (array_is_list($v))return '[' . implode(',',
                array_map(self::json(...),
                $v)) . ']';
            ksort($v,
                SORT_STRING);
            $p = [];
            foreach ($v as $k => $value)$p[] = self::json((string)$k) . ':' . self::json($value);
            return '{' . implode(',',
                $p) . '}';
        }
        if (is_float($v) || is_object($v))throw new \InvalidArgumentException('ONEC_BINARY_FLOAT_FORBIDDEN');
        return json_encode($v,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
    }
    public static function compare(string $kind,
        array $expected,
        array $actual): array {
        $errors = [];
        $walk = function($want,
            $got,
            string $path)use(&$walk,
            &$errors){
            if ($want instanceof OnecNumber){
                if ((!is_string($got) && !is_int($got)) || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',
                    (string)$got) || bccomp($want->decimal,
                    (string)$got,
                    30) !== 0)$errors[] = $path;
            }
            elseif(is_array($want)){
                if (!is_array($got) || array_is_list($want) && (!array_is_list($got) || count($want) !== count($got))){
                    $errors[] = $path;
                    return;
                }
                foreach ($want as $key => $value){
                    if (!array_key_exists($key,
                        $got))$errors[] = $path . '.' . $key;
                    else $walk($value,
                        $got[$key],
                        $path . '.' . $key);
                }
            }
            elseif(str_ends_with($path,
                '.LineNumber')){
                if ((!is_string($got) && !is_int($got)) || !preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',
                    (string)$got) || bccomp((string)$want,
                    (string)$got,
                    0) !== 0)$errors[] = $path;
            }
            elseif($want !== $got)$errors[] = $path;
        }
        ;
        $walk($expected,
            $actual,
            'object');
        foreach (['DeletionMark' => false,
                ...($kind === 'customer_invoice'?['Posted' => false]:[])] as $key => $v)if (($actual[$key]??null) !== $v)$errors[] = 'object.' . $key;
        foreach (['DataVersion',
                $kind === 'customer_invoice'?'Number':'Code'] as $key)if (!is_string($actual[$key]??null) || trim($actual[$key]) === '')$errors[] = 'object.' . $key;
        return $errors;
    }
}
