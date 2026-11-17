<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Http,View};
use RuntimeException;

/** PHP template adapter for source S premises/confirmed*.html; no new geometry. */
final class ConfirmedController
{
    private const URL='/auxiliary/premises';

    private static function e(mixed $value): string {return View::e($value);}

    public static function area(?string $value): string
    {
        if ($value===null) return 'Не установлена';
        return str_replace('.',',',str_contains($value,'.')?rtrim(rtrim($value,'0'),'.'):$value);
    }

    private static function value(array $row,string $field): string
    {
        if ($field==='area') return self::area($row[$field]);
        if ($field==='kind') return $row['display_kind']??$row[$field];
        if ($field==='aliases') return $row['display_aliases']??$row[$field];
        if ($field==='source_ids') return implode(', ',json_decode($row[$field],true,512,JSON_THROW_ON_ERROR));
        if ($field==='has_questions') return $row[$field]?'Да':'Нет';
        return (string)($row[$field]??'');
    }

    private static function returnQuery(array $options): string
    {
        $result=[];
        foreach (['tab','q','questions','representation'] as $key) if (isset($options[$key])) $result[$key]=mb_substr((string)$options[$key],0,200);
        return http_build_query($result);
    }

    private static function field(string $name,string $label,string $value,bool $disabled=false,bool $required=false): string
    {
        $id='confirmed-'.$name;
        $attrs=' name="'.self::e($name).'" id="'.self::e($id).'"'.($disabled?' disabled':'').($required?' required':'');
        $attrs.=match($name){'evidence'=>' minlength="10" maxlength="2000"','designation'=>' maxlength="200"','floor'=>' maxlength="50"','area'=>' inputmode="decimal"',default=>''};
        $control=mb_strlen($value)>85 || str_contains($value,"\n")
            ?'<textarea class="field-textarea" rows="2"'.$attrs.'>'.self::e($value).'</textarea>'
            :'<input class="field-input" value="'.self::e($value).'"'.$attrs.'>';
        return '<div class="field"><label class="field-label" for="'.$id.'">'.self::e($label).'</label>'.$control.'</div>';
    }

    private static function fragment(array $raw,array $row,bool $editable,array $input=[],string $error=''): string
    {
        $title=$row['stable_id'].' · '.$row['designation'];
        $html='<div data-editor-fragment data-editor-title="'.self::e($title).'">';
        if ($editable) {
            $html.='<form method="post" id="confirmed-premise-form" action="'.self::e($_SERVER['REQUEST_URI']??self::URL).'" class="registry-ui-modal-grid registry-ui-modal-grid--reading"><input type="hidden" name="_csrf" value="'.self::e(Http::csrf()).'"><input type="hidden" name="expected" value="'.self::e($input['expected']??ConfirmedPremises::fingerprint($raw)).'">';
            if ($error!=='') $html.='<span class="field-msg is-error" role="alert">'.self::e($error).'</span>';
            foreach (ConfirmedPremises::FIELDS as $index=>$field) {
                $mutable=in_array($field,ConfirmedPremises::EDITABLE,true);
                $value=$mutable && array_key_exists($field,$input)?(string)$input[$field]:self::value($row,$field);
                $html.=self::field($field,ConfirmedPremises::LABELS[$index],$value,!$mutable,$field==='designation');
            }
            $html.=self::field('evidence','Основание изменения',(string)($input['evidence']??''),false,true).'<span class="field-msg">Укажите документ или пояснение к исправлению.</span></form><div class="modal-foot-actions" data-editor-actions><button type="button" class="btn btn-secondary" data-editor-close>Отмена</button><button type="submit" class="btn btn-primary" form="confirmed-premise-form">Сохранить</button></div>';
        } else {
            $html.='<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">';
            foreach (ConfirmedPremises::FIELDS as $index=>$field) {
                $html.=self::field($field,ConfirmedPremises::LABELS[$index],self::value($row,$field),true);
            }
            $html.='</div><div class="modal-foot-actions" data-editor-actions><button type="button" class="btn btn-secondary" data-editor-close>Закрыть</button></div>';
        }
        return $html.'</div>';
    }

    public static function index(): string
    {
        $options=$_GET;
        $rows=ConfirmedPremises::catalogue($options);
        $historical=($options['representation']??'')==='history';
        $position=(string)($options['position']??'');
        if ($position!=='') {
            if (!$rows) throw new RuntimeException('Позиция не найдена.',404);
            $row=$rows[0];$raw=ConfirmedPremises::record($row['stable_id']);
            $editable=!$historical && ConfirmedPremises::editable();$error='';
            if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
                Http::checkCsrf();
                if (!$editable) throw new RuntimeException('Недостаточно прав для изменения помещения.',403);
                try {
                    ConfirmedPremises::save($raw['stable_id'],$_POST);
                    Http::redirect(self::URL.'?'.self::returnQuery($options));
                } catch (RuntimeException $exception) {
                    if (!in_array($exception->getCode(),[409,422],true)) throw $exception;
                    $error=$exception->getMessage();
                }
            }
            $fragment=self::fragment($raw,$row,$editable,$error!==''?$_POST:[],$error);
            if (($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='ERP-Fetch') return $fragment;
            $body=View::heading('Помещения').'<section class="table-card registry-ui-card"><section class="panel-section"><a class="btn btn-toolbar" href="'.self::URL.'?'.self::e(self::returnQuery($options)).'">Вернуться к перечню</a>'.$fragment.'</section></section>';
            return View::page('Помещения',$body,'',['source-premises.js']);
        }
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') throw new RuntimeException('Выберите позицию для изменения.',403);
        if (($options['export']??'')==='xlsx') {
            $bytes=PremisesExport::xlsx($rows);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="premises-confirmed.xlsx"');
            header('Content-Length: '.strlen($bytes));
            echo $bytes;
            exit;
        }
        $tab=in_array($options['tab']??'', ['rental','parking','technical'],true)?$options['tab']:'all';
        $query=mb_substr(trim((string)($options['q']??'')),0,200);
        $question=($options['questions']??'')==='yes';
        $export=self::URL.'?'.self::returnQuery($options).'&export=xlsx';
        $body=View::heading('Помещения','','document','<a class="btn btn-primary" data-premises-export href="'.self::e($export).'">Выгрузить в Excel</a>');
        $registry='premises-confirmed'.($historical?'-history':($tab==='all'?'':'-'.$tab));
        $body.='<div data-registry-view data-registry-id="'.$registry.'" data-registry-server-filter data-snapshot-render="all" data-snapshot-reset="filters"><form method="get" class="registry-ui-tabs" role="tablist" aria-label="Объекты" data-snapshot-tabs data-snapshot-default="all"><input type="hidden" name="q" value="'.self::e($query).'"><input type="hidden" name="questions" value="'.($question?'yes':'').'">';
        foreach (['all'=>'Все объекты','rental'=>'Аренда','parking'=>'Машиноместа','technical'=>'Технические помещения'] as $key=>$label) {
            $body.='<button type="submit" role="tab" name="tab" value="'.$key.'" data-linked-tab="'.$key.'" class="'.($tab===$key?'is-active':'').'" aria-selected="'.($tab===$key?'true':'false').'">'.$label.'</button>';
        }
        $total='0.000';$unknown=0;
        foreach ($rows as $row) {if ($row['area']===null) $unknown++;else $total=bcadd($total,$row['area'],3);}
        $body.='</form><section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll"><div class="table-toolbar registry-ui-toolbar"><div class="registry-ui-checkboxes"><span class="found-label">Найдено: <b data-result-count>'.count($rows).'</b></span><span class="found-label" data-premises-area aria-live="polite"><span data-area-known>Известная площадь: '.self::area($total).' м²</span><span class="field-msg is-error" data-area-unknown'.($unknown?'':' hidden').'>'.($unknown?' · не установлена у '.$unknown:'').'</span></span><span class="filter-sep" aria-hidden="true"></span><label class="registry-ui-checkbox"><input type="checkbox" name="questions" value="yes" form="premises-search" data-registry-field="questions" data-registry-default="false"'.($question?' checked':'').'>Показать несостыковки</label></div><form method="get" class="toolbar-right" id="premises-search" data-search-form><input type="hidden" name="tab" value="'.$tab.'">'.($historical?'<input type="hidden" name="representation" value="history">':'').'<button class="registry-ui-reset" type="button" data-registry-reset'.($query===''&&!$question?' hidden':'').'>Сбросить фильтр</button><div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" name="q" data-live-search data-registry-field="query" data-registry-default="" value="'.self::e($query).'" placeholder="Поиск по всему реестру" aria-label="Поиск" autocomplete="off"></div></form></div><div class="table-scroll" role="region" aria-label="Согласованные помещения" tabindex="0"><table class="table registry-ui-table registry-ui-data"><thead><tr>';
        foreach (['Обозначение','Этаж','Вид','Площадь, м²','Кадастровый номер','Есть вопросы'] as $index=>$label) $body.='<th'.($index===3?' class="registry-ui-money"':'').'>'.$label.'</th>';
        $body.='</tr></thead><tbody>';
        foreach ($rows as $row) {
            $snapshot=[];
            foreach (ConfirmedPremises::FIELDS as $field) $snapshot[$field]=self::value($row,$field);
            $snapshot+=['area_raw'=>$row['area'],'tab'=>$row['kind']==='Техническое помещение'?'technical':($row['display_kind']==='Машино-место'?'parking':'rental'),'composition'=>$row['composition'],'ownership'=>$row['owner']['ownership']??'','questions'=>$row['has_questions']?'yes':'no'];
            $url=self::URL.'?'.self::returnQuery($options).'&position='.rawurlencode($row['stable_id']);
            $body.='<tr data-record-key="'.self::e($row['stable_id']).'" data-record-url="'.self::e($url).'" tabindex="0" data-snapshot-values="'.self::e(json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'">';
            $disputed=json_decode($row['disputed_columns'],true,512,JSON_THROW_ON_ERROR);
            foreach ([3,2,1,4,6,12] as $index=>$column) {
                $value=self::value($row,ConfirmedPremises::FIELDS[$column]);
                $content=in_array($column,$disputed,true)?'<span class="registry-ui-status registry-ui-error">'.self::e($value?:'—').'</span>':($index===0?'<span class="registry-ui-name">'.self::e($value?:'—').'</span>':self::e($value?:'—'));
                $body.='<td'.($index===3?' class="registry-ui-money"':'').' title="'.self::e($value).'">'.$content.'</td>';
            }
            $body.='</tr>';
        }
        if (!$rows) $body.='<tr><td colspan="6">Позиции не найдены</td></tr>';
        $body.='</tbody></table></div><div data-linked-pagination></div></section></div>';
        return View::page('Помещения',$body.PremiseIssueController::details(PremiseIssues::state()),'',['source-premises.js']);
    }
}
