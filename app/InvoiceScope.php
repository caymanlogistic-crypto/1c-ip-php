<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Optional typed scope admission. Source verification alone never clears runtime_hold. */
final class InvoiceScope
{
    public const CANDIDATE_MANIFEST='5fdcf76e9c5c5528ee8627b2bfb9b382f046e1faa7ad4d3730e3ce74288e2b8d';
    public const SWITCH='APP_INVOICE_SCOPE_ADMISSION_SHA256';
    public const STATES=['KNOWN','PARTIAL','UNKNOWN'];

    public static function enabled(): bool
    {
        $value=getenv(self::SWITCH);
        if ($value===false || $value==='') return false;
        if (!hash_equals(self::CANDIDATE_MANIFEST,$value)) throw new RuntimeException('Invoice scope admission pin is not recognized.');
        return true;
    }

    /** Null while OFF: no SQL, including no schema/control-plane probes. */
    public static function metadata(PDO $pdo,int $invoiceId): ?array
    {
        if (!self::enabled()) return null;
        $query=$pdo->prepare('SELECT origin,objects_state,periods_state,availability,classification,runtime_hold,evidence_sha256,admission_sha256,source_sha256,invoice_version,revision FROM invoice_scope_availability WHERE invoice_id=?');
        $query->execute([$invoiceId]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [
            'origin'=>'unclassified','objects_state'=>'UNKNOWN','periods_state'=>'UNKNOWN','availability'=>'UNKNOWN',
            'classification'=>'UNCLASSIFIED','runtime_hold'=>'SCOPE_NOT_RECORDED','evidence_sha256'=>null,
            'admission_sha256'=>self::CANDIDATE_MANIFEST,'source_sha256'=>null,'invoice_version'=>null,'revision'=>0,
        ];
        if (!hash_equals(self::CANDIDATE_MANIFEST,$row['admission_sha256'])) throw new RuntimeException('Invoice scope row admission differs from the selected receipt.');
        foreach (['objects_state','periods_state','availability'] as $field) if (!in_array($row[$field],self::STATES,true)) throw new RuntimeException('Invoice scope classification is invalid.');
        $row['invoice_version']=(int)$row['invoice_version'];
        $row['revision']=(int)$row['revision'];
        return $row;
    }

    /** UNKNOWN is null, not a proved empty scope. Raw rows remain separately hash-bound. */
    public static function read(PDO $pdo,int $invoiceId): ?array
    {
        $metadata=self::metadata($pdo,$invoiceId);
        if ($metadata===null) return null;
        $query=$pdo->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
        $query->execute([$invoiceId]);
        $objects=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
        $query=$pdo->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date,end_date');
        $query->execute([$invoiceId]);$periods=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periods as &$period) $period['object_id']=(int)$period['object_id'];
        unset($period);
        return $metadata+[
            'object_ids'=>$metadata['objects_state']==='UNKNOWN'?null:$objects,
            'coverage'=>$metadata['periods_state']==='UNKNOWN'?null:$periods,
            'stored_scope_sha256'=>hash('sha256',Importer::canonicalJson(['objects'=>$objects,'coverage'=>$periods])),
        ];
    }

    /** Called only by a validated source-C writer, inside its existing command transaction. */
    public static function recordNative(PDO $pdo,int $invoiceId,string $origin,array $basis): void
    {
        if (!self::enabled()) return;
        if (!$pdo->inTransaction()) throw new RuntimeException('Native invoice scope must share its business transaction.');
        if (!in_array($origin,['native-c-manual','native-c-initial','native-c-monthly'],true)) throw new RuntimeException('Unrecognized native scope origin.');
        foreach (['objects_state','periods_state'] as $field) if (!in_array($basis[$field]??null,self::STATES,true)) throw new RuntimeException('Explicit native scope completeness is required.');
        if (!in_array($basis['classification']??null,['RENTAL','NOT_RENTAL_SECURITY'],true) || !preg_match('/^[a-f0-9]{64}$/D',$basis['source_sha256']??'')) throw new RuntimeException('Native source rule proof is required.');
        $command=$basis['command_key']??null;
        if ($command!==null && (!is_string($command) || strlen($command)>100 || str_contains($command,"\0"))) throw new RuntimeException('Invalid native scope command key.');
        $query=$pdo->prepare('SELECT version FROM invoices WHERE id=? FOR UPDATE');$query->execute([$invoiceId]);
        $version=$query->fetchColumn();
        if ($version===false) throw new RuntimeException('Invoice not found.',404);
        $query=$pdo->prepare('SELECT origin FROM invoice_scope_availability WHERE invoice_id=? FOR UPDATE');$query->execute([$invoiceId]);
        $old=$query->fetchColumn();
        if ($old!==false && !str_starts_with($old,'native-c-')) throw new RuntimeException('Imported scope cannot be replaced by a native fallback.');
        $objects=$basis['objects_state'];$periods=$basis['periods_state'];
        $availability=$objects==='KNOWN' && $periods==='KNOWN'?'KNOWN':($objects==='UNKNOWN' && $periods==='UNKNOWN'?'UNKNOWN':'PARTIAL');
        $proof=hash('sha256',Importer::canonicalJson(['origin'=>$origin,'basis'=>$basis,'invoice_version'=>(int)$version]));
        $pdo->prepare('INSERT INTO invoice_scope_availability(invoice_id,origin,objects_state,periods_state,availability,classification,runtime_hold,evidence_sha256,admission_sha256,source_sha256,command_key,invoice_version) VALUES(?,?,?,?,?,?,NULL,?,?,?,?,?) ON DUPLICATE KEY UPDATE origin=VALUES(origin),objects_state=VALUES(objects_state),periods_state=VALUES(periods_state),availability=VALUES(availability),classification=VALUES(classification),runtime_hold=NULL,evidence_sha256=VALUES(evidence_sha256),admission_sha256=VALUES(admission_sha256),source_sha256=VALUES(source_sha256),command_key=VALUES(command_key),invoice_version=VALUES(invoice_version),revision=revision+1')->execute([
            $invoiceId,$origin,$objects,$periods,$availability,$basis['classification'],$proof,self::CANDIDATE_MANIFEST,$basis['source_sha256'],$command,$version,
        ]);
    }
}
