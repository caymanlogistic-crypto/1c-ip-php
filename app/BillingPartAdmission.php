<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** One source-authority receipt namespace; no financial facts or runtime-state document. */
final class BillingPartAdmission
{
    public const SWITCH='APP_BILLING_PART_ADMISSION_SHA256';
    public const NAMESPACE='billing_part_admission';
    public const NATIVE_SOURCE='213de8b874100f2ff00251625e25113ed4e164a3f3cb7796e89573eccb3c6eac';

    public static function selected(): ?string
    {
        $value=getenv(self::SWITCH);
        if($value===false || $value==='')return null;
        self::sha($value);
        return $value;
    }

    public static function identity(string $namespace,string $key): string
    {
        if(trim($namespace)==='' || trim($key)==='' || mb_strlen($namespace)>100 || mb_strlen($key)>180 || str_contains($namespace,"\0") || str_contains($key,"\0"))throw new RuntimeException('Billing-part source identity is invalid.',409);
        return OperatingScopeArtifact::hash(['project_id'=>OperatingScopeArtifact::PROJECT,'namespace'=>$namespace,'key'=>$key]);
    }

    public static function id(string $namespace,string $key): string
    {
        $hex=substr(self::identity($namespace,$key),0,32);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    /** Existing ROOT/source applier only, in its own CAS/invariant transaction; not a UI API. */
    public static function recordSource(PDO $pdo,array $source): string
    {
        self::transaction($pdo);
        $receipt=self::document($source);$sha=OperatingScopeArtifact::hash($receipt);
        $query=$pdo->prepare('SELECT fact_json,operative FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE');$query->execute([self::NAMESPACE,$sha]);
        $old=$query->fetch(PDO::FETCH_ASSOC);
        if($old){if((int)$old['operative']!==0 || Importer::canonicalJson(Importer::decodeExact($old['fact_json']))!==Importer::canonicalJson($receipt))throw new RuntimeException('Billing-part admission receipt conflicts.',409);return $sha;}
        $pdo->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,0)')->execute([self::NAMESPACE,$sha,Importer::json($receipt)]);
        return $sha;
    }

    public static function document(array $source): array
    {
        $allowed=['source_manifest_sha256','part_source_sha256','term_source_sha256','source_identity_sha256s'];
        if(array_diff(array_keys($source),$allowed) || array_diff($allowed,array_keys($source)))throw new RuntimeException('Billing-part admission requires its exact technical source fields.',409);
        foreach(['source_manifest_sha256','part_source_sha256','term_source_sha256'] as $field)self::sha($source[$field]);
        $ids=$source['source_identity_sha256s'];
        if(!is_array($ids) || !array_is_list($ids) || !$ids || count($ids)>32)throw new RuntimeException('Billing-part source identities require an explicit bounded set.',409);
        foreach($ids as $id)self::sha($id);
        if(count(array_unique($ids))!==count($ids))throw new RuntimeException('Duplicate billing-part source identity.',409);
        sort($ids,SORT_STRING);
        return ['schema'=>1,'kind'=>'billing-part-source-admission','status'=>'SOURCE_ADMITTED','project_id'=>OperatingScopeArtifact::PROJECT,
            'source_manifest_sha256'=>$source['source_manifest_sha256'],'part_source_sha256'=>$source['part_source_sha256'],'term_source_sha256'=>$source['term_source_sha256'],'source_identity_sha256s'=>$ids,
            'contract_id'=>null,'money_mutation'=>false,'physical_mutation'=>false];
    }

    public static function receipt(PDO $pdo,string $sha): array
    {
        self::sha($sha);
        $query=$pdo->prepare('SELECT fact_json,operative FROM rental_facts WHERE namespace=? AND source_key=?');$query->execute([self::NAMESPACE,$sha]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row || (int)$row['operative']!==0)throw new RuntimeException('Billing-part source admission is not recorded.',409);
        $value=Importer::decodeExact($row['fact_json']);
        $source=[];foreach(['source_manifest_sha256','part_source_sha256','term_source_sha256','source_identity_sha256s'] as $field)$source[$field]=$value[$field]??null;
        $expected=self::document($source);
        if(Importer::canonicalJson($value)!==Importer::canonicalJson($expected) || !hash_equals($sha,OperatingScopeArtifact::hash($expected)))throw new RuntimeException('Billing-part technical source receipt hash differs.',409);
        return $expected;
    }

    /** OFF makes no SQL; an ON anchor admits only its exact source identity set. */
    public static function anchor(PDO $pdo): ?array
    {
        $selected=self::selected();return $selected===null?null:self::receipt($pdo,$selected);
    }

    public static function source(PDO $pdo,array $part,?array $anchor=null): array
    {
        $receipt=self::receipt($pdo,$part['admission_sha256']);
        $identity=self::identity($part['source_namespace'],$part['source_key']);
        if(!in_array($identity,$receipt['source_identity_sha256s'],true) || ($anchor!==null && !in_array($identity,$anchor['source_identity_sha256s'],true)) || $part['source_sha256']!==$receipt['part_source_sha256'])throw new RuntimeException('Part source proof is outside its admitted identity/document.',409);
        return $receipt;
    }

    public static function sha(mixed $value): void
    {
        if(!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value))throw new RuntimeException('Explicit billing-part source SHA-256 is required.',409);
    }

    public static function transaction(PDO $pdo): void
    {
        if(!$pdo->inTransaction())throw new RuntimeException('Billing-part writes must share their caller business/source transaction.',409);
    }
}
