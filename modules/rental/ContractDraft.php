<?php
declare(strict_types=1);
namespace Modules\Rental;
use PDO;
use RuntimeException;

/** Unvalidated editor state. It does not reserve objects or create billing terms. */
final class ContractDraft
{
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

    public static function save(PDO $pdo, array $input, ?array $old, string $number): int
    {
        if ($old && $old['return_to']==='lawyer') throw new RuntimeException('Проект не доступен для редактирования.',403);
        $draft=array_intersect_key($input,array_flip(['party_id','document_date','start_date','end_date','amount','objects','term_amount','part_terms_present','part_terms','booking_id','booking_version','draft_object_id']));
        // Keep the exact editor values; projection columns contain only valid, known facts.
        $draft['objects']=array_values(array_unique(array_map('intval',(array)($draft['objects'] ?? []))));
        if (count($draft['objects'])>256 || strlen(Importer::json($draft))>262144) throw new RuntimeException('Слишком большой черновик.',422);
        self::objects($pdo,$draft);
        $party=empty($draft['party_id'])?null:(int)$draft['party_id'];
        if ($party!==null) Service::get($pdo,'parties',$party);
        $source=$old?json_decode($old['source_json'],true):[];
        $source['draft_input']=$draft;
        $amount=null;$sum='0.00';$known=true;$hasPrice=false;
        $prices=[];
        foreach ($draft['objects'] as $oid) $prices[]=$draft['term_amount'][$oid] ?? '';
        foreach ($draft['term_amount'] ?? [] as $slot=>$price) if (str_starts_with((string)$slot,'part_') && trim((string)$price)!=='') $prices[]=$price;
        foreach ($prices as $price) {
            try { $value=Service::money($price); if(bccomp($value,'0',2)<=0)$known=false; $sum=bcadd($sum,$value,2);$hasPrice=true; }
            catch (RuntimeException) { $known=false; }
        }
        if ($known && $hasPrice) $amount=$sum;
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
