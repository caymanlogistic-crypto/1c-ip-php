<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\View;

/** Shared composition of contract and addendum room/price editors. */
final class ContractObjectEditor
{
    public static function row(array $object, ?array $period = null,bool $billingEditor=false,
        bool $fixed=false,bool $indexed=false): string
    {
        $id=$object['object_id'] ?? $object['id'];
        $area=($object['area'] ?? null)===null?'—':rtrim(rtrim((string)$object['area'],'0'),'.');
        $dates=$period===null?'':self::dateFields($object,$period);
        $base=$object['indexation_base'] ?? null;
        $baseRate=$indexed?'<div class="registry-ui-secondary">Исходная ставка <span data-rental-indexation-base>'
            .($base===null?'—':View::e(Controller::money($base))).'</span></div>':'';
        $price='<input class="field-input" type="number" min="0.01" step="0.01" name="term_amount['.View::e($id).']"'
            .' data-unit-price="'.View::e($id).'" value="'.View::e($object['amount'] ?? '').'" inputmode="decimal" '
            .($indexed?'readonly':'required').' aria-label="Аренда с НДС за '.View::e($object['name'] ?: 'помещение').'">';
        $composition=$indexed?'Без изменений':'<button class="registry-ui-reset" type="button" data-rental-remove-object>Убрать</button>';
        return '<tr data-rental-term-id="'.View::e($id).'"><td><span data-rental-term-name>'.View::e($object['name']).'</span>'
            .'<div class="registry-ui-secondary"><span data-rental-term-type>'.View::e($object['type'] ?? '').'</span> · <span data-rental-term-area>'.View::e($area).'</span> м²</div>'
            .$baseRate.'<input type="hidden" name="objects[]" value="'.View::e($id).'">'.$dates
            .($billingEditor?OccupiedBillingCard::fields((string)$id,$object['billing_exception'] ?? []):'').'</td>'
            .'<td>'.$price.'</td>'
            .(!$fixed||$indexed?'<td>'.$composition.'</td>':'').'</tr>';
    }

    public static function render(array $objects,string $options,string $total,?array $period=null,bool $draftPicker=false,
        string $countLabel='Помещения в договоре',bool $fixed=false,bool $indexed=false): string
    {
        $rows='';foreach($objects as $object)$rows.=self::row($object,$indexed?null:$period,$draftPicker,$fixed,$indexed);
        $columns=$fixed&&!$indexed?2:3;
        if($rows==='')$rows='<tr data-rental-empty-terms><td colspan="'.$columns.'">Помещения пока не добавлены.</td></tr>';
        $head='<th>Аренда с НДС, ₽</th>'.($columns===3?'<th>Состав</th>':'');
        $table='<div class="registry-ui-modal-grid">'.($draftPicker?'<input type="hidden" name="billing_exception_present" value="1">':'').'<div id="chosen-objects">'
            .'<span class="registry-ui-modal-summary">'.View::e($countLabel).' · <span data-rental-object-count>'.count($objects).'</span></span>'
            .'<div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Помещение</th>'.$head.'</tr></thead><tbody data-rental-terms>'.$rows.'</tbody>'
            .'<tfoot><tr><td colspan="'.$columns.'" class="registry-ui-number"><input type="hidden" name="amount" value="'.View::e($total).'"><strong data-rental-contract-total data-rental-total-prefix="ИТОГО: ">ИТОГО: '.View::e(Controller::money($total)).'</strong></td></tr></tfoot></table></div></div>';
        if ($fixed) return $table.($indexed && $period && $objects?self::periodDetails($objects,$period):'').'</div>';
        $template=['id'=>'__ID__','name'=>'','type'=>'','area'=>null,'amount'=>null];
        return $table.'<div class="field"><label class="field-label" for="contract-object">Выбрать помещение</label><div class="registry-ui-modal-address-row">'
            .'<select class="field-input field-select" id="contract-object" data-required-items="[name=&quot;objects[]&quot;],input[name^=&quot;term_amount[part_&quot;]"'.($draftPicker?' name="draft_object_id"':'').' data-rental-object-picker data-rental-picker="room">'.$options.'</select>'
            .'<button class="btn btn-secondary" type="button" data-rental-add-object>Добавить помещение</button></div></div>'
            .'<span class="field-msg">Укажите месячную стоимость каждого помещения с НДС.</span></div>'
            .'<template data-rental-term-template>'.self::row($template,$period,$draftPicker).'</template>';
    }

    private static function dateFields(array $object,array $period): string
    {
        $id=$object['object_id'] ?? $object['id'];
        return '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .Controller::input('term_start['.$id.']','Начало аренды',$object['start_date'] ?? $period['start'],'date',true)
            .Controller::input('term_end['.$id.']','Окончание аренды',$object['end_date'] ?? $period['end'],'date',true).'</div>';
    }

    private static function periodDetails(array $objects,array $period): string
    {
        $body='';
        foreach ($objects as $object) {
            $body.='<div class="registry-ui-modal-grid"><span class="registry-ui-modal-summary">'.View::e($object['name']).'</span>'
                .self::dateFields($object,$period).'</div>';
        }
        return '<details class="registry-ui-form-disclosure" data-form-disclosure>'
            .'<summary class="btn btn-secondary">Сроки по помещениям</summary>'
            .'<div class="registry-ui-form-section">'.$body.'</div></details>';
    }
}
