<?php
declare(strict_types=1);
namespace App;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Versioned nonphysical identities. No method inserts rental_objects or occupancy rows. */
final class BillingParts
{
    public static function appendVersion(PDO $pdo,array $input,int $expectedPreviousVersion): array
    {
        BillingPartAdmission::transaction($pdo);
        $row=self::part($input,$expectedPreviousVersion);
        BillingPartAdmission::source($pdo,$row);
        $query=$pdo->prepare('SELECT * FROM rental_billing_parts WHERE id=? ORDER BY version DESC LIMIT 1 FOR UPDATE');$query->execute([$row['id']]);$old=$query->fetch(PDO::FETCH_ASSOC);
        if($old && (int)$old['version']===$row['version']){
            $actual=self::part($old,$expectedPreviousVersion);
            if($actual!==$row)throw new RuntimeException('Part source version already differs; replacement is forbidden.',409);
            return $actual;
        }
        if((int)($old['version']??0)!==$expectedPreviousVersion)throw new RuntimeException('Part source version CAS failed.',409);
        self::insert($pdo,'rental_billing_parts',$row);
        return $row;
    }

    public static function part(array $input,int $previousVersion): array
    {
        if($previousVersion<0 || $previousVersion>=2147483647)throw new RuntimeException('Invalid previous part version.',409);
        $namespace=self::text($input['source_namespace']??null,100,false);
        $key=self::text($input['source_key']??null,180,false);
        $id=BillingPartAdmission::id($namespace,$key);
        if(isset($input['id']) && $input['id']!==$id)throw new RuntimeException('Part UUID differs from its exact source identity.',409);
        if(($input['use_type']??null)!=='LEGAL_ADDRESS' || (array_key_exists('physical_occupancy',$input) && !in_array($input['physical_occupancy'],[0,'0',false],true)))throw new RuntimeException('Only source-proven nonphysical legal-address parts are admitted.',409);
        if(isset($input['version']) && ((!is_int($input['version']) && !is_string($input['version'])) || !ctype_digit((string)$input['version']) || (int)$input['version']!==$previousVersion+1))throw new RuntimeException('Part source version differs from its explicit predecessor.',409);
        if(array_key_exists('supersedes_version',$input) && ($previousVersion===0?$input['supersedes_version']!==null:($input['supersedes_version']===null || (int)$input['supersedes_version']!==$previousVersion)))throw new RuntimeException('Part source predecessor differs.',409);
        foreach(['area','floor','address','parent_object_id'] as $field)if(!array_key_exists($field,$input))throw new RuntimeException('Part display/parent unknowns must be explicit NULL, not guessed.',409);
        $parent=$input['parent_object_id'];
        if($parent!==null && ((!is_int($parent)&&!is_string($parent)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$parent)))throw new RuntimeException('Part parent must be a real positive identity or NULL.',409);
        foreach(['source_sha256','evidence_sha256','admission_sha256'] as $field)BillingPartAdmission::sha($input[$field]??null);
        return ['id'=>$id,'version'=>$previousVersion+1,'supersedes_version'=>$previousVersion===0?null:$previousVersion,
            'source_namespace'=>$namespace,'source_key'=>$key,'number'=>self::text($input['number']??null,100,false),'use_type'=>'LEGAL_ADDRESS',
            'area'=>self::decimal($input['area'],4,14),'floor'=>self::text($input['floor'],100),'address'=>self::text($input['address'],65535),
            'parent_object_id'=>$parent===null?null:(int)$parent,'physical_occupancy'=>0,
            'source_sha256'=>$input['source_sha256'],'evidence_sha256'=>$input['evidence_sha256'],'admission_sha256'=>$input['admission_sha256']];
    }

    /** Exact frozen version plus separate current proof; never silently borrows new display fields. */
    public static function version(PDO $pdo,string $id,int $version,?array $anchor=null): array
    {
        $query=$pdo->prepare('SELECT * FROM rental_billing_parts WHERE id=? AND version=?');$query->execute([$id,$version]);$part=$query->fetch(PDO::FETCH_ASSOC);
        if(!$part)throw new RuntimeException('Frozen billing-part version is missing.',409);
        $part=self::part($part,$version-1);BillingPartAdmission::source($pdo,$part,$anchor);
        $query=$pdo->prepare('SELECT * FROM rental_billing_parts WHERE id=? ORDER BY version DESC LIMIT 1');$query->execute([$id]);$current=$query->fetch(PDO::FETCH_ASSOC);
        $current=self::part($current,(int)$current['version']-1);BillingPartAdmission::source($pdo,$current,$anchor);
        return ['part'=>$part,'current_version'=>$current['version'],'current_evidence_sha256'=>$current['evidence_sha256'],'current_admission_sha256'=>$current['admission_sha256']];
    }

    /** OFF returns [] without touching any of the pending051 tables. */
    public static function terms(PDO $pdo,string $kind,int $recordId): array
    {
        $anchor=BillingPartAdmission::anchor($pdo);if($anchor===null)return [];
        [$table,$owner]=match($kind){'contract'=>['contract_part_terms','contracts'],'document'=>['document_part_terms','rental_documents'],default=>throw new RuntimeException('Invalid part term record kind.',409)};
        $query=$pdo->prepare('SELECT t.*,r.version record_version FROM '.$table.' t JOIN '.$owner.' r ON r.id=t.'.$kind.'_id WHERE t.'.$kind.'_id=? ORDER BY t.id');$query->execute([$recordId]);$result=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $term){
            BillingPartAdmission::sha($term['evidence_sha256']);
            $frozen=self::version($pdo,$term['part_id'],(int)$term['part_version'],$anchor);
            $receipt=BillingPartAdmission::receipt($pdo,$term['admission_sha256']);
            $identity=BillingPartAdmission::identity($frozen['part']['source_namespace'],$frozen['part']['source_key']);
            if(!in_array($identity,$receipt['source_identity_sha256s'],true))throw new RuntimeException('Term is outside its source identity admission.',409);
            $source=$term['origin']==='source-admitted'?$receipt['term_source_sha256']:BillingPartAdmission::NATIVE_SOURCE;
            if($term['source_sha256']!==$source || ($term['origin']!=='source-admitted' && (!is_string($term['command_key']) || !preg_match('/^[A-Za-z0-9:._-]{1,100}$/D',$term['command_key']))))throw new RuntimeException('Dated term source proof differs.',409);
            $dto=BillableTerms::part($term,$frozen['part'],$kind,(int)$term['record_version']);
            $dto['source_term_key_native']=$term['source_term_key'];
            $dto['term_revision']=(int)$term['revision'];$dto['term_evidence_sha256']=$term['evidence_sha256'];$dto['term_admission_sha256']=$term['admission_sha256'];
            $dto['current_part_version']=$frozen['current_version'];$dto['current_part_evidence_sha256']=$frozen['current_evidence_sha256'];$dto['current_part_admission_sha256']=$frozen['current_admission_sha256'];
            $present=count(array_filter([$dto['start_date'],$dto['end_date'],$dto['amount']],static fn($value)=>$value!==null));
            $dto['terms_state']=$present===3?'KNOWN':($present===0?'UNKNOWN':'PARTIAL');
            $dto['runtime_hold']=$frozen['current_version']!==$frozen['part']['version']?'PART_VERSION_CHANGED':($dto['terms_state']==='KNOWN'?null:'DATED_PART_TERM_INCOMPLETE');
            $result[]=$dto;
        }
        return $result;
    }

    public static function requireReady(array $dto): void
    {
        if(($dto['target_kind']??null)!=='billing-part' || ($dto['terms_state']??null)!=='KNOWN' || ($dto['runtime_hold']??null)!==null)throw new RuntimeException('Billable part dated terms/source version are not ready.',409);
    }

    public static function date(mixed $value): ?string
    {
        if($value===null)return null;
        $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if(!$date || $date->format('Y-m-d')!==$value)throw new RuntimeException('Part term date must preserve an exact calendar date or NULL.',409);
        return $value;
    }

    public static function decimal(mixed $value,int $scale,int $integerDigits): ?string
    {
        if($value===null)return null;
        if(!is_string($value) || !preg_match('/^-?[0-9]{1,'.$integerDigits.'}(?:\.[0-9]{1,'.$scale.'})?$/D',$value))throw new RuntimeException('Part decimal requires an exact bounded string or NULL.',409);
        return bcadd($value,'0',$scale);
    }

    public static function text(mixed $value,int $limit,bool $nullable=true): ?string
    {
        if($value===null && $nullable)return null;
        if(!is_string($value) || mb_strlen($value)>$limit || ($limit===65535 && strlen($value)>65535) || str_contains($value,"\0") || (!$nullable && trim($value)===''))throw new RuntimeException('Billing-part text requires its bounded source value or explicit NULL.',409);
        return $value;
    }

    public static function positiveId(mixed $value): int
    {
        if((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$value))throw new RuntimeException('Billing-part numeric identity must be an exact positive bounded integer.',409);
        return (int)$value;
    }

    public static function partVersion(mixed $value): int
    {
        $version=self::positiveId($value);
        if($version>2147483647)throw new RuntimeException('Billing-part version exceeds its explicit bound.',409);
        return $version;
    }

    public static function insert(PDO $pdo,string $table,array $row): void
    {
        if(!in_array($table,['rental_billing_parts','contract_part_terms','document_part_terms','invoice_part_scope'],true))throw new RuntimeException('Unexpected billing-part storage table.',409);
        $columns=array_keys($row);
        foreach($columns as $column)if(!is_string($column) || !preg_match('/^[a-z_][a-z0-9_]*$/D',$column))throw new RuntimeException('Invalid billing-part storage column.',409);
        $pdo->prepare('INSERT INTO '.$table.'('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    }
}
