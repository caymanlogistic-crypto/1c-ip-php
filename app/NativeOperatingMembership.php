<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Called only by existing authorized source-C writers in their business transaction. */
final class NativeOperatingMembership
{
    public const INVOICE_SOURCE='213de8b874100f2ff00251625e25113ed4e164a3f3cb7796e89573eccb3c6eac';
    public const MANUAL_SOURCE='268bd262421f8730fb68b15586da4897be0215812175166596bb45c398d78d54';
    public const INVOICE_EDIT_COLUMNS=['number','title','purpose','issued','amount','kind','initial_invoice','period','due'];
    public const PAYMENT_EDIT_COLUMNS=['amount','payment_date','entered_by'];
    private static ?\WeakMap $tickets=null;

    /** Before the existing authorized INSERT; absent identity and caller TX are proved here. */
    public static function beginCreation(PDO $pdo,string $kind,int $id,string $origin,array $basis): ?object
    {
        if (OperatingMembership::selected()===null) return null;
        self::transaction($pdo);
        try {
            $basis=self::basis($kind,$origin,$basis,false);
            if ($id<=0 || ($basis['expected_entity_version']??null)!==1 || !array_key_exists('expected_event_date',$basis) || ($basis['expected_event_date']!==null && !is_string($basis['expected_event_date']))) throw new RuntimeException('New native identity/version/date proof is required.',409);
            OperatingMembership::classify($basis['expected_event_date']);
            $receipt=self::admission($pdo);
            self::notImported($pdo,$kind,$id);
            $query=$pdo->prepare('SELECT id FROM '.($kind==='invoice'?'invoices':'payments').' WHERE id=? FOR UPDATE');$query->execute([$id]);
            if ($query->fetchColumn()!==false) throw new RuntimeException('Existing rows need separate writer-proof admission; creation fallback is forbidden.',409);
            $savepoint='native_operating_'.bin2hex(random_bytes(12));
            $pdo->exec('SAVEPOINT '.$savepoint);
            $ticket=new \stdClass();self::$tickets??=new \WeakMap();
            self::$tickets[$ticket]=['pdo'=>$pdo,'kind'=>$kind,'id'=>$id,'origin'=>$origin,'basis_sha256'=>OperatingScopeArtifact::hash($basis),'admission_id'=>$receipt['id'],'savepoint'=>$savepoint];
            return $ticket;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function recordInvoice(PDO $pdo,int $id,string $origin,array $basis,?object $ticket=null): ?array
    {
        return self::record($pdo,'invoice',$id,$origin,$basis,$ticket);
    }

    public static function recordPayment(PDO $pdo,int $id,array $basis,?object $ticket=null): ?array
    {
        return self::record($pdo,'payment',$id,'native-c-manual',$basis,$ticket);
    }

    private static function record(PDO $pdo,string $kind,int $id,string $origin,array $basis,?object $ticket): ?array
    {
        if (OperatingMembership::selected()===null) return null;
        self::transaction($pdo);
        try { return self::recordEnabled($pdo,$kind,$id,$origin,$basis,$ticket); }
        catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        finally { if ($ticket!==null && self::$tickets!==null) unset(self::$tickets[$ticket]); }
    }

    private static function recordEnabled(PDO $pdo,string $kind,int $id,string $origin,array $basis,?object $ticket): array
    {
        $basis=self::basis($kind,$origin,$basis,false);
        $receipt=self::admission($pdo);
        $proof=$ticket!==null && self::$tickets!==null?(self::$tickets[$ticket]??null):null;
        if (!is_array($proof) || $proof['pdo']!==$pdo || $proof['kind']!==$kind || $proof['id']!==$id || $proof['origin']!==$origin || $proof['basis_sha256']!==OperatingScopeArtifact::hash($basis) || $proof['admission_id']!==$receipt['id']) throw new RuntimeException('A fresh same-transaction creation ticket is required.',409);
        // A commit/restart invalidates this savepoint, even on the same PDO connection.
        $pdo->exec('RELEASE SAVEPOINT '.$proof['savepoint']);
        self::notImported($pdo,$kind,$id);
        $entity=self::entity($pdo,$kind,$id);
        $date=$entity[$kind==='invoice'?'issued':'payment_date'];
        if ((int)$entity['version']!==1 || ($basis['expected_entity_version']??null)!==1 || !array_key_exists('expected_event_date',$basis) || $basis['expected_event_date']!==$date) throw new RuntimeException('Native creation version/date proof differs.',409);
        $membership=OperatingMembership::classify($date);
        $evidence=OperatingScopeArtifact::hash(['kind'=>$kind,'id'=>$id,'origin'=>$origin,'basis'=>$basis,'entity_version'=>1,'event_date'=>$date,'admission_sha256'=>$receipt['admission_sha256']]);
        $table=$kind.'_operating_membership';
        $query=$pdo->prepare('SELECT * FROM '.$table.' WHERE '.$kind.'_id=? FOR UPDATE');$query->execute([$id]);$old=$query->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if ($old['origin']!==$origin || $old['admission_id']!==$receipt['id'] || $old['evidence_sha256']!==$evidence || $old['event_date']!==$date || (int)$old['revision']!==1) throw new RuntimeException('Native creation membership already differs; replacement is forbidden.',409);
            return OperatingMembership::read($pdo,$kind,$id);
        }
        $row=[$kind.'_id'=>$id,'admission_id'=>$receipt['id'],'origin'=>$origin,'membership'=>$membership,'event_date'=>$date,'source_namespace'=>null,'source_collection'=>null,'source_id'=>null,'evidence_sha256'=>$evidence,'command_key'=>$basis['operation_key'],'revision'=>1];
        if ($kind==='payment') $row['posting_state']='NATIVE';
        $columns=array_keys($row);
        $pdo->prepare('INSERT INTO '.$table.'('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        return OperatingMembership::read($pdo,$kind,$id);
    }

    /** OFF preserves the existing update. ON compiles exactly one allowlisted entity UPDATE. */
    public static function changeNativeDate(PDO $pdo,string $kind,int $id,int $entityVersion,int $membershipRevision,string $newDate,array $changes,array $basis,callable $legacyWrite): mixed
    {
        if (OperatingMembership::selected()===null) return $legacyWrite();
        self::transaction($pdo);
        try {
            OperatingMembership::classify($newDate);
            $basis=self::basis($kind,'native-c-manual',$basis,true);
            self::admission($pdo);
            self::notImported($pdo,$kind,$id);
            $before=self::entity($pdo,$kind,$id);
            $metadata=OperatingMembership::read($pdo,$kind,$id);
            if (!$metadata || !str_starts_with($metadata['origin']??'','native-c-') || ($metadata['runtime_hold']??null)!==null || (int)$before['version']!==$entityVersion || $metadata['revision']!==$membershipRevision) throw new RuntimeException('Native date edit lacks current native writer admission; explicitly unsupported.',409);
            $changes=self::changes($kind,$newDate,$changes);
            $table=$kind==='invoice'?'invoices':'payments';
            $set=array_map(static fn($column)=>$column.'=?',array_keys($changes));
            $query=$pdo->prepare('UPDATE '.$table.' SET '.implode(',',$set).',version=version+1 WHERE id=? AND version=?');
            $query->execute([...array_values($changes),$id,$entityVersion]);
            if ($query->rowCount()!==1) throw new RuntimeException('Native entity version CAS failed.',409);
            $after=self::entity($pdo,$kind,$id);
            self::verifyChange($kind,$before,$after,$changes);
            $evidence=OperatingScopeArtifact::hash(['previous_evidence_sha256'=>$metadata['evidence_sha256'],'basis'=>$basis,'changes'=>$changes,'old_entity_version'=>$entityVersion,'new_entity_version'=>$entityVersion+1,'old_membership_revision'=>$membershipRevision]);
            $query=$pdo->prepare('UPDATE '.$kind.'_operating_membership SET membership=?,event_date=?,evidence_sha256=?,command_key=?,revision=revision+1 WHERE '.$kind.'_id=? AND revision=? AND origin=? AND admission_id=?');
            $query->execute([OperatingMembership::classify($newDate),$newDate,$evidence,$basis['operation_key'],$id,$membershipRevision,$metadata['origin'],$metadata['admission_id']]);
            if ($query->rowCount()!==1) throw new RuntimeException('Native membership revision CAS failed.',409);
            return OperatingMembership::read($pdo,$kind,$id);
        } catch (\Throwable $error) {
            // A caller cannot catch this refusal and commit only the business-side date.
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function changes(string $kind,string $newDate,array $changes): array
    {
        OperatingMembership::classify($newDate);
        $date=$kind==='invoice'?'issued':'payment_date';
        $allowed=match($kind){'invoice'=>self::INVOICE_EDIT_COLUMNS,'payment'=>self::PAYMENT_EDIT_COLUMNS,default=>throw new RuntimeException('Invalid native date entity kind.',409)};
        if (($changes[$date]??null)!==$newDate || !$changes || array_diff(array_keys($changes),$allowed)) throw new RuntimeException('Native date update columns differ from the authorized finite set.',409);
        foreach ($changes as $value) if (!is_string($value) && !is_int($value) && $value!==null) throw new RuntimeException('Native update requires exact SQL scalar values.',409);
        return $changes;
    }

    public static function verifyChange(string $kind,array $before,array $after,array $changes): void
    {
        if (array_keys($before)!==array_keys($after) || (int)$after['version']!==(int)$before['version']+1) throw new RuntimeException('Native update version or row shape differs.',409);
        foreach ($before as $column=>$old) {
            if ($column==='version') continue;
            $expected=array_key_exists($column,$changes)?$changes[$column]:$old;
            $actual=$after[$column];
            if (array_key_exists($column,$changes) && $expected!==null && $actual!==null) {
                if (in_array($column,['amount'],true)) {
                    if (bccomp((string)$actual,(string)$expected,2)===0) continue;
                } elseif ((string)$actual===(string)$expected) continue;
            }
            if ($actual!==$expected) throw new RuntimeException('Native update changed a field outside its exact expected values.',409);
        }
    }

    public static function basis(string $kind,string $origin,array $basis,bool $edit): array
    {
        $writers=$kind==='payment'?['service.payment.create','finance.payment.create']:match($origin){
            'native-c-manual'=>['finance.invoice.create'],
            'native-c-initial'=>['evidence.initial-invoice','addenda.initial-invoice','addendum.invoice.create'],
            'native-c-monthly'=>['monthly.invoice.create','addendum.invoice.create'],
            default=>[],
        };
        if (!in_array($kind,['invoice','payment'],true) || !in_array($origin,['native-c-manual','native-c-initial','native-c-monthly'],true)) throw new RuntimeException('Native writer kind/origin is invalid.',409);
        if ($edit) $writers=[$kind==='invoice'?'finance.invoice.edit':'finance.payment.edit'];
        $source=$kind==='payment' || $edit?self::MANUAL_SOURCE:self::INVOICE_SOURCE;
        if (($basis['source_rule_sha256']??null)!==$source || !in_array($basis['writer']??null,$writers,true) || !is_string($basis['operation_key']??null) || !preg_match('/^[A-Za-z0-9:._-]{1,100}$/D',$basis['operation_key']) || !array_key_exists('actor_id',$basis) || ($basis['actor_id']!==null && (!is_int($basis['actor_id']) || $basis['actor_id']<=0))) throw new RuntimeException('Explicit source-C writer provenance is required.',409);
        if (($kind==='payment' || $edit || $origin==='native-c-manual') && $basis['actor_id']===null) throw new RuntimeException('Manual writer requires its current authorized actor.',409);
        return $basis;
    }

    private static function notImported(PDO $pdo,string $kind,int $id): void
    {
        $query=$pdo->prepare('SELECT 1 FROM rental_source_bindings WHERE entity_type=? AND entity_id=? AND relation_kind=? LIMIT 1 FOR UPDATE');
        $query->execute([$kind,$id,$kind==='invoice'?'invoice_control':'allocation_no_cash_replay']);
        if ($query->fetchColumn()!==false) throw new RuntimeException('Imported membership and already-applied financial facts are immutable.',409);
        $query=$pdo->prepare('SELECT origin FROM '.$kind.'_operating_membership WHERE '.$kind.'_id=? FOR UPDATE');$query->execute([$id]);
        $origin=$query->fetchColumn();
        if ($origin!==false && !str_starts_with($origin,'native-c-')) throw new RuntimeException('Imported membership cannot become a native fallback.',409);
    }

    private static function admission(PDO $pdo): array
    {
        $query=$pdo->prepare('SELECT * FROM rental_operating_admissions WHERE admission_sha256=? FOR SHARE');$query->execute([OperatingMembership::selected()]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Selected operating admission is not applied.',409);
        foreach (['project_id'=>OperatingScopeArtifact::PROJECT,'scope_manifest_sha256'=>OperatingMembership::SCOPE,'owner_semantics_sha256'=>OperatingMembership::OWNER,'state_sha256'=>OperatingMembership::STATE,'mapping_sha256'=>OperatingMembership::MAPPING] as $field=>$pin) if ($row[$field]!==$pin) throw new RuntimeException('Native writer admission source pin differs.',409);
        return $row;
    }

    private static function entity(PDO $pdo,string $kind,int $id): array
    {
        if (!in_array($kind,['invoice','payment'],true) || $id<=0) throw new RuntimeException('Invalid native writer identity.',409);
        $query=$pdo->prepare('SELECT * FROM '.($kind==='invoice'?'invoices':'payments').' WHERE id=? FOR UPDATE');$query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Native writer entity is missing.',409);
    }

    private static function transaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Native membership must share its existing business transaction.',409);
    }
}
