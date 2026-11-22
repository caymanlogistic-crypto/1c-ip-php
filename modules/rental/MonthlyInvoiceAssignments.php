<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, Http, View};
use Modules\Integrations\{Config, Support};
use PDO;

final class MonthlyInvoiceAssignments
{
    public static function listing(PDO $db, array $months): array
    {
        $contracts = $db->query('SELECT c.id,c.number,p.name,p.source_json party_source
            FROM contracts c JOIN parties p ON p.id=c.party_id')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $bindings = $db->query("SELECT DISTINCT source_id,entity_id FROM rental_source_bindings WHERE entity_type='contract'
            AND collection='Catalog_ДоговорыКонтрагентов' AND relation_kind='rental_contract'")
            ->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_COLUMN);
        $archiveLinks = $db->query("SELECT DISTINCT source_id,entity_id FROM rental_source_bindings WHERE entity_type='invoice'
            AND collection='Document_СчетНаОплатуПокупателю' AND relation_kind='invoice_control'")
            ->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_COLUMN);
        $invoices = $db->query("SELECT i.id,i.number,i.period,i.issued,i.amount,i.paid,i.contract_id,i.kind,i.source_json,
            JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.Ref_Key')) source_ref FROM invoices i WHERE i.status<>'revoked'")
            ->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        foreach ($invoices as $id=>$invoice) {
            if ($invoice['source_ref']) $archiveLinks[$invoice['source_ref']][] = $id;
        }
        $customers = [];
        foreach ($contracts as &$lease) {
            $lease['party_ref'] = InvoiceContractAssignment::partyRef($lease);
            if ($ref = $lease['party_ref']) $customers[$ref] = $lease['name'];
        }
        unset($lease);
        $assignments = InvoiceContractAssignment::index($db);
        $rows = $seen = [];
        foreach ((new MonthlyInvoiceArchive($db, Config::store()))->rows() as $archive) {
            $matched = array_values(array_unique(array_filter($archiveLinks[$archive['reference']] ?? [],
                static fn($id): bool=>isset($invoices[$id]))));
            foreach ($matched as $id) $seen[$id] = true;
            $periods = array_keys($archive['periods'] ?? []);
            if (!array_intersect($months, $periods ?: [substr($archive['document_date'],0,7)])) continue;
            $owner = $archive['party_ref'];
            $manual = InvoiceContractAssignment::contractId($archive,$assignments[$archive['id']] ?? null);
            $ids = $bindings[$archive['contract_ref'] ?? ''] ?? [];
            $invoice = count($matched) === 1 ? $invoices[$matched[0]] : null;
            $linked = $invoice['contract_id'] ?? null;
            $lease = $contracts[$linked ?? $manual ?? (count($ids) === 1 ? $ids[0] : 0)] ?? null;
            if (!$owner || !$lease || $lease['party_ref'] !== $owner || count($matched)>1) $lease = null;
            $rows[] = ['archive'=>$archive['id'],'invoice_id'=>count($matched)===1?$matched[0]:null,
                'number'=>$archive['number'],'name'=>$archive['name'],'issued'=>$archive['document_date'],
                'amount'=>$archive['amount'],'paid'=>$invoice['paid'] ?? null,
                'months'=>$periods,'client'=>$lease['name'] ?? $customers[$owner] ?? 'Уточнить',
                'contract'=>$lease['number'] ?? null,'origin'=>'1С','status'=>$archive['status'],
                'editable'=>!$matched && $archive['status']==='Проведён'];
        }
        foreach ($invoices as $id=>$invoice) {
            if (isset($seen[$id])) continue;
            $period = InvoicePeriods::month($invoice['period']);
            if (!in_array($period ?: substr((string)$invoice['issued'],0,7),$months,true)) continue;
            $lease = $contracts[$invoice['contract_id']] ?? null;
            $rows[] = ['archive'=>null,'invoice_id'=>$id,'number'=>$invoice['number'],
                'name'=>MonthlyInvoiceArchive::erpName($invoice),'issued'=>$invoice['issued'],
                'amount'=>$invoice['amount'],'paid'=>$invoice['paid'],'months'=>$period?[$period]:[],'client'=>$lease['name'] ?? 'Уточнить',
                'contract'=>$lease['number'] ?? null,'origin'=>'ERP','status'=>'Учтён','editable'=>false];
        }
        usort($rows,static fn($a,$b)=>[$b['issued'],$b['number'],$b['archive'],$b['invoice_id']]
            <=> [$a['issued'],$a['number'],$a['archive'],$a['invoice_id']]);
        return $rows;
    }

    public static function table(array $rows, string $url): string
    {
        $items = $details = [];
        $canEdit = InvoiceContractAssignment::canEdit(Auth::user() ?? []);
        foreach ($rows as $index=>$row) {
            $number = $row['invoice_id'] ? Controller::link('/rental/invoice?id='.$row['invoice_id'],$row['number'])
                : View::e($row['number']);
            $action = $canEdit && $row['editable']
                ? '<a class="btn btn-secondary" href="'.View::e($url.'&document='.$row['archive']).'">'
                    .($row['contract'] === null ? 'Привязать' : 'Изменить').'</a>' : '';
            $items[] = [$number, MonthlyRentReconciliationPage::invoiceNameCell($row['name']), View::e($row['origin']), View::e($row['client']),
                View::e($row['contract'] === null ? 'Не привязан' : '№'.$row['contract']),
                View::e(implode(', ',array_map(MonthlyRentReconciliationPage::monthLabel(...),$row['months'])) ?: '—'),
                Documents::dateText($row['issued']), Controller::money($row['amount']),
                $row['paid'] === null ? '—' : Controller::money($row['paid']), View::e($row['status']),$action];
            if ($row['name'] !== '') $details[$index] = MonthlyRentReconciliationPage::invoiceNameDetails('bill-name-'.$index,
                $row['number'], $row['name']);
        }
        return $items ? Controller::table(['Счёт','Наименование счёта','Источник','Клиент','Договор','Период аренды','Выставлен',
            'Сумма','Разнесено','Состояние','Действие'], $items,['code','long','short','text','code','text','short','money','money','short','text'],
            $details, columnWidths:[5=>'code',10=>'code'])
            : '<section class="panel-section">'.RentalUI::alert('По выбранным условиям счетов нет.').'</section>';
    }

    public static function editor(string $documentId, string $url): string
    {
        if (!InvoiceContractAssignment::canEdit(Auth::user() ?? [])) return '';
        $db = DB::pdo();
        $archive = InvoiceContractAssignment::archive($db,$documentId);
        $source = Support::decode(Config::store()->read($archive['raw_path'],$archive['raw_sha256']))['fields']['record'];
        $assignment = InvoiceContractAssignment::index($db)[$archive['id']] ?? [];
        $options = InvoiceContractAssignment::options($db,$source);
        $header = '<section class="panel-section"><h2 class="section-title">Счёт '.View::e($archive['number']).'</h2>';
        if (!$options || $archive['status'] !== 'Проведён' || InvoiceContractAssignment::invoiceMatches($db,$source['Ref_Key'])) {
            return $header.RentalUI::alert('Для этого счёта нет доступного договора для ручной привязки.','','warning').'</section>';
        }
        $select = '<label class="field"><span class="field-label">Договор аренды</span><select class="field-select" name="lease" required>'
            .'<option value="">Выберите договор</option>';
        foreach ($options as $lease) {
            $select .= '<option value="'.(int)$lease['id'].':'.(int)$lease['version'].'"'
                .((int)($assignment['contract_id'] ?? 0)===(int)$lease['id']?' selected':'').'>'
                .View::e('№'.$lease['number'].' · '.$lease['name'].' · '.Documents::dateText($lease['start_date'])
                    .' — '.Documents::dateText($lease['end_date'])).'</option>';
        }
        return $header.'<form method="post" class="registry-ui-modal-grid" action="'.View::e($url).'">'
            .PremisesActsCard::hidden('_csrf',Http::csrf())
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)))
            .PremisesActsCard::hidden('document_id',$archive['id'])
            .PremisesActsCard::hidden('document_version',(string)$archive['version'])
            .PremisesActsCard::hidden('assignment_revision',(string)($assignment['revision'] ?? 0))
            .$select.'</select></label><label class="field"><span class="field-label">Основание</span>'
            .'<textarea name="reason" class="field-textarea" maxlength="500" required>'
            .View::e($assignment['reason'] ?? '').'</textarea></label>'
            .'<div class="toolbar-right"><a href="'.View::e($url).'" class="btn btn-secondary">Отмена</a>'
            .'<button type="submit" class="btn btn-primary">Сохранить привязку</button></div></form></section>';
    }
}
