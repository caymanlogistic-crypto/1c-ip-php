<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, View};

final class PacketForm
{
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

    public static function preview(string $kind, int $id): string
    {
        $packet = NativePacket::preview(DB::pdo(), $kind, $id, Auth::user());
        $context = $packet['view']['context'];
        $record = $context['record'];
        $rows = [];
        foreach ($packet['view']['invoices'] as $invoice) {
            $rows[] = [
                View::e($invoice['kind']), View::e($invoice['period']),
                Controller::money($invoice['amount']),
                View::e($context['organization']['vat'] === null
                    ? 'Не указан'
                    : rtrim(rtrim($context['organization']['vat'], '0'), '.').'%'),
            ];
        }
        $body = RentalUI::pairs([
            ['Документ', ($kind === 'document' ? 'ДС №' : 'Договор №').$record['number']],
            ['Файл', $context['file']['filename']],
            ['Контрагент', $context['party']['name']],
        ]);
        if ($rows) {
            $body .= Controller::table(['Назначение', 'Период', 'Сумма с НДС', 'НДС'], $rows);
        }
        if(isset($context['word_pack']))$body.=WordPack::links($context['word_pack']);
        $body .= '<input type="hidden" name="expected_packet_hash" value="'
            .View::e($packet['expected_packet_hash']).'">'
            .'<input type="hidden" name="packet_confirmed" value="1">';
        return $body;
    }
}
