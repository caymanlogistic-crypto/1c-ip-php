<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use Modules\Integrations\{Config,Support,YakovlevImport};
use PDO;
use RuntimeException;

/** Apply owner-confirmed parking corrections using the reviewed 1C originals. */
final class ArchivedParkingPayments
{
    private const RECEIPTS = [
        '46'=>[
            'invoice'=>'АЯИП-001263','invoice_id'=>'99ee4720-e9e6-4078-8387-c88f035ed927',
            'invoice_ref'=>'cbd2adee-df68-11f1-ae1e-005056950eec',
            'invoice_sha'=>'20f90fcf1c4e8b02689477575f62ce5cd1fd73089e28d6869799d9bb4ce89887',
            'bank'=>'АЯИП-001605','bank_ref'=>'1e4fdce3-e185-11f1-ae1e-005056950eec',
            'bank_sha'=>'ca7898e49bc32c3fff50cfe3a62a455ac3c760f95bbabea8db69c0a19331dce4',
            'period'=>'2026-12','purpose_invoice'=>1099,'amount'=>'25200.00','invoice_amount'=>'98700.00',
        ],
        '64'=>[
            'invoice'=>'АЯИП-001243','invoice_id'=>'b7ea83a0-f9b3-4d1b-8b44-1b8082f39a38',
            'invoice_ref'=>'0bf1842c-d8f4-11f1-ae1d-005056950eec',
            'invoice_sha'=>'9d414feec232ad8af5b58eddd90378737012c4d5ca43a099fa68b9c8d1836031',
            'bank'=>'АЯИП-001584','bank_ref'=>'73804b64-d9aa-11f1-ae1e-005056950eec',
            'bank_sha'=>'3b7f6dc6867d6c90df66a31ad96835ab952a7ab2614da2bc285f928fb6f2deed',
            'period'=>'2026-11','from'=>'2026-11-03','contract_start'=>'2026-11-05','amount'=>'23520.00','invoice_amount'=>'23520.00',
        ],
    ];

    public static function settle(PDO $db,array $actor,array $input,array $contract): array
    {
        ArchivedRentCorrection::requireOperator();
        $proof=self::RECEIPTS[$contract['number']]+['actor_id'=>(int)$actor['id']];
        if ($contract['number']==='64' && !in_array($contract['start_date'],[$proof['contract_start'],$proof['from']],true)) {
            throw new RuntimeException('Изменилась дата договора №64. Откройте оригинал на повторную сверку.',409);
        }
        $archive=InvoiceContractAssignment::archive($db,$proof['invoice_id'],true);
        $q=$db->prepare("SELECT id,raw_path,document_date FROM external_documents WHERE provider=? AND account_id='bank_in'
            AND source_id=? AND raw_sha256=? AND number=? AND status='Проведён' AND amount=? FOR UPDATE");
        $q->execute([YakovlevImport::PROVIDER,'bank_in:'.$proof['bank_ref'],$proof['bank_sha'],$proof['bank'],$proof['amount']]);
        $bank=$q->fetch();
        if (!$bank || $archive['raw_sha256']!==$proof['invoice_sha'] || $archive['source_id']!=='invoices:'.$proof['invoice_ref']
            || $archive['number']!==$proof['invoice'] || $archive['status']!=='Проведён'
            || YakovlevRentalSync::cents($archive['amount'])!==$proof['invoice_amount']) {
            throw new RuntimeException('Проведённый счёт или поступление отличается от подтверждённого оригинала.',409);
        }
        $store=Config::store();
        $document=Support::decode($store->read($archive['raw_path'],$archive['raw_sha256']));
        $receipt=Support::decode($store->read($bank['raw_path'],$proof['bank_sha']))['fields']['record'];
        $source=$document['fields']['record'];
        if (($receipt['Контрагент_Key'] ?? $receipt['Контрагент'] ?? null)!==($source['Контрагент_Key'] ?? $source['Контрагент'] ?? null)
            || array_column($receipt['РасшифровкаПлатежа'] ?? [],'СчетНаОплату_Key')!==[$proof['invoice_ref']]
            || MonthlyRentReconciliation::sourceMonths($source,$proof['invoice_amount'],$archive['document_date'])!==[$proof['period']=>$proof['invoice_amount']]) {
            throw new RuntimeException('Банковская расшифровка или месяц аренды изменились.',409);
        }

        $origin=Importer::decodeExact($contract['source_json']);
        if ($contract['number']==='46') {
            $origin['ownerConfirmedArchiveBank'][$proof['bank_ref']]=$proof;
        } else {
            $origin['ownerConfirmedArchiveCoverage'][$proof['invoice_ref']]=$proof;
            $origin['archivedRentRestoration']['first_invoice']=$proof['invoice'];
        }
        ContractHistory::remember($db,$contract,$actor,$input['command_key'],$proof);
        $db->prepare('UPDATE contracts SET source_json=?,version=version+1 WHERE id=?')
            ->execute([Importer::json($origin),$contract['id']]);

        $result=(new YakovlevRentalSync($db,$store))->accountAssignedInvoice($archive,$document);
        $rows=InvoiceContractAssignment::invoiceMatches($db,$proof['invoice_ref'],true);
        if (!in_array($result['state'],['existing','created'],true) || $result['review']!==0 || count($rows)!==1
            || (int)$rows[0]['contract_id']!==(int)$contract['id'] || InvoiceFacts::remaining($rows[0])!=='0.00') {
            throw new RuntimeException('Счёт остался на сверке: '.$proof['invoice'],409);
        }
        $q=$db->prepare("SELECT p.id,a.amount FROM payments p JOIN payment_allocations a ON a.payment_id=p.id
            WHERE a.invoice_id=? AND JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.bank_external_id'))=?");
        $q->execute([$rows[0]['id'],$proof['bank_ref']]);
        $allocations=$q->fetchAll();
        if (count($allocations)!==1 || $allocations[0]['amount']!==$proof['amount']) {
            throw new RuntimeException('Поступление не распределено в подтверждённой сумме.',409);
        }
        $evidence=['invoice_id'=>$rows[0]['id'],'payment_id'=>$allocations[0]['id'],'balance'=>'0.00']+$proof;
        Audit::record(ArchivedRentCorrection::ACTION,'invoice',$rows[0]['id'],null,$evidence);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,'Разнесена подтверждённая оплата '.$proof['bank'].'.',(int)$contract['id'],$evidence);
        return $evidence;
    }

    public static function correctFlyLedgerStart(PDO $db,array $actor,array $input,array $contract): array
    {
        ArchivedRentCorrection::requireOperator();
        $proof=self::RECEIPTS['64'];
        $start=$proof['from'];
        $id=(int)$contract['id'];
        $source=Importer::decodeExact($contract['source_json']);
        $confirmed=$source['ownerConfirmedArchiveCoverage'][$proof['invoice_ref']] ?? [];
        if ($input['owner_confirmed_start']!==$start || $contract['template_type']!=='parking'
            || $contract['start_date']!==$proof['contract_start'] || !$contract['end_date']
            || ($confirmed['invoice_sha'] ?? null)!==$proof['invoice_sha'] || ($confirmed['from'] ?? null)!==$start) {
            throw new RuntimeException('Проверьте исходную дату и подтверждённый счёт по договору №64.',409);
        }

        $terms=$db->prepare('SELECT t.*,o.name FROM contract_terms t JOIN rental_objects o ON o.id=t.object_id
            JOIN contract_objects co ON co.contract_id=t.contract_id AND co.object_id=t.object_id
            WHERE t.contract_id=? AND t.superseded_on IS NULL ORDER BY t.id FOR UPDATE');
        $terms->execute([$id]);
        $rows=$terms->fetchAll();
        $term=$rows[0] ?? [];
        if (count($rows)!==1 || $term['name']!=='1.13' || $term['start_date']!==$contract['start_date']
            || $term['effective_date']!==$contract['start_date'] || $term['end_date']!==$contract['end_date']
            || $term['amount']!==$contract['amount']) {
            throw new RuntimeException('Условия машино-места 1.13 отличаются от проверенных.',409);
        }
        $addedThrough=(new \DateTimeImmutable($contract['start_date']))->modify('-1 day')->format('Y-m-d');
        Service::available($db,[(int)$term['object_id']],$start,$addedThrough,$id,(int)$contract['party_id']);

        $details=['previous_start'=>$contract['start_date'],'start_date'=>$start,'object'=>$term['name'],
            'invoice'=>$proof['invoice'],'invoice_sha'=>$proof['invoice_sha'],'owner_confirmed'=>true];
        ContractHistory::remember($db,$contract,$actor,$input['command_key'],$details);
        $source['ownerConfirmedStartCorrection']=$details;
        $db->prepare('UPDATE contracts SET start_date=?,source_json=?,version=version+1 WHERE id=?')
            ->execute([$start,Importer::json($source),$id]);
        $db->prepare('UPDATE contract_terms SET start_date=?,effective_date=? WHERE id=?')
            ->execute([$start,$start,$term['id']]);
        Audit::record(ArchivedRentCorrection::ACTION,'contract',$id,['start_date'=>$contract['start_date']],$details);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,
            'По подтверждению владельца начало аренды машино-места 1.13 по №64 установлено на 03.11.2026.',$id,$details);
        return ['id'=>$id]+$details;
    }
}
