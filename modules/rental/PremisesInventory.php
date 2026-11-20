<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,DB,PagePolicy,View};
use PDO;
use RuntimeException;

final class PremisesInventory
{
    public const SAVE='object.inventory-save';
    public const MOVE='object.inventory-move';
    public const PHOTO='inventory-photo';
    private const NS='rental_inventory_item';

    public static function object(int $id,int $level=1): array
    {
        Access::pages(['objects'],$level);
        $object=Service::get(DB::pdo(),'rental_objects',$id);
        if (WorkspaceReset::hidden($object)) throw new RuntimeException('Объект не найден.',404);
        if (in_array($object['type'],TemplateAssets::PARKING_TYPES,true)) {
            throw new RuntimeException('Для машино-места опись имущества не предусмотрена.',422);
        }
        return $object;
    }

    public static function canEdit(): bool
    {
        $actor=Auth::user();
        return in_array($actor['role'],['manager','admin'],true) && Auth::can('rental.write')
            && PagePolicy::level('rental.objects',$actor)>=2;
    }

    public static function items(PDO $db,int $objectId): array
    {
        $q=$db->prepare("SELECT source_key,fact_json FROM rental_facts WHERE namespace=? AND operative=1
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.object_id')) AS UNSIGNED)=? ORDER BY id");
        $q->execute([self::NS,$objectId]);
        $items=[];
        foreach ($q->fetchAll() as $row) $items[$row['source_key']]=Importer::decodeExact($row['fact_json']);
        return $items;
    }

    public static function editableItem(PDO $db,int $objectId,array $input): array
    {
        $key=$input['item_id']??'';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D',$key)) throw new RuntimeException('Позиция описи не найдена.',404);
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::NS,$key]);
        $raw=$q->fetchColumn();
        $item=$raw===false?null:Importer::decodeExact($raw);
        if (!$item || $item['object_id']!==$objectId || (int)($input['item_version']??0)!==$item['version']) {
            throw new RuntimeException('Позиция описи изменена. Откройте её заново.',409);
        }
        return $item;
    }

    private static function write(PDO $db,string $key,array $item): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([self::NS,$key,Importer::json($item)]);
    }

    public static function execute(PDO $db,string $action,array $input): array
    {
        Service::roles(['manager']);
        $id=(int)($input['id']??0);
        $object=self::object($id,2);
        Service::version($object,$input);
        $key=$input['item_id']??'';
        $before=$key===''?null:self::editableItem($db,$id,$input);
        if ($action===self::MOVE) {
            if ($before===null || $object['type']!=='Офис') throw new RuntimeException('Выберите позицию описи офиса.',422);
            $destination=self::object((int)($input['destination_id']??0),2);
            if ((int)$destination['id']===$id || $destination['type']!=='Офис' || !$destination['active']) {
                throw new RuntimeException('Выберите другой действующий офис.',422);
            }
            $item=$before;
            $item['object_id']=(int)$destination['id'];
            $item['moved_from']=$id;
            $item['moved_on']=date('Y-m-d');
        } else {
            $name=DirectoryDetails::text($input,'item_name',200);
            $condition=DirectoryDetails::text($input,'condition',1000);
            $quantity=DirectoryDetails::text($input,'quantity',6);
            if ($name==='' || $condition==='' || !preg_match('/^[1-9][0-9]{0,5}$/D',$quantity)) {
                throw new RuntimeException('Укажите название, состояние и положительное целое количество.',422);
            }
            $value=Service::money(DirectoryDetails::text($input,'unit_value',24));
            $valuedOn=Service::date(DirectoryDetails::text($input,'valued_on',10));
            if ($valuedOn>date('Y-m-d')) throw new RuntimeException('Дата оценки не может быть в будущем.',422);
            $item=array_replace($before??['object_id'=>$id,'photos'=>[]],[
                'name'=>$name,'condition'=>$condition,'quantity'=>(int)$quantity,'unit_value'=>$value,'valued_on'=>$valuedOn,
            ]);
        }
        if ($key==='') $key=bin2hex(random_bytes(16));
        $item['version']=($before['version']??0)+1;
        self::write($db,$key,$item);
        Audit::record($action,'object',$id,$before,['item_id'=>$key]+$item);
        return ['id'=>$item['object_id'],'message'=>$action===self::MOVE?'Имущество перемещено.':'Позиция описи сохранена.'];
    }

    public static function addPhoto(PDO $db,string $key,array $item,string $fileId,string $sha): void
    {
        $item['photos'][]=['id'=>$fileId,'sha256'=>$sha];
        $item['version']++;
        self::write($db,$key,$item);
    }

    public static function table(array $items,int $contractId=0): string
    {
        if (!$items) return '<p class="registry-ui-secondary">Опись пуста.</p>';
        $rows=[];
        foreach ($items as $item) {
            $photos=[];
            foreach ($item['photos'] as $index=>$photo) {
                $photos[]='<a class="btn btn-secondary" href="/rental/download?id='.View::e($photo['id'])
                    .($contractId?'&amp;contract_id='.$contractId:'').'">Фото '.($index+1).'</a>';
            }
            $rows[]=[View::e($item['name']),$item['quantity'],View::e($item['condition']),
                View::e(Documents::amountText($item['unit_value']).' ₽'),View::e(Documents::dateText($item['valued_on'])),implode(' ',$photos)];
        }
        return Controller::table(['Имущество','Количество','Состояние','Стоимость за единицу','Дата оценки','Фото'],
            $rows,['text','short','text','money','date','text']);
    }
}
