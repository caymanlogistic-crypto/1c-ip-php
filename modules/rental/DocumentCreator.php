<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, View};
final class DocumentCreator {

    public static function render(): string {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('rental.write');
        Service::roles([
            'manager'
        ]);
        Access::pages([
            'contracts',
            'contracts-active',
            'approvals'
        ]);
        $options = '<option value="">Выберите договор</option>';
        foreach (Controller::query("SELECT c.id,c.number,c.document_date,p.name,p.inn FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status='active' AND NOT EXISTS(SELECT 1 FROM rental_documents d WHERE d.contract_id=c.id AND d.applied=0 AND d.status<>'cancelled') ORDER BY c.number,c.id") as $c)$options.='<option value="'
        . $c['id']
        . '" data-name="'
        . View::e('№'
        . $c['number']
        . ' · '
        . $c['name'])
        . '" data-description="'
        . View::e(Documents::dateText($c['document_date'])
        . ($c['inn']?' · ИНН: '
        . $c['inn']:''))
        . '" data-search="'
        . View::e($c['number']
        . ' '
        . $c['name']
        . ' '
        . $c['inn'])
        . '">'
        . View::e('№'
        . $c['number']
        . ' · '
        . $c['name'])
        . '</option>';
        $fid = 'rental-document-creator';
        $body = '<form id="'
        . $fid
        . '" method="get" action="/rental/document-next" data-rental-document-creator>'
        . '<label class="field">'
        . '<span class="field-label">Документ</span>'
        . '<select class="field-input field-select" name="documentType" data-rental-document-type>'
        . '<option value="contract">Новый договор</option>'
        . '<option value="addendum">Допсоглашение к действующему договору</option>'
        . '</select>'
        . '</label>'
        . '<div id="document-base" data-rental-document-base hidden>'
        . '<label class="field">'
        . '<span class="field-label">Действующий договор</span>'
        . '<select class="field-input field-select" name="baseContract" data-rental-picker="base" disabled>'
        . $options
        . '</select></label></div></form>';
        return RentalUI::modal('Создать документ', $body, '<button class="btn btn-primary" type="submit" form="'
        . $fid
        . '">Продолжить</button>', 'contracts');
    }

    public static function next(): string {
        Service::roles([
            'manager'
        ]);
        $type = $_GET['documentType'] ?? 'contract';
        if ($type === 'contract') {
            $_GET = [
            ];
            return ContractForm::render();
        }
        if ($type !== 'addendum')throw new \RuntimeException('Неизвестный вид документа.', 422);
        $id = (int)($_GET['baseContract'] ?? 0);
        $c = Access::contract($id);
        if ($c['status'] !== 'active')throw new \RuntimeException('Выберите действующий договор.', 422);
        if (Controller::query("SELECT id FROM rental_documents WHERE contract_id=? AND applied=0 AND status<>'cancelled'", [
            $id
        ]))throw new \RuntimeException('По договору уже есть незавершённое ДС.', 409);
        $_GET = [
            'contract_id' => $id
        ];
        return AddendumController::editor();
    }
}
