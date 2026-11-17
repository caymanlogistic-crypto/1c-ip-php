<?php
declare(strict_types=1);
namespace App;

use Modules\Rental\Importer;
use RuntimeException;

/** Read-only pinned OUTPUT projection. No fallback to server capture or archived input. */
final class OperatingScopeArtifact
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';

    public static function load(string $manifestPath,string $ownerPath,string $statePath,string $mappingPath): array
    {
        $manifest=self::pinned($manifestPath,OperatingMembership::SCOPE);
        $owner=self::pinned($ownerPath,OperatingMembership::OWNER);
        $state=self::pinned($statePath,OperatingMembership::STATE,true);
        $mapping=self::pinned($mappingPath,OperatingMembership::MAPPING,true);
        if (($owner['mode']??null)!=='PERIOD_START_PRESERVE_LATER' || ($owner['operatingStart']??null)!=='2026-10-01' || ($owner['parentManifest']['sha256']??null)!==OperatingMembership::SCOPE) throw new RuntimeException('Owner semantics are not bound to this scope.');
        if (($state['projectId']??null)!==self::PROJECT || !is_string($mapping['sourceNamespace']??null) || $mapping['sourceNamespace']==='') throw new RuntimeException('Operating source project/namespace is invalid.');
        $fragments=[];
        foreach ($manifest['fragments']??[] as $fragment) {
            $name=basename(str_replace('\\','/',$fragment['path']??''));
            if ($name==='' || !preg_match('/^[a-z0-9.-]+\.json$/D',$name) || isset($fragments[$name])) throw new RuntimeException('Invalid operating fragment identity.');
            $path=dirname($manifestPath).'/'.($name==='contracts-baseline01oct.compact.v1.json'?'contracts/':'').$name;
            self::pinned($path,$fragment['sha256']??'');
            if (filesize($path)!==($fragment['bytes']??null)) throw new RuntimeException('Operating fragment byte length differs.');
            $fragments[$name]=$fragment['sha256'];
        }
        if (count($fragments)!==6) throw new RuntimeException('All six scope fragments are required.');
        $invoiceSets=$manifest['exactCuratedInvoiceIds']??[];
        $paymentSets=$manifest['exactCuratedAllocationLinkIds']??[];
        $operatingInvoices=self::ids($invoiceSets['includedIssueEvents']??[],66);
        $contextInvoices=self::ids($invoiceSets['archiveIssueEventsBeforeLower']??[],67);
        $operatingPayments=self::ids($paymentSets['includedByNativeReceiptDate']??[],66);
        $contextPayments=self::ids($paymentSets['archiveBeforeLower']??[],48);
        $historical=self::ids($paymentSets['sep25To30HistoricalControlNoReplay']??[],16);
        $prewindow=$manifest['retainedPrewindowObligations']['preWindowOctoberBilledReferences']??[];
        if (count($prewindow)!==59) throw new RuntimeException('All 59 prewindow October references must be retained.');
        $prewindowIds=self::ids(array_column($prewindow,'invoiceId'),59);
        if (array_diff($historical,$operatingPayments) || array_diff($prewindowIds,$contextInvoices)) throw new RuntimeException('Context/reference subsets differ from admitted event sets.');
        $olderLinks=$paymentSets['postLowerLinksToPrewindowInvoices']??[];
        if (count($olderLinks)!==15) throw new RuntimeException('All 15 older invoice payment links are required.');
        foreach ($olderLinks as $link) {
            if (!in_array($link['paymentLinkId']??null,$operatingPayments,true) || !in_array($link['invoiceId']??null,$contextInvoices,true)) throw new RuntimeException('Older invoice payment link is outside its source sets.');
        }
        $invoices=self::project('invoice',$state['domain']['invoices']??[],$mapping['invoices']??[],$mapping['sourceNamespace'],$operatingInvoices,$contextInvoices);
        $payments=self::project('payment',self::materializedPayments($state['domain']),$mapping['payments']??[],$mapping['sourceNamespace'],$operatingPayments,$contextPayments);
        $result=['project_id'=>self::PROJECT,'scope_manifest_sha256'=>OperatingMembership::SCOPE,'owner_semantics_sha256'=>OperatingMembership::OWNER,'state_sha256'=>OperatingMembership::STATE,'mapping_sha256'=>OperatingMembership::MAPPING,'fragments'=>$fragments,'invoices'=>$invoices,'payments'=>$payments,'historical_nonposting_payment_ids'=>$historical,'postcut_links_to_context'=>$olderLinks,'prewindow_october_references'=>$prewindow,'opening_runtime_admission'=>'UNKNOWN','cash_replay_allowed'=>false];
        $result['projection_sha256']=self::hash($result);
        return $result;
    }

    private static function project(string $kind,array $records,array $mapping,string $namespace,array $operating,array $context): array
    {
        $all=array_merge($operating,$context);
        if (count(array_unique($all,SORT_NUMERIC))!==count($all) || count($records)!==count($all) || count($mapping)!==count($all)) throw new RuntimeException('Operating sets overlap or omit a source record.');
        $indexed=[];
        foreach ($mapping as $binding) {
            $id=$binding['erpId']??null;
            if (!is_int($id) || isset($indexed[$id])) throw new RuntimeException('Mapping IDs must be unique native integers.');
            $indexed[$id]=$binding;
        }
        $result=[];
        foreach ($records as $record) {
            $id=$record['id']??null;
            if (!is_int($id) || !in_array($id,$all,true) || isset($result[$id]) || !isset($indexed[$id])) throw new RuntimeException('OUTPUT identity is not uniquely bound to this scope.');
            $binding=$indexed[$id];
            $date=$record[$kind==='invoice'?'issued':'date']??null;
            if (!is_string($date) || !is_string($binding['sourceDate']??null) || substr($binding['sourceDate'],0,10)!==$date) throw new RuntimeException('Typed event date differs from actual mapped source date.');
            $membership=in_array($id,$operating,true)?'OPERATING':'CONTEXT';
            if (OperatingMembership::classify($date)!==$membership) throw new RuntimeException('Event date disagrees with exact scope membership.');
            $collection=$binding[$kind==='invoice'?'sourceCollection':'sourceReceiptCollection']??null;
            $sourceId=$binding[$kind==='invoice'?'source1CRef_Key':'sourceReceiptRef']??null;
            if (!is_string($collection) || $collection==='' || !is_string($sourceId) || $sourceId==='') throw new RuntimeException('Native source identity is required.');
            if ($kind==='payment') {
                $line=$binding['sourceAllocationLine']??null;
                if ((!is_int($line) && !is_string($line)) || !preg_match('/^[0-9]+$/D',(string)$line) || ($binding['cashReplayAllowed']??null)!==false || ($binding['invoiceErpId']??null)!==($record['invoice']??null)) throw new RuntimeException('Payment allocation provenance is invalid.');
                $sourceId.=':'.$line;
            }
            $result[$id]=['id'=>$id,'origin'=>'imported-output','membership'=>$membership,'event_date'=>$date,'source_namespace'=>$namespace,'source_collection'=>$collection,'source_id'=>$sourceId,'relation_kind'=>$kind==='invoice'?'invoice_control':'allocation_no_cash_replay','evidence_sha256'=>self::hash(['record'=>$record,'binding'=>$binding,'membership'=>$membership]),'source_record'=>$record,'source_binding'=>$binding];
            if ($kind==='payment') $result[$id]['posting_state']='ALREADY_APPLIED';
        }
        ksort($result,SORT_NUMERIC);
        return array_values($result);
    }

    /** Exact Importer mirror merge: source metadata is retained, never a second cash event. */
    private static function materializedPayments(array $domain): array
    {
        $nested=[];$result=[];
        foreach ($domain['invoices']??[] as $invoice) foreach ($invoice['payments']??[] as $payment) {
            $id=$payment['id']??null;
            if (!is_int($id) || isset($nested[$id])) throw new RuntimeException('Nested payment identities must be unique native integers.');
            $payment['invoice']=$invoice['id'];$nested[$id]=$payment;
        }
        foreach ($domain['payments']??[] as $payment) {
            $id=$payment['id']??null;
            if (!is_int($id) || !isset($nested[$id]) || isset($result[$id])) throw new RuntimeException('Every payment requires one exact nested source mirror.');
            foreach (['amount','invoice','date','source'] as $field) {
                if (($nested[$id][$field]??null)!==($payment[$field]??null)) throw new RuntimeException('Payment source mirrors disagree.');
            }
            $result[$id]=array_replace($nested[$id],$payment);
        }
        if (count($result)!==114 || count($nested)!==114) throw new RuntimeException('All 114 source mirrors must form one allocation set.');
        return array_values($result);
    }

    private static function pinned(string $path,string $sha,bool $exactDecimals=false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha) || !is_file($path) || is_link($path)) throw new RuntimeException('Operating source file hash differs.');
        $raw=file_get_contents($path);
        if ($raw===false || !hash_equals($sha,hash('sha256',$raw))) throw new RuntimeException('Operating source file hash differs.');
        $value=$exactDecimals?Importer::decodeExact($raw):json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Operating source must be a JSON object.');
        return $value;
    }

    private static function ids(array $ids,int $expected): array
    {
        if (!array_is_list($ids) || count($ids)!==$expected) throw new RuntimeException('Operating source set count differs.');
        foreach ($ids as $id) if (!is_int($id) || $id<=0) throw new RuntimeException('Operating source IDs must retain native integer types.');
        if (count(array_unique($ids,SORT_NUMERIC))!==count($ids)) throw new RuntimeException('Operating source IDs are duplicated.');
        sort($ids,SORT_NUMERIC);
        return $ids;
    }

    public static function hash(array $value): string
    {
        $normalize=static function(mixed $item)use(&$normalize):mixed{if(!is_array($item))return $item;if(!array_is_list($item))ksort($item,SORT_STRING);foreach($item as $key=>$child)$item[$key]=$normalize($child);return $item;};
        return hash('sha256',json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
}
