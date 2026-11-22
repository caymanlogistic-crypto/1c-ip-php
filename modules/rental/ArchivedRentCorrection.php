<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,BusinessIds};
use Modules\Integrations\{Config,Support};
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
        $contract=Service::get($db,'contracts',(int)($input['id'] ?? 0));
        Service::version($contract,$input);
        if ($contract['status']!=='active' || empty(Importer::decodeExact($contract['source_json'])['imported'])) {
            throw new RuntimeException('Нужен действующий исходный договор.',409);
        }
        return match ([$contract['number'],$contract['document_date']]) {
            ['120','2025-10-07']=>self::indexation($db,$actor,$input,$contract),
            ['127','2025-11-18']=>self::continuation($db,$actor,$input,$contract),
            default=>throw new RuntimeException('По договору отсутствует проверенное основание коррекции.',409),
        };
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
