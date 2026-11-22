<?php
declare(strict_types=1);

namespace Modules\Rental;

use DomainException;
use Modules\Integrations\{PrivateStore,Support,YakovlevImport};
use PDO;

/** Evidence of full bank payment, independent of a local invoice or allocation. */
final class MonthlyBankEvidence
{
    public const THIRD_PARTY = 'rental_yakovlev_confirmed_third_party';

    public function __construct(private PDO $db,private PrivateStore $store) {}

    public function fullyPaid(array $targets,array $invoices,string $through): array
    {
        if (!$targets) return [];
        $paid=[];
        foreach ($this->records('bank_in',$through) as [$header,$record]) {
            $links=array_column($record['lines'],'СчетНаОплату_Key');
            if (!array_intersect(array_keys($targets),$links)) continue;
            try {
                $links=self::payment($header['amount'],$record,$invoices);
                foreach (array_intersect_key($links,$targets) as $ref=>$amount) {
                    $paid[$ref]=bcadd($paid[$ref] ?? '0.00',$amount,2);
                }
            } catch (DomainException) {
                continue;
            }
        }

        foreach ($paid as $ref=>$amount) {
            if (bccomp($amount,$targets[$ref]['amount'],2)!==0) unset($paid[$ref]);
        }
        $thirdParty=$this->confirmedThirdParty($targets,$through);
        foreach ($thirdParty as $ref=>$fact) $paid[$ref]=$fact['amount'];
        if (!$paid) return [];

        foreach ($this->records('bank_out',$through) as [$header,$record]) {
            $links=array_filter(array_column($record['lines'],'СчетНаОплату_Key'),
                static fn($ref)=>isset($invoices[$ref]));
            foreach ($links as $ref) unset($paid[$ref]);
            if ($links) continue;
            $party=$record['party'];
            foreach ($paid as $ref=>$amount) {
                if ($targets[$ref]['party_ref']===$party
                    || (isset($thirdParty[$ref]) && $thirdParty[$ref]['payer_ref']===$party))
                    unset($paid[$ref]);
            }
        }
        return $paid;
    }

    private function confirmedThirdParty(array $targets,string $through): array
    {
        $query=$this->db->prepare("SELECT f.source_key,f.fact_json FROM rental_facts f
            JOIN external_documents d ON d.source_id=JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.bank_source'))
                AND d.raw_sha256=JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.bank_sha'))
            WHERE f.namespace=? AND f.operative=1 AND d.provider=? AND d.account_id='bank_in'
                AND d.status='Проведён' AND d.document_date<=?");
        $query->execute([self::THIRD_PARTY,YakovlevImport::PROVIDER,$through]);
        $confirmed=[];
        foreach ($query as $row) {
            $ref=$row['source_key'];
            $target=$targets[$ref] ?? null;
            if ($target===null) continue;
            $fact=Support::decode($row['fact_json']);
            if ($target['raw_sha256']===$fact['invoice_sha'] && bccomp($target['amount'],$fact['amount'],2)===0)
                $confirmed[$ref]=$fact;
        }
        return $confirmed;
    }

    private function records(string $kind,string $through): iterable
    {
        $query=$this->db->prepare("SELECT source_id,amount,raw_path,raw_sha256 FROM external_documents
            WHERE provider=? AND account_id=? AND status='Проведён' AND document_date<=?");
        $query->execute([YakovlevImport::PROVIDER,$kind,$through]);
        $path=APP_ROOT.'/runtime/monthly-rent-'.$kind.'-archive.json';
        $revision=hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/YakovlevRentalSync.php');
        $cache=json_decode(@file_get_contents($path) ?: '',true);
        $prior=($cache['revision'] ?? null)===$revision && is_array($cache['records'] ?? null) ? $cache['records'] : [];
        $records=[];
        $changed=false;
        foreach ($query as $header) {
            $key=$header['source_id'].':'.$header['raw_sha256'];
            $record=$prior[$key] ?? $this->record($header,$kind);
            $changed=$changed || !array_key_exists($key,$prior);
            $records[$key]=$record;
            if ($record) yield [$header,$record];
        }
        if ($changed || count($records)!==count($prior))
            MonthlyInvoiceArchive::saveCache($path,['revision'=>$revision,'records'=>$records]);
    }

    private function record(array $header,string $kind): array
    {
        $document=Support::decode($this->store->read($header['raw_path'],$header['raw_sha256']));
        $record=$document['fields']['record'];
        $operation=$kind==='bank_in' ? 'ОплатаПокупателя' : 'ВозвратПокупателю';
        if (($record['Posted'] ?? false)!==true || ($record['DeletionMark'] ?? true)!==false
            || ($record['ВидОперации'] ?? null)!==$operation
            || ($record['Организация_Key'] ?? null)!==YakovlevImport::ORGANIZATION
            || $header['source_id']!==$kind.':'.($record['Ref_Key'] ?? '')) return [];

        $fields=array_fill_keys(['СчетНаОплату_Key','LineNumber','СуммаПлатежа','СуммаВозврата','СуммаВзаиморасчетов'],true);
        return ['party'=>$record['Контрагент_Key'] ?? $record['Контрагент'] ?? null,
            'currency'=>$record['ВалютаДокумента_Key'] ?? null,
            'mentioned'=>YakovlevRentalSync::invoiceNumbersInPurpose((string)($record['НазначениеПлатежа'] ?? '')),
            'lines'=>array_map(static fn(array $line): array=>array_intersect_key($line,$fields),$record['РасшифровкаПлатежа'] ?? [])];
    }

    private static function payment(string $amount,array $record,array $invoices): array
    {
        $links=$numbers=$seen=[];
        $sum='0.00';
        $party=$record['party'];
        $currency=$record['currency'];
        foreach ($record['lines'] as $line) {
            $ref=$line['СчетНаОплату_Key'] ?? '';
            $id=(string)($line['LineNumber'] ?? '');
            $value=YakovlevRentalSync::cents($line['СуммаПлатежа'] ?? null);
            if (!ctype_digit($id) || isset($seen[$id])
                || YakovlevRentalSync::cents($line['СуммаВозврата'] ?? null,true)!=='0.00'
                || $value!==YakovlevRentalSync::cents($line['СуммаВзаиморасчетов'] ?? null)) {
                throw new DomainException('PAYMENT_LINE_AMBIGUOUS');
            }
            $seen[$id]=true;
            $sum=bcadd($sum,$value,2);
            $invoice=$invoices[$ref] ?? null;
            if ($invoice === null) continue;
            $numbers[]=$invoice['number'];
            if ($invoice['status']!=='Проведён' || !$party || !$currency || $invoice['party_ref']!==$party
                || $invoice['currency_ref']!==$currency) continue;
            $links[$ref]=bcadd($links[$ref] ?? '0.00',$value,2);
        }
        if (bccomp($sum,$amount,2)!==0
            || !YakovlevRentalSync::purposeMatchesInvoiceNumbers($record['mentioned'],$numbers))
            return [];
        return $links;
    }
}
