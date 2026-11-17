<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Compiles already selected source targets. It neither selects fees nor calculates money. */
final class BillingInvoiceTargets
{
    private const NAMESPACE='native_invoice_targets';

    public static function compile(array $terms,array $intervals,string $producerEvidence,bool $knownEmpty=false,string $classification='RENTAL'): array
    {
        if(!in_array($classification,['RENTAL','NOT_RENTAL_SECURITY'],true)||($classification==='NOT_RENTAL_SECURITY'&&$intervals!==[]))self::deny();
        $security=$classification==='NOT_RENTAL_SECURITY';
        if(!array_is_list($terms)||!array_is_list($intervals)||count($terms)>128||count($intervals)>512
            ||!preg_match('/^[a-f0-9]{64}$/D',$producerEvidence))self::deny();
        if(!$terms && (!$knownEmpty || $intervals))self::deny();
        if($terms && $knownEmpty)self::deny();
        $byKey=[];
        foreach($terms as $term){
            $key=$term['source_term_key']??null;
            if(!is_string($key)||$key===''||strlen($key)>180||isset($byKey[$key]))self::deny();
            $kind=$term['target_kind']??null;
            if($kind==='physical-object'){
                if(!is_int($term['object_id']??null)||$term['object_id']<1||($term['part_id']??null)!==null
                    ||($term['target_key']??null)!=='object:'.$term['object_id'])self::deny();
            }elseif($kind==='billing-part'){
                if(!$security)BillingParts::requireReady($term);
                if(!array_key_exists('object_id',$term)||$term['object_id']!==null||($term['physical_occupancy']??null)!==0
                    ||($term['type']??null)!=='LEGAL_ADDRESS'
                    ||!is_string($term['part_id']??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$term['part_id'])
                    ||($term['target_key']??null)!=='part:'.$term['part_id']
                    ||!is_int($term['part_version']??null)||$term['part_version']<1
                    ||($term['current_part_version']??null)!==$term['part_version'])self::deny();
                foreach(['evidence_sha256','admission_sha256','term_evidence_sha256','term_admission_sha256','current_part_evidence_sha256','current_part_admission_sha256'] as $field)
                    if(!is_string($term[$field]??null)||!preg_match('/^[a-f0-9]{64}$/D',$term[$field]))self::deny();
                if(!is_int($term['term_revision']??null)||$term['term_revision']<1)self::deny();
            }else self::deny();
            foreach(['start_date','end_date','amount'] as $field)if(!array_key_exists($field,$term))self::deny();
            foreach(['start_date','end_date'] as $field)if(!$security||$term[$field]!==null)self::date($term[$field]);
            if(($term['start_date']!==null&&$term['end_date']!==null&&$term['end_date']<$term['start_date'])
                ||(!($security&&$term['amount']===null)&&(!is_string($term['amount'])||!preg_match('/^-?[0-9]{1,18}(?:\.[0-9]{1,12})?$/D',$term['amount']))))self::deny();
            $byKey[$key]=$term;
        }
        $objects=[];$physical=[];$parts=[];$seen=[];$used=[];
        foreach($intervals as $interval){
            if(!is_array($interval)||array_diff(array_keys($interval),['source_term_key','start_date','end_date']))self::deny();
            $key=$interval['source_term_key']??null;
            if(!is_string($key)||!isset($byKey[$key]))self::deny();
            $term=$byKey[$key];$start=$interval['start_date']??null;$end=$interval['end_date']??null;
            self::date($start);self::date($end);
            if($start>$end||$start<$term['start_date']||$end>$term['end_date'])self::deny();
            $signature=Importer::canonicalJson([$key,$start,$end]);
            $targetInterval=Importer::canonicalJson([$term['target_key'],$start,$end]);
            if(isset($seen[$targetInterval]))self::deny();$seen[$targetInterval]=true;$used[$key]=true;
            if($term['target_kind']==='physical-object'){
                $objects[$term['object_id']]=$term['object_id'];
                $physical[]=['object_id'=>$term['object_id'],'start_date'=>$start,'end_date'=>$end];
            }else $parts[]=['source_scope_key'=>'selected:'.hash('sha256',$signature),'part_id'=>$term['part_id'],
                'part_version'=>$term['part_version'],'scope_state'=>'KNOWN','start_date'=>$start,'end_date'=>$end];
        }
        if(!$security&&count($used)!==count($byKey))self::deny();
        $objects=array_values($objects);sort($objects,SORT_NUMERIC);
        $targetKeys=array_values(array_unique(array_column($terms,'target_key')));sort($targetKeys,SORT_STRING);
        $body=['producer_evidence_sha256'=>$producerEvidence,'target_set_sha256'=>hash('sha256',Importer::canonicalJson($targetKeys)),'selected_terms'=>$terms,'selected_intervals'=>$intervals,
            'physical_objects'=>$objects,'physical_coverage'=>$physical,'physical_objects_state'=>'KNOWN','physical_periods_state'=>'KNOWN',
            'physical_empty_proven'=>$objects===[],'part_scope_state'=>'KNOWN','part_scope_rows'=>$parts,'part_empty_proven'=>$parts===[]];
        if($security)$body['classification']=$classification;
        return $body+['plan_sha256'=>hash('sha256',Importer::canonicalJson($body))];
    }

    /** Controlled NEW invoice INSERT metadata; this marker is relevance, not readiness or permission. */
    public static function producerMarker(array $plan,string $producer,string $termOwnerKind,int $termOwnerId): array
    {
        self::verifyPlan($plan);
        if(!in_array($producer,['initial','addendum'],true)||$termOwnerId<1
            ||$termOwnerKind!==($producer==='initial'?'contract':'document'))self::deny();
        return ['schema'=>1,'producer'=>$producer,'term_owner_kind'=>$termOwnerKind,'term_owner_id'=>$termOwnerId,'plan_sha256'=>$plan['plan_sha256']];
    }

    public static function record(PDO $pdo,int $invoiceId,array $plan,array $basis): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        BillingPartAdmission::transaction($pdo);self::verifyPlan($plan);
        BillingPartAdmission::anchor($pdo);
        $invoice=self::invoice($pdo,$invoiceId,true);
        if(($basis['invoice_version']??null)!==(int)$invoice['version'])self::deny();
        $binding=$pdo->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='invoice' AND entity_id=? AND relation_kind IN ('invoice_control','invoice_part_control') LIMIT 1 FOR UPDATE");$binding->execute([$invoiceId]);
        if($binding->fetchColumn()!==false)throw new RuntimeException('Imported invoice targets require separate source admission.',409);
        $owner=self::termOwner((int)$invoice['contract_id'],$basis);
        self::currentTerms($pdo,(int)$invoice['contract_id'],$plan,false,$owner);
        $document=self::document($invoiceId,(int)$invoice['contract_id'],$plan,$basis,BillingPartAdmission::selected());
        if($invoice['coverage_key']!==$document['coverage_key'])self::deny();
        self::producerInvoice($pdo,$invoice,$document);
        $query=$pdo->prepare('SELECT fact_json,operative FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE');$query->execute([self::NAMESPACE,(string)$invoiceId]);$old=$query->fetch(PDO::FETCH_ASSOC);
        if($old){if((int)$old['operative']!==0||Importer::canonicalJson(Importer::decodeExact($old['fact_json']))!==Importer::canonicalJson($document))throw new RuntimeException('Immutable invoice target plan conflicts.',409);return $document;}
        $pdo->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,0)')->execute([self::NAMESPACE,(string)$invoiceId,Importer::json($document)]);
        return $document;
    }

    /** Explicit typed-producer preflight before any domain INSERT; legacy callers omit this path. */
    public static function assertSelection(PDO $pdo,int $contractId,array $plan,?array $termOwner=null): void
    {
        if(BillingPartAdmission::selected()===null||$contractId<1)throw new RuntimeException('Typed invoice target admission is unavailable.',409);
        self::verifyPlan($plan);BillingPartAdmission::anchor($pdo);
        self::currentTerms($pdo,$contractId,$plan,false,$termOwner);
    }

    /** Neutral selected-invoice capture. Legacy physical invoices never query pending051 tables. */
    public static function snapshot(PDO $pdo,int $invoiceId): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        $invoice=self::invoice($pdo,$invoiceId,false);
        $query=$pdo->prepare('SELECT 1 FROM rental_facts WHERE namespace=? AND source_key=?');$query->execute([self::NAMESPACE,(string)$invoiceId]);
        $recorded=$query->fetchColumn()!==false;
        $key=$invoice['coverage_key']??'';
        $marker=self::invoiceMarker($invoice);
        $source=$pdo->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='invoice' AND entity_id=? AND relation_kind='invoice_part_control' LIMIT 1");$source->execute([$invoiceId]);
        if($source->fetchColumn()!==false){
            $snapshot=self::invoiceContext($invoiceId,$key,null,null,null);
            $snapshot['relevance']='source-invoice-part-control';
            $snapshot['runtime_hold']=$recorded||$marker!==null||str_starts_with($key,'billable-monthly:')?'INVOICE_TARGET_PROVENANCE_CONFLICT':'SOURCE_TARGET_PLAN_NOT_MATERIALIZED';
            return $snapshot;
        }
        if(!$recorded&&$marker===null&&!str_starts_with($key,'billable-monthly:'))return null;
        $receipt=$recorded?self::read($pdo,$invoiceId):null;
        if($receipt===null)return self::invoiceContext($invoiceId,$key,null,null,null);
        $current=self::selectedCurrentTerms($pdo,$receipt['owner_contract_id'],$receipt['plan']['selected_terms'],self::termOwner($receipt['owner_contract_id'],$receipt['basis']));
        return self::invoiceContext($invoiceId,$key,$receipt,$current,BillingPartScope::read($pdo,$invoiceId));
    }

    public static function requireReady(?array $snapshot): void
    {
        if($snapshot!==null&&(($snapshot['availability']??null)!=='KNOWN'||($snapshot['runtime_hold']??null)!==null))
            throw new RuntimeException('Selected invoice target evidence is incomplete or stale.',409);
    }

    public static function assertSnapshotCurrent(PDO $pdo,int $invoiceId,?array $frozen): ?array
    {
        $current=self::snapshot($pdo,$invoiceId);self::requireReady($current);
        if(!self::sameInvoiceContext($frozen,$current))throw new RuntimeException('Selected invoice target snapshot changed.',409);
        return $current;
    }

    /** Comparison projection only; store the original full snapshot as evidence. */
    public static function semanticSnapshot(?array $snapshot): ?array
    {
        if($snapshot!==null&&is_array($snapshot['current_terms']??null)){
            foreach($snapshot['current_terms'] as &$term)if(is_array($term))unset($term['record_version']);unset($term);
        }
        return $snapshot;
    }

    private static function invoiceContext(int $invoiceId,string $key,?array $receipt,?array $current,?array $scope): array
    {
        $body=['invoice_id'=>$invoiceId,'producer_key'=>$key,'receipt'=>$receipt,'current_terms'=>$current,'part_scope'=>$scope,
            'availability'=>'UNKNOWN','runtime_hold'=>'INVOICE_TARGET_PLAN_MISSING'];
        if($receipt===null)return $body;
        self::verifyPlan($receipt['plan']);
        if($current===null)return array_replace($body,['runtime_hold'=>'SELECTED_TARGET_TERM_MISSING']);
        $body['availability']=$scope['availability']??'UNKNOWN';
        $body['runtime_hold']=$scope['runtime_hold']??($scope===null?'PART_SCOPE_NOT_RECORDED':null);
        try{
            $plan=self::compile($current,$receipt['plan']['selected_intervals'],$receipt['plan']['producer_evidence_sha256'],$current===[],$receipt['plan']['classification']??'RENTAL');
            if(!self::sameTermsPlan($receipt['plan'],$plan))$body['runtime_hold']='SELECTED_TARGET_TERM_CHANGED';
        }catch(RuntimeException){$body['runtime_hold']='SELECTED_TARGET_TERM_NOT_READY';}
        if($scope!==null&&($scope['availability']??null)==='KNOWN'){
            $normalize=static function(array $rows):array{
                $rows=array_map(static fn($row)=>array_intersect_key($row,array_flip(['part_id','part_version','scope_state','start_date','end_date'])),$rows);
                usort($rows,static fn($a,$b)=>strcmp(Importer::canonicalJson($a),Importer::canonicalJson($b)));return $rows;
            };
            if(($scope['evidence_sha256']??null)!==$receipt['plan']['plan_sha256']||!is_array($scope['coverage']??null)
                ||Importer::canonicalJson($normalize($scope['coverage']))!==Importer::canonicalJson($normalize($receipt['plan']['part_scope_rows'])))
                $body['runtime_hold']='INVOICE_TARGET_SCOPE_CHANGED';
        }
        return $body;
    }

    /** Receipt remains byte-semantic evidence; only a current owner's workflow version is separate. */
    private static function sameInvoiceContext(?array $before,?array $after): bool
    {
        return Importer::canonicalJson(self::semanticSnapshot($before))===Importer::canonicalJson(self::semanticSnapshot($after));
    }

    /** Missing is not known-empty. Relevance must come from this namespace or a separate source proof. */
    public static function read(PDO $pdo,int $invoiceId): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        BillingPartAdmission::anchor($pdo);
        if($invoiceId<1)self::deny();
        $query=$pdo->prepare('SELECT fact_json,operative FROM rental_facts WHERE namespace=? AND source_key=?');$query->execute([self::NAMESPACE,(string)$invoiceId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        if((int)$row['operative']!==0)self::deny();
        $value=Importer::decodeExact($row['fact_json']);
        if(!is_array($value)||!is_array($value['plan']??null)||!is_array($value['basis']??null))self::deny();
        $expected=self::document($invoiceId,$value['owner_contract_id']??0,$value['plan'],$value['basis'],$value['admission_sha256']??'');
        if(Importer::canonicalJson($value)!==Importer::canonicalJson($expected))self::deny();
        BillingPartAdmission::receipt($pdo,$expected['admission_sha256']);
        $invoice=self::invoice($pdo,$invoiceId,false);
        if((int)$invoice['contract_id']!==$expected['owner_contract_id']||$invoice['coverage_key']!==$expected['coverage_key'])self::deny();
        self::producerInvoice($pdo,$invoice,$expected);
        $binding=$pdo->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='invoice' AND entity_id=? AND relation_kind IN ('invoice_control','invoice_part_control') LIMIT 1");$binding->execute([$invoiceId]);
        if($binding->fetchColumn()!==false)throw new RuntimeException('Native target plan conflicts with imported source authority.',409);
        return $expected;
    }

    public static function assertCurrent(PDO $pdo,int $invoiceId,?array $frozen,array $currentPlan): ?array
    {
        $current=self::read($pdo,$invoiceId);
        if($current===null&&$frozen===null&&BillingPartAdmission::selected()===null)return null;
        if($current===null||$frozen===null||Importer::canonicalJson($current)!==Importer::canonicalJson($frozen))throw new RuntimeException('Invoice producer plan changed or is unavailable.',409);
        self::verifyPlan($currentPlan);
        self::currentTerms($pdo,$current['owner_contract_id'],$currentPlan,true,self::termOwner($current['owner_contract_id'],$current['basis']));
        if(!self::sameTermsPlan($current['plan'],$currentPlan))throw new RuntimeException('Invoice selected term or part proof changed.',409);
        return $current;
    }

    private static function document(int $invoiceId,int $contractId,array $plan,array $basis,string $admission): array
    {
        self::verifyPlan($plan);BillingPartAdmission::sha($admission);
        $fields=['source_sha256','source_version','producer','source_identity','invoice_version','coverage_key'];
        $newProducer=in_array($basis['producer']??null,['initial','addendum'],true);
        if($newProducer)$fields=[...$fields,'term_owner_kind','term_owner_id'];
        if($invoiceId<1||$contractId<1||array_diff(array_keys($basis),$fields)||array_diff($fields,array_keys($basis))
            ||$basis['source_sha256']!==BillingPartAdmission::NATIVE_SOURCE||!in_array($basis['producer'],['monthly','initial','addendum'],true)
            ||!is_int($basis['invoice_version'])||$basis['invoice_version']<1)self::deny();
        foreach(['source_version','source_identity'] as $key)if(!is_string($basis[$key])||!preg_match('/^[A-Za-z0-9._:\\/-]{1,100}$/D',$basis[$key]))self::deny();
        if(!is_string($basis['coverage_key'])||$basis['coverage_key']===''||strlen($basis['coverage_key'])>180||str_contains($basis['coverage_key'],"\0"))self::deny();
        if(!$newProducer&&(!str_starts_with($basis['coverage_key'],'billable-monthly:')
            ||!str_ends_with($basis['coverage_key'],':'.$plan['target_set_sha256'])||($plan['classification']??'RENTAL')!=='RENTAL'))self::deny();
        $owner=self::termOwner($contractId,$basis);
        $body=['schema'=>1,'kind'=>'native-invoice-target-plan','project_id'=>OperatingScopeArtifact::PROJECT,'invoice_id'=>$invoiceId,'owner_contract_id'=>$contractId,
            'admission_sha256'=>$admission,'coverage_key'=>$basis['coverage_key'],'basis'=>$basis,'plan'=>$plan,'contract_id'=>null,'money_mutation'=>false,'physical_mutation'=>false];
        if($newProducer){$body['schema']=2;$body['term_owner']=$owner;}
        return $body+['receipt_sha256'=>hash('sha256',Importer::canonicalJson($body))];
    }

    private static function verifyPlan(array $plan): void
    {
        $terms=$plan['selected_terms']??null;$intervals=$plan['selected_intervals']??null;$proof=$plan['producer_evidence_sha256']??null;
        if(!is_array($terms)||!is_array($intervals)||!is_string($proof))self::deny();
        if(Importer::canonicalJson(self::compile($terms,$intervals,$proof,$terms===[],$plan['classification']??'RENTAL'))!==Importer::canonicalJson($plan))self::deny();
    }

    private static function currentTerms(PDO $pdo,int $contractId,array $plan,bool $semantic=false,?array $termOwner=null): void
    {
        $actual=self::selectedCurrentTerms($pdo,$contractId,$plan['selected_terms'],$termOwner);
        if($actual===null)throw new RuntimeException('Current selected billable term proof is missing.',409);
        foreach($plan['selected_terms'] as $index=>$term){
            $current=$actual[$index];
            if($semantic){unset($term['record_version'],$current['record_version']);}
            if($current===null||Importer::canonicalJson($current)!==Importer::canonicalJson($term))throw new RuntimeException('Current selected billable term proof differs.',409);
        }
    }

    private static function selectedCurrentTerms(PDO $pdo,int $contractId,array $frozenTerms,?array $termOwner=null): ?array
    {
        $termOwner??=['kind'=>'contract','id'=>$contractId];self::validateTermOwner($pdo,$contractId,$termOwner);
        $terms=$termOwner['kind']==='contract'?BillableTerms::forContract($pdo,$contractId):BillableTerms::forDocument($pdo,$termOwner['id']);
        $all=[];foreach($terms as $term)$all[$term['source_term_key']]=$term;
        $result=[];foreach($frozenTerms as $term){
            if(!isset($all[$term['source_term_key']]))return null;
            $result[]=$all[$term['source_term_key']];
        }
        return $result;
    }

    /** Owner command CAS remains caller authority; only its workflow version is not a term proof. */
    private static function sameTermsPlan(array $before,array $after): bool
    {
        foreach([&$before,&$after] as &$plan){
            unset($plan['plan_sha256']);
            foreach($plan['selected_terms'] as &$term)unset($term['record_version']);unset($term);
        }unset($plan);
        return Importer::canonicalJson($before)===Importer::canonicalJson($after);
    }

    private static function invoice(PDO $pdo,int $id,bool $lock): array
    {
        if($id<1)self::deny();$query=$pdo->prepare('SELECT id,contract_id,version,coverage_key,kind,document_id,planning_document_id,source_json FROM invoices WHERE id=?'.($lock?' FOR UPDATE':''));$query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Invoice target owner is missing.',409);
    }

    private static function termOwner(int $contractId,array $basis): array
    {
        if(($basis['producer']??null)==='monthly')return ['kind'=>'contract','id'=>$contractId];
        self::producerMarkerOwner($basis['producer']??'', $basis['term_owner_kind']??'', $basis['term_owner_id']??null);
        $owner=['kind'=>$basis['term_owner_kind'],'id'=>$basis['term_owner_id']];
        if($owner['kind']==='contract'&&$owner['id']!==$contractId)self::deny();
        return $owner;
    }

    private static function producerMarkerOwner(string $producer,string $kind,mixed $id): void
    {
        if(!in_array($producer,['initial','addendum'],true)||!is_int($id)||$id<1||$kind!==($producer==='initial'?'contract':'document'))self::deny();
    }

    private static function validateTermOwner(PDO $pdo,int $contractId,array $owner): void
    {
        if(array_diff(array_keys($owner),['kind','id'])||count($owner)!==2||!is_int($owner['id']??null)||$owner['id']<1)self::deny();
        if(($owner['kind']??null)==='contract'){if($owner['id']!==$contractId)self::deny();return;}
        if(($owner['kind']??null)!=='document')self::deny();
        $query=$pdo->prepare('SELECT contract_id FROM rental_documents WHERE id=?');$query->execute([$owner['id']]);
        if((int)$query->fetchColumn()!==$contractId)throw new RuntimeException('Invoice term owner belongs to another contract.',409);
    }

    private static function invoiceMarker(array $invoice): ?array
    {
        $source=Importer::decodeExact($invoice['source_json']??'{}');
        if(!is_array($source)||!array_key_exists('native_invoice_targets',$source))return null;
        $marker=$source['native_invoice_targets'];
        if(!is_array($marker))return ['invalid_marker'=>true];
        return $marker;
    }

    private static function producerInvoice(PDO $pdo,array $invoice,array $receipt): void
    {
        $security=($receipt['plan']['classification']??'RENTAL')==='NOT_RENTAL_SECURITY';
        if($security!==($invoice['kind']==='Обеспечительный платёж'))throw new RuntimeException('Invoice purpose differs from the explicit target plan.',409);
        if($receipt['schema']===1){if(self::invoiceMarker($invoice)!==null)throw new RuntimeException('Legacy monthly plan conflicts with a new producer marker.',409);return;}
        $basis=$receipt['basis'];$owner=$receipt['term_owner'];self::validateTermOwner($pdo,(int)$invoice['contract_id'],$owner);
        $expected=self::producerMarker($receipt['plan'],$basis['producer'],$owner['kind'],$owner['id']);
        if(Importer::canonicalJson(self::invoiceMarker($invoice))!==Importer::canonicalJson($expected))throw new RuntimeException('New invoice producer marker differs from its immutable target receipt.',409);
        $document=$invoice['document_id']===null?null:(int)$invoice['document_id'];$planning=$invoice['planning_document_id']===null?null:(int)$invoice['planning_document_id'];
        if($owner['kind']==='contract'){
            if($document!==null||$planning!==null)self::deny();
        }elseif(($document!==$owner['id']&&$planning!==$owner['id'])
            ||($document!==null&&$document!==$owner['id'])||($planning!==null&&$planning!==$owner['id']))self::deny();
    }

    private static function date(mixed $value): void
    {
        $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if(!$date||$date->format('Y-m-d')!==$value)self::deny();
    }

    private static function deny(): never
    {
        throw new RuntimeException('Selected invoice targets lack exact dated source/part evidence.',409);
    }
}
