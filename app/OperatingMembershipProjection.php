<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Bounded source CAS planner. It has no DDL, switch, callback or write capability. */
final class OperatingMembershipProjection
{
    public const TABLES=['rental_operating_admissions','invoice_operating_membership','payment_operating_membership'];

    /** Disposable-copy executor only. This method cannot authorize Main or reader activation. */
    public static function applyCopy(PDO $pdo,array $artifact,string $directory): array
    {
        self::copyTarget($pdo);
        if ($pdo->inTransaction() || OperatingMembership::selected()!==null) throw new RuntimeException('Projection starts outside a transaction with temporal readers OFF.');
        self::artifact($artifact);
        $plan=self::inspect($pdo,$artifact);
        $protected=self::protectedFingerprint($pdo);
        $existing=$pdo->prepare('SELECT * FROM rental_operating_admissions WHERE projection_sha256=?');
        $existing->execute([$artifact['projection_sha256']]);$receipt=$existing->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            self::verifyApplied($pdo,$artifact,$receipt);
            return ['already_applied'=>true,'admission_sha256'=>$receipt['admission_sha256'],'projection_sha256'=>$artifact['projection_sha256']];
        }
        foreach (self::TABLES as $table) if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0) throw new RuntimeException('Conflicting operating admission exists.',409);
        StorageBudget::target($directory,16777216);
        if (file_exists($directory) || !mkdir($directory,0700,true)) throw new RuntimeException('New private projection evidence directory required.');
        $id=bin2hex(random_bytes(16));$id=substr($id,0,8).'-'.substr($id,8,4).'-'.substr($id,12,4).'-'.substr($id,16,4).'-'.substr($id,20);
        $admission=OperatingScopeArtifact::hash(['id'=>$id,'projection_sha256'=>$artifact['projection_sha256'],'before_rows_sha256'=>$plan['before_rows_sha256'],'protected'=>$protected]);
        $rows=self::rows($artifact,$id);
        $beforePath=$directory.'/before.json';$candidatePath=$directory.'/candidate-not-applied.json';
        self::writeNew($beforePath,$plan+['protected'=>$protected]);
        self::writeNew($candidatePath,['status'=>'CANDIDATE_NOT_APPLIED','admission_sha256'=>$admission,'projection_sha256'=>$artifact['projection_sha256'],'rows'=>$rows]);
        $receipt=['id'=>$id,'project_id'=>OperatingScopeArtifact::PROJECT,'implementation_version'=>'operating-membership-v1','scope_manifest_sha256'=>OperatingMembership::SCOPE,'owner_semantics_sha256'=>OperatingMembership::OWNER,'state_sha256'=>OperatingMembership::STATE,'mapping_sha256'=>OperatingMembership::MAPPING,'projection_sha256'=>$artifact['projection_sha256'],'admission_sha256'=>$admission,'operating_start'=>'2026-10-01','event_lower_date'=>OperatingMembership::LOWER_DATE,'mode'=>'PERIOD_START_PRESERVE_LATER','before_rows_sha256'=>$plan['before_rows_sha256'],'after_rows_sha256'=>OperatingScopeArtifact::hash($rows),'protected_rows_sha256'=>$protected['rows_sha256'],'before_path'=>$beforePath,'candidate_path'=>$candidatePath,'invoice_count'=>133,'payment_count'=>114];
        $pdo->beginTransaction();
        try {
            $locked=self::inspect($pdo,$artifact,true);
            if ($locked['before_rows_sha256']!==$plan['before_rows_sha256'] || self::protectedFingerprint($pdo)!==$protected) throw new RuntimeException('Operating projection preimage changed before mutation.',409);
            self::insert($pdo,'rental_operating_admissions',$receipt);
            foreach ($rows as $table=>$records) foreach ($records as $record) self::insert($pdo,$table,$record);
            self::verifyApplied($pdo,$artifact,$receipt);
            if (self::protectedFingerprint($pdo)!==$protected) throw new RuntimeException('Operating projection altered a protected row or schema.');
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        // The SQL receipt is authoritative after commit. An optional evidence-write failure
        // cannot turn a committed projection into a failed mutation eligible for replay.
        $result=['already_applied'=>false,'applied'=>true,'admission_sha256'=>$admission,'projection_sha256'=>$artifact['projection_sha256'],'evidence_complete'=>true];
        try { self::writeNew($directory.'/applied.json',$result); }
        catch (\Throwable) { $result['evidence_complete']=false; }
        return $result;
    }

    private static function copyTarget(PDO $pdo): void
    {
        $config=getenv('APP_DB_CONFIG_FILE');
        if (getenv('IPPHP_INTEGRATION_SCRATCH')!=='explicit-empty-scratch' || getenv('APP_OPERATING_COPY_TEST')!=='explicit-temporary-three-tables'
            || !is_string($config) || is_link($config) || realpath($config)!==realpath(APP_ROOT.'/.local/scratch-database.php')) throw new RuntimeException('Exact temporary operating-copy configuration required.');
        $identity=$pdo->query('SELECT DATABASE(),@@port')->fetch(PDO::FETCH_NUM);
        if ($identity[0]!=='ip_ya_php_scratch_f6e21e3c' || (int)$identity[1]!==3306) throw new RuntimeException('Operating copy target differs.');
    }

    private static function rows(array $artifact,string $admissionId): array
    {
        $result=[];
        foreach (['invoice','payment'] as $kind) foreach ($artifact[$kind==='invoice'?'invoices':'payments'] as $candidate) {
            $row=[$kind.'_id'=>$candidate['id'],'admission_id'=>$admissionId];
            foreach (['origin','membership','event_date','source_namespace','source_collection','source_id','evidence_sha256'] as $field) $row[$field]=$candidate[$field];
            $row['command_key']=null;$row['revision']=1;
            if ($kind==='payment') $row['posting_state']='ALREADY_APPLIED';
            $result[$kind.'_operating_membership'][]=$row;
        }
        return $result;
    }

    private static function verifyApplied(PDO $pdo,array $artifact,array $receipt): void
    {
        foreach (['scope_manifest_sha256'=>OperatingMembership::SCOPE,'owner_semantics_sha256'=>OperatingMembership::OWNER,'state_sha256'=>OperatingMembership::STATE,'mapping_sha256'=>OperatingMembership::MAPPING,'projection_sha256'=>$artifact['projection_sha256']] as $field=>$expected) if ($receipt[$field]!==$expected) throw new RuntimeException('Existing operating admission differs.',409);
        $rows=self::rows($artifact,$receipt['id']);
        if ($receipt['after_rows_sha256']!==OperatingScopeArtifact::hash($rows)) throw new RuntimeException('Existing operating projection receipt differs.',409);
        foreach ($rows as $table=>$expectedRows) {
            $columns=array_keys($expectedRows[0]);$id=$columns[0];
            $query=$pdo->prepare('SELECT '.implode(',',$columns).' FROM '.$table." WHERE admission_id=? AND origin='imported-output' ORDER BY ".$id);$query->execute([$receipt['id']]);
            $actual=$query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($actual as &$row) {$row[$id]=(int)$row[$id];$row['revision']=(int)$row['revision'];}unset($row);
            if ($actual!==$expectedRows) throw new RuntimeException('Existing operating membership differs; replacement is forbidden.',409);
        }
    }

    private static function insert(PDO $pdo,string $table,array $row): void
    {
        $columns=array_keys($row);
        $pdo->prepare('INSERT INTO '.$table.'('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    }

    private static function writeNew(string $path,array $value): void
    {
        $raw=Importer::json($value);$handle=fopen($path,'xb');
        if (!$handle) throw new RuntimeException('Immutable operating evidence path already exists.');
        try {if(fwrite($handle,$raw)!==strlen($raw) || !fflush($handle))throw new RuntimeException('Operating evidence write failed.');if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Operating evidence sync failed.');}finally{fclose($handle);}
        if (!hash_equals(hash('sha256',$raw),hash_file('sha256',$path))) throw new RuntimeException('Operating evidence verification failed.');
    }

    public static function inspect(PDO $pdo,array $artifact,bool $lock=false): array
    {
        self::artifact($artifact);
        if ($lock && !$pdo->inTransaction()) throw new RuntimeException('Operating CAS locks require the caller transaction.');
        $suffix=$lock?' FOR UPDATE':'';
        $imports=$pdo->prepare('SELECT state_hash,mapping_hash FROM rental_imports WHERE state_hash=?'.$suffix);
        $imports->execute([OperatingMembership::STATE]);
        $receipt=$imports->fetchAll(PDO::FETCH_ASSOC);
        if (count($receipt)!==1 || $receipt[0]['mapping_hash']!==OperatingMembership::MAPPING) throw new RuntimeException('Operating projection import receipt differs.',409);
        $before=[];
        foreach (['invoice','payment'] as $kind) {
            foreach ($artifact[$kind==='invoice'?'invoices':'payments'] as $candidate) {
                $query=$pdo->prepare('SELECT * FROM '.($kind==='invoice'?'invoices':'payments').' WHERE id=?'.$suffix);
                $query->execute([$candidate['id']]);$row=$query->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('Operating projection source entity is missing.',409);
                self::entity($kind,$row,$candidate['source_record']);
                $query=$pdo->prepare('SELECT namespace,collection,source_id,entity_type,entity_id,relation_kind,evidence_json,mapping_hash FROM rental_source_bindings WHERE entity_type=? AND entity_id=? AND relation_kind=?'.$suffix);
                $query->execute([$kind,$candidate['id'],$candidate['relation_kind']]);
                $bindings=$query->fetchAll(PDO::FETCH_ASSOC);
                if (count($bindings)!==1) throw new RuntimeException('Operating projection requires one exact source binding.',409);
                $binding=$bindings[0];
                foreach (['namespace'=>'source_namespace','collection'=>'source_collection','source_id'=>'source_id'] as $field=>$expected) if ($binding[$field]!==$candidate[$expected]) throw new RuntimeException('Operating projection source identity differs.',409);
                if ($binding['mapping_hash']!==OperatingMembership::MAPPING || Importer::canonicalJson(Importer::decodeExact($binding['evidence_json']))!==Importer::canonicalJson($candidate['source_binding'])) throw new RuntimeException('Operating projection source evidence differs.',409);
                $item=['entity'=>$row,'binding'=>$binding];
                if ($kind==='payment') {
                    $query=$pdo->prepare('SELECT payment_id,invoice_id,amount FROM payment_allocations WHERE payment_id=? ORDER BY invoice_id'.$suffix);
                    $query->execute([$candidate['id']]);$allocations=$query->fetchAll(PDO::FETCH_ASSOC);
                    if (count($allocations)!==1 || (int)$allocations[0]['invoice_id']!==$candidate['source_record']['invoice'] || !self::money($allocations[0]['amount'],$candidate['source_record']['amount'])) throw new RuntimeException('Operating projection payment allocation differs.',409);
                    $item['allocations']=$allocations;
                }
                $before[$kind][$candidate['id']]=$item;
            }
        }
        return ['projection_sha256'=>$artifact['projection_sha256'],'before_rows_sha256'=>OperatingScopeArtifact::hash($before),'before'=>$before,'counts'=>['invoices'=>count($before['invoice']),'payments'=>count($before['payment'])]];
    }

    /** All preexisting tables are protected, including NULLs, statuses, receipts and counters. */
    public static function protectedFingerprint(PDO $pdo): array
    {
        $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
        $rows=$schemas=[];
        foreach ($tables as $table) {
            if (in_array($table,self::TABLES,true)) continue;
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new RuntimeException('Unexpected operating projection table identity.');
            $schemas[$table]=hash('sha256',$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
            $hashes=[];$query=$pdo->query('SELECT * FROM `'.$table.'`');
            while ($row=$query->fetch(PDO::FETCH_ASSOC)) $hashes[]=hash('sha256',Importer::canonicalJson($row));
            sort($hashes,SORT_STRING);
            $rows[$table]=['count'=>count($hashes),'sha256'=>hash('sha256',Importer::canonicalJson($hashes))];
        }
        return ['tables'=>count($rows),'rows_sha256'=>OperatingScopeArtifact::hash($rows),'schema_sha256'=>OperatingScopeArtifact::hash($schemas)];
    }

    public static function entity(string $kind,array $row,array $source): void
    {
        if (!in_array($kind,['invoice','payment'],true) || (int)($row['id']??0)!==($source['id']??null)) throw new RuntimeException('Operating entity identity differs.',409);
        if (Importer::canonicalJson(Importer::decodeExact($row['source_json']))!==Importer::canonicalJson($source)) throw new RuntimeException('Operating source preimage differs.',409);
        foreach ($kind==='invoice'?['amount','paid']:['amount'] as $field) if (!self::money($row[$field],$source[$field]??null)) throw new RuntimeException('Operating financial preimage differs.',409);
        if ($kind==='invoice') {
            foreach (['number','kind','period','status','issued','due'] as $field) if ($row[$field]!==($source[$field]??null)) throw new RuntimeException('Operating invoice identity/status/date differs.',409);
            if ((int)$row['contract_id']!==$source['contract']) throw new RuntimeException('Operating invoice contract differs.',409);
        } else {
            if ($row['payment_date']!==$source['date'] || $row['source']!==($source['source']??null)) throw new RuntimeException('Operating payment date/source differs.',409);
        }
    }

    public static function artifact(array $artifact): void
    {
        $hash=$artifact['projection_sha256']??null;unset($artifact['projection_sha256']);
        if (!is_string($hash) || !hash_equals($hash,OperatingScopeArtifact::hash($artifact))) throw new RuntimeException('Operating candidate hash differs.');
        foreach (['scope_manifest_sha256'=>OperatingMembership::SCOPE,'owner_semantics_sha256'=>OperatingMembership::OWNER,'state_sha256'=>OperatingMembership::STATE,'mapping_sha256'=>OperatingMembership::MAPPING] as $field=>$pin) if (($artifact[$field]??null)!==$pin) throw new RuntimeException('Operating candidate source pin differs.');
        if (count($artifact['invoices']??[])!==133 || count($artifact['payments']??[])!==114 || ($artifact['cash_replay_allowed']??null)!==false) throw new RuntimeException('Operating candidate completeness differs.');
    }

    private static function money(mixed $current,mixed $source): bool
    {
        if ($current===null || $source===null) return $current===$source;
        return bccomp((string)$current,(string)$source,2)===0;
    }
}
