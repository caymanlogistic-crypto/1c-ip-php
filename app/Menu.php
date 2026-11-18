<?php
declare(strict_types=1);
namespace App;
final class Menu {
    public static function attentionLabels(string $page=''):array {
        return $page==='objects'
            ? ['Простой от 30 дней','Простой 14–29 дней','Недавно сданы','Брони']
            : ['Ошибки / срочно','Требует внимания','Положительные события','Ожидание / наблюдение'];
    }
    public const GROUPS=['Договоры'=>['contracts'=>'Договоры в работе','contracts-active'=>'Действующие договоры','contracts-completed'=>'Завершённые договоры'],'Согласования'=>['approvals'=>'Согласование документов','templates'=>'Шаблоны документов'],'Счета и сверка'=>['invoices'=>'Оплаты контрагентов','invoice-list'=>'Счета и оплаты','reconciliation'=>'Финальная сверка'],'Справочники'=>['parties'=>'Контрагенты','organization'=>'Наша организация','cadastre'=>'Кадастровые объекты','objects'=>'Объекты аренды'],'Контроль'=>['operations'=>'Операции и отправки','errors'=>'Контроль ошибок','journal'=>'Журнал','summary'=>'Обзор документов']];
    public static function allowed(string $key,array $u):bool {if($u['role']==='admin')return true;if(!empty($u['grants_configured'])&&(empty($u['workflow_role'])||PagePolicy::level('rental.'.$key,$u)<1))return false;if(in_array($key,['errors','journal','summary','thresholds'],true))return $u['role']==='director';if($key==='contracts')return $u['role']==='manager';if($key==='templates')return in_array($u['role'],['lawyer','director'],true);return true;}
    public static function counts(array $u,?\Modules\Rental\ObjectReadModel $objects=null):array {
        if(!Auth::can('rental.read'))return ['pages'=>[],'folders'=>[]];
        $allowed=[];foreach(self::GROUPS as $items)foreach($items as $key=>$label)if(self::allowed($key,$u))$allowed[]=$key;
        $configured=!empty($u['grants_configured']);if($configured&&!$allowed)return ['pages'=>[],'folders'=>[]];
        if (in_array('objects',$allowed,true)) $objects??=new \Modules\Rental\ObjectReadModel(date('Y-m-d'));
        $tasks=\Modules\Rental\Indicators::tasks(null,null,$configured?$allowed:null,$objects);
        $raw=\Modules\Rental\Indicators::counts($u,$configured?$allowed:null,$tasks,$objects);
        if(in_array('invoice-list',$allowed,true))$raw['invoice-list']=\Modules\Rental\InvoiceFacts::menuCounts(DB::pdo());
        if(in_array('invoices',$allowed,true))$raw['invoices']=\Modules\Rental\InvoiceFacts::menuCounts(DB::pdo(),true);
        $pages=[];$folders=[];
        foreach(array_values(self::GROUPS)as $i=>$items){$sum=[0,0,0,0];$visible=false;foreach($items as $key=>$label){if(!in_array($key,$allowed,true))continue;$visible=true;$counts=array_map(static fn($n)=>max(0,(int)$n),$raw[$key]??[0,0,0,0]);$pages[$key]=$counts;foreach($counts as $tone=>$n)$sum[$tone]+=$n;}
            if(!$configured&&$i===0&&$u['role']==='director'){$sum[2]+=max(0,(int)($raw['contracts'][2]??0));$sum[3]+=max(0,(int)($raw['contracts'][3]??0));}
            if($visible||!$configured)$folders['menu-folder-'.$i]=$sum;
        }return ['pages'=>$pages,'folders'=>$folders,'notices'=>\Modules\Rental\WorkflowNotices::projection(DB::pdo(),$u,$tasks,$allowed)];
    }
    public static function badges(array $counts,bool $sub=false,string $page=''):string {$html='';$names=self::attentionLabels($page);foreach($counts as $i=>$n){if(!$n)continue;$label=$names[$i].': '.(int)$n;$html.='<span class="'.($sub?'sub-count':'nav-count').' '.['is-alert','is-warning','is-success','is-info'][$i].'" data-attention="'.['red','yellow','green','blue'][$i].'" aria-label="'.View::e($label).'" title="'.View::e($label).'">'.(int)$n.'</span>';}return $html?'<span class="nav-counts">'.$html.'</span>':'';}
}
