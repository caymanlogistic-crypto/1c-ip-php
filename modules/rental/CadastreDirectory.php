<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy,View};
use RuntimeException;

final class CadastreDirectory
{
    private static function editable(): bool
    {
        $actor=Auth::user();
        return in_array($actor['role'],['admin','manager'],true) && Auth::can('rental.write') && PagePolicy::level('rental.cadastre',$actor)>=2;
    }

    private static function record(int $id,int $level=1): array
    {
        Access::pages(['cadastre'],$level);
        return Controller::query('SELECT * FROM cadastre WHERE id=?',[$id])[0] ?? throw new RuntimeException('Кадастровый объект не найден.',404);
    }

    public static function page(): string
    {
        Access::pages(['cadastre']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $type=isset($_GET['reset'])?'all':(string)($_GET['type'] ?? 'all');
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','available','unavailable'],true)) $flag='all';
        $list=[];
        foreach (Controller::query('SELECT * FROM cadastre ORDER BY id') as $row) {
            if (!DirectoryScope::realCadastre($row) || $type!=='all' && $row['type']!==$type) continue;
            $status=DirectoryScope::cadastreAvailable($row)?'Доступен для сдачи':'Недоступен для сдачи';
            $search=implode(' ',[$row['number'],$row['type'],$row['floor'],$row['area'],DirectoryUi::area($row['area']),$status]);
            if ($query!=='' && !str_contains(mb_strtolower($search),mb_strtolower($query))) continue;
            $list[]=$row;
        }
        $available=count(array_filter($list,fn($row)=>DirectoryScope::cadastreAvailable($row)));
        $tabs=['all'=>['Все объекты',count($list)],'available'=>['Доступные для сдачи',$available],'unavailable'=>['Недоступные для сдачи',count($list)-$available]];
        $rows=[]; $area='0'; $unknown=0;
        foreach ($list as $row) {
            $rentable=DirectoryScope::cadastreAvailable($row);
            if ($flag==='available' && !$rentable || $flag==='unavailable' && $rentable) continue;
            if ($row['area']===null) $unknown++; else $area=bcadd($area,$row['area'],4);
            $status=View::e($rentable?'Доступен для сдачи':'Недоступен для сдачи');
            $rows[]=['_attrs'=>'data-rental-url="/rental/cadastre-card?id='.(int)$row['id'].'" tabindex="0"',
                View::e($row['number']),View::e($row['type']),View::e($row['floor']),View::e(DirectoryUi::area($row['area'])),
                $rentable?$status:'<strong class="registry-ui-error">'.$status.'</strong>'];
        }
        $controls='<form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'.View::e($flag).'">'
            .'<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query==='' && $type==='all'?' hidden':'').'>Сбросить фильтр</button>'
            .'<select class="toolbar-select" id="cadastre-type-filter" name="type">';
        foreach (['all'=>'Все типы','Офис'=>'Офис','Машиноместо'=>'Машиноместо','Коридор'=>'Коридор','Техническое помещение'=>'Техническое помещение'] as $key=>$label) {
            $controls.='<option value="'.View::e($key).'"'.($type===$key?' selected':'').'>'.View::e($label).'</option>';
        }
        $controls.='</select>'.DirectoryUi::search($query).'</form>';
        $summary=' · Площадь: <b>'.DirectoryUi::area($area).'</b> м²'.($unknown?' · Не указана у '.$unknown:'');
        $body=Controller::heading('Кадастровые объекты','Кадастровая площадь и доступность для сдачи.',self::editable()?RentalUI::button('Добавить объект','/rental/edit-cadastre',true):'')
            .Controller::tabs($tabs,$flag,true,['type'=>$type])
            .DirectoryUi::registry('rental-cadastre',['Кадастровый номер','Тип','Этаж','Площадь, м²','Доступность'],$rows,['flex','text','short','money','text'],$controls,$summary);
        return View::page('Кадастровые объекты',$body,'cadastre');
    }

    public static function card(): string
    {
        $row=self::record((int)($_GET['id'] ?? 0));
        $allocation=Controller::query('SELECT SUM(area) area FROM rental_objects WHERE cadastre_id=?',[$row['id']])[0]['area'] ?? '0';
        $remaining=$row['area']===null?'Площадь не указана':DirectoryUi::area(bcsub($row['area'],$allocation,4));
        $body=DirectoryUi::pairs([
            ['Объект',$row['number']],['Тип',$row['type']],['Этаж',$row['floor']],['Кадастровая площадь, м²',DirectoryUi::area($row['area'])],
            ['Доступность',DirectoryScope::cadastreAvailable($row)?'Доступен для сдачи':'Недоступен для сдачи'],
            ['Выделено под объекты аренды, м²',DirectoryUi::area($allocation)],['Осталось выделить, м²',$remaining],
        ]);
        return RentalUI::modal('Кадастровый объект '.$row['number'],$body,self::editable()?RentalUI::button('Редактировать','/rental/edit-cadastre?id='.$row['id']):'','cadastre');
    }

    public static function editor(): string
    {
        Access::pages(['cadastre'],2); Auth::requirePermission('rental.write'); Service::roles(['manager']);
        $id=(int)($_GET['id'] ?? 0); $row=$id?self::record($id,2):[];
        $types=['Офис','Машиноместо','Коридор','Техническое помещение'];
        if (!empty($row['type']) && !in_array($row['type'],$types,true)) array_unshift($types,$row['type']);
        $body='<div data-rental-cadastre-editor'.(!$row?' data-rental-new-cadastre':'').'>'
            .DirectoryUi::input('number','Кадастровый номер',$row['number'] ?? '','text',true)
            .DirectoryUi::select('type','Тип',array_combine($types,$types),$row['type'] ?? 'Офис')
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .DirectoryUi::input('floor','Этаж',$row['floor'] ?? '','text',true)
            .DirectoryUi::input('area','Кадастровая площадь, м²',$row['area'] ?? '','number',true).'</div>'
            .DirectoryUi::select('rentable','Доступность для сдачи',['1'=>'Доступен для сдачи','0'=>'Недоступен для сдачи'],$row?(int)DirectoryScope::cadastreAvailable($row):1).'</div>';
        [$form,$actions]=DirectoryUi::form('cadastre.save',$row,$body);
        return RentalUI::modal($row?'Редактировать объект':'Добавить кадастровый объект',$form,$actions,'cadastre');
    }
}
