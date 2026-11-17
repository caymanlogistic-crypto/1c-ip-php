<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\View;

/** Shared composition of contract and addendum room/price editors. */
final class ContractObjectEditor
{
    public static function row(array $object, ?array $period = null): string
    {
        $id=$object['object_id'] ?? $object['id'];
        $area=($object['area'] ?? null)===null?'—':rtrim(rtrim((string)$object['area'],'0'),'.');
        $dates=$period===null?'':'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .Controller::input('term_start['.$id.']','Начало аренды',$object['start_date'] ?? $period['start'],'date',true)
            .Controller::input('term_end['.$id.']','Окончание аренды',$object['end_date'] ?? $period['end'],'date',true).'</div>';
        return '<tr data-rental-term-id="'.View::e($id).'"><td><span data-rental-term-name>'.View::e($object['name']).'</span>'
            .'<div class="registry-ui-secondary"><span data-rental-term-type>'.View::e($object['type'] ?? '').'</span> · <span data-rental-term-area>'.View::e($area).'</span> м²</div>'
            .'<input type="hidden" name="objects[]" value="'.View::e($id).'">'.$dates.'</td>'
            .'<td><input class="field-input" type="number" min="0.01" step="0.01" name="term_amount['.View::e($id).']" data-unit-price="'.View::e($id).'" value="'.View::e($object['amount'] ?? '').'" inputmode="decimal" required aria-label="Аренда с НДС за '.View::e($object['name'] ?: 'помещение').'"></td>'
            .'<td><button class="registry-ui-reset" type="button" data-rental-remove-object>Убрать</button></td></tr>';
    }

    public static function render(array $objects,string $options,string $total,?array $period=null,bool $draftPicker=false): string
    {
        $rows='';foreach($objects as $object)$rows.=self::row($object,$period);
        if($rows==='')$rows='<tr data-rental-empty-terms><td colspan="3">Помещения пока не добавлены.</td></tr>';
        $template=['id'=>'__ID__','name'=>'','type'=>'','area'=>null,'amount'=>null];
        return '<div class="registry-ui-modal-grid"><div id="chosen-objects">'
            .'<span class="registry-ui-modal-summary">Помещения в договоре · <span data-rental-object-count>'.count($objects).'</span></span>'
            .'<div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Помещение</th><th>Аренда с НДС, ₽</th><th>Состав</th></tr></thead><tbody data-rental-terms>'.$rows.'</tbody>'
            .'<tfoot><tr><td colspan="3" class="registry-ui-number"><input type="hidden" name="amount" value="'.View::e($total).'"><strong data-rental-contract-total data-rental-total-prefix="ИТОГО: ">ИТОГО: '.View::e(Controller::money($total)).'</strong></td></tr></tfoot></table></div></div>'
            .'<div class="field"><label class="field-label" for="contract-object">Выбрать помещение</label><div class="registry-ui-modal-address-row">'
            .'<select class="field-input field-select" id="contract-object" data-required-items="[name=&quot;objects[]&quot;],input[name^=&quot;term_amount[part_&quot;]"'.($draftPicker?' name="draft_object_id"':'').' data-rental-object-picker data-rental-picker="room">'.$options.'</select>'
            .'<button class="btn btn-secondary" type="button" data-rental-add-object>Добавить помещение</button></div></div>'
            .'<span class="field-msg">Укажите месячную стоимость каждого помещения с НДС.</span></div>'
            .'<template data-rental-term-template>'.self::row($template,$period).'</template>';
    }
}
