<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

/** Explicit invoice part scope: missing, known-empty and partial are different states. */
final class BillingPartScope
{
    public static function record(PDO $pdo,int $invoiceId,string $state,array $parts,int $expectedRevision,array $basis): array
    {
        BillingPartAdmission::transaction($pdo);
        $receipt=BillingPartAdmission::receipt($pdo,$basis['admission_sha256']);
        $query=$pdo->prepare('SELECT version FROM invoices WHERE id=? FOR UPDATE');$query->execute([$invoiceId]);$version=$query->fetchColumn();
        if($version===false || (int)$version!==($basis['invoice_version']??null) || $expectedRevision<0)throw new RuntimeException('Invoice part-scope version preimage differs.',409);
        $source=$basis['source_sha256']??null;
        if(!in_array($source,[$receipt['term_source_sha256'],BillingPartAdmission::NATIVE_SOURCE],true))throw new RuntimeException('Explicit invoice period/source proof is required.',409);
        BillingPartAdmission::sha($basis['evidence_sha256']??null);
        if($source===BillingPartAdmission::NATIVE_SOURCE){
            $query=$pdo->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='invoice' AND entity_id=? AND relation_kind='invoice_control' LIMIT 1 FOR UPDATE");$query->execute([$invoiceId]);
            if($query->fetchColumn()!==false)throw new RuntimeException('Imported invoice part scope cannot use a native fallback.',409);
        }
        $rows=self::candidates($pdo,$invoiceId,$state,$parts,$expectedRevision+1,$basis);
        $query=$pdo->prepare('SELECT * FROM invoice_part_scope WHERE invoice_id=? ORDER BY ordinal FOR UPDATE');$query->execute([$invoiceId]);$old=$query->fetchAll(PDO::FETCH_ASSOC);
        if($old){
            $actual=self::normalizeRows($old);
            if($actual===$rows)return ['already_recorded'=>true,'revision'=>$rows[0]['revision']];
            if((int)$old[0]['revision']!==$expectedRevision || ($old[0]['source_sha256']!==BillingPartAdmission::NATIVE_SOURCE && $source===BillingPartAdmission::NATIVE_SOURCE))throw new RuntimeException('Invoice part scope conflicts; native source replacement is forbidden.',409);
        }elseif($expectedRevision!==0)throw new RuntimeException('Invoice part scope revision is missing.',409);
        try {
            $pdo->prepare('DELETE FROM invoice_part_scope WHERE invoice_id=?')->execute([$invoiceId]);
            foreach($rows as $row)BillingParts::insert($pdo,'invoice_part_scope',$row);
        } catch(\Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        return ['already_recorded'=>false,'revision'=>$expectedRevision+1];
    }

    public static function read(PDO $pdo,int $invoiceId): ?array
    {
        $anchor=BillingPartAdmission::anchor($pdo);if($anchor===null)return null;
        $query=$pdo->prepare('SELECT * FROM invoice_part_scope WHERE invoice_id=? ORDER BY ordinal');$query->execute([$invoiceId]);$rows=self::normalizeRows($query->fetchAll(PDO::FETCH_ASSOC));
        if(!$rows || $rows[0]['row_kind']!=='HEADER')return ['availability'=>$rows?'PARTIAL':'UNKNOWN','runtime_hold'=>$rows?'PART_SCOPE_HEADER_MISSING':'PART_SCOPE_NOT_RECORDED','part_ids'=>null,'coverage'=>null,'parts'=>[],'revision'=>0,'admission_sha256'=>BillingPartAdmission::selected(),'stored_scope_sha256'=>OperatingScopeArtifact::hash($rows),'stored_part_refs'=>array_map(static fn($row)=>['part_id'=>$row['part_id'],'part_version'=>$row['part_version'],'evidence_sha256'=>$row['evidence_sha256']],$rows)];
        $header=$rows[0];$receipt=BillingPartAdmission::receipt($pdo,$header['admission_sha256']);
        BillingPartAdmission::sha($header['evidence_sha256']);
        if(!in_array($header['source_sha256'],[$receipt['term_source_sha256'],BillingPartAdmission::NATIVE_SOURCE],true))throw new RuntimeException('Invoice part-scope period proof differs.',409);
        $query=$pdo->prepare('SELECT version FROM invoices WHERE id=?');$query->execute([$invoiceId]);$version=$query->fetchColumn();
        $state=$header['scope_state'];$hold=$version===false || (int)$version!==$header['invoice_version']?'INVOICE_PART_SCOPE_STALE':null;
        $parts=[];$coverage=[];$ids=[];
        foreach(array_slice($rows,1) as $row){
            if($row['row_kind']!=='PART' || $row['revision']!==$header['revision'] || $row['invoice_version']!==$header['invoice_version'] || $row['admission_sha256']!==$header['admission_sha256'] || $row['source_sha256']!==$header['source_sha256'] || $row['evidence_sha256']!==$header['evidence_sha256'])throw new RuntimeException('Part scope header/body revisions or source proof differ.',409);
            BillingPartAdmission::receipt($pdo,$row['admission_sha256']);
            $frozen=BillingParts::version($pdo,$row['part_id'],$row['part_version'],$anchor);$part=$frozen['part'];
            if($frozen['current_version']!==$part['version'])$hold='PART_VERSION_CHANGED';
            if($row['scope_state']!=='KNOWN' && $state==='KNOWN')$state='PARTIAL';
            if($row['scope_state']==='UNKNOWN' && $state!=='UNKNOWN')$state='PARTIAL';
            $ids[]=$part['id'];$coverage[]=['part_id'=>$part['id'],'part_version'=>$part['version'],'start_date'=>$row['start_date'],'end_date'=>$row['end_date'],'scope_state'=>$row['scope_state']];
            $parts[]=['target_kind'=>'billing-part','target_key'=>'part:'.$part['id'],'object_id'=>null,'part_id'=>$part['id'],'part_version'=>$part['version'],'parent_object_id'=>$part['parent_object_id'],'physical_occupancy'=>0,
                'name'=>$part['number'],'type'=>$part['use_type'],'area'=>$part['area'],'floor'=>$part['floor'],'address'=>$part['address'],
                'part_evidence_sha256'=>$part['evidence_sha256'],'part_admission_sha256'=>$part['admission_sha256'],'current_part_version'=>$frozen['current_version'],'current_part_evidence_sha256'=>$frozen['current_evidence_sha256'],'scope_evidence_sha256'=>$row['evidence_sha256']];
        }
        if($state!=='KNOWN' && $hold===null)$hold='PART_SCOPE_INCOMPLETE';
        return ['availability'=>$state,'runtime_hold'=>$hold,'part_ids'=>$state==='UNKNOWN'?null:array_values(array_unique($ids)),'coverage'=>$state==='UNKNOWN'?null:$coverage,'parts'=>$parts,
            'revision'=>$header['revision'],'invoice_version'=>$header['invoice_version'],'evidence_sha256'=>$header['evidence_sha256'],'admission_sha256'=>$header['admission_sha256'],
            'stored_scope_sha256'=>OperatingScopeArtifact::hash($rows)];
    }

    public static function requireReady(array $scope): void
    {
        if(($scope['availability']??null)!=='KNOWN' || ($scope['runtime_hold']??null)!==null)throw new RuntimeException('Invoice billing-part scope is incomplete or stale.',409);
    }

    private static function candidates(PDO $pdo,int $invoiceId,string $state,array $parts,int $revision,array $basis): array
    {
        if(!in_array($state,['KNOWN','PARTIAL','UNKNOWN'],true) || !array_is_list($parts) || count($parts)>128 || (!$parts && $state==='KNOWN' && ($basis['known_empty_proven']??null)!==true))throw new RuntimeException('Explicit part scope state/known-empty evidence is required.',409);
        $base=['invoice_id'=>$invoiceId,'source_sha256'=>$basis['source_sha256'],'evidence_sha256'=>$basis['evidence_sha256'],'admission_sha256'=>$basis['admission_sha256'],'invoice_version'=>$basis['invoice_version'],'command_key'=>$basis['command_key']??null,'revision'=>$revision];
        $rows=[['invoice_id'=>$invoiceId,'source_scope_key'=>'@header','row_kind'=>'HEADER','ordinal'=>0,'part_id'=>null,'part_version'=>null,'scope_state'=>$state,'start_date'=>null,'end_date'=>null]+array_diff_key($base,['invoice_id'=>true])];$keys=[];
        foreach($parts as $index=>$input){
            $key=BillingParts::text($input['source_scope_key']??null,180,false);
            if($key==='@header' || isset($keys[$key]))throw new RuntimeException('Part scope key conflicts with its unique header/body.',409);$keys[$key]=true;
            $part=BillingParts::version($pdo,$input['part_id'],BillingParts::partVersion($input['part_version']??null));
            $receipt=BillingPartAdmission::receipt($pdo,$basis['admission_sha256']);
            if(!in_array(BillingPartAdmission::identity($part['part']['source_namespace'],$part['part']['source_key']),$receipt['source_identity_sha256s'],true))throw new RuntimeException('Invoice part target is not admitted by its period proof.',409);
            $child=$input['scope_state']??null;
            $start=BillingParts::date($input['start_date']??null);$end=BillingParts::date($input['end_date']??null);
            if(!in_array($child,['KNOWN','PARTIAL','UNKNOWN'],true) || ($child==='KNOWN' && ($start===null || $end===null)) || ($state==='KNOWN' && $child!=='KNOWN') || ($start!==null && $end!==null && $end<$start))throw new RuntimeException('Part coverage interval/state differs from its evidence.',409);
            $rows[]=['invoice_id'=>$invoiceId,'source_scope_key'=>$key,'row_kind'=>'PART','ordinal'=>$index+1,'part_id'=>$part['part']['id'],'part_version'=>$part['part']['version'],'scope_state'=>$child,'start_date'=>$start,'end_date'=>$end]+array_diff_key($base,['invoice_id'=>true]);
        }
        return $rows;
    }

    private static function normalizeRows(array $rows): array
    {
        foreach($rows as &$row){foreach(['invoice_id','ordinal','invoice_version','revision'] as $field)$row[$field]=(int)$row[$field];if($row['part_version']!==null)$row['part_version']=(int)$row['part_version'];}unset($row);
        return $rows;
    }
}
