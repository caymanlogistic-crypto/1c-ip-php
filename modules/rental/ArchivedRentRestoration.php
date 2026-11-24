<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,BusinessIds,ContractOccupancy};
use Modules\Integrations\{Config,Support,YakovlevImport};
use PDO;
use RuntimeException;

/** Restore the reviewed contract and original 1C invoices in one business transaction. */
final class ArchivedRentRestoration
{
    private const RSTROY_PARTY_REF = 'a444cc56-df4f-11f1-ae1e-005056950eec';

    private const LEASES = [
        '160'=>['2026-10-15','2026-11-29','2027-10-28','296861.36',1076,1009,'office',115,
            ['АЯИП-001148'=>['19790.76',true],'АЯИП-001258'=>['296861.36',false]],['128','2026-11-28']],
        '161'=>['2026-11-02','2026-12-01','2027-10-01','210067.00',1080,1024,'office',116,
            ['АЯИП-001300'=>['210067.00',true]],['130','2026-11-30']],
        '164'=>['2026-11-11','2026-11-16','2027-10-15','577500.00',[1093=>null,1094=>null],null,'office',112,
            ['АЯИП-001251'=>['288750.00',true],'АЯИП-001252'=>['577500.00',true]]],
        '64'=>['2026-11-02','2026-11-05','2027-10-02','25200.00',1043,1016,'parking',44,
            ['АЯИП-001310'=>['25200.00',false]]],
        '149/1'=>['2026-10-10','2026-10-10','2026-12-31','12000.00',1105,900015,'legal',393,
            ['АЯИП-001225'=>['32516.55',false]]],
        '132'=>['2025-12-01','2026-10-01','2027-02-28','15000.00',1101,null,'legal',352,
            ['АЯИП-001048'=>['15000.00',false],'АЯИП-001165'=>['15000.00',false],'АЯИП-001269'=>['15000.00',false]]],
        '56/10'=>['2023-10-25','2026-10-01','2027-06-26','12705.00',1084,null,'legal',159,
            ['АЯИП-001062'=>['12705.00',true],'АЯИП-001186'=>['12705.00',true],'АЯИП-001278'=>['12705.00',true]]],
        '32'=>['2022-09-20','2026-10-01','2027-04-19','10000.00',1073,null,'legal',121,
            ['АЯИП-001092'=>['10000.00',true],'АЯИП-001112'=>['10000.00',true],'АЯИП-001306'=>['10000.00',false]]],
        '148'=>['2026-05-20','2026-10-01','2027-04-21','10500.00',1101,null,'legal',377,
            ['АЯИП-001091'=>['10500.00',true],'АЯИП-001212'=>['10500.00',true],'АЯИП-001305'=>['10500.00',false]]],
        '75'=>['2024-06-10','2026-09-01','2026-12-31','10500.00',1069,null,'billing-only',20,
            ['АЯИП-001088'=>['31500.00',true]]],
        '65'=>['2026-11-11','2026-11-16','2026-12-31','48300.00',[1015=>'24150.00',1016=>'24150.00'],null,'parking',null,
            ['АЯИП-001322'=>['72450.00',true]]],
    ];

    public static function execute(PDO $db,array $actor,array $input): array
    {
        ArchivedRentCorrection::requireOperator();
        if (!$db->inTransaction()) throw new RuntimeException('Восстановление договора должно выполняться в транзакции.');
        $number=(string)$input['restore_number'];
        $spec=self::LEASES[$number] ?? throw new RuntimeException('В реестре нет проверенного основания восстановления.',409);
        [$signed,$from,$until,$rate,$object,$knownParty,$use,$registerRow,$invoices]=$spec;
        $sharedParking=null;
        if (array_key_exists('shared_parking_reservation',$input)) {
            $reservation=filter_var($input['shared_parking_reservation'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($number!=='64' || $reservation===false) {
                throw new RuntimeException('Укажите подтверждённую бронь совместной аренды по договору №64.',409);
            }
            $from='2026-11-03';
            $sharedParking=self::flyLedgerSharedParking($db,$reservation,(int)$object,$from);
            $invoices=['АЯИП-001243'=>['23520.00',true]]+$invoices;
        }
        $objectRates=is_array($object) ? $object : [$object=>$rate];
        $store=Config::store();
        $query=$db->prepare("SELECT * FROM external_documents WHERE provider=? AND account_id='invoices' AND number=?
            AND status='Проведён' AND amount=? FOR UPDATE");
        $documents=[];
        $parties=[];
        $contracts=[];
        foreach ($invoices as $number1c=>[$amount]) {
            $query->execute([YakovlevImport::PROVIDER,$number1c,$amount]);
            $rows=$query->fetchAll();
            if (count($rows)!==1) throw new RuntimeException('Нет однозначного оригинала счёта '.$number1c.'.',409);
            $archive=$rows[0];
            $document=Support::decode($store->read($archive['raw_path'],$archive['raw_sha256']));
            $source=$document['fields']['record'];
            $normalized=YakovlevImport::normalize($document);
            if ($archive['source_id']!==$normalized['source_id'] || $normalized['status']!=='Проведён'
                || !MonthlyRentReconciliation::rentLines($source)) {
                throw new RuntimeException('Оригинал счёта не подтверждён.',409);
            }
            $parties[]=$source['Контрагент_Key'];
            $contracts[]=$source['ДоговорКонтрагента_Key'];
            $documents[$number1c]=[$archive,$document];
        }
        if (count(array_unique($parties))!==1 || count(array_unique($contracts))!==1)
            throw new RuntimeException('В счетах разные исходные договоры или контрагенты.',409);
        $partyRef=\Modules\Integrations\OnecMapping::guid($parties[0]);
        $contractRef=\Modules\Integrations\OnecMapping::guid($contracts[0]);
        $basis=['register_row'=>$registerRow,'source_contract'=>$contractRef,'payer'=>$partyRef,
            'accounting_from'=>$from,'use'=>$use,'source_invoices'=>array_map(static fn($entry)=>$entry[0]['raw_sha256'],$documents)];
        if ($sharedParking!==null) {
            $basis['first_invoice']='АЯИП-001243';
            $basis['shared_parking']=$sharedParking;
        }
        if ($number==='164') {
            $basis['register_as_of']='2026-11-18';
            $basis['register_sha256']='328caf9a55923613b1845cce03fd65b0fe1e9f7d795a6c1d2f6193443f192fb2';
            $basis['register_party_ref']='c19311a4-c2be-11ef-9f81-005056954c70';
            $basis['first_invoice']='АЯИП-001251';
            if ($partyRef!==self::RSTROY_PARTY_REF || $contractRef!=='e92b088f-df4f-11f1-ae1e-005056950eec')
                throw new RuntimeException('Изменилась связь Р-СТРОЙ с оригиналами 1С.',409);
        }
        $party=self::party($db,$partyRef,$knownParty);
        $used=$db->prepare("SELECT 1 FROM contracts WHERE number=? OR EXISTS (
            SELECT 1 FROM rental_source_bindings b WHERE b.entity_id=contracts.id AND b.entity_type='contract'
                AND b.collection='Catalog_ДоговорыКонтрагентов' AND b.source_id=?)");
        $used->execute([$number,$contractRef]);
        if ($used->fetchColumn()) throw new RuntimeException('Договор с этим номером или ключом уже существует.',409);
        $prior=isset($spec[9]) ? self::predecessor($db,$party,(int)$object,$spec[9]) : null;
        $exceptions=[];
        foreach (array_keys($objectRates) as $objectId) {
            $target=Service::get($db,'rental_objects',$objectId);
            if ($target['type']!==($use==='parking'?'Машиноместо':'Офис'))
                throw new RuntimeException('Тип объекта расходится с реестром аренды.',409);
            $reason=match (true) {
                $sharedParking!==null=>'Владелец подтвердил совместную аренду машино-места 1.13 по №64 и №115. Бронь ДЛТК сохраняется.',
                $use==='legal'=>'Юридический адрес указан в оригинале 1С. Помещение учитывается только для начислений.',
                $use==='billing-only'=>'Счёт подтверждён. Физическое закрепление офиса 304 выясняется по договорам №75 и №143.',
                $number==='65' && $objectId===1015=>'Счёт подтверждён. Физическое закрепление машино-места 2.12 выясняется по договорам №65 и №24.',
                default=>null,
            };
            if ($reason===null) Service::available($db,[$objectId],$from,$until,$prior,$party,false,null,false,$prior);
            else $exceptions[$objectId]=['reason'=>$reason,'until'=>$until,'actor_id'=>(int)$actor['id'],'version'=>1];
        }
        $origin=['imported'=>true,'sourceLegalMapping'=>['legalDate'=>$signed,'legalNumber'=>$number,'party1CRef_Key'=>$partyRef],
            'archivedRentRestoration'=>$basis];
        if ($number==='164') {
            $origin['sourceStatus']='Действует';
            $condition=['condition_id'=>'owner-register:2026-11-18:112','kind'=>'RENT','monthly_gross'=>$rate,'start'=>$from,'end'=>$until];
            $origin['sourceLegalMapping']['sourceConditions']=[$condition];
            $origin['sourceLegalMapping']['unresolvedComponentAllocation']=[['condition'=>$condition,'objects'=>array_keys($objectRates)]];
            $origin['ownerConfirmedArchiveBank']=[
                '38cb81e3-e185-11f1-ae1e-005056950eec'=>[
                    'bank_sha'=>'42980e789cdf9dd4d7b3b472d6ea76703fa38f3c4141276a99bb113fa275ecef',
                    'invoice_ref'=>'b940f4b1-df50-11f1-ae1e-005056950eec',
                    'invoice_sha'=>'9accbcd4e9b42c0b9f01ff85074be7c029f8044bea0e5d4126558ae6e40aa2d5',
                    'period'=>'2026-12','purpose_invoice'=>1251,'actor_id'=>(int)$actor['id'],
                ],
            ];
        }
        if ($prior) $origin['archiveContinuity']=['predecessor'=>$prior]+$basis;
        if ($number==='65') {
            $origin['archiveContinuity']=['confirmed_rent_from'=>$from,'confirmed_rent_through'=>$until,'owner_confirmed'=>true];
        }
        if ($exceptions) $origin[ContractOccupancy::EXCEPTIONS]=$exceptions;
        $id=BusinessIds::next($db,'contracts',900000,false);
        $db->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,template_type,source_json)
            VALUES(?,?,?,?,?,?,?,'active',?,?)")->execute([$id,$number,$party,$signed,$from,$number==='65'?null:$until,$rate,
                $use==='parking'?'parking':'office',Importer::json($origin)]);
        $term=$db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json)
            VALUES(?,?,?,?,?,?,?)');
        foreach ($objectRates as $objectId=>$objectRate) {
            Occupancy::insert($db,'contract_objects',['contract_id'=>$id,'object_id'=>$objectId]);
            $term->execute([$id,$objectId,$from,$until,$objectRate,$from,Importer::json($basis)]);
        }
        $evidence=Importer::canonicalJson($basis);
        $db->prepare("INSERT INTO rental_source_bindings(namespace,collection,source_id,entity_type,entity_id,relation_kind,evidence_json,mapping_hash)
            VALUES('verified-rent-register','Catalog_ДоговорыКонтрагентов',?,'contract',?,'rental_contract',?,?)")
            ->execute([$contractRef,$id,$evidence,hash('sha256',$evidence)]);
        $sync=new YakovlevRentalSync($db,$store);
        $settled=[];
        foreach ($documents as $number1c=>[$archive,$document]) {
            $result=$sync->accountAssignedInvoice($archive,$document);
            if ($number==='149/1' && $result['state']==='review') {
                $settled[$number1c]=$result;
                continue;
            }
            if (!in_array($result['state'],['created','existing'],true) || $result['review']!==0
                || ($invoices[$number1c][1] && $result['created']+$result['existing']!==1))
                throw new RuntimeException('Счёт или поступление '.$number1c.' требуют дополнительной сверки.',409);
            $settled[$number1c]=$result;
        }
        $result=['id'=>$id,'number'=>$number,'invoices'=>$settled]+$basis;
        Audit::record(ArchivedRentCorrection::ACTION,'contract',$id,null,$result);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,'Восстановлен договор '.$number.' по проверенному реестру и счёту 1С.',$id,$result);
        return $result;
    }

    private static function flyLedgerSharedParking(PDO $db,int $reservation,int $object,string $from): array
    {
        $query=$db->prepare("SELECT r.id,r.version,r.contract_id,r.document_id FROM reservations r
            JOIN rental_documents d ON d.id=r.document_id AND d.contract_id=r.contract_id
            JOIN document_terms t ON t.document_id=d.id AND t.object_id=r.object_id
            JOIN contracts c ON c.id=r.contract_id AND c.party_id=r.party_id
            JOIN rental_objects o ON o.id=r.object_id
            WHERE r.id=? AND r.object_id=? AND r.status='active' AND r.start_date<=?
                AND r.end_date>='2026-12-31' AND r.until_date>='2026-12-31'
                AND c.number='115' AND c.party_id=1002 AND c.status='active'
                AND d.number='11' AND d.kind='renewal' AND d.applied=0 AND d.status='returned'
                AND o.name='1.13' AND o.active=1 FOR UPDATE");
        $query->execute([$reservation,$object,$from]);
        $rows=$query->fetchAll();
        if (count($rows)!==1) throw new RuntimeException('Изменилась проверенная бронь ДЛТК по месту 1.13. Сверьте ДС №11.',409);
        return $rows[0]+['owner_confirmed'=>true];
    }

    private static function party(PDO $db,string $ref,?int $known): int
    {
        $query=$db->prepare("SELECT id,blocked FROM parties WHERE COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.source_party_guid')),JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.Ref_Key')))=? FOR UPDATE");
        $query->execute([$ref]);
        $rows=$query->fetchAll();
        if (count($rows)===1 && !$rows[0]['blocked'] && ($known===null || (int)$rows[0]['id']===$known)) return (int)$rows[0]['id'];
        if ($rows || $known!==null) throw new RuntimeException('Связь контрагента с 1С не подтверждена.',409);
        $query=$db->prepare("SELECT * FROM external_documents WHERE provider=? AND source_id=? AND account_id='parties'");
        $query->execute([YakovlevImport::PROVIDER,'parties:'.$ref]);
        $archive=$query->fetch() ?: throw new RuntimeException('Нет оригинала контрагента 1С.',409);
        $document=Support::decode(Config::store()->read($archive['raw_path'],$archive['raw_sha256']));
        $source=$document['fields']['record'];
        $inn=(string)($source['ИНН'] ?? '');
        $name=trim((string)($source['Description'] ?? ''));
        if (($source['Ref_Key'] ?? '')!==$ref || ($source['DeletionMark'] ?? true)!==false || !preg_match('/^\d{10}$/D',$inn) || $name==='')
            throw new RuntimeException('Юрлицо 1С не подтверждено.',409);
        $query=$db->prepare('SELECT id,name,kpp,blocked,source_json FROM parties p WHERE inn=? AND '.WorkspaceReset::visibleSql('p').' FOR UPDATE');
        $query->execute([$inn]);
        $existing=$query->fetchAll();
        $proof=['source_party_guid'=>$ref,'source_sha256'=>$archive['raw_sha256']];
        if ($existing) {
            $card=$existing[0];
            $bindings=$db->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='party' AND entity_id=? LIMIT 1 FOR UPDATE");
            $bindings->execute([$card['id']]);
            if ($ref!==self::RSTROY_PARTY_REF || count($existing)!==1 || $card['blocked'] || $card['name']!==$name
                || ($card['kpp'] && $card['kpp']!==($source['КПП'] ?? null))
                || Importer::decodeExact($card['source_json']) || $bindings->fetchColumn()) {
                throw new RuntimeException('Есть другое юридическое лицо с тем же ИНН.',409);
            }
            $db->prepare('UPDATE parties SET source_json=?,version=version+1 WHERE id=?')
                ->execute([Importer::json($proof),$card['id']]);
            Audit::record(ArchivedRentCorrection::ACTION,'party',$card['id'],[],['inn'=>$inn]+$proof);
            return (int)$card['id'];
        }
        $id=BusinessIds::next($db,'parties',900000,false);
        $db->prepare('INSERT INTO parties(id,name,inn,source_json) VALUES(?,?,?,?)')->execute(
            [$id,$name,$inn,Importer::json($proof)]);
        return $id;
    }

    private static function predecessor(PDO $db,int $party,int $object,array $expected): int
    {
        $query=$db->prepare('SELECT c.id,c.end_date,t.end_date term_end FROM contracts c JOIN contract_terms t ON t.contract_id=c.id
            WHERE c.number=? AND c.party_id=? AND t.object_id=? AND t.superseded_on IS NULL FOR UPDATE');
        $query->execute([$expected[0],$party,$object]);
        $rows=$query->fetchAll();
        if (count($rows)!==1 || $rows[0]['end_date']!==$expected[1] || $rows[0]['term_end']!==$expected[1])
            throw new RuntimeException('Нет подтверждённой границы предыдущего договора.',409);
        return (int)$rows[0]['id'];
    }
}
