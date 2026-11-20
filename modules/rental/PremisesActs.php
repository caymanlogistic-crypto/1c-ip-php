<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,PagePolicy};
use PDO;
use RuntimeException;

final class PremisesActs
{
    public const COUNT='contract.act-accessories';
    public const CONFIRM='contract.act-confirm';
    public const TITLES=['access'=>'Допуск','transfer'=>'Передача','return'=>'Возврат'];
    public const FILE_KINDS=['access'=>'signed-access-act','transfer'=>'signed-transfer-act'];
    private const NS='rental_premises_act';
    private const COUNTS='rental_lease_accessories';
    private const UPLOAD='rental_premises_act_file';

    public static function subjects(PDO $db,int $contractId): array
    {
        $q=$db->prepare('SELECT o.id,o.name,o.type FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id
            WHERE co.contract_id=? ORDER BY o.id');
        $q->execute([$contractId]);
        $result=[];
        foreach ($q->fetchAll() as $object) $result[(int)$object['id']]=$object;
        return $result;
    }

    public static function parking(array $object): bool
    {
        return in_array($object['type'],TemplateAssets::PARKING_TYPES,true);
    }

    public static function permits(array $object,string $kind): bool
    {
        return isset(self::TITLES[$kind]) && ($kind!=='access' || (!self::parking($object) && !preg_match('/^апартамент/iu',$object['type'])));
    }

    private static function key(int $contractId,int $objectId): string
    {
        return $contractId.':'.$objectId;
    }

    private static function read(PDO $db,string $namespace,string $key): ?array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([$namespace,$key]);
        $raw=$q->fetchColumn();
        return $raw===false?null:Importer::decodeExact($raw);
    }

    private static function store(PDO $db,string $namespace,string $key,array $data): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([$namespace,$key,Importer::json($data)]);
    }

    public static function count(PDO $db,int $contractId,int $objectId): ?array
    {
        return self::read($db,self::COUNTS,self::key($contractId,$objectId));
    }

    public static function recorded(PDO $db,int $contractId,int $objectId,string $kind): ?array
    {
        return self::read($db,self::NS,self::key($contractId,$objectId).':'.$kind);
    }

    public static function history(PDO $db,int $contractId): array
    {
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1 AND source_key LIKE ? ORDER BY id");
        $q->execute([self::NS,$contractId.':%']);
        return array_map(Importer::decodeExact(...),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function includesPhoto(PDO $db,int $contractId,array $file): bool
    {
        foreach (self::history($db,$contractId) as $act) {
            foreach ($act['inventory'] as $item) {
                foreach ($item['photos'] as $photo) {
                    if ($photo['id']===$file['id'] && hash_equals($photo['sha256'],$file['sha256'])) return true;
                }
            }
        }
        return false;
    }

    private static function editableStage(array $contract,string $kind): bool
    {
        $stages=match($kind){
            'access','count'=>['approved','sent','active'],
            'transfer'=>['sent','active'],
            default=>[],
        };
        return in_array($contract['status'],$stages,true);
    }

    public static function canEdit(array $contract,string $kind): bool
    {
        $actor=Auth::user();
        return in_array($actor['role'],['manager','admin'],true) && Auth::can('rental.write')
            && PagePolicy::level('rental.contracts-active',$actor)>=2 && self::editableStage($contract,$kind);
    }

    public static function requireSubject(PDO $db,array $contract,int $objectId,string $kind): array
    {
        $object=self::subjects($db,(int)$contract['id'])[$objectId]??null;
        if (!$object) throw new RuntimeException('Объект не входит в договор.',422);
        if ($kind!=='count' && !self::permits($object,$kind)) throw new RuntimeException('Вид акта не применяется к этому объекту.',422);
        return $object;
    }

    public static function candidate(PDO $db,array $contract,int $objectId,string $kind): ?array
    {
        $q=$db->prepare('SELECT f.* FROM rental_files f JOIN rental_facts p ON p.source_key=f.id AND p.namespace=? AND p.operative=1
            WHERE f.entity_type=\'contract\' AND f.entity_id=? AND f.version=? AND f.kind=?
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(p.fact_json,\'$.object_id\')) AS UNSIGNED)=?
            ORDER BY f.upload_order DESC LIMIT 1');
        $q->execute([self::UPLOAD,$contract['id'],$contract['version'],self::FILE_KINDS[$kind],$objectId]);
        return $q->fetch()?:null;
    }

    public static function checkUpload(PDO $db,array $contract,int $objectId,string $kind): ?array
    {
        PagePolicy::requireCommand(self::CONFIRM);
        self::requireSubject($db,$contract,$objectId,$kind);
        if (!self::editableStage($contract,$kind) || self::recorded($db,(int)$contract['id'],$objectId,$kind)) {
            throw new RuntimeException('Загрузка акта на этом этапе недоступна.',409);
        }
        return self::candidate($db,$contract,$objectId,$kind);
    }

    public static function pinUpload(PDO $db,string $fileId,int $objectId): void
    {
        self::store($db,self::UPLOAD,$fileId,['object_id'=>$objectId]);
    }

    public static function inventoryDigest(array $items): string
    {
        return hash('sha256',Importer::canonicalJson($items));
    }

    public static function execute(PDO $db,string $action,array $contract,array $input): array
    {
        Service::roles(['manager']);
        $id=(int)$contract['id'];
        Access::contract($id,2);
        $objectId=(int)($input['object_id']??0);
        $kind=$action===self::COUNT?'count':($input['act_kind']??'');
        if (!is_string($kind) || !self::editableStage($contract,$kind) || ($action===self::CONFIRM && !isset(self::FILE_KINDS[$kind]))) {
            throw new RuntimeException('Действие с актом недоступно на этом этапе.',409);
        }
        $object=self::requireSubject($db,$contract,$objectId,$kind);
        $key=self::key($id,$objectId);
        if ($action===self::COUNT) {
            $old=self::count($db,$id,$objectId);
            if ((int)($input['count_version']??0)!==($old['version']??0)) throw new RuntimeException('Количество изменилось. Откройте форму заново.',409);
            $count=DirectoryDetails::text($input,'accessories',6);
            if (!preg_match('/^(?:0|[1-9][0-9]{0,5})$/D',$count)) throw new RuntimeException('Укажите согласованное целое количество.',422);
            $next=['count'=>(int)$count,'version'=>($old['version']??0)+1];
            $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
                ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([self::COUNTS,$key,Importer::json($next)]);
            Audit::record($action,'contract',$id,$old,['object_id'=>$objectId]+$next);
            return ['id'=>$id,'message'=>'Согласованное количество сохранено.'];
        }

        if (self::recorded($db,$id,$objectId,$kind)) throw new RuntimeException('Этот акт уже подтверждён.',409);
        if (($input['both_signed']??'')!=='1' && ($input['both_signed']??0)!==1) throw new RuntimeException('Подтвердите подписи обеих сторон.',422);
        $file=self::candidate($db,$contract,$objectId,$kind);
        if (!$file || $file['id']!==($input['file_id']??null) || !hash_equals((string)$file['baseline_hash'],Evidence::signature($db,$id))) {
            throw new RuntimeException('Файл или исходные условия акта изменились. Откройте форму заново.',409);
        }
        Evidence::integrity($file);
        $date=Service::date((string)($input['act_date']??''));
        if ($date>date('Y-m-d')) throw new RuntimeException('Фактическая дата не может быть в будущем.',422);
        $details=[];
        if ($kind==='access') {
            $from=Service::date((string)($input['period_from']??''));
            $until=Service::date((string)($input['period_until']??''));
            $keys=DirectoryDetails::text($input,'keys',1000);
            if ($from>$until || $keys==='') throw new RuntimeException('Укажите период допуска и переданные ключи.',422);
            $details=['period_from'=>$from,'period_until'=>$until,'keys'=>$keys];
        }
        $items=$kind==='transfer' && !self::parking($object)?PremisesInventory::items($db,$objectId):[];
        if (!hash_equals(self::inventoryDigest($items),(string)($input['inventory_hash']??''))) {
            throw new RuntimeException('Опись изменилась. Сверьте её и откройте форму заново.',409);
        }
        self::saveAct($db,$contract,$object,$kind,$date,$file,$items,$details);
        return ['id'=>$id,'message'=>'Подписанный акт подтверждён.'];
    }

    private static function saveAct(PDO $db,array $contract,array $object,string $kind,string $date,array $file,array $items,array $details): void
    {
        $id=(int)$contract['id'];$objectId=(int)$object['id'];
        $record=['kind'=>$kind,'object_id'=>$objectId,'object_name'=>$object['name'],'object_type'=>$object['type'],'date'=>$date,
            'file_id'=>$file['id'],'file_sha256'=>$file['sha256'],'version'=>(int)$file['version'],
            'accessories'=>self::count($db,$id,$objectId)['count']??null,'inventory'=>$items,'details'=>$details];
        $key=self::key($id,$objectId).':'.$kind;
        if ($kind==='return') $key.=':'.$file['id'];
        self::store($db,self::NS,$key,$record);
        Audit::record('contract.act-snapshot','contract',$id,null,$record);
    }

    public static function returnInventory(PDO $db,array $objects): array
    {
        $items=[];
        foreach ($objects as $objectId=>$object) {
            if (!self::parking($object)) $items[$objectId]=PremisesInventory::items($db,$objectId);
        }
        return $items;
    }

    public static function recordReturn(PDO $db,array $contract,array $file,string $date,array $input): void
    {
        $id=(int)$contract['id'];
        $objects=self::subjects($db,$id);
        $items=self::returnInventory($db,$objects);
        if (isset($input['inventory_hash']) && !hash_equals(self::inventoryDigest($items),(string)$input['inventory_hash'])) {
            throw new RuntimeException('Опись возврата изменилась. Сверьте её и откройте форму заново.',409);
        }
        $data=$input['return_details']??[];
        if (!is_array($data) || array_diff_key($data,$items)) throw new RuntimeException('Сведения о дефектах относятся к возвращаемому помещению.',422);
        foreach ($objects as $objectId=>$object) {
            $details=$data[$objectId]??[];
            if (!is_array($details)) throw new RuntimeException('Проверьте сведения о дефектах.',422);
            $defects=DirectoryDetails::text($details,'defects',2000);
            $deadline=DirectoryDetails::text($details,'remedy_by',10);
            if (($defects==='')!==($deadline==='') || ($deadline!=='' && Service::date($deadline)<$date)) {
                throw new RuntimeException('Укажите дефекты и согласованный срок устранения не раньше возврата.',422);
            }
            self::saveAct($db,$contract,$object,'return',$date,$file,$items[$objectId]??[],['defects'=>$defects,'remedy_by'=>$deadline]);
        }
    }
}
