<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,DB,PagePolicy};
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RentalServices
{
    public const CREATE='service.cleaning-create';
    public const AMEND='service.cleaning-amend';
    public const CONFIRM='service.cleaning-confirm';
    public const BOOK='service.cleaning-period';
    public const ORDER='service.order';
    public const CANCEL='service.cleaning-cancel';
    public const ACTIONS=[self::CREATE,self::AMEND,self::CONFIRM,self::BOOK,self::ORDER,self::CANCEL];
    public const FILE='signed-service';
    public const ORDERS=['pass'=>'Пропуск','fob'=>'Брелок','address'=>'Юридический адрес','extra'=>'Дополнительная уборка'];
    public const AMENDMENTS=['renew'=>'Продление','tariff'=>'Изменение тарифа','terminate'=>'Расторжение'];
    private const AGREEMENT='rental_service_agreement';
    private const AMENDMENT='rental_service_amendment';
    private const CHARGE='rental_service_charge';
    private const UPLOAD='rental_service_file';

    public static function canEdit(array $lease,bool $accounting=false): bool
    {
        $user=Auth::user();
        if (!in_array($user['role'],$accounting?['admin','accountant']:['admin','manager'],true)
            || !Auth::can($accounting?'finance.write':'rental.write')) return false;
        foreach (Access::statusPages($lease['status']) as $page) {
            if (PagePolicy::level('rental.'.$page,$user)>=2) return true;
        }
        return false;
    }

    private static function entries(PDO $db,string $namespace,string $field='',string|int $value=''): array
    {
        $allowed=['lease_id','agreement_id'];
        if ($field!=='' && !in_array($field,$allowed,true)) throw new RuntimeException('Неизвестный контекст услуг.');
        $sql="SELECT source_key,fact_json FROM rental_facts WHERE namespace=? AND operative=1";
        if ($field!=='') $sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.".$field."'))=?";
        $q=$db->prepare($sql.' ORDER BY id');
        $q->execute($field===''?[$namespace]:[$namespace,(string)$value]);
        $rows=[];
        foreach ($q->fetchAll() as $row) $rows[$row['source_key']]=Importer::decodeExact($row['fact_json']);
        return $rows;
    }

    private static function fact(PDO $db,string $namespace,string $key): ?array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([$namespace,$key]);
        $raw=$q->fetchColumn();
        return $raw===false?null:Importer::decodeExact($raw);
    }

    private static function insert(PDO $db,string $namespace,string $key,array $data): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([$namespace,$key,Importer::json($data)]);
    }

    private static function update(PDO $db,string $namespace,string $key,array $data): void
    {
        $db->prepare('UPDATE rental_facts SET fact_json=? WHERE namespace=? AND source_key=? AND operative=1')
            ->execute([Importer::json($data),$namespace,$key]);
    }

    public static function agreements(PDO $db,?int $leaseId=null): array
    {
        return $leaseId===null?self::entries($db,self::AGREEMENT):self::entries($db,self::AGREEMENT,'lease_id',$leaseId);
    }

    public static function agreement(PDO $db,string $key): array
    {
        return self::fact($db,self::AGREEMENT,$key)??throw new RuntimeException('Договор клининга не найден.',404);
    }

    public static function amendments(PDO $db,string $key): array
    {
        return self::entries($db,self::AMENDMENT,'agreement_id',$key);
    }

    public static function charges(PDO $db,int $leaseId): array
    {
        return self::entries($db,self::CHARGE,'lease_id',$leaseId);
    }

    public static function document(PDO $db,string $key): array
    {
        return self::fact($db,self::AMENDMENT,$key)??self::agreement($db,$key);
    }

    public static function pendingFile(PDO $db,int $leaseId,string $key): ?array
    {
        $q=$db->prepare("SELECT f.* FROM rental_files f JOIN rental_facts p ON p.source_key=f.id AND p.namespace=? AND p.operative=1
            WHERE f.entity_type='contract' AND f.entity_id=? AND f.kind=?
            AND JSON_UNQUOTE(JSON_EXTRACT(p.fact_json,'$.service_id'))=? ORDER BY f.upload_order DESC LIMIT 1");
        $q->execute([self::UPLOAD,$leaseId,self::FILE,$key]);
        return $q->fetch()?:null;
    }

    public static function checkUpload(PDO $db,array $lease,string $key,int $revision): ?array
    {
        $document=self::document($db,$key);
        if ($document['lease_id']!==(int)$lease['id'] || $document['status']!=='draft' || $document['version']!==$revision) {
            throw new RuntimeException('Редакция документа услуг изменилась. Откройте карточку заново.',409);
        }
        self::amendmentLease($lease,$document['kind']);
        return self::pendingFile($db,(int)$lease['id'],$key);
    }

    public static function pinUpload(PDO $db,string $fileId,string $key): void
    {
        self::insert($db,self::UPLOAD,$fileId,['service_id'=>$key]);
    }

    private static function activeLease(array $lease,string $date): void
    {
        if ($lease['status']!=='active' || !$lease['start_date'] || !$lease['end_date']
            || $date<$lease['start_date'] || $date>$lease['end_date']) {
            throw new RuntimeException('Выберите действующий договор аренды на дату услуги.',422);
        }
    }

    private static function amendmentLease(array $lease,string $kind): void
    {
        if ($kind==='terminate' && in_array($lease['status'],['reconciling','ended','closed'],true)) return;
        self::activeLease($lease,date('Y-m-d'));
    }

    public static function mayAmend(array $lease,string $kind): bool
    {
        try {self::amendmentLease($lease,$kind);return true;}
        catch (RuntimeException $error) {if ($error->getCode()!==422) throw $error;return false;}
    }

    private static function basis(PDO $db,array $lease,string $kind): void
    {
        if (!in_array($kind,['cleaning','pass','fob'],true)) return;
        $subject=TemplateAssets::objectKind($db,(int)$lease['id']);
        if (($kind==='fob' && $subject!=='parking')
            || ($kind!=='fob' && ($subject!=='office' || !PremisesActs::subjects($db,(int)$lease['id'])))) {
            throw new RuntimeException($kind==='fob'?'Брелок оформляется по договору машино-места.':'Выберите договор аренды помещения.',422);
        }
    }

    private static function date(array $input,string $key): string
    {
        return Service::date(DirectoryDetails::text($input,$key,10));
    }

    private static function number(PDO $db,string $namespace,string $prefix,array $input): string
    {
        $rows=self::entries($db,$namespace);
        $used=array_map('mb_strtolower',array_column($rows,'number'));
        $number=DirectoryDetails::text($input,'number',100);
        if ($number==='') {
            $sequence=count($rows)+1;
            do {$number=$prefix.$sequence++;} while (in_array(mb_strtolower($number),$used,true));
        }
        if (in_array(mb_strtolower($number),$used,true)) throw new RuntimeException('Этот номер документа услуг уже занят.',422);
        return $number;
    }

    private static function price(array $input,string $area): array
    {
        $manual=DirectoryDetails::text($input,'manual_rate',24);
        $reason=DirectoryDetails::text($input,'rate_basis',1000);
        if (($manual==='')!==($reason==='')) throw new RuntimeException('Для индивидуального тарифа укажите ставку и согласованное основание.',422);
        $rate=$manual===''?CleaningTerms::rate($area):Service::money($manual);
        if ($rate!==null && bccomp($rate,'0',2)<=0) throw new RuntimeException('Тариф должен быть положительным.',422);
        return ['area'=>$area,'rate'=>$rate,'rate_basis'=>$reason,
            'monthly'=>$rate===null?null:CleaningTerms::amount($area,$rate)];
    }

    private static function terms(array $input): array
    {
        $from=self::date($input,'from');$until=self::date($input,'until');
        if ($from>$until) throw new RuntimeException('Окончание раньше начала.',422);
        $area=Service::area(str_replace(',','.',DirectoryDetails::text($input,'area',24)));
        return ['from'=>$from,'until'=>$until]+self::price($input,$area);
    }

    private static function agreementVersion(array $record,array $input): void
    {
        if ($record['version']!==(int)($input['service_version']??0)) throw new RuntimeException('Условия услуг изменились. Откройте карточку заново.',409);
    }

    private static function changeTerms(array $proposal,array $agreement,array $history): ?array
    {
        $previous=$agreement['terms'];
        $latest=$previous['from'];
        foreach ($history as $entry) {
            if ($entry['status']!=='active') continue;
            if ($entry['kind']==='terminate') throw new RuntimeException('Договор клининга уже прекращён.',409);
            $previous=$entry['terms'];$latest=$entry['effective'];
        }
        $date=$proposal['effective'];
        if ($date<=$latest || $date>(new DateTimeImmutable($previous['until']))->modify('+1 day')->format('Y-m-d')) {
            throw new RuntimeException('Дата изменения должна следовать за действующими условиями без разрыва.',422);
        }
        if ($proposal['kind']==='terminate') {
            if ($date>$previous['until']) throw new RuntimeException('Расторжение должно приходиться на срок договора.',422);
            return null;
        }
        if ($proposal['kind']==='renew') {
            $after=(new DateTimeImmutable($previous['until']))->modify('+1 day')->format('Y-m-d');
            if ($date!==$after || $proposal['until']<$date) throw new RuntimeException('Продление начинается со дня, следующего за окончанием текущего срока.',422);
            return array_replace($previous,['from'=>$date,'until'=>$proposal['until']]);
        }
        if ($date>$previous['until']) throw new RuntimeException('Изменение тарифа должно приходиться на действующий срок договора.',422);
        return array_replace($previous,$proposal['price'],['from'=>$date]);
    }

    public static function execute(PDO $db,string $action,array $input): array
    {
        Service::roles(in_array($action,[self::CONFIRM,self::BOOK],true)?['accountant']:['manager']);
        $id=(int)($input['id']??0);
        Access::contract($id,2);
        $lease=Service::get($db,'contracts',$id);
        Service::version($lease,$input);
        $key=DirectoryDetails::text($input,'service_id',32);
        if ($action===self::CREATE) {
            self::activeLease($lease,date('Y-m-d'));self::basis($db,$lease,'cleaning');
            $key=bin2hex(random_bytes(16));
            $document=['lease_id'=>$id,'number'=>self::number($db,self::AGREEMENT,'КЛ-',$input),'kind'=>'cleaning',
                'document_date'=>self::date($input,'document_date'),'status'=>'draft','version'=>1,'terms'=>self::terms($input)];
            self::insert($db,self::AGREEMENT,$key,$document);
            Audit::record($action,'contract',$id,null,['service_id'=>$key]+$document);
            return ['id'=>$id,'service_id'=>$key,'message'=>'Договор клининга создан.'];
        }
        if ($action===self::ORDER && ($input['order_kind']??'')!=='extra') return self::order($db,$lease,$input);
        $document=self::document($db,$key);
        if ($document['lease_id']!==$id) throw new RuntimeException('Услуга не относится к этому договору аренды.',404);
        $root=$document['agreement_id']??$key;
        $agreement=self::agreement($db,$root);
        $history=self::amendments($db,$root);
        if ($action!==self::AMEND) self::agreementVersion($document,$input);
        if ($action===self::CONFIRM) return self::confirm($db,$lease,$key,$document,$root,$agreement,$history,$input);
        if ($action===self::CANCEL) {
            if ($document['status']!=='draft') throw new RuntimeException('Отменить можно только проект документа услуг.',409);
            $reason=DirectoryDetails::text($input,'reason',1000);
            if ($reason==='') throw new RuntimeException('Укажите причину отмены проекта.',422);
            $before=$document;
            $document['status']='cancelled';$document['version']++;$document['reason']=$reason;
            self::update($db,$root===$key?self::AGREEMENT:self::AMENDMENT,$key,$document);
            Audit::record($action,'contract',$id,$before,['service_id'=>$key]+$document);
            return ['id'=>$id,'service_id'=>$key,'message'=>'Проект документа услуг отменён.'];
        }
        if ($action===self::ORDER) return self::order($db,$lease,$input,$root,$agreement,$history);
        if ($agreement['status']!=='active' || $root!==$key) throw new RuntimeException('Выберите подтверждённый договор клининга.',422);
        if ($action===self::BOOK) return self::book($db,$id,$root,$agreement,$history,$input);

        self::agreementVersion($agreement,$input);
        $type=DirectoryDetails::text($input,'amend_kind',20);
        if (!isset(self::AMENDMENTS[$type])) throw new RuntimeException('Выберите вид ДС.',422);
        self::amendmentLease($lease,$type);
        foreach ($history as $entry) if ($entry['status']==='draft') throw new RuntimeException('Сначала завершите оформление предыдущего ДС.',409);
        $amendment=['lease_id'=>$id,'agreement_id'=>$root,'kind'=>$type,'status'=>'draft','version'=>1,
            'number'=>self::number($db,self::AMENDMENT,'ДС-КЛ-',$input),'document_date'=>self::date($input,'document_date'),
            'effective'=>self::date($input,'effective')];
        if ($type==='renew') $amendment['until']=self::date($input,'until');
        if ($type==='tariff') $amendment['price']=self::price($input,$agreement['terms']['area']);
        $amendment['terms']=self::changeTerms($amendment,$agreement,$history);
        $key=bin2hex(random_bytes(16));
        self::insert($db,self::AMENDMENT,$key,$amendment);
        Audit::record($action,'contract',$id,null,['service_id'=>$key]+$amendment);
        return ['id'=>$id,'service_id'=>$key,'message'=>'ДС по клинингу создано.'];
    }

    private static function confirm(PDO $db,array $lease,string $key,array $document,string $root,array $agreement,array $history,array $input): array
    {
        $id=(int)$lease['id'];
        self::amendmentLease($lease,$document['kind']);
        if ($document['status']!=='draft') throw new RuntimeException('Документ услуг уже подтверждён.',409);
        if (($input['both_signed']??null)!=='1' && ($input['both_signed']??null)!==1) throw new RuntimeException('Подтвердите подписи обеих сторон.',422);
        $file=self::pendingFile($db,$id,$key);
        if (!$file || $file['id']!==($input['file_id']??null)) throw new RuntimeException('Загрузите актуальный подписанный файл.',409);
        Evidence::integrity($file);
        $before=$document;
        if ($root!==$key) {
            foreach (self::charges($db,$id) as $charge) {
                if ($charge['kind']==='regular' && $charge['agreement_id']===$root && $document['effective']<=$charge['until']) {
                    throw new RuntimeException('Дата изменения затрагивает закрытый месяц клининга.',409);
                }
            }
            $document['terms']=self::changeTerms($document,$agreement,$history);
        } else self::basis($db,$lease,'cleaning');
        if ($document['kind']!=='terminate' && $document['terms']['rate']===null) {
            if (DirectoryDetails::text($input,'manual_rate',24)==='') throw new RuntimeException('Площадь вне согласованных диапазонов. Укажите индивидуальный тариф и основание для этого договора.',422);
            $document['terms']=array_replace($document['terms'],self::price($input,$document['terms']['area']));
        }
        $document['status']='active';
        $document['version']++;
        $document['file_id']=$file['id'];
        $document['file_sha256']=$file['sha256'];
        self::update($db,$root===$key?self::AGREEMENT:self::AMENDMENT,$key,$document);
        if ($root!==$key) {
            $agreement['version']++;
            self::update($db,self::AGREEMENT,$root,$agreement);
        }
        Audit::record(self::CONFIRM,'contract',$id,$before,['service_id'=>$key]+$document);
        return ['id'=>$id,'service_id'=>$key,'message'=>'Подписанные условия услуг подтверждены.'];
    }

    private static function book(PDO $db,int $leaseId,string $key,array $agreement,array $history,array $input): array
    {
        $period=DirectoryDetails::text($input,'period',7);
        [$from,$until]=CleaningTerms::month($period);
        if ($until>=date('Y-m-d')) throw new RuntimeException('Закрыть можно только закончившийся месяц.',422);
        $id=$key.':'.$period;
        if (self::fact($db,self::CHARGE,$id)) throw new RuntimeException('Месяц уже закрыт.',409);
        $terms=CleaningTerms::monthTerms($agreement,$history,$period);
        if (!$terms || $terms['rate']===null) throw new RuntimeException('Для этого месяца требуется согласованный расчёт по действующим условиям.',422);
        $charge=['lease_id'=>$leaseId,'agreement_id'=>$key,'period'=>$period,'kind'=>'regular','from'=>$from,'until'=>$until,
            'area'=>$terms['area'],'rate'=>$terms['rate'],'amount'=>$terms['monthly']];
        self::insert($db,self::CHARGE,$id,$charge);
        Audit::record(self::BOOK,'contract',$leaseId,null,$charge);
        return ['id'=>$leaseId,'service_id'=>$key,'message'=>'Регулярная сумма за месяц зафиксирована.'];
    }

    private static function order(PDO $db,array $lease,array $input,string $key='',?array $agreement=null,array $history=[]): array
    {
        $id=(int)$lease['id'];$kind=DirectoryDetails::text($input,'order_kind',20);$date=self::date($input,'order_date');
        if (!isset(self::ORDERS[$kind])) throw new RuntimeException('Выберите услугу.',422);
        self::activeLease($lease,$date);self::basis($db,$lease,$kind);
        if ($date>date('Y-m-d')) throw new RuntimeException('Дата фактической услуги не может быть в будущем.',422);
        if ($kind==='extra') {
            if (!$agreement || $agreement['status']!=='active' || !CleaningTerms::resolve($agreement,$history,$date)) throw new RuntimeException('Укажите действующий договор клининга на дату уборки.',422);
            if (self::fact($db,self::CHARGE,$key.':'.substr($date,0,7))) throw new RuntimeException('Месяц клининга закрыт.',409);
        }
        $quantity=DirectoryDetails::text($input,'quantity',24);
        if ($kind==='extra') $quantity=Service::area(str_replace(',','.',$quantity));
        elseif (!preg_match('/^[1-9][0-9]{0,5}$/D',$quantity)) throw new RuntimeException('Укажите согласованное количество.',422);
        $price=Service::money(DirectoryDetails::text($input,'unit_price',24));
        $details=DirectoryDetails::text($input,'details',1000);
        if ($details==='') throw new RuntimeException('Укажите основание и согласование услуги.',422);
        $charge=['lease_id'=>$id,'agreement_id'=>$key,'kind'=>$kind,'date'=>$date,'period'=>substr($date,0,7),
            'quantity'=>$kind==='extra'?$quantity:(int)$quantity,'rate'=>$price,
            'amount'=>Billing::round(bcmul($quantity,$price,6)),'details'=>$details];
        self::insert($db,self::CHARGE,bin2hex(random_bytes(16)),$charge);
        Audit::record(self::ORDER,'contract',$id,null,$charge);
        return ['id'=>$id,'service_id'=>$key,'message'=>'Дополнительная услуга сохранена.'];
    }
}
