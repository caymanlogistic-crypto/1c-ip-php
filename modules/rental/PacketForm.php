<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, View};

final class PacketForm
{
    public const CHANGED_MESSAGE = 'Пакет изменился после открытия. Проверьте актуальные данные и поставьте отметки заново.';

    public static function requiresConfirmation(string $kind,int $id): bool
    {
        Service::roles(['accountant']);
        Auth::requirePermission('finance.write');
        if ($kind==='contract') Access::contract($id,2); else Access::document($id,2);
        $query=DB::pdo()->prepare("SELECT id FROM rental_delivery_intents WHERE entity_type=? AND entity_id=? AND status IN ('claimed','unknown') LIMIT 1");
        $query->execute([$kind,$id]);
        if ($query->fetchColumn()) return false;
        try {
            return (NativePacket::current(DB::pdo(),$kind,$id,Auth::user(),'prepare')['state'] ?? null)!=='confirmed';
        } catch (\RuntimeException $error) {
            if (in_array($error->getMessage(),['NATIVE_PACKET_STALE','NATIVE_PACKET_INVOICE_CHANGED','NATIVE_PACKET_CONFIRMATION_ACTOR_CHANGED','NATIVE_PACKET_WORKFLOW_CHANGED'],true)) return true;
            throw $error;
        }
    }

    public static function confirmation(array $packet, ?array $previous = null): array
    {
        $view = $packet['view'];
        $context = $view['context'];
        $record = $context['record'];
        // These summaries only explain a conflict. NativePacket validates the full preview hash.
        $sections = [
            'details' => ['Реквизиты', [$context['party'], $context['organization']]],
            'billing' => ['Финансовые условия', [$context['terms'], $context['financial_state'], $view['invoices'],
                $context['organization']['vat'], $context['initial_terms'] ?? null]],
            'documents' => ['Договор и документы', [$record, $context['contract'], $context['file'],
                $context['legal'], $context['template'], $context['word_pack'] ?? null]],
        ];
        $previousSections = $previous['packet_sections'] ?? [];
        $hidden = '';
        $changed = [];
        foreach ($sections as $name => [$label, $values]) {
            $digest = hash('sha256', Importer::canonicalJson($values));
            $oldDigest = $previousSections[$name] ?? null;
            if (is_string($oldDigest) && !hash_equals($digest, $oldDigest)) $changed[] = $label;
            $hidden .= '<input type="hidden" name="packet_sections['.$name.']" value="'.$digest.'">';
        }
        $hidden .= '<input type="hidden" name="expected_packet_hash" value="'
            .View::e($packet['expected_packet_hash']).'">'
            .'<input type="hidden" name="packet_confirmed" value="1">';
        $notice = $previous === null ? '' : RentalUI::alert(self::CHANGED_MESSAGE, implode(' · ', $changed), 'warning');
        return ['fields'=>$hidden,'notice'=>$notice];
    }

    public static function preview(string $kind, int $id, ?array $previous = null): string
    {
        $packet = NativePacket::preview(DB::pdo(), $kind, $id, Auth::user());
        $view = $packet['view'];
        $context = $view['context'];
        $record = $context['record'];
        $confirmation = self::confirmation($packet,$previous);
        $body = $confirmation['notice'];
        $body .= RentalUI::pairs([
            ['Документ', $kind === 'document'
                ? 'ДС № '.$record['number'].' к договору '.$context['contract']['number']
                : 'Договор № '.$record['number']],
            ['Файл', $context['file']['filename']],
            ['Контрагент', $context['party']['name']],
        ]);
        if ($kind === 'contract') {
            $body .= RentalUI::pairs([
                ['Срок аренды',Documents::dateText($record['start_date']).' — '.Documents::dateText($record['end_date'])],
                ['Месячная ставка с НДС',Controller::money($record['amount'])],
            ]);
            $body .= RentalUI::alert('Подтверждение пакета не создаёт счета',
                $view['invoices'] ? 'В пакет входят показанные ранее выставленные счета с подтверждённым сроком.'
                    : 'Пакет договора доступен отдельно. Для выпуска откройте «Выставить первый счёт».');
            if ($context['initial_terms']) {
                $body .= RentalUI::pairs([
                    ['Условия первого начисления',$context['initial_terms']['reason']],
                    ['Кратность обеспечения',(string)$context['initial_terms']['multiple']],
                ]);
            }
            $separate = InitialInvoice::separateIds(DB::pdo(),$id);
            $attached = array_map('intval',array_column($view['invoices'],'id'));
            $held = array_diff($separate,$attached);
            if ($held) {
                $body .= RentalUI::alert('Отдельные счета ждут проверки перед включением в пакет',
                    'Сверьте срок оплаты и актуальность условий перед включением этих счетов.','warning');
                $rows = [];
                foreach ($context['financial_state'] as $invoice) {
                    if (!in_array((int)$invoice['id'],$held,true)) continue;
                    $rows[] = [View::e($invoice['kind']),View::e($invoice['period']),Controller::money($invoice['amount']),
                        View::e($invoice['due'] ? Documents::dateText($invoice['due']) : 'Требует подтверждения')];
                }
                if ($rows) $body .= Controller::table(['Счёт','Период','Сумма с НДС','Срок оплаты'],$rows);
            }
        }
        if ($view['invoices']) {
            $vat = $context['organization']['vat'];
            $vatText = $vat === null ? 'Не указан' : rtrim(rtrim($vat, '0'), '.').'%';
            $body .= '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">';
            foreach ($view['invoices'] as $invoice) {
                $body .= '<section class="registry-ui-form-section"><h3 class="registry-ui-modal-summary">'
                    .View::e($invoice['kind']).'</h3>'.RentalUI::pairs([
                        ['Период', $invoice['period']],
                        ['Сумма с НДС', Controller::money($invoice['amount'])],
                        ['НДС', $vatText],
                    ]).'</section>';
            }
            $body .= '</div>';
        }
        if (isset($context['legal_handoff'])) $body.=LegalHandoffCard::accountantDocuments($id,$context['legal_handoff']);
        elseif(isset($context['word_pack']))$body.=WordPack::links($context['word_pack']);
        return $body.$confirmation['fields'];
    }
}
