<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Frozen history, not current posting readiness. No current part lookup during preview. */
final class BillingPartHistory
{
    public static function snapshot(PDO $pdo,string $kind,int $id,int $version): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        BillingPartAdmission::transaction($pdo);
        $branch=BillingPartTerms::state($pdo,$kind,$id);
        if($branch===null)return null;
        if(($branch['state']??null)!=='KNOWN')self::deny();
        $raw=$branch['rows'];
        return self::freeze($kind,$id,$version,$raw,BillingParts::terms($pdo,$kind,$id),$branch);
    }

    public static function freeze(string $kind,int $id,int $version,array $raw,array $terms,?array $branch=null): array
    {
        if(!in_array($kind,['contract','document'],true)||$id<1||$version<1||!array_is_list($raw)||!array_is_list($terms)
            ||($raw===[]&&$branch===null)||count($raw)>128||count($raw)!==count($terms))self::deny();
        if($branch!==null&&(($branch['state']??null)!=='KNOWN'||($branch['owner_kind']??null)!==$kind||($branch['owner_id']??null)!==$id
            ||($branch['known_empty']??null)!==($raw===[])||($branch['rows']??null)!==$raw
            ||($branch['rows_sha256']??null)!==OperatingScopeArtifact::hash($raw)
            ||($branch['receipt_sha256']??null)!==OperatingScopeArtifact::hash($branch['receipt']??[])
            ||($branch['receipt']['rows_sha256']??null)!==OperatingScopeArtifact::hash($raw)))self::deny();
        $rows=[];foreach($raw as $row){
            $termId=(int)($row['id']??0);
            if($termId<1||isset($rows[$termId])||(int)($row[$kind.'_id']??0)!==$id)self::deny();$rows[$termId]=$row;
        }
        $seen=[];
        foreach($terms as $term){
            $prefix=$kind.'-part-term:';$key=$term['source_term_key']??'';
            if(!is_string($key)||!str_starts_with($key,$prefix)||!ctype_digit(substr($key,strlen($prefix))))self::deny();
            $termId=(int)substr($key,strlen($prefix));$row=$rows[$termId]??null;
            if($row===null||isset($seen[$termId])||($term['target_kind']??null)!=='billing-part'
                ||!array_key_exists('object_id',$term)||$term['object_id']!==null||($term['physical_occupancy']??null)!==0
                ||($term['type']??null)!=='LEGAL_ADDRESS'||($term['record_version']??null)!==$version||($term['part_id']??null)!==($row['part_id']??null)
                ||($term['part_version']??null)!==(int)($row['part_version']??0)
                ||($term['term_revision']??null)!==(int)($row['revision']??0)
                ||($term['term_evidence_sha256']??null)!==($row['evidence_sha256']??null))self::deny();
            foreach(['name','type','start_date','end_date','amount','parent_object_id'] as $field)if(!array_key_exists($field,$term))self::deny();
            foreach(['start_date','end_date','amount'] as $field)if($term[$field]!==($row[$field]??null))self::deny();
            $seen[$termId]=true;
        }
        $body=['schema'=>1,'owner_kind'=>$kind,'owner_id'=>$id,'owner_version'=>$version,'raw_terms'=>$raw,'terms'=>$terms];
        if($branch!==null)$body['branch_state']=$branch;
        return $body+['sha256'=>hash('sha256',Importer::canonicalJson($body))];
    }

    public static function terms(array $snapshot): array
    {
        $expected=self::freeze($snapshot['owner_kind']??'',(int)($snapshot['owner_id']??0),(int)($snapshot['owner_version']??0),$snapshot['raw_terms']??[],$snapshot['terms']??[],$snapshot['branch_state']??null);
        if(Importer::canonicalJson($snapshot)!==Importer::canonicalJson($expected))self::deny();
        return $expected['terms'];
    }

    private static function deny(): never
    {
        throw new RuntimeException('Frozen billable part history identity/proof differs.',409);
    }
}
