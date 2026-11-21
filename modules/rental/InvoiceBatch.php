<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\InvoiceScope;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class InvoiceBatch
{
    public const SAVE = 'invoice.save-month';
    private const REVIEW = 'Сверьте покрытие. Нужны оригинал прежнего счёта из 1С, подписанный договор или ДС и расшифровка начисления с объектами и датами.';

    public static function contracts(PDO $db): array
    {
        $rows = $db->query("SELECT c.* FROM contracts c WHERE c.status IN ('active','reconciling') AND "
            .ContractDraft::visibleSql()." ORDER BY c.number,c.id")->fetchAll();
        $visible = [];
        foreach ($rows as $contract) {
            try {
                Access::pages(Access::statusPages($contract['status']));
            } catch (RuntimeException $error) {
                if ($error->getCode() === 403) continue;
                throw $error;
            }
            $visible[] = $contract;
        }
        return $visible;
    }

    public static function row(PDO $db, array $contract, string $period): array
    {
        $id = (int)$contract['id'];
        $cycle = InvoiceCycle::read($db,$id,$period);
        $mode = $cycle['mode'] ?? '';
        $quarterly = $mode === 'quarterly';
        $months = $quarterly ? InvoiceCycle::quarter($period) : [$period];
        $row = ['contract'=>$contract,'cycle'=>$cycle,'mode'=>$mode,'months'=>$months,'documents'=>[],
            'existing'=>[],'question'=>null,'preparation'=>null,'estimate'=>null];

        [$existing,$review] = self::history($db,$contract,$months);
        $row['existing'] = $existing;
        if ($review) return array_replace($row,['question'=>$review.' '.self::REVIEW]);
        if ($existing) return array_replace($row,['question'=>'Выбранный период уже покрыт полностью или частично. Откройте выставленный счёт.']);
        $last = (new DateTimeImmutable($months[count($months)-1].'-01'))->format('Y-m-t');
        if (($contract['start_date'] && $contract['start_date'] > $last)
            || ($contract['end_date'] && $contract['end_date'] < $months[0].'-01'))
            return array_replace($row,['question'=>'Выбранный период вне срока аренды.']);
        if ($mode === 'self') return array_replace($row,['question'=>'Подтверждена самостоятельная оплата. Счёт в пакет не входит.']);

        $facts = LeasePaymentTerms::facts($db,$id);
        if (($facts['decision']['decision'] ?? '') === 'self')
            return array_replace($row,['question'=>'Подтверждена самостоятельная оплата. Счёт в пакет не входит.']);
        $preparation = PaymentDayCalendar::preparation($months[0]);
        $row['preparation'] = $preparation;
        $issued = $preparation['date'] ?? (new DateTimeImmutable($months[0].'-01'))->modify('-1 month')->format('Y-m-17');
        $documents = $quarterly ? ($cycle['documents'] ?? []) : [['periods'=>$months,'due'=>null]];
        if (!$documents) return array_replace($row,['question'=>'Подтвердите число счетов, месяцы покрытия и срок каждого квартального счёта.']);
        $pending = [];
        foreach ($documents as $document) {
            $quotes = [];
            foreach ($document['periods'] as $month) {
                try {
                    InitialInvoice::assertBeyondInitial($db,$contract,$month,$facts);
                    $quote = RegularInvoice::preview($db,$contract,$month,$issued,0,true,$document['due']);
                } catch (RuntimeException $error) {
                    if ($error instanceof \PDOException || !in_array($error->getCode(),[409,422],true)) throw $error;
                    $pending[] = $error->getMessage();
                    continue;
                }
                if (isset($quote['amount'])) $row['estimate'] = $quote;
                if (empty($quote['token'])) {
                    $pending[] = $quote['questions'][0] ?? 'Сверьте существующее начисление.';
                    continue;
                }
                unset($quote['token'],$quote['number']);
                $quotes[] = $quote;
            }
            if (count($quotes) !== count($document['periods'])) continue;
            $combined = self::combine($contract,$quotes,$document);
            if (isset($combined['question'])) {
                $pending[] = $combined['question'];
                continue;
            }
            $row['documents'][] = ['periods'=>$document['periods'],'quote'=>$combined];
        }
        if (!$mode && ($facts['decision']['decision'] ?? '') !== 'invoice')
            $pending[] = 'Подтвердите по договору или переписке помесячный либо квартальный порядок и число счетов.';
        if (isset($preparation['question'])) $pending[] = $preparation['question'];
        if ($pending) return array_replace($row,['question'=>implode(' ',array_unique($pending))]);

        foreach ($row['documents'] as &$document) {
            $document['quote']['preparation'] = $preparation;
            $document['quote']['cycle'] = ['mode'=>$mode ?: 'monthly','confirmed'=>$cycle,'periods'=>$document['periods']];
        }
        unset($document);
        $row['token'] = InvoiceCycle::revision([(int)$contract['version'],$id,$period,$row['documents']]);
        return $row;
    }

    private static function history(PDO $db, array $contract, array $months): array
    {
        $id = (int)$contract['id'];
        $first = $months[0].'-01';
        $last = (new DateTimeImmutable(end($months).'-01'))->format('Y-m-t');
        $existing = [];
        foreach ($months as $month) {
            foreach (RegularInvoice::existing($db,$id,$month) as $invoice) $existing[(int)$invoice['id']] = $invoice;
        }
        $review = null;
        $unresolved = [];
        try {
            MonthlyBilling::assertCoverageReady($db,$id);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'IMPORTED_COVERAGE_REVIEW_REQUIRED') throw $error;
            $review = 'По импортированному договору покрытие не подтверждено.';
        }
        $query = $db->prepare("SELECT id,number,kind,amount,issued,due FROM invoices
            WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж' ORDER BY id"
            .($db->inTransaction() ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $intervals = $db->prepare('SELECT start_date,end_date FROM invoice_coverage WHERE invoice_id=?');
        foreach ($query->fetchAll() as $invoice) {
            $invoiceId = (int)$invoice['id'];
            $scope = InvoiceScope::read($db,$invoiceId);
            if (($scope['classification'] ?? '') === 'NOT_RENTAL_SECURITY' && $scope['availability'] === 'KNOWN' && !$scope['runtime_hold']) continue;
            if ($scope !== null && ($scope['availability'] !== 'KNOWN' || $scope['runtime_hold'] || $scope['classification'] !== 'RENTAL')) {
                $unresolved[] = $invoice['number'];
                continue;
            }
            $intervals->execute([$invoiceId]);
            $parts = $scope['coverage'] ?? $intervals->fetchAll();
            if (!$parts) $unresolved[] = $invoice['number'];
            foreach ($parts as $part) {
                if ($part['start_date'] <= $last && $part['end_date'] >= $first) $existing[$invoiceId] = $invoice;
            }
        }
        if ($unresolved) $review = trim($review.' Не подтверждены интервалы по счетам '.implode(', ',array_slice($unresolved,0,3))
            .(count($unresolved)>3 ? ' и другим.' : '.'));
        return [array_values($existing),$review];
    }

    private static function combine(array $contract, array $quotes, array $document): array
    {
        $first = $quotes[0];
        if (count($quotes) === 1) return array_replace($first,['due'=>$document['due'] ?? $first['due']]);

        $coverage = [];
        $amount = '0.00';
        $vat = null;
        foreach ($quotes as $quote) {
            if (isset($quote['billing']['invoice_targets']) || $quote['vat_rate'] !== $first['vat_rate']
                || $quote['billing']['objects'] !== $first['billing']['objects'])
                return ['question'=>'Сверьте предметы начисления и НДС. Эти месяцы нельзя объединить в один счёт автоматически.'];
            foreach ($quote['billing']['coverage'] as $line) {
                $object = $line['object_id'];
                if (isset($coverage[$object])
                    && (new DateTimeImmutable($coverage[$object]['end_date']))->modify('+1 day')->format('Y-m-d') !== $line['start_date'])
                    return ['question'=>'Покрытие месяцев прерывается. Сверьте согласованный состав квартальных счетов.'];
                $coverage[$object] = ['object_id'=>$object,'start_date'=>$coverage[$object]['start_date'] ?? $line['start_date'],
                    'end_date'=>$line['end_date']];
            }
            $amount = bcadd($amount,$quote['amount'],2);
            if (isset($quote['vat_amount'])) $vat = bcadd($vat ?? '0.00',$quote['vat_amount'],2);
        }
        $start = $document['periods'][0];
        $end = $document['periods'][count($document['periods'])-1];
        $scope = array_values($coverage);
        $first['billing']['coverage'] = $scope;
        $first['billing']['amount'] = $amount;
        $first['billing']['key'] = $contract['id'].':quarter:'.$start.':'.$end.':'.InvoiceCycle::revision($scope);
        $first['amount'] = $amount;
        $first['due'] = $document['due'];
        $first['purpose'] = 'Арендная плата за '.implode(', ',$document['periods']).' по договору № '.$contract['number']
            .' от '.Documents::dateText($contract['document_date']).'.';
        if ($vat !== null && $first['vat_rate'] !== null)
            $first['purpose'] .= ' В том числе НДС '.$first['vat_rate'].' % — '.Documents::amountText($vat).' ₽.';
        $first['rates'] = array_column($quotes,'source');
        return $first;
    }

    public static function save(PDO $db, array $actor, array $input): array
    {
        RegularInvoice::requireAccess(2);
        $period = RegularInvoice::period((string)($input['period'] ?? ''));
        $choices = $input['choices'] ?? [];
        if (!is_array($choices) || !$choices || count($choices)>250 || ($input['confirmed'] ?? '') !== '1')
            throw new RuntimeException('Выберите разрешённые позиции и подтвердите сохранение.',422);
        ksort($choices,SORT_NUMERIC);
        $plan = [];
        foreach ($choices as $id=>$token) {
            if (!is_int($id) || $id<1 || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token))
                throw new RuntimeException('Проверьте выбранные позиции.',422);
            RegularInvoice::contract($id);
            $contract = Service::get($db,'contracts',$id);
            $row = self::row($db,$contract,$period);
            if (!isset($row['token']) || !hash_equals($row['token'],$token))
                throw new RuntimeException('Договор № '.$contract['number'].': '.($row['question'] ?? 'изменились условия расчёта.')
                    .' Обновите предпросмотр. Пакет не сохранён.',409);
            $plan[] = $row;
        }
        $ids = [];
        $total = '0.00';
        foreach ($plan as $row) {
            foreach ($row['documents'] as $document) {
                $quote = $document['quote'];
                $ids[] = RegularInvoice::issue($db,$actor,$row['contract'],$quote,$document['periods'],$input['command_key']);
                $total = bcadd($total,$quote['amount'],2);
            }
        }
        return ['invoice_ids'=>$ids,'total'=>$total,
            'message'=>'Сохранено счетов: '.count($ids).'. Сумма '.Documents::amountText($total).' ₽.'];
    }
}
