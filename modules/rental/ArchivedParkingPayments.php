<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use Modules\Integrations\{Config,Support,YakovlevImport};
use PDO;
use RuntimeException;

/** Post the two receipts confirmed by the owner against their reviewed 1C originals. */
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
        if ($contract['number']==='64' && $contract['start_date']!==$proof['contract_start']) {
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
}
