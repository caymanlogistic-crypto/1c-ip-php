<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class UpdEvidence
{
    public const BASIS = 'rental_upd_basis';

    public static function fact(PDO $db,string $namespace,string $key): array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([$namespace,$key]);
        return Importer::decodeExact($q->fetchColumn() ?: '{}');
    }

    public static function basis(PDO $db,array $invoice,string $month): array
    {
        $basis=self::fact($db,self::BASIS,$invoice['id'].':'.$month);
        if (($basis['contract_id'] ?? null)!==(int)$invoice['contract_id']
            || ($basis['number'] ?? null)!==$invoice['number'] || ($basis['issued'] ?? null)!==$invoice['issued']
            || self::money($basis['invoice_amount'] ?? null)!==self::money($invoice['amount'])
            || !is_string($basis['reference'] ?? null) || trim($basis['reference'])==='') return [];
        return $basis;
    }

    public static function original(PDO $db,array $invoice,array $basis,string $month): array
    {
        $sourceId=$basis['source_invoice_id'] ?? null;
        $key=Importer::decodeExact($invoice['source_json'])['Ref_Key'] ?? null;
        if (!$sourceId && (!is_string($key) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key))) return [];
        $identity=$sourceId ? 'id=?' : 'source_key=? AND collection=?';
        $q=$db->prepare("SELECT id,number,record_date,amount FROM aux_source_records
            WHERE $identity AND kind='invoice' AND present=1 AND deleted=0 AND folder=0");
        $q->execute($sourceId ? [$sourceId] : [$key,\Modules\Integrations\OnecMapping::COLLECTIONS['invoice']]);
        $source=$q->fetch();
        if (!$source || $source['number']!==$invoice['number'] || substr((string)$source['record_date'],0,10)!==$invoice['issued']
            || self::money($source['amount'])!==self::money($invoice['amount']))
            return ['question'=>'Сверьте закреплённый счёт 1С с номером, датой и суммой счёта.'];
        $q=$db->prepare("SELECT r.id,r.number,r.record_date,r.amount,r.source_key,r.collection,l.position,l.description,
            l.amount AS line_amount,l.raw_evidence,s.sha256 AS snapshot_sha,
            JSON_UNQUOTE(JSON_EXTRACT(s.raw_evidence,'$.documentKind')) AS document_kind
            FROM aux_source_lines l JOIN aux_source_records r ON r.id=l.record_id
            LEFT JOIN aux_source_snapshots s ON s.id=(SELECT MAX(s2.id) FROM aux_source_snapshots s2 WHERE s2.record_id=r.id)
            WHERE l.invoice_id=? AND r.kind='realization' AND r.present=1 AND r.deleted=0 AND r.folder=0
            ORDER BY r.id,l.position");
        $q->execute([$source['id']]);
        $docs=[];
        foreach ($q->fetchAll() as $row) {
            $line=Importer::decodeExact($row['raw_evidence']);
            $period=InvoicePeriods::month($line['rentalPeriod'] ?? null);
            if (!$period) return ['question'=>'Уточните месяц строки связанной реализации 1С.'];
            if ($period!==$month) continue;
            if ($row['document_kind']!=='upd' || !preg_match('/^[0-9a-f]{64}$/D',$row['snapshot_sha'] ?? ''))
                return ['question'=>'Подтвердите, что связанная реализация 1С является УПД, и сверьте снимок оригинала.'];
            $day=substr($row['record_date'] ?? '',0,10);
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$day,$date) || !checkdate((int)$date[2],(int)$date[3],(int)$date[1]))
                return ['question'=>'Уточните дату связанного УПД в сохранённом источнике 1С.'];
            $docs[$row['id']]['record']=$row;
            $docs[$row['id']]['lines'][]=['description'=>$row['description'],'amount'=>self::money($row['line_amount'])];
        }
        if (count($docs)>1) return ['question'=>'С этим счётом связаны несколько УПД 1С за месяц. Сверьте покрытие.'];
        if ($docs) return ['original'=>reset($docs)];
        if (!is_string($basis['realizations_checked'] ?? null) || trim($basis['realizations_checked'])==='')
            return ['question'=>'Подтвердите полноту сохранённой выборки УПД 1С для этого счёта и месяца.'];
        return ['checked_source_invoice'=>(int)$source['id'],'realizations_checked'=>$basis['realizations_checked']];
    }

    public static function postings(PDO $db,array $invoice): array
    {
        $q=$db->prepare('SELECT p.*,a.amount AS allocated FROM payment_allocations a JOIN payments p ON p.id=a.payment_id
            WHERE a.invoice_id=? ORDER BY p.id');
        $q->execute([$invoice['id']]);
        $rows=$q->fetchAll();
        if (count($rows)!==1 || self::money($rows[0]['allocated'])!==self::money($invoice['amount'])
            || (int)$rows[0]['party_id']!==(int)$invoice['party_id'])
            return ['question'=>'Подтвердите одно поступление по этому счёту и его распределение по сохранённой выписке.'];
        $payment=$rows[0];
        $fact=Importer::decodeExact($payment['source_json'])['bankPosting'] ?? [];
        $actual=$payment['payment_date'];
        $stamp=$fact['postedAt'] ?? null;
        if (!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$stamp)
            || !is_string($fact['reference'] ?? null) || trim($fact['reference'])==='' || !$actual
            || ($fact['paymentDate'] ?? null)!==$actual
            || !($posted=DateTimeImmutable::createFromFormat(DATE_ATOM,$stamp)) || $posted->format(DATE_ATOM)!==$stamp)
            return ['question'=>'Для этого счёта подтвердите дату оплаты и точное время проведения по выписке.'];
        $posted=$posted->setTimezone(new DateTimeZone('Europe/Moscow'));
        if ($posted->format('Y-m-d')<$actual) return ['question'=>'Время проведения предшествует дате платежа. Сверьте выписку.'];
        return ['date'=>$actual,'posted_at'=>$posted->format(DATE_ATOM),'payment_id'=>(int)$payment['id'],
            'payment_version'=>(int)$payment['version'],'reference'=>$fact['reference']];
    }

    public static function advance(PDO $db,array $invoice,string $month,string $date,string $gross): array
    {
        $known=LeasePaymentTerms::facts($db,(int)$invoice['contract_id']);
        $allocations=$db->prepare('SELECT invoice_id,amount FROM payment_allocations WHERE payment_id=? ORDER BY invoice_id');
        $q=$db->prepare('SELECT p.*,(SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.payment_id=p.id) allocated
            FROM payments p WHERE p.party_id=? AND (p.payment_date<=? OR p.payment_date IS NULL) ORDER BY p.id');
        $q->execute([$invoice['party_id'],$date]);
        $found=[];
        foreach ($q->fetchAll() as $payment) {
            $source=Importer::decodeExact($payment['source_json']);
            $basis=$source['rentalAdvance'] ?? [];
            if (in_array($basis['kind'] ?? null,['security','other'],true)) continue;
            $id=(int)$payment['id'];
            $claim=self::fact($db,UpdRegister::CREDIT,(string)$id);
            if ($claim) continue;
            $available=bcsub($payment['amount'],$payment['allocated'],2);
            $recorded=$known['advance:'.$id] ?? [];
            $booked=false;
            if ($recorded && bccomp($payment['allocated'],'0',2)>0) {
                $allocations->execute([$id]);
                $links=$allocations->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!isset($links[$invoice['id']]) && bccomp($available,'0',2)<=0) continue;
                $months=array_unique(array_column(InvoiceReport::evidence($invoice)['lines'] ?? [],'period'));
                if (count($links)!==1 || !isset($links[$invoice['id']]) || count($months)!==1
                    || $recorded['date']!==$payment['payment_date'] || $payment['payment_date']>=$invoice['issued']
                    || self::money($recorded['amount'])!==self::money($payment['amount'])
                    || bccomp($available,'0',2)!==0)
                    return ['question'=>'По авансу № '.$id.' сверено поступление. Уточните покрытие и зачёт по счёту.'];
                $booked=true;
                $available=$links[$invoice['id']];
                $basis=['kind'=>'rent','invoiceIds'=>[(int)$invoice['id']],'contractId'=>(int)$invoice['contract_id'],
                    'reference'=>$recorded['reason'],'confirmedAmount'=>$recorded['amount'],'month'=>reset($months)];
            }
            if (bccomp($available,'0',2)<=0) continue;
            if (($basis['kind'] ?? null)!=='rent') {
                if ($recorded) return ['question'=>'По принятому авансу № '.$id.' подтвердите назначение аренды и конкретный счёт.'];
                return ['question'=>'Приход № '.$id.' не распределён. Подтвердите, относится ли он к авансу по договору.'];
            }
            $targets=$basis['invoiceIds'] ?? [];
            if ($targets && !in_array((int)$invoice['id'],$targets,true)) continue;
            if (!$targets && isset($basis['contractId']) && $basis['contractId']!==(int)$invoice['contract_id']) continue;
            if (InvoicePeriods::month($basis['month'] ?? null) && $basis['month']!==$month) continue;
            if ($targets!==[(int)$invoice['id']] || ($basis['contractId'] ?? null)!==(int)$invoice['contract_id']
                || !is_string($basis['reference'] ?? null) || trim($basis['reference'])==='' || !$payment['payment_date']
                || self::money($basis['confirmedAmount'] ?? null)!==self::money($payment['amount'])
                || ($basis['month'] ?? null)!==$month)
                return ['question'=>'По авансу № '.$id.' подтвердите один счёт, месяц и основание зачёта.'];
            $found[]=['payment_id'=>$id,'payment_date'=>$payment['payment_date'],'reference'=>$basis['reference'],
                'amount'=>$available,'payment_version'=>(int)$payment['version'],'already_booked'=>$booked];
        }
        if (!$found) return [];
        if (count($found)!==1 || bccomp($found[0]['amount'],$gross,2)>0
            || (!$found[0]['already_booked'] && bccomp($found[0]['amount'],InvoiceFacts::remaining($invoice) ?? '0',2)>0))
            return ['question'=>'Подтвердите очередность и сумму зачёта по этому счёту.'];
        return ['credit'=>$found[0]];
    }

    public static function money(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D',$value)
            || bccomp($value,bcadd($value,'0',2),8)!==0) return null;
        return bcadd($value,'0',2);
    }
}
