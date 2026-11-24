<?php
declare(strict_types=1);
namespace Modules\Rental;
use PDO;
use RuntimeException;

/** Editor state with priced rental positions. Saving does not reserve objects or create billing terms. */
final class ContractDraft
{
    public static function deleted(array $contract): bool
    {
        return isset(json_decode($contract['source_json'],true)['draft_deleted']) || WorkspaceReset::hidden($contract);
    }

    public static function visibleSql(string $alias='c'): string
    {
        if(!preg_match('/^[a-z_]+$/iD',$alias))throw new RuntimeException('Invalid contract alias');
        return "JSON_CONTAINS_PATH(".$alias.".source_json,'one','$.draft_deleted','$.workspace_cleared')=0";
    }

    public static function deletable(PDO $pdo,array $contract): bool
    {
        $source=json_decode($contract['source_json'],true) ?: [];
        if(self::deleted($contract)||!empty($source['imported']))return false;
        if($contract['status']==='cancelled'){
            $q=$pdo->prepare("SELECT before_json FROM audit_log WHERE action='contract.cancel' AND entity_type='contract' AND entity_id=? ORDER BY id DESC LIMIT 1");$q->execute([$contract['id']]);
            $before=json_decode($q->fetchColumn() ?: '{}',true);
            if(($before['status'] ?? null)!=='draft')return false;
        } elseif($contract['status']!=='draft')return false;
        foreach(['SELECT 1 FROM invoices WHERE contract_id=? LIMIT 1','SELECT 1 FROM rental_documents WHERE contract_id=? LIMIT 1',"SELECT 1 FROM rental_files WHERE entity_type='contract' AND entity_id=? LIMIT 1","SELECT 1 FROM rental_approvals WHERE entity_type='contract' AND entity_id=? LIMIT 1","SELECT 1 FROM rental_delivery_intents WHERE entity_type='contract' AND entity_id=? LIMIT 1"] as $sql){
            $q=$pdo->prepare($sql);$q->execute([$contract['id']]);if($q->fetchColumn())return false;
        }
        return true;
    }

    public static function delete(PDO $pdo,array $contract,array $actor,string $commandKey): array
    {
        if(!self::deletable($pdo,$contract))throw new RuntimeException('Удалить можно только незавершённый черновик без счетов, документов и согласований.',409);
        ContractHistory::remember($pdo,$contract,$actor,$commandKey);
        $source=json_decode($contract['source_json'],true) ?: [];
        $source['draft_deleted']=['at'=>date(DATE_ATOM),'actor_id'=>(int)$actor['id'],'command_key'=>$commandKey];
        $pdo->prepare('UPDATE contracts SET source_json=?,version=version+1 WHERE id=?')->execute([Importer::json($source),$contract['id']]);
        Service::event($pdo,$actor,'contract.delete','Удалён черновик договора №'.$contract['number'],(int)$contract['id']);
        \App\Audit::record('contract.delete','contract',(int)$contract['id'],$contract,['draft_deleted'=>$source['draft_deleted'],'version'=>(int)$contract['version']+1]);
        return ['id'=>(int)$contract['id'],'message'=>'Черновик удалён.'];
    }

    public static function input(?array $contract): ?array
    {
        if (!$contract || !in_array($contract['status'], ['draft','returned'], true)) return null;
        return json_decode($contract['source_json'], true)['draft_input'] ?? null;
    }

    private static function optionalDate(mixed $value): ?string
    {
        try { return Service::date((string)$value); } catch (RuntimeException) { return null; }
    }

    public static function objects(PDO $pdo, array $input): array
    {
        $rows=[];
        foreach (array_unique((array)($input['objects'] ?? [])) as $id) {
            $row=Service::get($pdo, 'rental_objects', (int)$id);
            $row['amount']=$input['term_amount'][$id] ?? null;
            $rows[]=$row;
        }
        return $rows;
    }

    private static function rentAmount(PDO $pdo,int $id,array $objects,array $draft): string
    {
        $sum='0.00';
        foreach ($objects as $object) {
            $message='Укажите месячную аренду с НДС больше нуля за объект «'.$object['name'].'».';
            $price=$object['amount'];
            if (!is_string($price) && !is_int($price)) throw new RuntimeException($message,422);
            try {
                $amount=Service::money($price);
            } catch (RuntimeException $error) {
                throw new RuntimeException($message,422,$error);
            }
            if (bccomp($amount,'0',2)<=0) throw new RuntimeException($message,422);
            $sum=bcadd($sum,$amount,2);
        }

        // An empty optional part price keeps that part out of the contract.
        $candidates=null;
        foreach (array_keys((array)($draft['part_terms'] ?? [])) as $slot) {
            $price=$draft['term_amount'][$slot] ?? '';
            if (!is_string($price)) throw new RuntimeException('Проверьте месячную цену части.',422);
            if (trim($price)==='') continue;
            $candidates??=PartTerms::candidates($pdo,'contract',$id);
            if (!isset($candidates[$slot])) throw new RuntimeException('Проверьте состав условий частей.',422);
            $sum=bcadd($sum,PartTerms::validatedPrice(trim($price),'contract'),2);
        }
        if (bccomp($sum,'0',2)<=0) {
            throw new RuntimeException('Добавьте объект аренды и укажите месячную ставку с НДС.',422);
        }
        return $sum;
    }

    public static function save(PDO $pdo, array $input, ?array $old, string $number): int
    {
        if ($old && $old['return_to']==='lawyer') throw new RuntimeException('Проект не доступен для редактирования.',403);
        $draft=array_intersect_key($input,array_flip(['party_id','document_date','start_date','end_date','amount','objects','term_amount','part_terms_present','part_terms','booking_id','booking_version','draft_object_id','billing_exception_present','billing_exception']));
        // Keep the exact editor values; projection columns contain only valid, known facts.
        $draft['objects']=array_values(array_unique(array_map('intval',(array)($draft['objects'] ?? []))));
        if (count($draft['objects'])>256 || strlen(Importer::json($draft))>262144) throw new RuntimeException('Слишком большой черновик.',422);
        if (!is_array($draft['term_amount'] ?? [])) throw new RuntimeException('Проверьте месячные ставки.',422);
        $amount=self::rentAmount($pdo,(int)($old['id'] ?? 0),self::objects($pdo,$draft),$draft);
        $party=empty($draft['party_id'])?null:(int)$draft['party_id'];
        if ($party!==null) Service::get($pdo,'parties',$party);
        $source=$old?json_decode($old['source_json'],true):[];
        $source['draft_input']=$draft;
        $values=[$party,self::optionalDate($draft['document_date'] ?? ''),self::optionalDate($draft['start_date'] ?? ''),self::optionalDate($draft['end_date'] ?? ''),$amount,Importer::json($source)];
        if ($old) {
            $id=(int)$old['id'];
            $pdo->prepare('UPDATE contracts SET number=?,party_id=?,document_date=?,start_date=?,end_date=?,amount=?,source_json=?,version=version+1 WHERE id=?')->execute([$number,...$values,$id]);
        } else {
            $id=(int)$pdo->query('SELECT COALESCE(MAX(id),900000)+1 FROM contracts')->fetchColumn();
            $pdo->prepare("INSERT INTO contracts(party_id,document_date,start_date,end_date,amount,source_json,id,number,status) VALUES(?,?,?,?,?,?,?,?,'draft')")->execute([...$values,$id,$number]);
        }
        return $id;
    }
}
