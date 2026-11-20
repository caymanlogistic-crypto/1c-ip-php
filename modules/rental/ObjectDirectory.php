<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy,View};
use RuntimeException;

final class ObjectDirectory
{
    private static function editable(): bool
    {
        $actor=Auth::user();
        return in_array($actor['role'],['admin','manager'],true) && Auth::can('rental.write') && PagePolicy::level('rental.objects',$actor)>=2;
    }

    private static function record(int $id,int $level=1): array
    {
        Access::pages(['objects'],$level);
        $row=Controller::query('SELECT o.*,c.number cadastral,c.area parent_area,c.type parent_type,c.rentable parent_rentable,c.source_json parent_source '
            .'FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id=?',[$id])[0] ?? throw new RuntimeException('Объект не найден.',404);
        if(WorkspaceReset::hidden($row))throw new RuntimeException('Объект не найден.',404);
        return $row;
    }

    private static function parent(array $row): array
    {
        return ['number'=>$row['cadastral'],'type'=>$row['parent_type'],'area'=>$row['parent_area'],'rentable'=>$row['parent_rentable'],'source_json'=>$row['parent_source']];
    }

    private static function availability(): array
    {
        $map=[];
        foreach (Availability::items(date('Y-m-d'),date('Y-m-d')) as $item) $map[$item['id']]=$item;
        return $map;
    }

    public static function page(): string
    {
        Access::pages(['objects']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $floor=isset($_GET['reset'])?'all':(string)($_GET['floor'] ?? 'all');
        $type=isset($_GET['reset'])?'all':(string)($_GET['type'] ?? 'all');
        if (!in_array($type,['all','office','parking'],true)) $type='all';
        $show=!isset($_GET['reset']) && !empty($_GET['inactive']);
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','office','parking','free','work','inactive'],true)) $flag='all';
        $read=new ObjectReadModel(date('Y-m-d'));
        $availability=$read->availability;$list=[];$floors=[];
        foreach ($read->objects as $object) {
            if (!DirectoryScope::realObject($object,self::parent($object))) continue;
            if ($object['floor']!==null && trim($object['floor'])!=='') $floors[(string)$object['floor']]=(string)$object['floor'];
            if ($floor!=='all' && $floor!==(string)$object['floor']) continue;
            if ($type!=='all' && $object['type']!==($type==='office'?'Офис':'Машиноместо')) continue;
            $facts=ObjectFacts::project($object,self::parent($object),$availability[$object['id']] ?? null,$read->today,$read);
            $search=implode(' ',[$object['name'],$object['type'],$object['floor'],$object['area'],DirectoryUi::area($object['area']),$facts['status'],
                $facts['lease']['number'] ?? '',$facts['lease']['tenant'] ?? '',$facts['lease']['end_date'] ?? '',$facts['deadline'],
                $facts['days']===null?'':ObjectFacts::days($facts['days']),$facts['previous'],$facts['booking']['client_name'] ?? '',$facts['booking']['comment'] ?? '']);
            if ($query!=='' && !str_contains(mb_strtolower($search),mb_strtolower($query))) continue;
            $list[]=['object'=>$object,'facts'=>$facts];
        }
        $select=static function(array $item,string $key) use($show):bool {
            $object=$item['object'];$facts=$item['facts'];
            return match($key) {
                'free'=>$facts['eligible'] && $facts['free'],
                'work'=>in_array($facts['severity'],['green','blue'],true),
                'inactive'=>!$object['active'],
                default=>($show || $object['active']) && ($key==='all' || $key==='office' && $object['type']==='Офис' || $key==='parking' && $object['type']==='Машиноместо'),
            };
        };
        $tabs=[];
        foreach (['all'=>'Все объекты','office'=>'Офисы','parking'=>'Машиноместа','free'=>'Свободные площади','work'=>'Текущая работа','inactive'=>'Неактивные'] as $key=>$label) {
            $tabs[$key]=[$label,count(array_filter($list,fn($item)=>$select($item,$key)))];
            if (in_array($key,['free','work'],true)) {
                $counts=[0,0,0,0];
                foreach ($list as $item) if ($select($item,$key)) {
                    $index=array_search($item['facts']['severity'],['red','yellow','green','blue'],true);
                    if ($index!==false) $counts[$index]++;
                }
                $tabs[$key][]=$counts;
            }
        }
        $visible=array_values(array_filter($list,fn($item)=>$select($item,$flag)));
        if ($flag==='free') usort($visible,fn($a,$b)=>($a['facts']['days'] ?? PHP_INT_MAX)<=>($b['facts']['days'] ?? PHP_INT_MAX) ?: strnatcasecmp($a['object']['name'],$b['object']['name']));
        $rows=[];$area='0';$unknown=0;
        foreach ($visible as $item) {
            $object=$item['object'];$facts=$item['facts'];
            if ($object['area']===null) $unknown++;else $area=bcadd($area,$object['area'],4);
            $class=match($facts['severity']) {'red'=>'registry-ui-soft-error','yellow'=>'registry-ui-warning','green'=>'registry-ui-soft-success','blue'=>'registry-ui-soft-info',default=>$object['active']?'':'registry-ui-muted'};
            $status=View::e($facts['status']);
            if ($facts['free']) $status='<strong class="registry-ui-error">'.$status.'</strong>';
            elseif ($facts['lease'] && !str_contains($facts['status'],'акт') && $facts['lease']['status']!=='reconciling') {
                $lease=$facts['lease'];
                $status='<strong>№'.View::e($lease['number']).'</strong>'.($lease['document_date']?' от '.Documents::dateText($lease['document_date']):'').' '.View::e($lease['tenant']);
                if ($facts['document']) {
                    $document=$facts['document'];
                    $status='<strong>ДС'.($document['number']!==null && $document['number']!==''?' №'.View::e($document['number']):'').'</strong>'
                        .($document['document_date']?' от '.Documents::dateText($document['document_date']):'').' к <strong>дог. №'.View::e($lease['number']).'</strong>'
                        .($lease['document_date']?' от '.Documents::dateText($lease['document_date']):'').' '.View::e($lease['tenant']);
                }
            }
            $comment=self::comment($facts);
            $end=$facts['lease']['end_date'] ?? null;
            $date=$end?Documents::dateText($end):'—';
            if ($facts['deadline']) $date='<strong class="registry-ui-error">'.$date.'</strong>';
            $rows[]=['_attrs'=>'class="'.$class.'" data-rental-url="/rental/object?id='.(int)$object['id'].'" tabindex="0"',
                View::e($object['name']),View::e($object['type']),View::e($object['floor']),DirectoryUi::area($object['area']),$status,$date,$comment];
        }
        natsort($floors);
        $inactive=in_array($flag,['all','office','parking'],true)?DirectoryUi::checkbox('inactive','Показать неактивные',$show):'';
        $controls='<input type="hidden" name="flag" value="'.View::e($flag).'">'.$inactive
            .'<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query==='' && !$show && $floor==='all' && $type==='all'?' hidden':'').'>Сбросить фильтр</button>'
            .'<select class="toolbar-select" id="objects-floor-filter" name="floor" aria-label="Этаж"><option value="all">Все этажи</option>';
        foreach ($floors as $value) $controls.='<option value="'.View::e($value).'"'.($floor===$value?' selected':'').'>'.View::e($value).'</option>';
        $controls.='</select><select class="toolbar-select" id="objects-type-filter" name="type" aria-label="Тип объекта">';
        foreach (['all'=>'Показать все','office'=>'Офисы','parking'=>'Машиноместа'] as $value=>$label) $controls.='<option value="'.$value.'"'.($type===$value?' selected':'').'>'.$label.'</option>';
        $controls.='</select>'.DirectoryUi::search($query,true);
        $summary=' · Площадь: <b>'.DirectoryUi::area($area).'</b> м²'.($unknown?' · Не указана у '.$unknown:'');
        $notice='';
        if (isset($_SESSION['rental_notice'])) {
            $notice=RentalUI::alert((string)$_SESSION['rental_notice']);unset($_SESSION['rental_notice']);
        }
        $body=Controller::heading('Объекты аренды','Помещения и машиноместа: занятость, бронирование и контроль простоя',self::editable()?RentalUI::button('Добавить объект','/rental/edit-object',true):'').$notice
            .Controller::tabs($tabs,$flag,false,['floor'=>$floor,'type'=>$type,'inactive'=>(int)$show,'q'=>$query],'objects')
            .DirectoryUi::registry('rental-contracts',[],$rows,[],$controls,$summary,filterForm:true,
                design:new DesignTable('rental.objects',[
                    'name'=>'text','type'=>'text','floor'=>'short','area'=>'money',
                    'occupancy'=>'flex','until'=>'code','comment'=>'flex',
                ],['name','type','occupancy','comment']));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET' && ($_SERVER['HTTP_X_RENTAL_OBJECTS_FRAGMENT'] ?? '')==='1') {
            if (PHP_SAPI!=='cli') header('Cache-Control: no-store');
            return View::workspaceFragment($body);
        }
        return View::page('Объекты аренды',$body,'objects',[],$read);
    }

    private static function comment(array $facts): string
    {
        $comment='—';
        if ($facts['recent']) $comment='<strong class="registry-ui-success">'.ObjectFacts::days($facts['recent']['days']).'</strong><span class="registry-ui-secondary">Новый с '.Documents::dateText($facts['recent']['date']).': '.View::e($facts['lease']['tenant']).'</span>';
        elseif ($facts['severity']==='blue') {
            $comments=[];foreach($facts['bookings'] as $booking)$comments[]=View::e(($booking['client_name'] ?: 'Клиент не указан').' до '.Documents::dateText($booking['until_date']).($booking['comment']?': '.$booking['comment']:''));
            $comment=(count($comments)>1?'<strong>Брони: '.count($comments).'</strong><br>':'').implode('<br>',$comments);
        }
        elseif ($facts['free']) $comment=($facts['idleUnknown']?'<span class="registry-ui-secondary">'.View::e($facts['idleUnknown']).'</span>':'<strong class="registry-ui-error">'.($facts['days']===null?'Указать дату':ObjectFacts::days($facts['days'])).'</strong>').'<span class="registry-ui-secondary">Последний арендатор: '.View::e($facts['previous'] ?: 'Не указан').'</span>';
        if ($facts['deadline']) $comment.='<div><strong class="registry-ui-error">'.View::e($facts['deadline']).'</strong></div><div class="doc-meta">Необходимо подписать допсоглашение о пролонгации или начать процедуру завершения договора</div>';
        return $comment;
    }

    public static function card(): string
    {
        $object=self::record((int)($_GET['id'] ?? 0));
        $availability=self::availability();
        $facts=ObjectFacts::project($object,self::parent($object),$availability[$object['id']] ?? null,date('Y-m-d'));
        $body=DirectoryUi::pairs([
            ['Объект',$object['name']],['Тип',$object['type']],['Этаж',$object['floor']],['Площадь сдачи, м²',DirectoryUi::area($object['area'])],
            ['Кадастровый объект',$object['cadastral']],['Кадастровая площадь, м²',DirectoryUi::area($object['parent_area'])],
            ['Состояние',$facts['status'],$facts['free']],['Дата освобождения',$facts['free']?($object['free_since']?Documents::dateText($object['free_since']):'Не указана'):'—'],
            ['Простой',$facts['free']?($facts['idleUnknown'] ?: ($facts['days']===null?'Указать дату':ObjectFacts::days($facts['days']))):'—',$facts['free'] && !$facts['idleUnknown']],['Последний арендатор',$facts['previous'] ?: '—'],
        ]);
        $actions=self::editable()?RentalUI::button('Редактировать','/rental/edit-object?id='.$object['id']):'';
        if (self::editable() && $facts['eligible'] && PagePolicy::level('rental.contracts',Auth::user())>=2) $actions.=RentalUI::button('Забронировать','/rental/reservations?object_id='.$object['id'],true);
        return RentalUI::modal('Объект аренды '.$object['name'],$body,$actions,'objects');
    }

    public static function editor(): string
    {
        Access::pages(['objects'],2);Auth::requirePermission('rental.write');Service::roles(['manager']);
        $id=(int)($_GET['id'] ?? 0);$object=$id?self::record($id,2):[];
        $parentOptions='<option value="">Выберите кадастровый объект</option>';
        foreach (Controller::query('SELECT * FROM cadastre ORDER BY id') as $parent) {
            if (!DirectoryScope::realCadastre($parent)) continue;
            $parentOptions.='<option value="'.(int)$parent['id'].'" data-floor="'.View::e($parent['floor']).'"'
                .((int)($object['cadastre_id'] ?? 0)===(int)$parent['id']?' selected':'').'>'
                .View::e($parent['number'].' · '.DirectoryUi::area($parent['area']).' м²').'</option>';
        }
        $types=['Офис','Машиноместо','Техническое помещение'];if (!empty($object['type']) && !in_array($object['type'],$types,true)) array_unshift($types,$object['type']);
        $body='<label class="field"><span class="field-label">Исходный кадастровый объект</span>'
            .'<select class="field-select field-input" name="cadastre_id" required>'.$parentOptions.'</select></label>'
            .DirectoryUi::input('name','Наименование объекта аренды',$object['name'] ?? '','text',true)
            .DirectoryUi::select('type','Тип',array_combine($types,$types),$object['type'] ?? 'Офис')
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'.DirectoryUi::input('floor','Этаж',$object['floor'] ?? '','text',true)
            .DirectoryUi::input('area','Площадь сдачи, м²',$object['area'] ?? '','number',true).'</div>'
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .DirectoryUi::select('active','Состояние',['1'=>'Активен','0'=>'Неактивен'],$object['active'] ?? 1)
            .($object?DirectoryUi::input('active_on','Дата изменения состояния',date('Y-m-d'),'date',true):'').'</div>'
            .DirectoryUi::input('free_since','Дата освобождения / начала простоя',$object['free_since'] ?? ($object?'':date('Y-m-d')),'date');
        [$form,$actions]=DirectoryUi::form('object.save',$object,$body);
        $form=str_replace('<form ', '<form class="registry-ui-modal-grid" data-rental-object-editor data-original-active="'.(int)($object['active'] ?? 1).'"'.(!$object?' data-rental-new-object':'').' ', $form);
        return RentalUI::modal($object?'Редактировать объект':'Создать объект аренды',$form,$actions,'objects');
    }
}
