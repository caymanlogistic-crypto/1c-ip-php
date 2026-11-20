<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{InterfaceDesign,InterfaceDesignStore,View};

final class DesignTable
{
    private array $state;
    private array $layout;

    public function __construct(private string $id, private array $types, private array $textKeys=[])
    {
        $this->state=InterfaceDesignStore::read($id);
        $this->layout=($this->state['applied'] ?? InterfaceDesign::defaults($id))['columns'];
    }

    public function start(bool $disclosure=false, string $details=''): string
    {
        $columns=$headers='';
        foreach ($this->layout as ['key'=>$key,'label'=>$label]) {
            $columns.='<col data-design-key="'.$key.'" class="registry-column-'.$this->types[$key].'">';
            $headers.='<th data-design-key="'.$key.'" scope="col"'.$this->cellClass($key).'><span data-design-label>'.View::e($label).'</span></th>';
        }
        if ($disclosure) {
            $columns.='<col class="registry-ui-disclosure-column">';
            $headers.='<th scope="col" aria-label="Раскрыть счета"></th>';
        }
        return '<table class="table registry-ui-table registry-ui-data'.($details!==''?' registry-ui-table--details':'').'" data-column-profile="rental-contracts" data-design-table'
            .($details!==''?' aria-label="'.View::e($details).'"':'').'><colgroup>'.$columns.'</colgroup><thead><tr>'.$headers.'</tr></thead><tbody>';
    }

    public function row(array $cells, string $attributes='', string $tail='', bool $summary=false): string
    {
        $html='<tr '.$attributes.($summary?' data-design-summary':'').'>';
        foreach ($this->layout as ['key'=>$key]) {
            $merge=$summary && !in_array($key,['amount','paid','debt','state'],true);
            $html.='<td data-design-key="'.$key.'"'.($merge?' data-design-merge':'').$this->cellClass($key).'>'.($cells[$key]??'').'</td>';
        }
        return $html.$tail.'</tr>';
    }

    public function attributes(): string
    {
        return InterfaceDesign::attributes($this->id,$this->state);
    }

    public function keys(): array
    {
        return array_keys($this->types);
    }

    private function cellClass(string $key): string
    {
        if ($this->types[$key]==='money') return ' class="registry-ui-number"';
        return in_array($key,$this->textKeys,true)?' class="registry-column-text"':'';
    }
}
