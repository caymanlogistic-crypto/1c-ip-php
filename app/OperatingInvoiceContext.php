<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Shared temporal evidence for selected invoice effects; not source-scope or actor authority. */
final class OperatingInvoiceContext
{
    /** Legal/B capture may retain CONTEXT/UNKNOWN; effect authorization is a separate call. */
    public static function snapshot(PDO $pdo,int $invoiceId): ?array
    {
        if(OperatingMembership::selected()===null)return null;
        if($invoiceId<=0)throw new RuntimeException('Invalid temporal invoice identity.',409);
        $metadata=OperatingMembership::read($pdo,'invoice',$invoiceId);
        return $metadata===null?null:array_replace($metadata,['invoice_id'=>$invoiceId]);
    }

    public static function requireOrdinary(?array $snapshot): void
    {
        if($snapshot!==null)OperatingMembership::requireOrdinary($snapshot);
    }

    /** Replay/dispatch must compare the full frozen DTO, including activation changes. */
    public static function assertCurrent(PDO $pdo,int $invoiceId,?array $frozen): ?array
    {
        $current=self::snapshot($pdo,$invoiceId);
        self::requireOrdinary($current);
        if(Importer::canonicalJson($current)!==Importer::canonicalJson($frozen))throw new RuntimeException('Invoice operating membership changed since preparation.',409);
        return $current;
    }

    /** Optional explicit feed selector. History/financial/anti-duplicate queries need not use it. */
    public static function filter(string $alias,string $state): array
    {
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/D',$alias) || !in_array($state,['OPERATING','CONTEXT','UNKNOWN'],true))throw new RuntimeException('Invalid explicit operating invoice selector.',409);
        $selected=OperatingMembership::selected();
        if($selected===null)return ['active'=>false,'sql'=>'1=1','params'=>[]];
        $match=$state==='UNKNOWN'?"m.membership IN ('OPERATING','CONTEXT')":'m.membership=?';
        $sql='EXISTS (SELECT 1 FROM invoice_operating_membership m JOIN rental_operating_admissions a ON a.id=m.admission_id '
            .'WHERE m.invoice_id='.$alias.'.id AND a.admission_sha256=? AND a.scope_manifest_sha256=? '
            .'AND a.owner_semantics_sha256=? AND a.state_sha256=? AND a.mapping_sha256=? '
            .'AND m.event_date IS NOT NULL AND m.event_date='.$alias.'.issued AND '.$match.')';
        $params=[$selected,OperatingMembership::SCOPE,OperatingMembership::OWNER,OperatingMembership::STATE,OperatingMembership::MAPPING];
        if($state==='UNKNOWN')$sql='NOT '.$sql;else $params[]=$state;
        return ['active'=>true,'sql'=>$sql,'params'=>$params];
    }
}
