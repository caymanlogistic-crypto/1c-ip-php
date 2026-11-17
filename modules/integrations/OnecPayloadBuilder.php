<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Only called with a server-verified NativePacket snapshot; no authority is created by mapping. */
final class OnecPayloadBuilder {
    public function __construct(private OnecReferenceSource $bindings) {
    }
    private static function gap(string ...$p): never {
        throw new OnecSetupGap($p);
    }
    private static function day(mixed $v): string {
        if (!is_string($v) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',
            $v) || Support::day($v) !== $v)self::gap('source.approved_packet.explicit_dates');
        return $v;
    }
    public function build(array $source,
        array $packet,
        string $kind,
        string $subject): array {
        if (!in_array($kind,
            ['counterparty',
                'customer_invoice'],
            true))throw new \InvalidArgumentException('ONEC_KIND_UNSUPPORTED');
        $c = $packet['confirmation']??null;
        if (!is_array($c) || !is_string($c['command_key']??null) || $c['command_key'] === '' || !is_string($c['audit_ref']??null) || $c['audit_ref'] === '')throw new OnecNormalConfirmationRequired();
        foreach (['counterparty',
                'financial',
                'document'] as $check)if (($c['checks'][$check]??null) !== true)throw new OnecNormalConfirmationRequired(['confirmation.checks.' . $check]);
        $record = $packet['record']??null;
        $w = $packet['workflow']??null;
        $b = $packet['b_packet']??null;
        if (!is_array($record) || !in_array($record['kind']??'',
            ['contract',
                'document'],
            true) || !is_int($record['version']??null) || $record['version'] < 1 || !is_array($w) || !is_string($w['epoch']??null) || $w['epoch'] === '' || !is_int($w['revision']??null) || $w['revision'] < 1)self::gap('source.normalized_record_and_workflow');
        if (!is_array($b) || !is_string($b['binding_id']??null) || $b['binding_id'] === '' || ($b['version']??null) !== $record['version'] || !preg_match('/^[a-f0-9]{64}$/D',
            $b['sha256']??''))self::gap('source.approved_packet.exact_b_binding');
        $steps = array_values(array_filter($packet['native_steps']??[],
            static fn($s) => is_array($s) && ($s['kind']??'') === $kind && ($s['subject_id']??'') === $subject));
        if (count($steps) !== 1 || !is_string($steps[0]['step_id']??null) || $steps[0]['step_id'] === '')self::gap('source.operation_group.actual_native_step');
        $org = $packet['organization']??null;
        $party = $packet['party']??null;
        $contract = $packet['contract']??null;
        $preview = $packet['preview']??null;
        if (!is_array($org) || ($org['inn']??'') !== $source['organization_inn'])self::gap('source.organization.inn_matches_native_binding');
        if (!is_array($party) || !isset($party['id']) || !is_string($party['name']??null) || $party['name'] === '')self::gap('source.approved_party.identity');
        $terms = $preview['terms']??null;
        if (!is_array($terms) || !array_is_list($terms) || !$terms)self::gap('source.approved_packet.explicit_per_room_dated_terms');
        foreach ($terms as $t)if (!is_array($t) || !is_string($t['amount']??null) || !preg_match('/^[0-9]+(?:\\.[0-9]+)?$/D',
            $t['amount']) || bccomp($t['amount'],
            '0',
            30) <= 0 || self::day($t['end']??null) < self::day($t['start']??null))self::gap('source.approved_packet.explicit_per_room_dated_terms');
        if ($kind === 'counterparty') {
            if ($subject !== 'party:' . (string)$party['id'])throw new \RuntimeException('ONEC_SUBJECT_MISMATCH');
            $identity = $this->bindings->partyIdentity($source,
                $party);
            $payload = ['entity_type' => $identity['entity_type'],
                'name' => $party['name'],
                'full_name' => $identity['full_name'],
                'inn' => $identity['inn'],
                'kpp' => $identity['kpp']];
        }
        else {
            $invoices = $packet['invoices']??null;
            $ids = $packet['invoice_ids']??null;
            if (!is_array($invoices) || !array_is_list($invoices) || !is_array($ids) || count($invoices) !== count($ids))self::gap('source.approved_packet.ordered_invoice_ids');
            foreach ($invoices as $i => $invoice)if (!is_array($invoice) || !isset($invoice['id']) || (string)$invoice['id'] !== (string)$ids[$i])self::gap('source.approved_packet.ordered_invoice_ids');
            $matches = array_values(array_filter($invoices,
                static fn($i) => $subject === 'invoice:' . (string)$i['id']));
            if (count($matches) !== 1)self::gap('source.operation_group.selected_invoice');
            $invoice = $matches[0];
            OnecInvoiceScope::requireReady($invoice);
            if (!is_array($contract) || !isset($contract['id']) || (string)($invoice['contract_id']??$invoice['contract']??'') !== (string)$contract['id'])throw new \RuntimeException('ONEC_INVOICE_CONTRACT_MISMATCH');
            $amount = $invoice['amount']??null;
            if (!is_string($amount) || !preg_match('/^[0-9]+(?:\\.[0-9]{1,2})?$/D',
                $amount) || bccomp($amount,
                '0',
                30) <= 0)self::gap('source.approved_invoice.amount_exact_positive_cents');
            $amount = bcadd($amount,
                '0',
                2);
            $when = self::day($invoice['issued']??null);
            if (($preview['vatIncluded']??null) !== true || !is_string($preview['vat']??null) || !preg_match('/^[0-9]+(?:\\.[0-9]+)?$/D',
                $preview['vat']))self::gap('source.organization.vat',
                'source.approved_packet.vatIncluded');
            $vat = [];
            foreach ($source['profile']['vat_rates']??[] as $code => $rule)if (($rule['without_vat']??true) === false && is_string($rule['rate']??null) && bccomp($rule['rate'],
                $preview['vat'],
                30) === 0)$vat[] = $code;
            if (count($vat) !== 1)self::gap('native_policy.vat_enum_for_raw_organization_rate');
            $policy = $this->bindings->invoicePolicy($source,
                (string)($invoice['kind']??''));
            $buyer = $this->bindings->observation($source,
                'parties',
                (string)$party['id'],
                'counterparty');
            if (($buyer['facts']['ИНН']??'') !== ($party['inn']??'') || ($buyer['facts']['Description']??null) !== $party['name'])self::gap('native_refs.counterparty.current_source_name_inn');
            $refs = ['organization' => $this->bindings->reference($source,
                    'organization',
                    'organization',
                    'organization'),
                'counterparty' => $buyer['reference'],
                'contract' => $this->bindings->reference($source,
                    'contracts',
                    (string)$contract['id'],
                    'contract',
                    $policy['contract_purpose']),
                'currency' => $this->bindings->reference($source,
                    'currency',
                    $policy['currency_logical_id'],
                    'currency')];
            if ($refs['contract']['organization_key'] !== $refs['organization']['key'] || $refs['contract']['counterparty_key'] !== $refs['counterparty']['key'])throw new \RuntimeException('ONEC_CONTRACT_OWNERSHIP');
            $item = $this->bindings->reference($source,
                'invoice_service',
                $policy['invoice_service_logical_id'],
                'item');
            $description = (string)($invoice['title']??'');
            if ($description === '')$description = (string)($invoice['purpose']??'');
            if ($description === '')$description = (string)($invoice['kind']??'');
            $payload = $refs+['date' => $when,
                'prices_include_vat' => true,
                'comment' => (string)($invoice['purpose']??''),
                'lines' => [['item' => $item,
                'description' => $description,
                'quantity' => '1',
                'price' => $amount,
                'vat_code' => $vat[0]]]];
        }
        $out = ['schema' => 'ipya.rental.onec.prepared.v1',
            'project_id' => CaptureReader::PROJECT,
            'source_namespace' => $source['workflow_namespace'],
            'logical_document_id' => $subject,
            'step_id' => $steps[0]['step_id'],
            'kind' => $kind,
            'native_source_instance' => $source['source_instance'],
            'workflow' => ['epoch' => $w['epoch'],
            'revision' => $w['revision']],
            'connection_revision' => (int)($source['operative_connection_revision'] ?? $source['revision']),
            'b_packet' => ['binding_id' => $b['binding_id'],
            'version' => $b['version'],
            'sha256' => $b['sha256']],
            'payload' => $payload];
        if ($kind === 'customer_invoice'){
            $v = $invoice['version'] ?? null;
            if (!array_key_exists('version', $invoice) || !is_int($v) || $v < 0)self::gap('source.approved_invoice.version_integer_ge_zero');
            $out['source_invoice_version'] = $v;
        }
        return $out;
    }
}
