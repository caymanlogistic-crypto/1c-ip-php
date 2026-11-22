<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,BusinessIds,ContractOccupancy};
use Modules\Integrations\{Config,OnecMapping,Support,YakovlevImport};
use PDO;
use RuntimeException;

/** Restore the dated conditions verified against the rent register and issued 1C originals. */
final class ArchivedRentCorrection
{
    public const ACTION = 'contract.correct-archived-rent';

    public static function requireOperator(): void
    {
        if (PHP_SAPI !== 'cli' || (Auth::user()['role'] ?? null) !== 'admin') {
            throw new RuntimeException('Коррекция архивных условий доступна администратору через CLI.',403);
        }
    }

    public static function execute(PDO $db,array $actor,array $input): array
    {
        self::requireOperator();
        if (isset($input['restore_number'])) return ArchivedRentRestoration::execute($db,$actor,$input);
        $contract=Service::get($db,'contracts',(int)($input['id'] ?? 0));
        Service::version($contract,$input);
        if ($contract['status']!=='active' || empty(Importer::decodeExact($contract['source_json'])['imported'])) {
            throw new RuntimeException('Нужен действующий исходный договор.',409);
        }
        return match ([$contract['number'],$contract['document_date']]) {
            ['120','2025-10-07']=>self::indexation($db,$actor,$input,$contract),
            ['127','2025-11-18']=>self::continuation($db,$actor,$input,$contract),
            ['24','2023-02-17']=>self::settleParkingInvoices($db,$actor,$contract,
                ['АЯИП-001246'=>'20930.00','АЯИП-001273'=>'48150.00'],['2.40'=>'2.12']),
            ['33','2024-02-01']=>self::settleParkingInvoices($db,$actor,$contract,
                ['АЯИП-001298'=>'24000.00'],['1.34'=>'1.14']),
            ['34','2024-02-28']=>self::decemberParkingRates($db,$actor,$input,$contract),
            ['140','2026-02-03']=>self::decemberSuccessor($db,$actor,$input,$contract,
                ['number'=>'162','signed'=>'2026-11-03','from'=>'2026-12-01','party'=>1010,'object'=>1095,
                    'invoice'=>'АЯИП-001307','amount'=>'275000.00','rate'=>'275000.00','prior_end'=>'2027-01-09','paid'=>false]),
            ['133','2026-01-16']=>self::decemberSuccessor($db,$actor,$input,$contract,
                ['number'=>'163','signed'=>'2026-11-10','from'=>'2026-12-25','party'=>(int)$contract['party_id'],'object'=>1073,
                    'invoice'=>'АЯИП-001297','amount'=>'58709.68','rate'=>'260000.00','prior_end'=>'2027-01-15','paid'=>true]),
            ['55','2025-10-20']=>isset($input['payer_agreement'])
                ? self::recordPayerAgreement($db,$actor,$input,$contract)
                : self::settleThirdPartyParking($db,$actor,$contract),
            ['144','2026-03-10']=>self::recordRentCredit($db,$actor,$contract,$input['rent_credit'] ?? []),
            ['164','2026-11-11']=>self::settleRstroySecurity($db,$actor,$contract),
            ['65','2026-11-11']=>self::extendRstroyParking($db,$actor,$contract),
            default=>throw new RuntimeException('По договору отсутствует проверенное основание коррекции.',409),
        };
    }

    private static function settleRstroySecurity(PDO $db,array $actor,array $contract): array
    {
        $result=(new YakovlevRentalSync($db,Config::store()))->accountArchivedSecurity((int)$contract['id'],[
            'invoice_ref'=>'2f5c5a24-df50-11f1-ae1e-005056950eec',
            'invoice_sha'=>'471d3c313ec00200a2b86b499edd30571c3264ce50454817450523e484e6d363',
            'bank_ref'=>'376bf581-e185-11f1-ae1e-005056950eec',
            'bank_sha'=>'3fdbcf2f6440cdb7d4ddc2ff6b5138578ef6a03f24b4e44998dbfdeaf59c6543',
            'bank_contract_ref'=>'ab03e263-e186-11f1-ae1e-005056950eec',
        ]);
        Service::event($db,$actor,self::ACTION,'Учтён отдельный обеспечительный платёж по договору №164.',(int)$contract['id'],$result);
        return $result;
    }

    private static function extendRstroyParking(PDO $db,array $actor,array $contract): array
    {
        $id=(int)$contract['id'];
        $end='2027-10-15';
        $source=Importer::decodeExact($contract['source_json']);
        if ($contract['template_type']!=='parking' || $contract['start_date']!=='2026-11-16'
            || $contract['end_date']!==null || ($source['sourceLegalMapping']['party1CRef_Key'] ?? null)!=='a444cc56-df4f-11f1-ae1e-005056950eec'
            || !isset($source[ContractOccupancy::EXCEPTIONS][1015])) {
            throw new RuntimeException('Условия договора №65 изменились. Сверьте карточку парковки.',409);
        }
        $q=$db->prepare('SELECT t.id,t.object_id,t.start_date,t.end_date,t.amount,o.name FROM contract_terms t
            JOIN rental_objects o ON o.id=t.object_id WHERE t.contract_id=? AND t.superseded_on IS NULL ORDER BY t.object_id FOR UPDATE');
        $q->execute([$id]);
        $terms=$q->fetchAll();
        if (array_column($terms,'name')!==['2.12','2.13'] || array_column($terms,'object_id')!==[1015,1016]
            || array_column($terms,'amount')!==['24150.00','24150.00'] || array_column($terms,'end_date')!==['2026-12-31','2026-12-31']) {
            throw new RuntimeException('Состав парковки №65 изменился.',409);
        }
        Service::available($db,[1016],'2027-01-01',$end,$id,(int)$contract['party_id']);
        $basis=['as_of'=>'2026-11-18','rows'=>[51,52],'workbook_sha256'=>'328caf9a55923613b1845cce03fd65b0fe1e9f7d795a6c1d2f6193443f192fb2'];
        $source['archivedRentRestoration']['confirmed_end']=$basis;
        $source['sourceStatus']='Действует';
        $source[ContractOccupancy::EXCEPTIONS][1015]['until']=$end;
        $db->prepare('UPDATE contracts SET end_date=?,source_json=?,version=version+1 WHERE id=?')
            ->execute([$end,Importer::json($source),$id]);
        $update=$db->prepare('UPDATE contract_terms SET end_date=? WHERE id=?');
        foreach ($terms as $term) $update->execute([$end,$term['id']]);
        $result=['id'=>$id,'end_date'=>$end,'object_numbers'=>['2.12','2.13'],'physical_review'=>['2.12'],'source'=>$basis];
        Audit::record(self::ACTION,'contract',$id,['end_date'=>null,'terms'=>$terms],$result);
        Service::event($db,$actor,self::ACTION,'Уточнён срок договора парковки №65 по реестру на 18 ноября.',$id,$result);
        return $result;
    }

    public static function confirmedBillingEnd(array $contract,string $period): ?string
    {
        if ($contract['end_date']) return $contract['end_date'];
        $continuity=Importer::decodeExact($contract['source_json'])['archiveContinuity'] ?? [];
        $through=$continuity['confirmed_rent_through'] ?? null;
        $from=$continuity['confirmed_rent_from'] ?? $through;
        return !empty($continuity['owner_confirmed']) && is_string($through)
            && is_string($from) && substr($from,0,7)<=$period && $period<=substr($through,0,7) ? $through : null;
    }

    private static function decemberParkingRates(PDO $db,array $actor,array $input,array $contract): array
    {
        $from='2026-12-01';
        $rate='25410.00';
        $monthly='76230.00';
        $query=$db->prepare('SELECT t.*,o.name FROM contract_terms t JOIN contract_objects co
            ON co.contract_id=t.contract_id AND co.object_id=t.object_id JOIN rental_objects o ON o.id=t.object_id
            WHERE t.contract_id=? AND t.superseded_on IS NULL ORDER BY o.name FOR UPDATE');
        $query->execute([$contract['id']]);
        $terms=$query->fetchAll(PDO::FETCH_ASSOC);
        if ($contract['amount']!=='71610.00' || $contract['end_date']<'2026-12-31'
            || array_column($terms,'amount','name')!==['1.44'=>'23100.00','2.92'=>$rate,'2.93'=>'23100.00']) {
            throw new RuntimeException('Состав или прежние ставки договора №34 изменились.',409);
        }
        [$archive,$document]=self::sourceInvoice($db,(int)$contract['party_id'],'АЯИП-001287',$monthly);
        $basis=['effective_date'=>$from,'owner_confirmed'=>true,'source_invoice'=>$archive['source_id'],
            'source_sha256'=>$archive['raw_sha256'],'rates'=>['1.44'=>$rate,'2.92'=>$rate,'2.93'=>$rate]];
        ContractHistory::remember($db,$contract,$actor,$input['command_key'],$basis);
        $supersede=$db->prepare('UPDATE contract_terms SET superseded_on=? WHERE id=?');
        $put=$db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json)
            VALUES(?,?,?,?,?,?,?)');
        foreach ($terms as $term) {
            if ($term['amount']===$rate) continue;
            if ($term['end_date']<'2026-12-31' || ($term['effective_date'] ?? $term['start_date'])>=$from)
                throw new RuntimeException('Не подтверждён декабрьский срок машино-места '.$term['name'].'.',409);
            $supersede->execute(['2026-11-30',$term['id']]);
            $put->execute([$contract['id'],$term['object_id'],$from,$term['end_date'],$rate,$from,Importer::json($basis)]);
        }
        $db->prepare('UPDATE contracts SET amount=?,version=version+1 WHERE id=?')->execute([$monthly,$contract['id']]);
        $invoice=(new YakovlevRentalSync($db,Config::store()))->accountAssignedInvoice($archive,$document);
        if (!in_array($invoice['state'],['created','existing'],true) || $invoice['review']!==0)
            throw new RuntimeException('Декабрьский счёт №34 не прошёл проверку ставок и объектов.',409);
        $result=$basis+['id'=>(int)$contract['id'],'amount'=>$monthly,'invoice'=>$invoice];
        Audit::record(self::ACTION,'contract',$contract['id'],['amount'=>$contract['amount']],$result);
        Service::event($db,$actor,self::ACTION,'Подтверждены декабрьские ставки по трём машино-местам.',(int)$contract['id'],$result);
        return $result;
    }

    private static function decemberSuccessor(PDO $db,array $actor,array $input,array $prior,array $terms): array
    {
        $number=$terms['number'];
        $object=$terms['object'];
        $from=$terms['from'];
        $knownThrough='2026-12-31';
        $priorEnd=(new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        if ($prior['end_date']!==$terms['prior_end']) throw new RuntimeException('Прежний срок договора изменился.',409);
        $priorTerm=self::terms($db,$prior,$object);
        [$archive,$document]=self::sourceInvoice($db,$terms['party'],$terms['invoice'],$terms['amount']);
        $source=$document['fields']['record'];
        $sourceRef=OnecMapping::guid($source['ДоговорКонтрагента_Key'] ?? null);
        $rent=MonthlyRentReconciliation::rentLines($source);
        if (count($rent)!==1 || YakovlevInvoiceCoverage::period($rent[0]['Содержание'],$archive['document_date'])
            !==['2026-12',$from,$knownThrough])
            throw new RuntimeException('В оригинале не подтверждён декабрьский период нового договора.',409);
        $conflict=$db->prepare("SELECT id FROM contracts WHERE number=? OR EXISTS (
            SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='contract' AND b.entity_id=contracts.id AND b.source_id=?)");
        $conflict->execute([$number,$sourceRef]);
        if ($conflict->fetchColumn()) throw new RuntimeException('Новый договор или его исходная привязка уже существуют.',409);
        Service::available($db,[$object],$from,$knownThrough,(int)$prior['id'], $terms['party']);
        $basis=['predecessor'=>(int)$prior['id'],'previous_end'=>$prior['end_date'],'handover_from'=>$from,
            'confirmed_rent_through'=>$knownThrough,'owner_confirmed'=>true,'source_invoice'=>$archive['source_id'],
            'source_sha256'=>$archive['raw_sha256'],'key_1c'=>$sourceRef];
        ContractHistory::remember($db,$prior,$actor,$input['command_key'],$basis);
        $db->prepare('UPDATE contracts SET end_date=?,version=version+1 WHERE id=?')->execute([$priorEnd,$prior['id']]);
        $db->prepare('UPDATE contract_terms SET end_date=? WHERE id=?')->execute([$priorEnd,$priorTerm['id']]);
        $id=BusinessIds::next($db,'contracts',900000,false);
        $origin=['imported'=>true,'sourceLegalMapping'=>['legalDate'=>$terms['signed'],'legalNumber'=>$number,
            'party1CRef_Key'=>$source['Контрагент_Key']],'archiveContinuity'=>$basis];
        $db->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,template_type,source_json)
            VALUES(?,?,?,?,?,NULL,?,'active',?,?)")->execute([
                $id,$number,$terms['party'],$terms['signed'],$from,$terms['rate'],$prior['template_type'],Importer::json($origin)
            ]);
        $db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,?)')->execute([$id,$object]);
        $db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json)
            VALUES(?,?,?,?,?,?,?)')->execute([$id,$object,$from,$knownThrough,$terms['rate'],$from,Importer::json($basis)]);
        $evidence=Importer::canonicalJson($basis);
        $db->prepare("INSERT INTO rental_source_bindings(namespace,collection,source_id,entity_type,entity_id,relation_kind,evidence_json,mapping_hash)
            VALUES('verified-rent-register','Catalog_ДоговорыКонтрагентов',?,'contract',?,'rental_contract',?,?)")
            ->execute([$sourceRef,$id,$evidence,hash('sha256',$evidence)]);
        $settled=(new YakovlevRentalSync($db,Config::store()))->accountAssignedInvoice($archive,$document);
        if ($settled['state']!=='created' || $settled['review']!==0 || ($terms['paid'] && $settled['created']!==1))
            throw new RuntimeException('Счёт или оплата нового договора №'.$number.' не прошли сверку.',409);
        $result=$basis+['id'=>$id,'number'=>$number,'prior_number'=>$prior['number'],'prior_end'=>$priorEnd,
            'rate'=>$terms['rate'],'amount'=>$terms['amount'],'settled'=>$settled];
        Audit::record(self::ACTION,'contract',$id,['predecessor'=>$prior['id']],$result);
        Service::event($db,$actor,self::ACTION,'Подтверждено продолжение аренды по договору №'.$number.'.',$id,$result);
        return $result;
    }

    private static function settleThirdPartyParking(PDO $db,array $actor,array $contract): array
    {
        $sync=new YakovlevRentalSync($db,Config::store());
        $settled=[];
        foreach (['АЯИП-001021','АЯИП-001132'] as $number) {
            [, $document]=self::sourceInvoice($db,(int)$contract['party_id'],$number,'33000.00');
            $settled[$number]=$sync->accountConfirmedTransfer($document['fields']['record']['Ref_Key'],(int)$contract['id']);
        }
        $result=['id'=>(int)$contract['id'],'invoices'=>$settled];
        if (in_array('created',array_column($settled,'effect'),true)) {
            Audit::record(self::ACTION,'contract',$contract['id'],null,$result);
            Service::event($db,$actor,self::ACTION,'Разнесены подтверждённые оплаты парковки третьим лицом.',
                (int)$contract['id'],$result);
        }
        return $result;
    }

    private static function recordRentCredit(PDO $db,array $actor,array $contract,array $proof): array
    {
        $portions=$proof['portions'] ?? [];
        $date=$proof['date'] ?? '';
        if ($date!=='2026-10-31' || ($proof['registrar_number'] ?? '')!=='АЯИП-001152'
            || !is_array($portions) || count($portions)!==2)
            throw new RuntimeException('Укажите проведение зачёта аренды за октябрь.',422);
        $registrar=OnecMapping::guid($proof['registrar_ref'] ?? null);
        [$archive,$original]=self::sourceInvoice($db,(int)$contract['party_id'],'АЯИП-001090','455000.01');
        $source=$original['fields']['record'];
        $ref=$source['Ref_Key'];
        $invoices=InvoiceContractAssignment::invoiceMatches($db,$ref,true);
        if (count($invoices)!==1 || (int)$invoices[0]['contract_id']!==(int)$contract['id']
            || $invoices[0]['status']!=='active' || $invoices[0]['kind']!=='Аренда' || $invoices[0]['period']!=='2026-10'
            || !MonthlyInvoiceArchive::matchesInvoice($archive,$invoices[0])
            || MonthlyRentReconciliation::sourceMonths($source,$archive['amount'],$archive['document_date'])!==['2026-10'=>'455000.01'])
            throw new RuntimeException('Не подтверждён октябрьский счёт договора №144.',409);
        $banks=$db->prepare("SELECT * FROM external_documents WHERE provider='onec-yakovlev'
            AND account_id='bank_in' AND status='Проведён' AND source_id=? AND document_date<=?");
        $receipts=[];
        $total='0.00';
        foreach ($portions as $bankRef=>$value) {
            $banks->execute(['bank_in:'.OnecMapping::guid($bankRef),$date]);
            $bank=$banks->fetch() ?: throw new RuntimeException('Поступление для зачёта отсутствует в архиве.',409);
            $document=Support::decode(Config::store()->read($bank['raw_path'],$bank['raw_sha256']));
            $receipt=YakovlevImport::normalize($document);
            $row=$document['fields']['record'];
            $amount=YakovlevRentalSync::cents($value);
            if ($receipt['source_id']!==$bank['source_id'] || $receipt['status']!=='Проведён'
                || ($row['Контрагент_Key'] ?? $row['Контрагент'] ?? null)!==$source['Контрагент_Key']
                || ($row['ВалютаДокумента_Key'] ?? null)!==$source['ВалютаДокумента_Key']
                || ($row['ВидОперации'] ?? null)!=='ОплатаПокупателя' || bccomp($amount,$receipt['amount'],2)>0)
                throw new RuntimeException('Исходное поступление не подтверждает зачёт.',409);
            $receipts[$bankRef]=['sha'=>$bank['raw_sha256'],'number'=>$receipt['number'],'amount'=>$amount];
            $total=bcadd($total,$amount,2);
        }
        if ($total!=='14550.12') throw new RuntimeException('Сумма зачёта расходится с проводкой.',409);
        $fact=['invoice_sha'=>$archive['raw_sha256'],'month'=>'2026-10','date'=>$date,'registrar_ref'=>$registrar,
            'registrar_number'=>$proof['registrar_number'],'receipts'=>$receipts,'amount'=>$total];
        $prior=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1 FOR UPDATE');
        $prior->execute([MonthlyBankEvidence::RENT_CREDIT,$ref]);
        $saved=$prior->fetchColumn();
        $result=['id'=>(int)$contract['id'],'invoice'=>$archive['number']]+$fact;
        if ($saved) {
            if (Importer::canonicalJson(Support::decode($saved))!==Importer::canonicalJson($fact))
                throw new RuntimeException('У счёта уже есть другое подтверждение зачёта.',409);
            return $result;
        }
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([MonthlyBankEvidence::RENT_CREDIT,$ref,Importer::json($fact)]);
        Audit::record(self::ACTION,'contract',$contract['id'],null,$result);
        Service::event($db,$actor,self::ACTION,'В сверке аренды учтён зачёт аванса по октябрьскому счёту.',
            (int)$contract['id'],$result);
        return $result;
    }

    private static function recordPayerAgreement(PDO $db,array $actor,array $input,array $contract): array
    {
        $agreement=$input['payer_agreement'] ?? [];
        $payer=OnecMapping::guid($agreement['payer_ref'] ?? null);
        $payerName=trim((string)($agreement['payer_name'] ?? ''));
        $receipts=$agreement['receipts'] ?? [];
        if ($payerName==='' || array_keys($receipts)!==['2026-10','2026-11']) {
            throw new RuntimeException('Укажите согласованного плательщика и основания за октябрь и ноябрь.',422);
        }
        $bankQuery=$db->prepare("SELECT * FROM external_documents WHERE provider='onec-yakovlev'
            AND account_id='bank_in' AND source_id=? AND status='Проведён'");
        $contractLink=$db->prepare("SELECT 1 FROM rental_source_bindings WHERE entity_type='contract'
            AND entity_id=? AND relation_kind='rental_contract' AND collection='Catalog_ДоговорыКонтрагентов' AND source_id=?");
        $save=$db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)');
        $saved=[];
        $bankReferences=[];
        foreach ($receipts as $month=>$proof) {
            [$invoice,$original]=self::sourceInvoice($db,(int)$contract['party_id'],$proof['invoice'],$proof['amount']);
            $source=$original['fields']['record'];
            $contractLink->execute([$contract['id'],$source['ДоговорКонтрагента_Key']]);
            $bankQuery->execute(['bank_in:'.$proof['bank_ref']]);
            $bank=$bankQuery->fetch() ?: throw new RuntimeException('Банковское поступление отсутствует в архиве.',409);
            $receipt=Support::decode(Config::store()->read($bank['raw_path'],$bank['raw_sha256']))['fields']['record'];
            $received=YakovlevRentalSync::cents($bank['amount']);
            $amount=YakovlevRentalSync::cents($invoice['amount']);
            $deposit=YakovlevRentalSync::cents($proof['deposit_amount'] ?? '0.00',true);
            $transfer=OnecMapping::guid($proof['transfer_ref'] ?? null);
            $depositTransfer=$deposit==='0.00' ? null : OnecMapping::guid($proof['deposit_transfer_ref'] ?? null);
            if (!$contractLink->fetchColumn() || $source['Контрагент_Key']===$payer
                || MonthlyRentReconciliation::sourceMonths($source,$amount,$invoice['document_date'])!==[$month=>$amount]
                || ($receipt['Контрагент_Key'] ?? $receipt['Контрагент'] ?? null)!==$payer
                || ($receipt['Posted'] ?? false)!==true || ($receipt['DeletionMark'] ?? true)!==false
                || ($receipt['ВидОперации'] ?? null)!=='ОплатаПокупателя'
                || ($receipt['Организация_Key'] ?? null)!==$source['Организация_Key']
                || $bank['source_id']!=='bank_in:'.($receipt['Ref_Key'] ?? '')
                || ($receipt['ВалютаДокумента_Key'] ?? null)!==$source['ВалютаДокумента_Key']
                || bcadd($amount,$deposit,2)!==$received || isset($bankReferences[$bank['source_id']])
                || $transfer===$depositTransfer || empty($proof['transfer_number'])) {
                throw new RuntimeException('Поступление или основание переноса расходится с оригиналом '.$invoice['number'].'.',409);
            }
            $fact=['invoice_sha'=>$invoice['raw_sha256'],'bank_source'=>$bank['source_id'],'bank_sha'=>$bank['raw_sha256'],
                'payer_ref'=>$payer,'payer_name'=>$payerName,'amount'=>$amount,'month'=>$month,'invoice'=>$invoice['number'],
                'bank'=>$bank['number'],'payment_date'=>$bank['document_date'],'transfer_ref'=>$transfer,
                'transfer_number'=>$proof['transfer_number'],'deposit_amount'=>$deposit,
                'deposit_transfer_ref'=>$depositTransfer];
            $save->execute([MonthlyBankEvidence::THIRD_PARTY,$source['Ref_Key'],Importer::json($fact)]);
            $bankReferences[$bank['source_id']]=true;
            $saved[]=$fact;
        }
        $result=['id'=>(int)$contract['id'],'payer'=>$payerName,'receipts'=>$saved];
        $source=Importer::decodeExact($contract['source_json']);
        $source['ownerConfirmedThirdPartyRent']=['payer_ref'=>$payer,'payer_name'=>$payerName];
        ContractHistory::remember($db,$contract,$actor,$input['command_key'],$result);
        $db->prepare('UPDATE contracts SET source_json=?,version=version+1 WHERE id=?')
            ->execute([Importer::json($source),$contract['id']]);
        Audit::record(self::ACTION,'contract',$contract['id'],null,$result);
        Service::event($db,$actor,self::ACTION,'Подтверждена договорённость об оплате парковки третьим лицом: '.$payerName.'.',
            (int)$contract['id'],$result);
        return $result;
    }

    private static function terms(PDO $db,array $contract,int $object): array
    {
        $query=$db->prepare('SELECT t.* FROM contract_terms t JOIN contract_objects co
            ON co.contract_id=t.contract_id AND co.object_id=t.object_id WHERE t.contract_id=? FOR UPDATE');
        $query->execute([$contract['id']]);
        $terms=$query->fetchAll();
        if (count($terms)!==1 || (int)$terms[0]['object_id']!==$object || $terms[0]['superseded_on']!==null
            || $terms[0]['start_date']!==$contract['start_date'] || $terms[0]['end_date']!==$contract['end_date']
            || $terms[0]['amount']!==$contract['amount']) {
            throw new RuntimeException('Состав объектов или действующие цены изменились. Проверьте условия.',409);
        }
        return $terms[0];
    }

    private static function sourceInvoice(PDO $db,int $partyId,string $number,string $amount): array
    {
        $query=$db->prepare("SELECT a.*,p.source_json party_source FROM external_documents a
            JOIN parties p ON p.id=? WHERE a.provider='onec-yakovlev' AND a.account_id='invoices'
            AND a.number=? AND a.status='Проведён' AND a.amount=?");
        $query->execute([$partyId,$number,$amount]);
        $rows=$query->fetchAll();
        if (count($rows)!==1) throw new RuntimeException('Проведённый оригинал не подтверждён: '.$number,409);
        $archive=$rows[0];
        $document=Support::decode(Config::store()->read($archive['raw_path'],$archive['raw_sha256']));
        $raw=$document['fields']['record'];
        if (($raw['Контрагент_Key'] ?? null)!==InvoiceContractAssignment::partyRef($archive)
            || empty($raw['Posted']) || !empty($raw['DeletionMark'])
            || $archive['source_id']!=='invoices:'.($raw['Ref_Key'] ?? '')) {
            throw new RuntimeException('Плательщик или состояние оригинала расходится: '.$number,409);
        }
        return [$archive,$document];
    }

    private static function settleParkingInvoices(PDO $db,array $actor,array $contract,array $invoices,array $map): array
    {
        if ($contract['number']==='33') {
            $replacement=Importer::decodeExact($contract['source_json'])['ownerConfirmedReplacement'] ?? [];
            if (($replacement['date'] ?? '')!=='2026-10-23' || ($replacement['to'] ?? null)!==1044)
                throw new RuntimeException('Не подтверждена замена машино-места по договору №33.',409);
        }
        $sync=new YakovlevRentalSync($db,Config::store());
        $settled=[];
        foreach ($invoices as $number=>$amount) {
            [$archive,$document]=self::sourceInvoice($db,(int)$contract['party_id'],$number,$amount);
            $result=$sync->accountAssignedInvoice($archive,$document,$map);
            if (!in_array($result['state'],['created','existing'],true)
                || $result['created']+$result['existing']!==1 || $result['review']!==0) {
                throw new RuntimeException('Не прошла сверка оригинала или банковского прихода '.$number.'.',409);
            }
            $settled[]=['number'=>$number,'amount'=>$amount,'source_invoice'=>$archive['source_id'],
                'source_sha256'=>$archive['raw_sha256'],'result'=>$result];
        }
        $result=['id'=>(int)$contract['id'],'invoices'=>$settled,'parking_accounting_map'=>$map];
        Audit::record(self::ACTION,'contract',$contract['id'],null,$result);
        Service::event($db,$actor,self::ACTION,'Разнесены оплаты по проверенным архивным счетам за машино-места.',(int)$contract['id'],$result);
        return $result;
    }

    private static function indexation(PDO $db,array $actor,array $input,array $contract): array
    {
        $from='2026-11-01';
        $rate='4392633.34';
        if ($contract['amount']!=='4105264.80' || $contract['end_date']!=='2030-10-31' || date('Y-m-d')<$from
            || $contract['servicing_effective_date']!==null) {
            throw new RuntimeException('Исходная ставка или срок договора №120 изменились.',409);
        }
        $term=self::terms($db,$contract,1070);
        [$archive,$document]=self::sourceInvoice($db,(int)$contract['party_id'],'АЯИП-001181',$rate);
        if (MonthlyRentReconciliation::sourceMonths($document['fields']['record'],$rate,$archive['document_date'])
            !== ['2026-11'=>$rate]) throw new RuntimeException('Счёт не подтверждает новую ставку за ноябрь.',409);

        $basis=['number'=>'ДС №2','document_date'=>'2026-10-09','effective_date'=>$from,
            'condition_id'=>'c369679f-5cd3-56f0-b7f7-1c4d075cf2eb','register_row'=>74,
            'source_invoice'=>$archive['source_id'],'source_sha256'=>$archive['raw_sha256'],'owner_confirmed'=>true];
        ContractHistory::remember($db,$contract,$actor,$input['command_key'],$basis);
        $db->prepare('UPDATE contract_terms SET superseded_on=? WHERE id=?')->execute(['2026-10-31',$term['id']]);
        $db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json)
            VALUES(?,?,?,?,?,?,?)')->execute([$contract['id'],$term['object_id'],$from,$term['end_date'],$rate,$from,Importer::json($basis)]);
        $db->prepare('UPDATE contracts SET amount=?,version=version+1 WHERE id=?')->execute([$rate,$contract['id']]);
        $result=$basis+['id'=>(int)$contract['id'],'amount'=>$rate];
        Audit::record(self::ACTION,'contract',$contract['id'],['amount'=>$contract['amount']],$result);
        Service::event($db,$actor,self::ACTION,'Применена архивная индексация по ДС №2 с 01.11.2026.',(int)$contract['id'],$result);
        return $result;
    }

    private static function continuation(PDO $db,array $actor,array $input,array $predecessor): array
    {
        $date='2026-10-14';
        $from='2026-11-19';
        $until='2027-10-18';
        $rate='261414.30';
        if ($predecessor['end_date']!=='2026-11-18' || $predecessor['amount']!==$rate || date('Y-m-d')<$from) {
            throw new RuntimeException('Граница договора или ставка №127 изменилась.',409);
        }
        self::terms($db,$predecessor,1063);
        [$archive,$document]=self::sourceInvoice($db,(int)$predecessor['party_id'],'АЯИП-001189','104565.72');
        $source=$document['fields']['record'];
        $ref=$source['ДоговорКонтрагента_Key'] ?? '';
        if (!preg_match('/^[0-9a-f-]{36}$/D',$ref) || $ref==='00000000-0000-0000-0000-000000000000') {
            throw new RuntimeException('Счёт не содержит ключа договора №159.',409);
        }
        $conflict=$db->prepare("SELECT id FROM contracts WHERE number='159' OR EXISTS (
            SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='contract' AND b.entity_id=contracts.id AND b.source_id=?)");
        $conflict->execute([$ref]);
        if ($conflict->fetchColumn()) throw new RuntimeException('Договор №159 или исходная привязка уже существует.',409);
        Service::available($db,[1063],$from,$until,(int)$predecessor['id']);

        $id=BusinessIds::next($db,'contracts',900000,false);
        $basis=['condition_id'=>'e158809f-9d17-5b3a-8d5a-c994182d8104','register_row'=>391,
            'source_invoice'=>$archive['source_id'],'source_sha256'=>$archive['raw_sha256'],'key_1c'=>$ref,
            'object'=>'201','predecessor'=>(int)$predecessor['id']];
        $origin=['imported'=>true,'sourceLegalMapping'=>['legalDate'=>$date,'legalNumber'=>'159',
            'party1CRef_Key'=>$source['Контрагент_Key']],'archiveContinuity'=>$basis];
        ContractHistory::remember($db,$predecessor,$actor,$input['command_key'],$basis);
        $db->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,template_type,source_json)
            VALUES(?,'159',?,?,?,?,?,'active',?,?)")->execute([
                $id,$predecessor['party_id'],$date,$from,$until,$rate,$predecessor['template_type'],Importer::json($origin)
            ]);
        $db->prepare('INSERT INTO contract_objects(contract_id,object_id) VALUES(?,1063)')->execute([$id]);
        $db->prepare('INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json)
            VALUES(?,1063,?,?,?,?,?)')->execute([$id,$from,$until,$rate,$from,Importer::json($basis)]);
        $evidence=Importer::canonicalJson($basis);
        $db->prepare("INSERT INTO rental_source_bindings(namespace,collection,source_id,entity_type,entity_id,relation_kind,evidence_json,mapping_hash)
            VALUES('verified-rent-register','Catalog_ДоговорыКонтрагентов',?,'contract',?,'rental_contract',?,?)")
            ->execute([$ref,$id,$evidence,hash('sha256',$evidence)]);
        $db->prepare("UPDATE contracts SET status='ended',version=version+1 WHERE id=?")->execute([$predecessor['id']]);
        $settled=(new YakovlevRentalSync($db,Config::store()))->accountAssignedInvoice($archive,$document);
        if ($settled['state']!=='created' || $settled['created']!==1) {
            throw new RuntimeException('Счёт и банковское поступление №159 не прошли контроль. Коррекция отменена.',409);
        }
        $result=$basis+['id'=>$id,'number'=>'159','effective_date'=>$from,'amount'=>$rate,'settled'=>$settled];
        Audit::record(self::ACTION,'contract',$id,['predecessor'=>$predecessor['id']],$result);
        Service::event($db,$actor,self::ACTION,'Восстановлен договор №159 и разнесён ноябрьский приход.', $id,$result);
        return $result;
    }
}
