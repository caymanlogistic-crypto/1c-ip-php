<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{DB,Http,View};
use RuntimeException;

final class PremisesInventoryCard
{
    public static function card(): string
    {
        $id=(int)($_GET['id']??0);
        $object=PremisesInventory::object($id);
        $items=PremisesInventory::items(DB::pdo(),$id);
        $key=(string)($_GET['item']??'');
        $route='/rental/object-inventory?id='.$id;
        if ($key!=='') {
            if (!PremisesInventory::canEdit()) throw new RuntimeException('Редактирование описи недоступно.',403);
            $item=$key==='new'?null:($items[$key]??throw new RuntimeException('Позиция описи перемещена или недоступна.',404));
            return self::editor($object,$key,$item);
        }

        $body=RentalUI::notice().PremisesInventory::table($items);
        $actions=RentalUI::button('К объекту','/rental/object?id='.$id);
        if (PremisesInventory::canEdit()) {
            foreach ($items as $itemId=>$item) {
                $body.='<div class="demo-row">'.RentalUI::button('Открыть позицию «'.$item['name'].'»',$route.'&item='.$itemId).'</div>';
            }
            $actions.=RentalUI::button('Добавить позицию',$route.'&item=new',true);
        }
        return RentalUI::modal('Опись имущества · '.$object['name'],$body,$actions,'objects',true);
    }

    private static function editor(array $object,string $key,?array $item): string
    {
        $id=(int)$object['id'];$version=(int)$object['version'];
        $itemFields=$item?PremisesActsCard::hidden('item_id',$key).PremisesActsCard::hidden('item_version',$item['version']):'';
        $fields=$itemFields.Controller::input('item_name','Название',$item['name']??'')
            .Controller::input('quantity','Количество',$item['quantity']??'','number')
            .Controller::input('condition','Состояние',$item['condition']??'')
            .Controller::input('unit_value','Стоимость за единицу, ₽',$item['unit_value']??'')
            .Controller::input('valued_on','Дата оценки',$item['valued_on']??date('Y-m-d'),'date');
        $body=Controller::form(PremisesInventory::SAVE,$id,$version,$fields,'Сохранить позицию');
        if ($item) {
            $body.=PremisesInventory::table([$item]);
            $body.='<form class="registry-ui-modal-grid" method="post" action="/rental/upload" enctype="multipart/form-data">'
                .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('id',$id).PremisesActsCard::hidden('version',$version)
                .PremisesActsCard::hidden('kind',PremisesInventory::PHOTO).PremisesActsCard::hidden('entity_type','object').$itemFields
                .'<label class="field"><span class="field-label">Фотография, PNG или JPEG до 5 МБ</span>'
                .'<input type="file" name="file" accept=".png,.jpg,.jpeg" required></label>'
                .'<button class="btn btn-secondary" type="submit">Добавить фото</button></form>';
            if ($object['type']==='Офис') {
                $destinations=[''=>'Выберите офис'];
                foreach (Controller::query("SELECT id,name,source_json FROM rental_objects WHERE active=1 AND type='Офис' AND id<>? ORDER BY name,id",[$id]) as $row) {
                    if (!WorkspaceReset::hidden($row)) $destinations[(int)$row['id']]=$row['name'];
                }
                if (count($destinations)>1) $body.=Controller::form(PremisesInventory::MOVE,$id,$version,
                    $itemFields.DirectoryUi::select('destination_id','Офис назначения',$destinations,'',true),'Переместить');
            }
        }
        return RentalUI::modal($item?$item['name']:'Новая позиция описи',
            RentalUI::pairs([['Текущее место',$object['name']]]).$body,'','objects');
    }
}
