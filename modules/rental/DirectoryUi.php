<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Http,View};

/** Existing C directory components with server-side data and commands. */
final class DirectoryUi
{
    public static function input(string $key,string $label,mixed $value='',string $type='text',bool $required=false): string
    {
        return '<label class="field"><span class="field-label-row"><span class="field-label">'.View::e($label)
            .($required?' <span class="req">*</span>':'').'</span></span><input class="field-input" name="'.View::e($key)
            .'" type="'.View::e($type).'"'.($type==='number'?' step="0.01"':'').' value="'.View::e($value)
            .'"'.($required?' required':'').'></label>';
    }

    public static function inputWithOption(string $key,string $label,mixed $value,string $flag,string $caption,string $accessible,bool $checked): string
    {
        $id='party-'.$key;
        return '<div class="field"><div class="field-label-row"><label class="field-label" for="'.$id.'">'.View::e($label).'</label>'
            .'<input type="hidden" name="'.$flag.'" value="0"><label class="checkbox-inline"><input type="checkbox" name="'.$flag.'" value="1" aria-label="'.View::e($accessible).'"'.($checked?' checked':'').'><span>'.View::e($caption).'</span></label></div>'
            .'<input class="field-input" id="'.$id.'" name="'.$key.'" type="text" value="'.View::e($value).'"></div>';
    }

    public static function pairs(array $rows): string
    {
        $html='<div class="demo-value-pair">';
        foreach ($rows as $row) {
            [$label,$value]=$row;
            $text=View::e($value ?? '—');
            $html.='<span class="registry-ui-secondary">'.View::e($label).'</span><span class="registry-ui-modal-text">'
                .(!empty($row[2])?'<strong class="registry-ui-error">'.$text.'</strong>':$text).'</span>';
        }
        return $html.'</div>';
    }
    public static function checkbox(string $key,string $label,bool $checked,bool $required=false): string
    {
        return '<input type="hidden" name="'.$key.'" value="0"><label class="registry-ui-checkbox">'
            .'<input type="checkbox" name="'.$key.'" value="1"'.($checked?' checked':'').($required?' required':'').'><span>'.View::e($label).'</span></label>';
    }

    public static function counts(array $counts,string $page=''): string
    {
        $html='';
        foreach (['is-alert','is-warning','is-success','is-info'] as $index=>$class) {
            $count=(int)($counts[$index] ?? 0);
            if ($count>0) {
                $label=\App\Menu::attentionLabels($page)[$index].': '.$count;
                $html.='<span class="sub-count '.$class.'" data-attention="'.['red','yellow','green','blue'][$index].'" aria-label="'.$label.'" title="'.$label.'">'.$count.'</span>';
            }
        }
        return $html!==''?'<span class="nav-counts">'.$html.'</span>':'';
    }

    public static function select(string $key,string $label,array $options,mixed $selected='',bool $required=false,string $attributes='',bool $alignedLabel=false): string
    {
        $caption='<span class="field-label">'.View::e($label).($alignedLabel && $required?' <span class="req">*</span>':'').'</span>';
        if ($alignedLabel) $caption='<span class="field-label-row">'.$caption.'</span>';
        $html='<label class="field">'.$caption.'<select class="field-select field-input" name="'.$key.'"'.($required?' required':'').' '.$attributes.'>';
        foreach ($options as $value=>$text) $html.='<option value="'.View::e($value).'"'.((string)$value===(string)$selected?' selected':'').'>'.View::e($text).'</option>';
        return $html.'</select></label>';
    }

    public static function form(string $action,array $record,string $fields,string $label='Сохранить'): array
    {
        $id='directory-form-'.bin2hex(random_bytes(6));
        $body='<form id="'.$id.'" method="post" action="/rental/action">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="action" value="'.$action.'">'
            .'<input type="hidden" name="id" value="'.($record['id'] ?? 0).'">'
            .'<input type="hidden" name="version" value="'.($record['version'] ?? 0).'">'
            .'<input type="hidden" name="command_key" value="'.bin2hex(random_bytes(16)).'">'.$fields.'</form>';
        return [$body,'<button class="btn btn-primary" type="submit" form="'.$id.'">'.View::e($label).'</button>',$id];
    }

    public static function registry(string $id,array $headers,array $rows,array $columns,string $controls='',string $summary='',string $after='',bool $filterForm=false, ?DesignTable $design=null): string
    {
        $toolbar=$filterForm?'form':'div';
        $table = $design===null ? Controller::table($headers,$rows,$columns) : $design->start();
        if ($design!==null) {
            foreach ($rows as $row) {
                $attributes=$row['_attrs'] ?? '';
                unset($row['_attrs']);
                $table.=$design->row(array_combine($design->keys(),$row),$attributes);
            }
            $table.='</tbody></table>';
            $table='<div class="table-scroll">'.$table.'</div>';
        }
        return '<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-registry-id="'.$id.'" data-registry-managed-markup="true"'.($design?->attributes() ?? '').'>'
            .'<'.$toolbar.($filterForm?' method="get" data-rental-registry-filter':'').' class="table-toolbar registry-ui-toolbar"><div class="found-label">Найдено: <b>'.count($rows).'</b>'.$summary.'</div>'
            .'<div class="toolbar-right">'.$controls.'</div></'.$toolbar.'>'.$after.$table.'</section>';
    }

    public static function search(string $query,bool $grouped=false): string
    {
        $input='<input class="'.($grouped?'toolbar-search ':'').'registry-ui-search" id="search" name="q" type="search" value="'.View::e($query).'" placeholder="'.($grouped?'Поиск по всему реестру':'Поиск по всем столбцам').'" aria-label="'.($grouped?'Поиск по всему реестру':'Поиск по реестру').'">';
        return $grouped?'<div class="registry-ui-search-group">'.$input.'</div>':$input;
    }

    public static function bankName(array $bank): string
    {
        $raw=json_decode($bank['source_json'] ?? '{}',true) ?: [];
        return !empty($raw['example']) && $bank['bank']==='Банк «Деловой квартал» (пример)' ? 'Банк «Деловой квартал»' : (string)($bank['bank'] ?? '');
    }

    public static function area(?string $value): string
    {
        if ($value===null) return '—';
        $rounded=Billing::round($value);
        [$whole,$fraction]=explode('.',$rounded);
        $fraction=rtrim($fraction,'0');
        return preg_replace('/\B(?=(\d{3})+(?!\d))/u',' ',$whole).($fraction!==''?','.$fraction:'');
    }
}
