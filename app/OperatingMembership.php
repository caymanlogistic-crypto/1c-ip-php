<?php
declare(strict_types=1);
namespace App;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Temporal permission is independent of invoice object/period completeness. */
final class OperatingMembership
{
    public const SWITCH='APP_OPERATING_ADMISSION_SHA256';
    public const LOWER_DATE='2026-09-25';
    public const SCOPE='e952ab2b1fbf557d387101fed9d98dcab3dea6aa98214099e0d226a3dd048a86';
    public const OWNER='85eb7c2ee3078fc267a5c3d293d69a9a2d70e3a5460263b0affe6e7d2d8db5e9';
    public const STATE='429f9bee7ebaaf6d221ce89c9b345316e27e44bf7ab7ec13a27989faaad7a148';
    public const MAPPING='27d1e223abbad1bfbe964e9a3ebf2bddf1573a33bc6a5a19efd2762fe77c6624';

    public static function selected(): ?string
    {
        $value=getenv(self::SWITCH);
        if ($value===false || $value==='') return null;
        if (!preg_match('/^[a-f0-9]{64}$/D',$value)) throw new RuntimeException('Invalid operating admission selection.');
        return $value;
    }

    /** No SQL at all while OFF. Never substitutes source_json or contract provenance. */
    public static function read(PDO $pdo,string $kind,int $id): ?array
    {
        $selected=self::selected();
        if ($selected===null) return null;
        if (!in_array($kind,['invoice','payment'],true) || $id<=0) throw new RuntimeException('Invalid operating membership identity.');
        $table=$kind==='invoice'?'invoice_operating_membership':'payment_operating_membership';
        $entity=$kind==='invoice'?'invoices':'payments';
        $date=$kind==='invoice'?'issued':'payment_date';
        $query=$pdo->prepare("SELECT m.*,e.$date AS current_event_date,a.admission_sha256,a.scope_manifest_sha256,a.owner_semantics_sha256,a.state_sha256,a.mapping_sha256 FROM $table m JOIN $entity e ON e.id=m.{$kind}_id JOIN rental_operating_admissions a ON a.id=m.admission_id WHERE m.{$kind}_id=?");
        $query->execute([$id]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['membership'=>'UNKNOWN','effective_membership'=>'UNKNOWN','runtime_hold'=>'MEMBERSHIP_NOT_RECORDED','revision'=>0,'admission_sha256'=>$selected];
        return self::evaluate($row,$kind,$selected);
    }

    public static function classify(?string $date): string
    {
        if ($date===null) return 'UNKNOWN';
        self::date($date);
        return $date>=self::LOWER_DATE?'OPERATING':'CONTEXT';
    }

    /** Pure validation used by readers and copy fixtures; preserves original classifications. */
    public static function evaluate(array $row,string $kind,string $selected): array
    {
        if (!in_array($kind,['invoice','payment'],true)) throw new RuntimeException('Invalid operating membership kind.');
        foreach (['admission_sha256'=>$selected,'scope_manifest_sha256'=>self::SCOPE,'owner_semantics_sha256'=>self::OWNER,'state_sha256'=>self::STATE,'mapping_sha256'=>self::MAPPING] as $field=>$pin) {
            if (!is_string($row[$field]??null) || !hash_equals($pin,$row[$field])) throw new RuntimeException('Operating membership receipt differs from the selected source admission.',409);
        }
        $origin=$row['origin']??null;
        $origins=$kind==='invoice'?['imported-output','native-c-manual','native-c-initial','native-c-monthly']:['imported-output','native-c-manual'];
        if (!in_array($origin,$origins,true)) throw new RuntimeException('Operating membership origin is invalid.',409);
        foreach (['source_namespace','source_collection','source_id'] as $field) {
            $value=$row[$field]??null;
            if ($origin==='imported-output' ? !is_string($value) || $value==='' : $value!==null) throw new RuntimeException('Operating membership source identity is invalid.',409);
        }
        $membership=$row['membership']??null;
        if (!in_array($membership,['OPERATING','CONTEXT','UNKNOWN'],true)) throw new RuntimeException('Operating membership state is invalid.',409);
        $date=$row['event_date']??null;
        if ($date!==null && !is_string($date)) throw new RuntimeException('Operating event date is invalid.',409);
        if ($membership!=='UNKNOWN' && self::classify($date)!==$membership) throw new RuntimeException('Operating event classification differs from its date.',409);
        if ($date!==null) self::date($date);
        $current=$row['current_event_date']??null;
        if ($current!==null) self::date($current);
        if ($kind==='payment' && ($row['posting_state']??null)!==($origin==='imported-output'?'ALREADY_APPLIED':'NATIVE')) throw new RuntimeException('Payment posting provenance is invalid.',409);
        $hold=$date!==$current?'MEMBERSHIP_DATE_CHANGED':($membership==='UNKNOWN'?'MEMBERSHIP_UNKNOWN':null);
        $row['revision']=(int)($row['revision']??0);
        if ($row['revision']<1) throw new RuntimeException('Operating membership revision is invalid.',409);
        return array_replace($row,['effective_membership'=>$hold===null?$membership:'UNKNOWN','runtime_hold'=>$hold]);
    }

    /** Ordinary dispatch only. Context stays available to anti-duplicate coverage readers. */
    public static function requireOrdinary(array $membership): void
    {
        if (($membership['effective_membership']??null)!=='OPERATING' || ($membership['runtime_hold']??null)!==null) throw new RuntimeException('Invoice is outside admitted ordinary operations.',409);
    }

    /** Imported allocations are facts already posted; this is never a cash replay authority. */
    public static function requireNativePosting(array $membership): void
    {
        if (($membership['posting_state']??null)!=='NATIVE' || ($membership['origin']??null)!=='native-c-manual' || ($membership['effective_membership']??null)!=='OPERATING' || ($membership['runtime_hold']??null)!==null) throw new RuntimeException('Payment is not an admitted new native posting.',409);
    }

    private static function date(string $date): void
    {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('Operating event date must be an exact calendar date.',409);
    }
}
