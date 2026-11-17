<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;
use Modules\Rental\Importer;

/** Part-only dated term storage. Existing rental domain writers retain all business authority. */
final class BillingPartTerms
{
    private static ?\WeakMap $edits=null;
    private const OWNER_FACT='billing_part_owner';
    private const TERMS_FACT='billing_part_terms';

    /** Relevance is an actual technical binding, never the activation flag or an empty table. */
    public static function state(PDO $pdo,string $kind,int $id): ?array
    {
        if(BillingPartAdmission::selected()===null)return null;
        self::table($kind);if($id<1)throw new RuntimeException('Invalid part owner identity.',409);
        $key=$kind.':'.$id;
        $query=$pdo->prepare('SELECT namespace,source_key,fact_json,operative FROM rental_facts WHERE (namespace=? AND source_key=?) OR (namespace=? AND source_key LIKE ?) LIMIT 4097');
        $query->execute([self::OWNER_FACT,$key,self::TERMS_FACT,$key.':%']);$facts=$query->fetchAll(PDO::FETCH_ASSOC);
        if($facts===[])return null; // No pending051 query for unrelated physical legacy.
        try{
            if(count($facts)>4096)throw new RuntimeException('Part branch receipt history exceeds its bounded reader.',409);
            $marker=null;$receipts=[];
            foreach($facts as $fact){
                if((int)$fact['operative']!==0)throw new RuntimeException('Part branch receipt is not technical-only.',409);
                $body=Importer::decodeExact($fact['fact_json']);
                if($fact['namespace']===self::OWNER_FACT){
                    if($marker!==null||Importer::canonicalJson($body)!==Importer::canonicalJson(self::ownerMarker($kind,$id)))throw new RuntimeException('Part owner marker differs.',409);
                    $marker=$body;
                }else{
                    if(($body['owner_kind']??null)!==$kind||($body['owner_id']??null)!==$id||$fact['source_key']!==$key.':'.hash('sha256',(string)($body['command_identity']??'')))throw new RuntimeException('Part branch command identity differs.',409);
                    $receipts[]=$body;
                }
            }
            if($marker===null||$receipts===[])throw new RuntimeException('Part branch marker/receipt pair is incomplete.',409);
            $latest=self::receiptChain($kind,$id,$receipts);
            $rows=self::raw($pdo,$kind,$id);
            if(!hash_equals($latest['rows_sha256'],OperatingScopeArtifact::hash($rows))||$latest['known_empty']!==($rows===[]))throw new RuntimeException('Part branch raw terms differ from their receipt.',409);
            $proofs=[];
            foreach($rows as $row){self::normalize($pdo,$kind,$id,$row,(int)$row['revision']);$proofs[]=self::rowProof($row);}
            if(Importer::canonicalJson($proofs)!==Importer::canonicalJson($latest['term_proofs']))throw new RuntimeException('Part branch source/admission/revisions differ.',409);
            $priceProofs=self::rawPriceProofs($kind,$rows,$latest['writer']['validated_raw_terms']??null,$latest['writer']['raw_terms_origin']??null);
            if(isset($latest['writer']['raw_terms_origin']))self::rawPriceOrigin($pdo,$kind,$id,$latest['writer']['raw_terms_origin'],$priceProofs);
            $state=['state'=>'KNOWN','owner_kind'=>$kind,'owner_id'=>$id,'known_empty'=>$rows===[],
                'rows_sha256'=>$latest['rows_sha256'],'receipt_sha256'=>OperatingScopeArtifact::hash($latest),'receipt'=>$latest,'rows'=>$rows];
            if(array_key_exists('validated_raw_terms',$latest['writer']))$state['validated_zero_term_keys']=array_keys(array_filter($priceProofs,static fn($proof)=>bccomp($proof['rounded_amount'],'0',2)===0));
            return $state;
        }catch(RuntimeException $error){
            return ['state'=>'UNKNOWN','owner_kind'=>$kind,'owner_id'=>$id,'known_empty'=>false,'reason'=>$error->getMessage()];
        }
    }

    /** Trusted native create branch only. Freeze absent identity BEFORE its actual INSERT. */
    public static function beginCreation(PDO $pdo,string $kind,int $id,array $basis): ?object
    {
        if(BillingPartAdmission::selected()===null)return null;
        BillingPartAdmission::transaction($pdo);BillingPartAdmission::anchor($pdo);
        self::creationBasis($kind,$id,$basis);
        $table=$kind==='contract'?'contracts':'rental_documents';
        $query=$pdo->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE');$query->execute([$id]);
        if($query->fetchColumn()!==false)throw new RuntimeException('Part creation identity already exists.',409);
        self::requireUnboundCreation($pdo,$kind,$id);
        $parentVersion=null;$parentBranch=null;
        if($kind==='document'){
            $parent=self::owner($pdo,'contract',$basis['parent_contract_id']);
            if($parent['status']!=='active')throw new RuntimeException('Part creation parent is not active.',409);
            $parentVersion=(int)$parent['version'];
            $parentBranch=self::state($pdo,'contract',$basis['parent_contract_id']);
        }
        $savepoint='part_create_'.bin2hex(random_bytes(12));$pdo->exec('SAVEPOINT '.$savepoint);
        $token=new \stdClass();self::$edits??=new \WeakMap();
        self::$edits[$token]=['pdo'=>$pdo,'kind'=>$kind,'id'=>$id,'version'=>0,'before'=>[],
            'current_sha256'=>OperatingScopeArtifact::hash([]),'savepoint'=>$savepoint,'creation_basis'=>$basis,
            'parent_version'=>$parentVersion,'creation_saved'=>false,'empty_replacement_basis'=>$parentBranch];
        return $token;
    }

    public static function raw(PDO $pdo,string $kind,int $id,bool $lock=false): array
    {
        $table=self::table($kind);
        $query=$pdo->prepare('SELECT * FROM '.$table.' WHERE '.$kind.'_id=? ORDER BY id'.($lock?' FOR UPDATE':''));$query->execute([$id]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Capture BEFORE the caller changes a returned DS to LEGAL or increments its version. */
    public static function beginEdit(PDO $pdo,string $kind,int $id,int $version): object
    {
        BillingPartAdmission::transaction($pdo);
        if(BillingPartAdmission::anchor($pdo)===null)throw new RuntimeException('Part editing is not activated.',409);
        $owner=self::owner($pdo,$kind,$id);
        if((int)$owner['version']!==$version)throw new RuntimeException('Part owner version CAS failed.',409);
        if($kind==='document' && ((int)$owner['applied']!==0 || $owner['parent_status']!=='active' || !($owner['status']==='draft' || ($owner['status']==='returned' && $owner['return_to']==='manager'))))throw new RuntimeException('Historical or noneditable DS part terms are immutable.',409);
        $before=self::raw($pdo,$kind,$id,true);$savepoint='part_terms_'.bin2hex(random_bytes(12));$pdo->exec('SAVEPOINT '.$savepoint);
        $token=new \stdClass();self::$edits??=new \WeakMap();
        self::$edits[$token]=['pdo'=>$pdo,'kind'=>$kind,'id'=>$id,'version'=>$version,'before'=>$before,'current_sha256'=>OperatingScopeArtifact::hash($before),'savepoint'=>$savepoint,'empty_replacement_basis'=>self::state($pdo,$kind,$id)];
        return $token;
    }

    /** Caller archives this in its existing contract/document history before saving. */
    public static function before(object $token): array
    {
        $context=self::$edits[$token]??null;
        if(!$context)throw new RuntimeException('Part edit context is missing.',409);
        return ['rows'=>$context['before'],'sha256'=>OperatingScopeArtifact::hash($context['before'])];
    }

    public static function finish(PDO $pdo,object $token): void
    {
        $context=self::$edits[$token]??null;
        if(!$context || $context['pdo']!==$pdo || !$pdo->inTransaction())throw new RuntimeException('Part edit context belongs to another transaction.',409);
        $pdo->exec('RELEASE SAVEPOINT '.$context['savepoint']);unset(self::$edits[$token]);
    }

    /** Native full replacement of ONLY the part branch; physical contract/document terms stay intact. */
    public static function save(PDO $pdo,string $kind,int $id,array $desired,array $basis,object $token,bool $preserveFrozen=false): array
    {
        BillingPartAdmission::transaction($pdo);
        try {
            $context=self::$edits[$token]??null;
            if(!$context || $context['pdo']!==$pdo || $context['kind']!==$kind || $context['id']!==$id)throw new RuntimeException('Exact part edit context required.',409);
            if(($context['creation_saved']??false)===true)throw new RuntimeException('Part creation ticket is single use.',409);
            $pdo->exec('RELEASE SAVEPOINT '.$context['savepoint']);$pdo->exec('SAVEPOINT '.$context['savepoint']);
            $owner=self::owner($pdo,$kind,$id);
            if(isset($context['creation_basis'])){
                self::creationOwner($kind,$owner,$context['creation_basis'],$basis,$context['parent_version']);
                self::requireUnboundCreation($pdo,$kind,$id);
            }
            if(!in_array((int)$owner['version'],[$context['version'],$context['version']+1],true) || ($basis['record_version']??null)!==(int)$owner['version'] || ($basis['history_before_sha256']??null)!==OperatingScopeArtifact::hash($context['before']))throw new RuntimeException('Part owner/history preimage differs.',409);
            self::nativeBasis($basis);
            if(!isset($context['creation_basis'])&&($context['empty_replacement_basis']['state']??null)==='UNKNOWN')throw new RuntimeException('Existing part branch proof is unknown; native save cannot repair it.',409);
            $old=self::raw($pdo,$kind,$id,true);
            if(OperatingScopeArtifact::hash($old)!==$context['current_sha256'])throw new RuntimeException('Part term set changed since its edit snapshot.',409);
            if(!array_is_list($desired) || count($desired)>128)throw new RuntimeException('Bounded ordered part terms required.',409);
            $emptyProof=self::emptyReplacementProof($desired,$old,$basis,$context['empty_replacement_basis']??null);
            $priceProofs=self::rawPriceProofs($kind,$desired,$basis['validated_raw_terms']??null,$context['application_raw_source']??null);
            $byKey=[];foreach($old as $row)$byKey[$row['source_term_key']]=$row;
            $keys=[];$keep=[];$result=[];
            foreach($desired as $input){
                $key=BillingParts::text($input['source_term_key']??null,180,false);
                if(isset($keys[$key]))throw new RuntimeException('Duplicate part term source key.',409);$keys[$key]=true;
                $existing=$byKey[$key]??null;
                $input['id']=$existing?(int)$existing['id']:self::nextId($pdo,$kind);
                $input['origin']=$kind==='contract'?'native-c-contract':'native-c-document';$input['source_sha256']=BillingPartAdmission::NATIVE_SOURCE;$input['command_key']=$basis['command_key'];
                $input['evidence_sha256']=OperatingScopeArtifact::hash(['owner_kind'=>$kind,'owner_id'=>$id,'input'=>$input,'basis'=>$basis,'previous'=>$existing]);
                $row=self::normalize($pdo,$kind,$id,$input,$existing?(int)$existing['revision']+1:1);
                $frozen=BillingParts::version($pdo,$row['part_id'],$row['part_version'],BillingPartAdmission::anchor($pdo));
                if(!$preserveFrozen && $frozen['current_version']!==$row['part_version'])throw new RuntimeException('Native term edit must select the current admitted part version explicitly.',409);
                if($existing){
                    $columns=array_diff(array_keys($row),['id',$kind.'_id','source_term_key']);$set=array_map(static fn($column)=>$column.'=?',$columns);
                    $query=$pdo->prepare('UPDATE '.self::table($kind).' SET '.implode(',',$set).' WHERE id=? AND revision=?');$query->execute([...array_map(static fn($column)=>$row[$column],$columns),$row['id'],$existing['revision']]);
                    if($query->rowCount()!==1)throw new RuntimeException('Part dated term revision CAS failed.',409);
                }else BillingParts::insert($pdo,self::table($kind),$row);
                $keep[]=$row['id'];$result[]=$row;
            }
            foreach($old as $row)if(!in_array((int)$row['id'],$keep,true))$pdo->prepare('DELETE FROM '.self::table($kind).' WHERE id=? AND revision=?')->execute([$row['id'],$row['revision']]);
            $actual=self::raw($pdo,$kind,$id);$context['current_sha256']=OperatingScopeArtifact::hash($actual);
            $writer=[
                'origin'=>'native','source_rule_sha256'=>$basis['source_rule_sha256'],'actor_id'=>$basis['actor_id'],
                'source_owner_version'=>(int)$owner['version'],'empty_replacement_proof'=>$emptyProof];
            if($priceProofs!==[])$writer['validated_raw_terms']=$priceProofs;
            if(isset($context['application_raw_source']))$writer['raw_terms_origin']=$context['application_raw_source'];
            if($actual!==[]||$emptyProof!==null)self::recordBranch($pdo,$kind,$id,$actual,'native:'.$basis['command_key'],$writer);
            if(isset($context['creation_basis']))$context['creation_saved']=true;
            self::$edits[$token]=$context;
            return $result;
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    /** Initial source projection is append/exact-NOOP only, never source-history replacement. */
    public static function putSource(PDO $pdo,string $kind,int $id,array $input): array
    {
        BillingPartAdmission::transaction($pdo);$owner=self::owner($pdo,$kind,$id);
        if((self::state($pdo,$kind,$id)['state']??null)==='UNKNOWN')throw new RuntimeException('Existing part branch proof is unknown; source append cannot repair it.',409);
        $input['origin']='source-admitted';$input['command_key']=null;
        $row=self::normalize($pdo,$kind,$id,$input,1);
        $query=$pdo->prepare('SELECT * FROM '.self::table($kind).' WHERE '.$kind.'_id=? AND source_term_key=? FOR UPDATE');$query->execute([$id,$row['source_term_key']]);$old=$query->fetch(PDO::FETCH_ASSOC);
        if($old){$actual=self::normalize($pdo,$kind,$id,$old,(int)$old['revision']);if($actual!==$row)throw new RuntimeException('Existing source part terms differ; replacement is forbidden.',409);return $actual;}
        BillingParts::insert($pdo,self::table($kind),$row);
        self::recordBranch($pdo,$kind,$id,self::raw($pdo,$kind,$id),'source:'.OperatingScopeArtifact::hash($row),[
            'origin'=>'source-admitted','source_rule_sha256'=>$row['source_sha256'],'actor_id'=>null,
            'source_owner_version'=>(int)$owner['version'],'empty_replacement_proof'=>null]);
        return $row;
    }

    public static function snapshotDocument(PDO $pdo,int $documentId,int $contractId,array $basis,object $documentEdit): array
    {
        $desired=[];
        foreach(self::raw($pdo,'contract',$contractId) as $row){$row['source_term_key']='snapshot:'.$row['id'];$desired[]=$row;}
        return self::save($pdo,'document',$documentId,$desired,$basis,$documentEdit,true);
    }

    /** Caller has checked signature/integrity/rights; invoke from its literal sign/apply branch. */
    public static function applyDocument(PDO $pdo,int $documentId,int $contractId,int $documentVersion,int $contractVersion,array $basis,string $applicationAction): array
    {
        BillingPartAdmission::transaction($pdo);
        $document=self::owner($pdo,'document',$documentId);
        if((int)$document['contract_id']!==$contractId || (int)$document['version']!==$documentVersion)throw new RuntimeException('DS part application owner/version differs.',409);
        self::applicationState($document,$applicationAction,date('Y-m-d'));
        $branch=self::state($pdo,'document',$documentId);
        if(($branch['state']??null)!=='KNOWN')throw new RuntimeException('DS part branch is not proven, including its explicit empty replacement.',409);
        foreach(BillingParts::terms($pdo,'document',$documentId) as $dto)BillingParts::requireReady($dto);
        $token=self::beginEdit($pdo,'contract',$contractId,$contractVersion);
        $before=self::before($token);
        if(($basis['history_before_sha256']??null)!==$before['sha256'])throw new RuntimeException('Archive the exact current contract part terms before DS application.',409);
        $desired=[];
        $rawProofs=[];unset($basis['validated_raw_terms']);
        foreach(self::raw($pdo,'document',$documentId) as $row){
            $originalKey=$row['source_term_key'];$row['source_term_key']='ds:'.$documentId.':'.hash('sha256',$originalKey);$desired[]=$row;
            if(isset($branch['receipt']['writer']['validated_raw_terms'][$originalKey]))$rawProofs[$row['source_term_key']]=$branch['receipt']['writer']['validated_raw_terms'][$originalKey];
        }
        if($rawProofs!==[]){
            $context=self::$edits[$token];$context['application_raw_source']=['owner_kind'=>'document','owner_id'=>$documentId,'receipt_sha256'=>$branch['receipt_sha256']];self::$edits[$token]=$context;
            $basis['validated_raw_terms']=$rawProofs;
        }
        if($desired===[])$basis['replace_part_branch']=true;
        $result=self::save($pdo,'contract',$contractId,$desired,$basis,$token);self::finish($pdo,$token);
        return ['before'=>$before,'terms'=>$result];
    }

    /** Pure actual source state predicate; today comes from the configured application PHP clock. */
    private static function applicationState(array $document,string $action,string $today): void
    {
        $status=match($action){'document.sign'=>'sent','document.apply'=>'signed',default=>null};
        $effective=BillingParts::date($document['effective_date']??null);
        if($status===null||($document['status']??null)!==$status||($document['kind']??null)!=='renewal'
            ||(int)($document['applied']??1)!==0||($document['parent_status']??null)!=='active'
            ||$effective===null||$effective>$today)throw new RuntimeException('DS part application requires its actual signed/effective source branch.',409);
    }

    private static function ownerMarker(string $kind,int $id): array
    {
        return ['schema'=>1,'project_id'=>OperatingScopeArtifact::PROJECT,'kind'=>'billing-part-owner',
            'owner_kind'=>$kind,'owner_id'=>$id,'contract_id'=>null,'money_mutation'=>false];
    }

    private static function rowProof(array $row): array
    {
        $proof=[];foreach(['id','part_id','part_version','source_term_key','origin','source_sha256','evidence_sha256','admission_sha256','revision'] as $field)$proof[$field]=$row[$field];
        return $proof;
    }

    /** Validated native input evidence, not a blanket exemption for stored/source zero amounts. */
    private static function rawPriceProofs(string $kind,array $rows,mixed $proofs,?array $origin=null): array
    {
        if($proofs===null)return [];
        if(!is_array($proofs)||count($proofs)>128)throw new RuntimeException('Bounded raw part-price proof is required.',409);
        $expectedKind=$origin===null?$kind:'document';
        if($origin!==null&&($kind!=='contract'||array_diff(array_keys($origin),['owner_kind','owner_id','receipt_sha256'])||count($origin)!==3
            ||($origin['owner_kind']??null)!=='document'||!is_int($origin['owner_id']??null)||$origin['owner_id']<1))throw new RuntimeException('Raw part-price application origin differs.',409);
        if($origin!==null)BillingPartAdmission::sha($origin['receipt_sha256']);
        $amounts=[];foreach($rows as $row)$amounts[$row['source_term_key']]=$row['amount'];
        foreach($proofs as $key=>$proof){
            if(!is_string($key)||!array_key_exists($key,$amounts)||!is_array($proof)||array_diff(array_keys($proof),['raw_amount','rounded_amount','price_kind'])||count($proof)!==3
                ||($proof['price_kind']??null)!==$expectedKind||!is_string($proof['raw_amount']??null)
                ||!preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,12})?$/D',$proof['raw_amount'])||bccomp($proof['raw_amount'],'0',12)<=0
                ||!is_string($proof['rounded_amount']??null)||!preg_match('/^[0-9]{1,18}\.[0-9]{2}$/D',$proof['rounded_amount'])
                ||bcadd($proof['raw_amount'],'0.005',2)!==$proof['rounded_amount']||$amounts[$key]!==$proof['rounded_amount']
                ||($expectedKind==='contract'&&bccomp($proof['rounded_amount'],'0',2)<=0))throw new RuntimeException('Raw part-price proof does not match its validated native branch and stored amount.',409);
        }
        return $proofs;
    }

    private static function rawPriceOrigin(PDO $pdo,string $kind,int $id,array $origin,array $proofs): void
    {
        if($kind!=='contract'||$proofs===[])throw new RuntimeException('Raw part-price source is not an actual DS application.',409);
        $query=$pdo->prepare('SELECT contract_id FROM rental_documents WHERE id=?');$query->execute([$origin['owner_id']]);
        if((int)$query->fetchColumn()!==$id)throw new RuntimeException('Applied raw part-price document belongs to another contract.',409);
        $source=self::state($pdo,'document',$origin['owner_id']);
        if(($source['state']??null)!=='KNOWN'||($source['receipt_sha256']??null)!==$origin['receipt_sha256'])throw new RuntimeException('Applied raw part-price document receipt changed or is missing.',409);
        $expected=[];
        foreach($source['receipt']['writer']['validated_raw_terms']??[] as $key=>$proof)$expected['ds:'.$origin['owner_id'].':'.hash('sha256',$key)]=$proof;
        if(Importer::canonicalJson($proofs)!==Importer::canonicalJson($expected))throw new RuntimeException('Applied raw part-price keys differ from their signed source receipt.',409);
    }

    /** An empty client section alone is never evidence of an existing part obligation. */
    private static function emptyReplacementProof(array $desired,array $old,array $basis,?array $prior): ?array
    {
        if($desired!==[])return null;
        if(($basis['replace_part_branch']??false)===true&&($prior['state']??null)==='KNOWN'){
            return ['owner_kind'=>$prior['owner_kind'],'owner_id'=>$prior['owner_id'],
                'receipt_sha256'=>$prior['receipt_sha256'],'rows_sha256'=>$prior['rows_sha256']];
        }
        if($old!==[])throw new RuntimeException('Empty part replacement requires its exact known prior branch and trusted explicit mutation.',409);
        return null;
    }

    /** Latest is the unique complete serialized hash chain, never MAX(fact id) or wall clock. */
    private static function receiptChain(string $kind,int $id,array $receipts): array
    {
        if($receipts===[]||count($receipts)>4095)throw new RuntimeException('Part receipt chain is missing or unbounded.',409);
        $byRevision=[];
        foreach($receipts as $receipt){
            $keys=['schema','project_id','kind','owner_kind','owner_id','branch_revision','previous_receipt_sha256','command_identity','rows_sha256','known_empty','term_proofs','writer','contract_id','money_mutation'];
            if(array_diff(array_keys($receipt),$keys)||array_diff($keys,array_keys($receipt))||$receipt['schema']!==1
                ||$receipt['project_id']!==OperatingScopeArtifact::PROJECT||$receipt['kind']!=='billing-part-terms'
                ||$receipt['owner_kind']!==$kind||$receipt['owner_id']!==$id||!is_int($receipt['branch_revision'])||$receipt['branch_revision']<1
                ||isset($byRevision[$receipt['branch_revision']])||!is_string($receipt['command_identity'])||$receipt['command_identity']===''
                ||!is_bool($receipt['known_empty'])||!is_array($receipt['term_proofs'])||!array_is_list($receipt['term_proofs'])
                ||$receipt['known_empty']!==($receipt['term_proofs']===[])||$receipt['contract_id']!==null||$receipt['money_mutation']!==false)throw new RuntimeException('Part receipt chain identity/revision differs.',409);
            BillingPartAdmission::sha($receipt['rows_sha256']);
            if(!is_array($receipt['writer'])||!in_array($receipt['writer']['origin']??null,['native','source-admitted'],true)
                ||!is_int($receipt['writer']['source_owner_version']??null)||$receipt['writer']['source_owner_version']<1)throw new RuntimeException('Part receipt writer proof differs.',409);
            if($receipt['writer']['origin']!=='native'&&(array_key_exists('validated_raw_terms',$receipt['writer'])||array_key_exists('raw_terms_origin',$receipt['writer'])))throw new RuntimeException('Raw positive-price proof must come from the actual native writer.',409);
            BillingPartAdmission::sha($receipt['writer']['source_rule_sha256']??null);
            if($receipt['writer']['origin']==='native'&&($receipt['writer']['source_rule_sha256']!==BillingPartAdmission::NATIVE_SOURCE
                ||!is_int($receipt['writer']['actor_id']??null)||$receipt['writer']['actor_id']<1))throw new RuntimeException('Part native receipt writer differs.',409);
            if($receipt['known_empty']){
                $proof=$receipt['writer']['empty_replacement_proof']??null;
                $proofKeys=['owner_kind','owner_id','receipt_sha256','rows_sha256'];
                if(!is_array($proof)||$receipt['writer']['origin']!=='native'||array_diff(array_keys($proof),$proofKeys)||array_diff($proofKeys,array_keys($proof))
                    ||!in_array($proof['owner_kind'],['contract','document'],true)||!is_int($proof['owner_id'])||$proof['owner_id']<1
                    ||($proof['owner_kind']!==$kind||$proof['owner_id']!==$id)&&!($kind==='document'&&$proof['owner_kind']==='contract'))throw new RuntimeException('Known-empty part receipt lacks previous branch evidence.',409);
                BillingPartAdmission::sha($proof['receipt_sha256']);BillingPartAdmission::sha($proof['rows_sha256']);
            }
            $byRevision[$receipt['branch_revision']]=$receipt;
        }
        $previous=null;$latest=null;
        for($revision=1;$revision<=count($byRevision);$revision++){
            $receipt=$byRevision[$revision]??null;
            if($receipt===null||$receipt['previous_receipt_sha256']!==$previous)throw new RuntimeException('Part receipt chain has a gap or fork.',409);
            if($receipt['known_empty']){
                $proof=$receipt['writer']['empty_replacement_proof'];
                if($proof['owner_kind']===$kind&&$proof['owner_id']===$id
                    &&($latest===null||$proof['receipt_sha256']!==$previous||$proof['rows_sha256']!==$latest['rows_sha256']))throw new RuntimeException('Empty part replacement is not linked to its previous proven branch.',409);
            }
            $previous=OperatingScopeArtifact::hash($receipt);$latest=$receipt;
        }
        return $latest;
    }

    private static function recordBranch(PDO $pdo,string $kind,int $id,array $rows,string $command,array $writer): void
    {
        $key=$kind.':'.$id;$marker=self::ownerMarker($kind,$id);
        $query=$pdo->prepare('SELECT fact_json,operative FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE');$query->execute([self::OWNER_FACT,$key]);$oldMarker=$query->fetch(PDO::FETCH_ASSOC);
        if($oldMarker&&((int)$oldMarker['operative']!==0||Importer::canonicalJson(Importer::decodeExact($oldMarker['fact_json']))!==Importer::canonicalJson($marker)))throw new RuntimeException('Existing part relevance marker conflicts.',409);
        $query=$pdo->prepare('SELECT source_key,fact_json,operative FROM rental_facts WHERE namespace=? AND source_key LIKE ? LIMIT 4097 FOR UPDATE');$query->execute([self::TERMS_FACT,$key.':%']);$old=$query->fetchAll(PDO::FETCH_ASSOC);$chain=[];
        foreach($old as $fact){
            $value=Importer::decodeExact($fact['fact_json']);
            if((int)$fact['operative']!==0||$fact['source_key']!==$key.':'.hash('sha256',(string)($value['command_identity']??'')))throw new RuntimeException('Existing part branch receipt conflicts.',409);
            $chain[]=$value;
        }
        if(($oldMarker===false)!==($chain===[]))throw new RuntimeException('Part owner marker/receipt pair is incomplete; repair requires source proof.',409);
        $latest=$chain===[]?null:self::receiptChain($kind,$id,$chain);
        $proofs=array_map(self::rowProof(...),$rows);
        $document=['schema'=>1,'project_id'=>OperatingScopeArtifact::PROJECT,'kind'=>'billing-part-terms','owner_kind'=>$kind,'owner_id'=>$id,
            'branch_revision'=>count($chain)+1,'previous_receipt_sha256'=>$latest===null?null:OperatingScopeArtifact::hash($latest),
            'command_identity'=>$command,'rows_sha256'=>OperatingScopeArtifact::hash($rows),'known_empty'=>$rows===[],'term_proofs'=>$proofs,
            'writer'=>$writer,'contract_id'=>null,'money_mutation'=>false];
        foreach($chain as $existing)if($existing['command_identity']===$command){
            $expected=$document;$expected['branch_revision']=$existing['branch_revision'];$expected['previous_receipt_sha256']=$existing['previous_receipt_sha256'];
            if(Importer::canonicalJson($existing)!==Importer::canonicalJson($expected)||$existing!==$latest)throw new RuntimeException('Part command receipt replay conflicts.',409);
            return;
        }
        self::receiptChain($kind,$id,[...$chain,$document]);
        if(!$oldMarker)$pdo->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,0)')->execute([self::OWNER_FACT,$key,Importer::json($marker)]);
        $pdo->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,0)')->execute([self::TERMS_FACT,$key.':'.hash('sha256',$command),Importer::json($document)]);
    }

    private static function normalize(PDO $pdo,string $kind,int $owner,array $input,int $revision): array
    {
        self::table($kind);
        $termId=BillingParts::positiveId($input['id']??null);
        if($owner<=0 || $revision<1)throw new RuntimeException('Part-term identity/revision is invalid.',409);
        $part=BillingParts::version($pdo,$input['part_id'],BillingParts::partVersion($input['part_version']??null));
        $receipt=BillingPartAdmission::receipt($pdo,$input['admission_sha256']);
        if(!in_array(BillingPartAdmission::identity($part['part']['source_namespace'],$part['part']['source_key']),$receipt['source_identity_sha256s'],true))throw new RuntimeException('Dated term source does not admit this exact part.',409);
        $native=$kind==='contract'?'native-c-contract':'native-c-document';
        if(!in_array($input['origin'],['source-admitted',$native],true))throw new RuntimeException('Invalid dated part-term origin.',409);
        $source=$input['origin']==='source-admitted'?$receipt['term_source_sha256']:BillingPartAdmission::NATIVE_SOURCE;
        if($input['source_sha256']!==$source)throw new RuntimeException('Dated term proof is independent of part-source proof.',409);
        BillingPartAdmission::sha($input['evidence_sha256']);
        $start=BillingParts::date($input['start_date']??null);$end=BillingParts::date($input['end_date']??null);
        if($start!==null && $end!==null && $end<$start)throw new RuntimeException('Part term end precedes start.',409);
        return ['id'=>$termId,$kind.'_id'=>$owner,'part_id'=>$part['part']['id'],'part_version'=>$part['part']['version'],
            'source_term_key'=>BillingParts::text($input['source_term_key']??null,180,false),'origin'=>$input['origin'],'start_date'=>$start,'end_date'=>$end,
            'amount'=>BillingParts::decimal($input['amount']??null,2,18),'purpose'=>BillingParts::text($input['purpose']??null,65535),
            'effective_date'=>BillingParts::date($input['effective_date']??null),'superseded_on'=>BillingParts::date($input['superseded_on']??null),
            'source_sha256'=>$source,'evidence_sha256'=>$input['evidence_sha256'],'admission_sha256'=>$input['admission_sha256'],'command_key'=>$input['command_key']??null,'revision'=>$revision];
    }

    private static function nativeBasis(array $basis): void
    {
        if(($basis['source_rule_sha256']??null)!==BillingPartAdmission::NATIVE_SOURCE || !is_int($basis['actor_id']??null) || $basis['actor_id']<=0 || !is_string($basis['command_key']??null) || !preg_match('/^[A-Za-z0-9:._-]{1,100}$/D',$basis['command_key']))throw new RuntimeException('Current authorized native part writer basis is required.',409);
    }

    private static function creationBasis(string $kind,int $id,array $basis): void
    {
        self::nativeBasis($basis);
        $keys=['actor_id','command_key','source_rule_sha256','parent_contract_id','initial_status'];
        if(!in_array($kind,['contract','document'],true)||$id<1||array_diff(array_keys($basis),$keys)||array_diff($keys,array_keys($basis)))throw new RuntimeException('Exact native part creation basis required.',409);
        if($kind==='contract' ? $basis['parent_contract_id']!==null||$basis['initial_status']!=='draft'
            : !is_int($basis['parent_contract_id'])||$basis['parent_contract_id']<1||!in_array($basis['initial_status'],['draft','legal'],true))throw new RuntimeException('Native part creation branch/status differs.',409);
    }

    private static function creationOwner(string $kind,array $owner,array $creation,array $save,?int $parentVersion): void
    {
        if((int)($owner['version']??0)!==1||($owner['status']??null)!==$creation['initial_status'])throw new RuntimeException('New part owner/version differs from its creation ticket.',409);
        foreach(['actor_id','command_key','source_rule_sha256'] as $field)if(($save[$field]??null)!==$creation[$field])throw new RuntimeException('Part creation writer basis changed.',409);
        if($kind==='document'&&((int)($owner['contract_id']??0)!==$creation['parent_contract_id']
            ||($owner['parent_status']??null)!=='active'||(int)($owner['parent_version']??0)!==$parentVersion
            ||(int)($owner['applied']??1)!==0))throw new RuntimeException('New DS part parent/source state changed.',409);
    }

    private static function requireUnboundCreation(PDO $pdo,string $kind,int $id): void
    {
        $query=$pdo->prepare('SELECT 1 FROM rental_source_bindings WHERE entity_type=? AND entity_id=? LIMIT 1 FOR UPDATE');$query->execute([$kind,$id]);
        if($query->fetchColumn()!==false)throw new RuntimeException('Source-bound part owner cannot be admitted as a new native creation.',409);
    }

    private static function owner(PDO $pdo,string $kind,int $id): array
    {
        self::table($kind);
        $sql=$kind==='document'?'SELECT d.*,c.status parent_status,c.version parent_version FROM rental_documents d JOIN contracts c ON c.id=d.contract_id WHERE d.id=? FOR UPDATE':'SELECT * FROM contracts WHERE id=? FOR UPDATE';
        $query=$pdo->prepare($sql);$query->execute([$id]);return $query->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Part term owner is missing.',409);
    }

    private static function table(string $kind): string
    {
        return match($kind){'contract'=>'contract_part_terms','document'=>'document_part_terms',default=>throw new RuntimeException('Invalid dated part-term owner kind.',409)};
    }

    private static function nextId(PDO $pdo,string $kind): int
    {
        $pdo->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
        return (int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM '.self::table($kind))->fetchColumn();
    }
}
