<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use RuntimeException;

/** The admitted v3 two-field source delta, not a whole-state importer. */
final class InvoiceScopeArtifact
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';
    public const STATE='429f9bee7ebaaf6d221ce89c9b345316e27e44bf7ab7ec13a27989faaad7a148';
    public const MAPPING='27d1e223abbad1bfbe964e9a3ebf2bddf1573a33bc6a5a19efd2762fe77c6624';
    public const PINS=[
        'manifest.json'=>'5fdcf76e9c5c5528ee8627b2bfb9b382f046e1faa7ad4d3730e3ce74288e2b8d',
        'coverage-delta.json'=>'08854a40111d7b02a089eda6ebd27e96193724869164527549d8c97f347e1d8c',
        'invoice-coverage-audit.json'=>'eda8ff3c4014fcc8b07c73d1d78161be52d298101d80346a82a52a92847438ef',
    ];
    public const RAW=[
        'onec-live-before2025-20261114'=>'288d1dcf1f25e465a1560bcaed614e774df5de4cd9e654161623ed1a0641b82a',
        'onec-live-entities-20261114-final'=>'40aca4547d61262b5d4f1764176a4a36b6ead1c0f24050815a0eede554a25a5d',
    ];

    private static function bytes(string $path,string $sha,int $maximum): string
    {
        if (!is_file($path) || is_link($path) || filesize($path)>$maximum || !hash_equals($sha,(string)hash_file('sha256',$path))) throw new RuntimeException('Scope artifact input hash/path failed.');
        return file_get_contents($path)?:throw new RuntimeException('Scope artifact input is empty.');
    }

    private static function fields(array $fields): void
    {
        $keys=array_keys($fields);sort($keys);
        if ($keys!==['coverage','objectIds'] || !is_array($fields['objectIds']) || !array_is_list($fields['objectIds']) || !is_array($fields['coverage']) || !array_is_list($fields['coverage'])) throw new RuntimeException('Scope delta fields differ from the admitted schema.');
        $ids=[];
        foreach ($fields['objectIds'] as $id) {
            if (!is_int($id) || $id<=0 || isset($ids[$id])) throw new RuntimeException('Scope object IDs must retain exact integer type and uniqueness.');
            $ids[$id]=true;
        }
        foreach ($fields['coverage'] as $period) {
            if (!is_array($period)) throw new RuntimeException('Scope interval is invalid.');
            $keys=array_keys($period);sort($keys);
            if ($keys!==['end','object','start'] || !is_int($period['object']) || !isset($ids[$period['object']])) throw new RuntimeException('Scope interval object/type mismatch.');
            foreach (['start','end'] as $date) {
                $value=$period[$date];
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || date('Y-m-d',strtotime($value))!==$value) throw new RuntimeException('Scope interval date is invalid.');
            }
            if ($period['end']<$period['start']) throw new RuntimeException('Scope interval is reversed.');
        }
    }

    public static function load(string $directory,string $statePath,string $mappingPath,string $rawRoot): array
    {
        $raw=$data=[];
        foreach (self::PINS as $file=>$sha) {
            $raw[$file]=self::bytes(rtrim($directory,'/\\').'/'.$file,$sha,8388608);
            // Scope IDs/versions are native integers; no decimal lexer rewrites their types.
            $data[$file]=json_decode($raw[$file],true,512,JSON_THROW_ON_ERROR);
        }
        $stateRaw=self::bytes($statePath,self::STATE,16777216);
        $mappingRaw=self::bytes($mappingPath,self::MAPPING,16777216);
        $state=Importer::decodeExact($stateRaw);$mapping=Importer::decodeExact($mappingRaw);
        if (($state['projectId']??null)!==self::PROJECT || count($state['domain']['invoices']??[])!==133) throw new RuntimeException('Scope correction project/invoice namespace mismatch.');
        $original=array_column($state['domain']['invoices'],null,'id');
        $binding=array_column($mapping['invoices'],null,'erpId');
        if (count($original)!==133 || count($binding)!==133) throw new RuntimeException('Scope correction source IDs are not unique.');
        $manifest=$data['manifest.json'];$delta=$data['coverage-delta.json'];$audit=$data['invoice-coverage-audit.json'];
        if (($manifest['inputs']['state']['sha256']??null)!==self::STATE || ($manifest['inputs']['mapping']['sha256']??null)!==self::MAPPING || ($delta['expectedInputSha256']??null)!==self::STATE || ($delta['scope']??null)!=='ONLY_VERIFIED_TWO_FIELD_CORRECTION') throw new RuntimeException('Scope correction pair/delta binding mismatch.');
        if (($delta['autobillingActivation']??null)!=='NOT_AUTHORIZED' || count($delta['invoiceChanges']??[])!==109 || count($audit['invoices']??[])!==133) throw new RuntimeException('Scope correction count/activation policy mismatch.');
        $audits=$changes=$wanted=[];
        foreach ($audit['invoices'] as $item) {
            $id=$item['invoiceId']??null;
            if (!is_int($id) || !isset($original[$id]) || isset($audits[$id]) || ($item['contractId']??null)!==$original[$id]['contract'] || ($item['sourceInvoiceRef']??null)!==($binding[$id]['source1CRef_Key']??null)) throw new RuntimeException('Scope audit source invoice join mismatch.');
            if (!in_array($item['status']??null,['VERIFIED','AMBIGUOUS'],true)) throw new RuntimeException('Unrecognized source verification state.');
            $old=['objectIds'=>$original[$id]['objectIds'],'coverage'=>$original[$id]['coverage']];
            self::fields($old);
            if ($item['old']!==$old) throw new RuntimeException('Scope audit old preimage/type mismatch.');
            $proof=$item['rawEvidence']??null;
            if (!is_array($proof) || !in_array($proof['fileSha256']??null,self::RAW,true) || !is_int($proof['oneBasedRow']??null) || $proof['oneBasedRow']<1 || !preg_match('/^[a-f0-9]{64}$/D',$proof['rawLineSha256']??'')) throw new RuntimeException('Scope audit raw source evidence missing.');
            $wanted[$proof['fileSha256']][$proof['oneBasedRow']][]=$id;
            $audits[$id]=$item;
        }
        foreach ($delta['invoiceChanges'] as $item) {
            $id=$item['invoiceId']??null;
            if (!is_int($id) || !isset($audits[$id]) || isset($changes[$id]) || $audits[$id]['status']!=='VERIFIED') throw new RuntimeException('Scope delta invoice namespace mismatch.');
            self::fields($item['expectedOld']);self::fields($item['replaceWith']);
            if ($item['expectedOld']!==$audits[$id]['old'] || $item['replaceWith']!==$audits[$id]['new'] || $item['classification']!==$audits[$id]['classification'] || $item['sourceInvoiceRef']!==$audits[$id]['sourceInvoiceRef']) throw new RuntimeException('Scope delta/audit proof mismatch.');
            $changes[$id]=$item;
        }
        if (count(array_filter($audits,fn($i)=>$i['status']==='VERIFIED'))!==109 || count(array_filter($audits,fn($i)=>$i['status']==='AMBIGUOUS'))!==24) throw new RuntimeException('Scope verification cohorts mismatch.');
        $lines=$rawFiles=[];
        foreach (self::RAW as $relative=>$sha) {
            $file=rtrim($rawRoot,'/\\').'/'.$relative.'/entities/invoices.jsonl';
            if (!is_file($file) || is_link($file) || !hash_equals($sha,(string)hash_file('sha256',$file))) throw new RuntimeException('Scope raw file hash failed.');
            $rawFiles[$sha]=['relative'=>$relative.'/entities/invoices.jsonl','sha256'=>$sha,'bytes'=>filesize($file)];
            $handle=fopen($file,'rb');$number=0;
            try {
                while (($line=fgets($handle,16777218))!==false) {
                    $number++;
                    if (strlen($line)>16777216) throw new RuntimeException('Scope raw line exceeds bound.');
                    if (!isset($wanted[$sha][$number])) continue;
                    $trimmed=hash('sha256',rtrim($line,"\r\n"));
                    $wrapper=json_decode($line,true,512,JSON_THROW_ON_ERROR);$invoice=$wrapper['raw']??$wrapper;
                    foreach ($wanted[$sha][$number] as $id) {
                        if (!hash_equals($audits[$id]['rawEvidence']['rawLineSha256'],$trimmed) || ($invoice['Ref_Key']??null)!==$audits[$id]['sourceInvoiceRef']) throw new RuntimeException('Scope raw line hash/reference failed.');
                        $lines[$id]=['physical'=>$line,'physical_sha256'=>hash('sha256',$line),'producer_trimmed_sha256'=>$trimmed,'file_sha256'=>$sha,'one_based_row'=>$number];
                    }
                }
            } finally {fclose($handle);}
        }
        if (count($lines)!==133) throw new RuntimeException('Scope raw evidence inventory is incomplete.');
        ksort($original,SORT_NUMERIC);ksort($changes,SORT_NUMERIC);ksort($audits,SORT_NUMERIC);ksort($lines,SORT_NUMERIC);
        return ['project_id'=>self::PROJECT,'pins'=>self::PINS,'files'=>$raw,'state_raw'=>$stateRaw,'mapping_raw'=>$mappingRaw,'state'=>$state,'original'=>$original,'changes'=>$changes,'audit'=>$audits,'raw_lines'=>$lines,'raw_files'=>$rawFiles];
    }
}
