<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, View};
use RuntimeException;

final class DocumentCreator
{
    public static function render(?array $baseContract = null): string
    {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('rental.write');
        Service::roles(['manager']);
        Access::pages(['contracts','contracts-active','approvals']);
        $form = 'rental-document-creator';
        $body = '<form id="'.$form.'" class="registry-ui-modal-grid" method="get" action="/rental/document-next" data-rental-document-creator>';
        if ($baseContract) {
            $baseId = (int)$baseContract['id'];
            $party = Controller::query('SELECT name FROM parties WHERE id=?',[$baseContract['party_id']])[0]['name'];
            $body .= '<input type="hidden" name="documentType" value="addendum" data-rental-document-type>'
                .'<input type="hidden" name="baseContract" value="'.$baseId.'">'
                .RentalUI::pairs([['Действующий договор','№'.$baseContract['number'].' от '.Documents::dateText($baseContract['document_date'])],
                    ['Контрагент',$party]]);
        } else {
            $options = '<option value="">Выберите договор</option>';
            foreach (Controller::query("SELECT c.id,c.number,c.document_date,p.name,p.inn FROM contracts c JOIN parties p ON p.id=c.party_id
                WHERE c.status='active' AND NOT EXISTS(SELECT 1 FROM rental_documents d WHERE d.contract_id=c.id AND d.applied=0 AND d.status<>'cancelled')
                ORDER BY c.number,c.id") as $c) {
                $name = '№'.$c['number'].' · '.$c['name'];
                $description = Documents::dateText($c['document_date']).($c['inn']?' · ИНН: '.$c['inn']:'');
                $options .= '<option value="'.$c['id'].'" data-name="'.View::e($name).'" data-description="'.View::e($description)
                    .'" data-search="'.View::e($name.' '.$description).'">'.View::e($name).'</option>';
            }
            $body .= '<label class="field"><span class="field-label">Документ</span>'
                .'<select class="field-input field-select" name="documentType" data-rental-document-type>'
                .'<option value="contract">Новый договор</option><option value="addendum">Допсоглашение к действующему договору</option>'
                .'</select></label><div id="document-base" data-rental-document-base hidden>'
                .'<label class="field"><span class="field-label">Действующий договор</span>'
                .'<select class="field-input field-select" name="baseContract" data-rental-picker="base" disabled>'
                .$options.'</select></label></div>';
        }
        $body .= '<div class="registry-ui-modal-grid" data-rental-document-scope'.($baseContract?'':' hidden').'>'
            .'<label class="field"><span class="field-label">Состав помещений</span>'
            .'<select class="field-input field-select" name="object_scope"'.($baseContract?' required':' disabled').'>'
            .'<option value="">Выберите состав</option><option value="same">Прежний состав помещений</option>'
            .'<option value="change">Изменения в составе помещений</option></select></label>'
            .'<div class="registry-ui-modal-grid" data-rental-document-indexation hidden>'
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="indexation" value="1" disabled><span>Индексация</span></label>'
            .'<div data-rental-indexation-percent hidden>'
            .self::percentField(AddendumIndexation::DEFAULT_PERCENT,enabled:false)
            .'</div></div></div></form>';
        return RentalUI::modal($baseContract?'Создать допсоглашение':'Создать документ', $body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Продолжить</button>', $baseContract?'contracts-active':'contracts');
    }

    public static function percentField(string $percent, bool $enabled=true): string
    {
        return '<label class="field"><span class="field-label">Процент индексации</span>'
            .'<input class="field-input" type="text" inputmode="decimal" name="index_percent" value="'.View::e($percent)
            .'" pattern="[0-9]{1,3}([,.][0-9]{1,4})?"'.($enabled?' required':' disabled').'></label>';
    }

    public static function next(): string
    {
        Service::roles(['manager']);
        $type = $_GET['documentType'] ?? 'contract';
        if ($type === 'contract') {
            $_GET = [];
            return ContractForm::render();
        }
        if ($type !== 'addendum') throw new RuntimeException('Неизвестный вид документа.',422);
        $id = (int)($_GET['baseContract'] ?? 0);
        $c = Access::contract($id);
        if ($c['status'] !== 'active') throw new RuntimeException('Выберите действующий договор.',422);
        if (Controller::query("SELECT id FROM rental_documents WHERE contract_id=? AND applied=0 AND status<>'cancelled'",[$id]))
            throw new RuntimeException('По договору уже есть незавершённое ДС.',409);
        $selection = AddendumIndexation::selection($_GET);
        if ($selection['scope'] === '') throw new RuntimeException('Выберите, меняется ли состав помещений.',422);
        $_GET = ['contract_id'=>$id,'object_scope'=>$selection['scope']];
        if ($selection['percent'] !== null) $_GET += ['indexation'=>'1','index_percent'=>$selection['percent']];
        return AddendumController::editor();
    }
}
