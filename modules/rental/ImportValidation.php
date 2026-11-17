<?php
declare(strict_types=1);
namespace Modules\Rental;
use PDO;
use RuntimeException;

final class ImportValidation
{
    private static function canonical(mixed $v):mixed {if(is_object($v)){$a=get_object_vars($v);ksort($a,SORT_STRING);$o=new \stdClass();foreach($a as $k=>$x)$o->$k=self::canonical($x);return $o;}return is_array($v)?array_map(self::canonical(...),$v):$v;}
    public static function verify(array $state,array $mapping,string $statePath):array
    {
        $d=$state['domain']??throw new RuntimeException('State должен содержать domain.');$project=$state['projectId']??'';
        if($project!=='f6e21e3c-d974-4604-82fb-333da66559d2'||($mapping['sourceNamespace']??'')!=='onec-owner-confirmed-copy:'.$project)throw new RuntimeException('Импорт другого проекта запрещён.');
        if(($state['schema']??null)!==1||($mapping['schema']??null)!==1||($mapping['kind']??'')!=='LOCAL_OFFLINE_DATA_REPLACEMENT_CANDIDATE')throw new RuntimeException('Неподдерживаемая схема/классификация пакета.');
        if(($mapping['asOf']??'')!=='2026-10-31')throw new RuntimeException('Неожиданный cutoff пакета; требуется отдельный план импорта.');
        if(($mapping['sourceState']['sourceEpoch']??null)!==($state['epoch']??null)||($mapping['sourceState']['sourceRevision']??null)!==($state['revision']??null))throw new RuntimeException('Source epoch/revision не соответствует mapping.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$mapping['sourceState']['sha256']??'')||!preg_match('/^[a-f0-9]{64}$/D',$mapping['sourceState']['sourceDomainHash']??''))throw new RuntimeException('Отсутствует source-state provenance hash.');
        $counts=[];foreach(['parties','contracts','invoices','payments','objects','cadastre'] as $key){$counts[$key]=count($d[$key]??[]);if(($mapping['counts'][$key]??null)!==$counts[$key])throw new RuntimeException('Mapping count mismatch: '.$key);$ids=[];foreach($d[$key] as $row){if(isset($ids[(string)$row['id']]))throw new RuntimeException('Повторный source ID: '.$key);$ids[(string)$row['id']]=$row;}foreach($mapping[$key]??[] as $b){if(!isset($b['erpId'])||!isset($ids[(string)$b['erpId']]))throw new RuntimeException('Mapping ссылается на отсутствующий '.$key);if(isset($b['amount'])&&isset($ids[(string)$b['erpId']]['amount'])&&bccomp((string)$b['amount'],(string)$ids[(string)$b['erpId']]['amount'],2)!==0)throw new RuntimeException('Mapping financial mismatch: '.$key);}}
        // Hashes cover the producer's recursively key-sorted JSON representation.
        // Original objects preserve {} versus []; operational decimals use decodeExact separately.
        $object=json_decode(file_get_contents($statePath),false,512,JSON_THROW_ON_ERROR);$checked=[];foreach($mapping['protectedDomainHashes']??[] as $key=>$hash){if(!property_exists($object->domain,$key))throw new RuntimeException('Protected field missing: '.$key);$bytes=json_encode(self::canonical($object->domain->$key),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(hash('sha256',$bytes)!==$hash)throw new RuntimeException('Protected hash mismatch: '.$key);$checked[]=$key;}
        if(!$checked)throw new RuntimeException('Отсутствует protected hash inventory.');
        $nested=0;foreach($d['invoices'] as $i)$nested+=count($i['payments']??[]);if($nested!==($mapping['counts']['nestedPaymentEvents']??null))throw new RuntimeException('Nested payment count mismatch.');
        foreach($mapping['payments'] as $b)if(($b['mirrorOfNestedEvent']??null)!==true||($b['cashReplayAllowed']??null)!==false)throw new RuntimeException('Неподдерживаемая классификация исторического денежного события.');
        return ['validatorVersion'=>1,'counts'=>$counts,'protectedHashes'=>count($checked),'asOf'=>$mapping['asOf'],'sourceStateHash'=>$mapping['sourceState']['sha256'],'sourceStateIsPreAdaptation'=>true,'deltaPolicy'=>'already_applied_not_replayed','archivePolicy'=>'non_operational','futurePlans'=>count($mapping['futurePlans']??[]),'unresolvedReview'=>count($mapping['review']??[])];
    }
    public static function bindings(PDO $p,array $m,string $stateHash,string $mappingHash,array $report):void
    {
        $put=$p->prepare('INSERT IGNORE INTO rental_source_bindings(namespace,collection,source_id,entity_type,entity_id,relation_kind,evidence_json,mapping_hash) VALUES(?,?,?,?,?,?,?,?)');
        $add=function(string $collection,string $source,string $type,int $id,string $relation,array $evidence)use($put,$m,$mappingHash){if($source==='')return;$put->execute([$m['sourceNamespace'],$collection,$source,$type,$id,$relation,Importer::json($evidence),$mappingHash]);};
        foreach($m['parties'] as $x)$add($x['sourceCollection']??'Catalog_Контрагенты',$x['source1CRef_Key']??'','party',(int)$x['erpId'],'identity',$x);
        foreach($m['contracts'] as $x){foreach($x['accountingContractRefs']??[] as $ref)$add('Catalog_ДоговорыКонтрагентов',$ref['ref']??'','contract',(int)$x['erpId'],!empty($ref['security'])?'security_contract':'rental_contract',$ref);foreach($x['sourceConditions']??[] as $condition)$add('rental_conditions',$condition['condition_id']??'','contract',(int)$x['erpId'],'accepted_condition',$condition);}
        foreach($m['invoices'] as $x)$add($x['sourceCollection']??'Document_СчетНаОплатуПокупателю',$x['source1CRef_Key']??'','invoice',(int)$x['erpId'],'invoice_control',$x);
        foreach($m['payments'] as $x)$add($x['sourceReceiptCollection']??'Document_ПоступлениеНаРасчетныйСчет',($x['sourceReceiptRef']??'').':'.($x['sourceAllocationLine']??''),'payment',(int)$x['erpId'],'allocation_no_cash_replay',$x);
        foreach($m['objects'] as $x)$add('xlsx_rental_units',$x['object_import_key']??'','object',(int)$x['erpId'],'object_identity',$x);
        $p->prepare('INSERT IGNORE INTO rental_import_validations(state_hash,mapping_hash,validator_version,report_json) VALUES(?,?,1,?)')->execute([$stateHash,$mappingHash,Importer::json($report)]);
    }
}
