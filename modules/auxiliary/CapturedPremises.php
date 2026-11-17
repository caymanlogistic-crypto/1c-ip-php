<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{DB,StorageBudget};
use Modules\Integrations\CaptureReader;
use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Typed technical/owner catalogue import; no rental financial or workflow writes. */
final class CapturedPremises
{
    public const TABLES = [
        'premises_importbatch'=>1,
        'premises_premise'=>114,
        'premises_importidentity'=>114,
        'premises_sourcerow'=>392,
        'premises_sourcerow_premises'=>371,
        'premises_confirmedpremise'=>116,
        'premises_ownerevidence'=>9,
        'premises_cadastralobject'=>100,
        'premises_ownerunit'=>113,
        'premises_ownerchange'=>835,
    ];

    public static function plan(string $directory): array
    {
        $reader = new CaptureReader($directory);
        $rows = $inventory = $external = [];
        foreach (self::TABLES as $table=>$expected) {
            $info = $reader->tableInfo($table);
            if ($info['rows']!==$expected) throw new RuntimeException('PREMISES_CAPTURE_COUNT_MISMATCH');
            $inventory[$table]=$info;
            $types=array_column($reader->schema($table)['columns'],'type','name');
            foreach ($reader->rows($table) as $record) {
                $row=[];
                foreach ($record['row'] as $column=>$cell) {
                    if (is_array($cell) && isset($cell['status'])) {
                        $value=$reader->raw($cell,$table,$record['ordinal'],$column);
                        if (!mb_check_encoding($value,'UTF-8')) throw new RuntimeException('PREMISES_CAPTURE_TEXT_ENCODING');
                        if ($types[$column]==='json' && !json_validate($value)) throw new RuntimeException('PREMISES_CAPTURE_JSON_INVALID');
                        $row[$column]=$value;
                        if (($cell['status']??null)==='INCLUDED') {
                            $external[$cell['path']]=['sha256'=>$cell['sha256'],'bytes'=>$cell['bytes']];
                        }
                    } else {
                        $row[$column]=CaptureReader::scalar($cell);
                    }
                }
                $id=$row['id']??null;
                if (!is_string($id) || !ctype_digit($id) || isset($rows[$table][$id])) {
                    throw new RuntimeException('PREMISES_CAPTURE_ID_INVALID');
                }
                foreach (['area'] as $decimal) {
                    if (isset($row[$decimal]) && (!preg_match('/^\d{1,11}\.\d{3}$/D',$row[$decimal]) || bccomp($row[$decimal],'0',3)<0)) {
                        throw new RuntimeException('PREMISES_CAPTURE_DECIMAL_INVALID');
                    }
                }
                $rows[$table][$id]=['fields'=>$row,'ordinal'=>$record['ordinal'],'sha256'=>$record['sha256'],'evidence'=>$record['row']];
            }
        }
        // Every generator reached EOF and verified complete source bytes before any SQL.
        $pins=CaptureReader::PINS;
        $hash=hash('sha256',Importer::canonicalJson(['pins'=>$pins,'tables'=>$inventory]));
        return ['hash'=>$hash,'pins'=>$pins,'inventory'=>$inventory,'rows'=>$rows,'external'=>$external];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private static function immutable(string $path,string $bytes): void
    {
        if (is_link($path)) throw new RuntimeException('PREMISES_CAPTURE_LINK_REJECTED');
        if (is_file($path)) {
            if (!hash_equals(hash('sha256',$bytes),(string)hash_file('sha256',$path))) throw new RuntimeException('PREMISES_CAPTURE_COPY_CONFLICT');
            return;
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) throw new RuntimeException('PREMISES_CAPTURE_COPY_FAILED');
        $stream=fopen($path,'xb');
        if (!$stream) throw new RuntimeException('PREMISES_CAPTURE_COPY_FAILED');
        try {
            if (fwrite($stream,$bytes)!==strlen($bytes) || !fflush($stream)) throw new RuntimeException('PREMISES_CAPTURE_COPY_FAILED');
            if (function_exists('fsync')) fsync($stream);
        } finally {fclose($stream);}
        chmod($path,0400);
    }

    private static function archive(string $directory,array $plan): string
    {
        $target=APP_ROOT.'/private/auxiliary/capture/'.$plan['hash'];
        $size=array_sum(array_column($plan['inventory'],'bytes'))+array_sum(array_column($plan['external'],'bytes'));
        StorageBudget::target($target,$size+16777216);
        $reader=new CaptureReader($directory);
        foreach ($plan['inventory'] as $table=>$info) {
            $reader->copy($info['path'],$info['sha256'],$info['bytes'],$target.'/'.$info['path']);
        }
        foreach ($plan['external'] as $path=>$info) {
            $reader->copy($path,$info['sha256'],$info['bytes'],$target.'/'.$path);
        }
        foreach ($plan['pins'] as $path=>$sha) {
            $bytes=file_get_contents($reader->path($path));
            if (!is_string($bytes) || !hash_equals($sha,hash('sha256',$bytes))) throw new RuntimeException('PREMISES_CAPTURE_METADATA_CHANGED');
            self::immutable($target.'/metadata/'.$path,$bytes);
        }
        self::immutable($target.'/source-receipt.json',self::json([
            'capture_hash'=>$plan['hash'],'pins'=>$plan['pins'],'tables'=>$plan['inventory'],
            'external_cells'=>$plan['external'],'original_workbook_retained'=>false,
            'scope'=>'technical and confirmed owner catalogue only; no rental overlay',
        ]));
        return 'private/auxiliary/capture/'.$plan['hash'];
    }

    private static function insert(PDO $pdo,string $table,array $fields): int
    {
        $sql='INSERT INTO '.$table.' (`'.implode('`,`',array_keys($fields)).'`) VALUES('.implode(',',array_fill(0,count($fields),'?')).')';
        try {
            $pdo->prepare($sql)->execute(array_values($fields));
        } catch (\PDOException $error) {
            throw new RuntimeException('PREMISES_CAPTURE_INSERT_FAILED '.$table.' '.($error->errorInfo[1]??$error->getCode()));
        }
        return (int)$pdo->lastInsertId();
    }

    private static function fields(array $row,array $names): array
    {
        $result=[];
        foreach ($names as $name) {
            if (!array_key_exists($name,$row)) throw new RuntimeException('PREMISES_CAPTURE_FIELD_MISSING');
            $result[$name]=$row[$name];
        }
        return $result;
    }

    public static function import(string $directory): array
    {
        $plan=self::plan($directory);
        $archive=self::archive($directory,$plan);
        return DB::transaction(function(PDO $pdo) use($plan,$archive): array {
            $pdo->query('SELECT id FROM aux_write_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $q=$pdo->prepare('SELECT id,archive_path FROM aux_capture_imports WHERE capture_hash=?');
            $q->execute([$plan['hash']]);
            if ($old=$q->fetch()) return ['capture_hash'=>$plan['hash'],'capture_id'=>(int)$old['id'],'replay'=>true,'counts'=>self::TABLES,'archive_path'=>$old['archive_path']];
            $capture=self::insert($pdo,'aux_capture_imports',[
                'capture_hash'=>$plan['hash'],'source_pins'=>self::json($plan['pins']),
                'table_inventory'=>self::json($plan['inventory']),'archive_path'=>$archive,
            ]);
            $map=[];
            $bind=function(string $source,string $id,string $target,int $targetId) use($pdo,$capture,&$map): void {
                $map[$source][$id]=$targetId;
                self::insert($pdo,'aux_capture_bindings',['source_table'=>$source,'source_id'=>$id,'target_table'=>$target,'target_id'=>$targetId,'capture_id'=>$capture]);
            };
            $ref=function(string $table,mixed $id) use(&$map): ?int {
                if ($id===null) return null;
                return $map[$table][(string)$id]??throw new RuntimeException('PREMISES_CAPTURE_FOREIGN_KEY_MISSING');
            };
            foreach ($plan['rows'] as $table=>$records) {
                foreach ($records as $id=>$record) {
                    self::insert($pdo,'aux_capture_records',[
                        'capture_id'=>$capture,'source_table'=>$table,'source_id'=>$id,
                        'source_ordinal'=>$record['ordinal'],'row_sha256'=>$record['sha256'],'raw_evidence'=>self::json($record['evidence']),
                    ]);
                }
            }
            foreach ($plan['rows']['premises_importbatch'] as $id=>$record) {
                $r=$record['fields'];
                $batch=self::insert($pdo,'aux_import_batches',[
                    'sha256'=>$r['sha256'],'kind'=>'captured-premises','filename'=>$r['filename'],
                    'archive_path'=>$archive,'created_at'=>$r['created_at'],
                ]);
                self::insert($pdo,'aux_import_reports',['batch_id'=>$batch,'parser_version'=>$r['parser_version'],'sheet'=>$r['sheet'],'report_json'=>$r['report']]);
                $bind('premises_importbatch',(string)$id,'aux_import_batches',$batch);
            }
            foreach ($plan['rows']['premises_premise'] as $id=>$record) {
                $r=$record['fields'];
                $fields=self::fields($r,['address','designation','floor','kind','area','cadastral_number','account','parent_description','note','area_variants','account_variants','revision','creation_key']);
                // Historical NULL is retained in immutable source evidence. This is our import idempotency key.
                if ($fields['creation_key']===null) $fields['creation_key']='capture-'.hash('sha256',CaptureReader::PROJECT.'/'.$plan['hash'].'/premises_premise/'.$id);
                $fields+=['source_created_at'=>$r['created_at'],'source_updated_at'=>$r['updated_at']];
                $bind('premises_premise',(string)$id,'aux_premises',self::insert($pdo,'aux_premises',$fields));
            }
            foreach ($plan['rows']['premises_premise'] as $id=>$record) {
                $pdo->prepare('UPDATE aux_premises SET parent_id=? WHERE id=?')->execute([$ref('premises_premise',$record['fields']['parent_id']),$ref('premises_premise',$id)]);
            }
            foreach ($plan['rows']['premises_importidentity'] as $id=>$record) {
                $r=$record['fields'];$premise=$ref('premises_premise',$r['premise_id']);
                self::insert($pdo,'aux_import_identities',['identity_key'=>$r['key'],'premise_id'=>$premise]);
                $bind('premises_importidentity',(string)$id,'aux_premises',$premise);
            }
            foreach ($plan['rows']['premises_sourcerow'] as $id=>$record) {
                $r=$record['fields'];
                $target=self::insert($pdo,'aux_source_rows',['batch_id'=>$ref('premises_importbatch',$r['batch_id']),'source_row_number'=>$r['row_number'],'hidden'=>$r['hidden'],'raw_evidence'=>$r['raw'],'reason'=>$r['reason']]);
                $bind('premises_sourcerow',(string)$id,'aux_source_rows',$target);
            }
            foreach ($plan['rows']['premises_sourcerow_premises'] as $id=>$record) {
                $r=$record['fields'];$row=$ref('premises_sourcerow',$r['sourcerow_id']);
                self::insert($pdo,'aux_premise_source_links',['source_row_id'=>$row,'premise_id'=>$ref('premises_premise',$r['premise_id'])]);
                $bind('premises_sourcerow_premises',(string)$id,'aux_source_rows',$row);
            }
            foreach ($plan['rows']['premises_confirmedpremise'] as $id=>$record) {
                $r=$record['fields'];unset($r['id']);
                $bind('premises_confirmedpremise',(string)$id,'aux_confirmed_premises',self::insert($pdo,'aux_confirmed_premises',$r));
            }
            foreach ($plan['rows']['premises_ownerevidence'] as $id=>$record) {
                $r=$record['fields'];
                $target=self::insert($pdo,'aux_owner_evidence',[
                    'evidence_key'=>$r['key'],'received_on'=>$r['received_on'],'sequence_number'=>$r['sequence'],
                    'sha256'=>$r['sha256'],'payload'=>$r['payload'],'source_created_at'=>$r['created_at'],
                ]);
                $bind('premises_ownerevidence',(string)$id,'aux_owner_evidence',$target);
            }
            foreach ($plan['rows']['premises_cadastralobject'] as $id=>$record) {
                $r=$record['fields'];$fields=self::fields($r,['number','level','floor','source_positions']);
                $fields['evidence_id']=$ref('premises_ownerevidence',$r['evidence_id']);
                $bind('premises_cadastralobject',(string)$id,'aux_owner_cadastral_objects',self::insert($pdo,'aux_owner_cadastral_objects',$fields));
            }
            foreach ($plan['rows']['premises_ownerunit'] as $id=>$record) {
                $r=$record['fields'];$fields=self::fields($r,['role','ownership','sale_date','legal_unit_key','aliases','notes']);
                $fields+=[
                    'premise_id'=>$ref('premises_confirmedpremise',$r['premise_id']),
                    'canonical_premise_id'=>$ref('premises_confirmedpremise',$r['canonical_premise_id']),
                    'cadastral_object_id'=>$ref('premises_cadastralobject',$r['cadastral_object_id']),
                    'evidence_id'=>$ref('premises_ownerevidence',$r['evidence_id']),
                ];
                $bind('premises_ownerunit',(string)$id,'aux_owner_units',self::insert($pdo,'aux_owner_units',$fields));
            }
            foreach ($plan['rows']['premises_ownerchange'] as $id=>$record) {
                $r=$record['fields'];
                $target=self::insert($pdo,'aux_owner_changes',[
                    'premise_id'=>$ref('premises_confirmedpremise',$r['premise_id']),
                    'evidence_id'=>$ref('premises_ownerevidence',$r['evidence_id']),
                    'field'=>$r['field'],'before_value'=>$r['before'],'after_value'=>$r['after'],
                ]);
                $bind('premises_ownerchange',(string)$id,'aux_owner_changes',$target);
            }
            return ['capture_hash'=>$plan['hash'],'capture_id'=>$capture,'replay'=>false,'counts'=>self::TABLES,'archive_path'=>$archive];
        });
    }
}
