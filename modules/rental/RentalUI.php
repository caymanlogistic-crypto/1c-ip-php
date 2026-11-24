<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\View;
final class RentalUI
{
    public static function pairs(array $rows): string
    {
        $h='<div class="demo-value-pair">';
        foreach ($rows as [$label,$value])
            $h.='<span class="registry-ui-secondary">'.View::e($label).'</span><span class="registry-ui-modal-text">'.View::e($value).'</span>';
        return $h.'</div>';
    }

    public static function button(string $label,string $url,bool $primary=false,bool $danger=false): string
    {
        return '<button type="button" class="btn '.($danger?'btn-danger':($primary?'btn-primary':'btn-secondary')).'" data-rental-open="'.View::e($url).'">'.View::e($label).'</button>';
    }

    public static function alert(string $title,string $sub='',string $tone='info',bool $filled=false): string
    {
        return '<div class="form-alert alert-'.$tone.($filled?' form-alert--filled':'').'" role="'.($tone==='error'?'alert':'status').'"><span class="alert-mark" aria-hidden="true">'.($tone==='error'?'×':($tone==='warning'?'!':'i')).'</span><div class="alert-body"><div class="alert-body-title">'.View::e($title).'</div>'.($sub?'<div class="alert-body-sub">'.View::e($sub).'</div>':'').'</div></div>';
    }

    public static function notice(): string
    {
        if (!isset($_SESSION['rental_notice'])) return '';
        $message=(string)$_SESSION['rental_notice'];
        unset($_SESSION['rental_notice']);
        return self::alert($message);
    }

    public static function reviewTermsTable(array $terms,?string $total): string
    {
        $rows=[];
        foreach ($terms as $term) {
            $rows[]=[View::e($term['name']),View::e($term['type']??''),View::e($term['area']===null
                ?'—':DirectoryUi::area((string)$term['area']).' м²'),Controller::money($term['amount'])];
        }
        if (count($rows)!==1 && $total!==null) {
            $rows[]=['<strong>Итого по договору</strong>','—','—','<strong>'.Controller::money($total).'</strong>'];
        }
        return $rows?'<div class="registry-ui-review-terms">'
            .Controller::table(['Объект','Тип','Площадь','Аренда за месяц с НДС'],$rows,['text','text','short','money']).'</div>':'';
    }

    public static function reviewSection(string $title,string $body,string $state='',bool $done=false,string $statusUrl=''): string
    {
        $status=$state===''?'':'<span class="registry-ui-review-state"'.($done?' data-complete':'').'>'.View::e($state).'</span>';
        if ($statusUrl!=='')
            $status='<a class="registry-ui-review-state" href="'.View::e($statusUrl).'">'.View::e($state).'</a>';
        return '<section class="registry-ui-form-section registry-ui-review-section" data-form-disclosure-scope>'
            .'<div class="registry-ui-form-section-heading"><h3 class="modal-title">'.View::e($title).'</h3>'.$status.'</div>'
            .$body.'</section>';
    }

    public static function modal(string $title,string $body,string $actions='',string $active='contracts',bool $wide=false,string $leadingActions='',string $closeLabel='Закрыть',bool $extractForms=true,bool $canvas=false,bool $tableOnly=false, string $design="",bool $tall=false,bool $confirmation=false):string
    {
        if ($actions==='' && $extractForms)
            $body=preg_replace_callback('~<form\b[^>]*>.*?</form>~s',function($match) use(&$actions) {
                $html=$match[0];
                $id='rental-form-'.bin2hex(random_bytes(6));
                $html=preg_replace('/<form\b/','<form id="'.$id.'"',$html,1);
                $html=preg_replace_callback('~<button\b[^>]*>.*?</button>~s',function($m) use(&$actions,$id) {
                    if (str_contains($m[0],'type="button"')) return $m[0];
                    $actions.=preg_replace('/<button\b/','<button form="'.$id.'"',$m[0],1);
                    return '';
                },$html);
                return $html;
            },$body);
        $fetch=($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='ERP-Fetch';
        $titleId='rental-modal-title-'.bin2hex(random_bytes(6));
        $h='<dialog data-rental-dialog aria-labelledby="'.$titleId.'" data-rental-return="/rental/'.View::e($active).'"'
            .(!$fetch?' data-rental-autopen':'').($design!==''?\App\InterfaceDesign::attributes($design):'').' class="modal registry-ui-modal '
            .($confirmation?'registry-ui-modal--confirmation':($wide?'registry-ui-modal--probe':'registry-ui-modal--reading')).($canvas?' registry-ui-modal--form-canvas':'').($tableOnly?' registry-ui-modal--table':'')
            .($tall?' registry-ui-modal--tall':'')
            .' demo-dialog"><div class="registry-ui-modal-form"><div class="modal-head"><h2 class="modal-title" id="'.$titleId.'">'
            .View::e($title)
            .'</h2><button class="modal-close" type="button" data-rental-close aria-label="Закрыть">×</button></div><div class="modal-body"><div class="registry-ui-modal-grid"><div id="form-error" hidden></div>'
            .$body.'</div></div><div class="modal-foot">'.$leadingActions
            .'<div class="modal-foot-actions"><button class="btn btn-secondary" type="button" data-rental-close data-rental-dismiss'.($design!==''?' data-design-key="close"':'').'>'
            .View::e($closeLabel).'</button>'.$actions.'</div></div></div></dialog>';
        return $fetch?$h:View::page($title,$h,$active);
    }
}
