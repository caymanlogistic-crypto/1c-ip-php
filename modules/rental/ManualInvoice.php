<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{BillingPartAdmission,BillingParts,InvoiceScope};
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class ManualInvoice
{
    public static function route(int $id, string $period, string $issued): string
    {
        return '/rental/edit-invoice?'.http_build_query(['contract_id'=>$id,'period'=>$period,'issued'=>$issued]);
    }

    public static function coverage(PDO $db, array $contract, string $period, string $issued, ?array $preview = null): array
    {
        $id = (int)$contract['id'];
        if ($error = InitialInvoice::dateError($contract,$issued))
            throw new RuntimeException($error,422);
        HandoverQuestion::assertBillingReady($db,$id,$period);
        InvoiceCycle::assertIndividual($db,$id,$period);
        $facts = LeasePaymentTerms::facts($db,$id);
        if (($facts['decision']['decision'] ?? null) === 'self')
            throw new RuntimeException('По договору подтверждена самостоятельная оплата. Для счёта сначала уточните согласованный порядок.',409);
        $starts = InitialInvoice::assertBeyondInitial($db,$contract,$period,$facts);

        $first = $period.'-01';
        $last = (new DateTimeImmutable($first))->format('Y-m-t');
        $from = max($first,...$starts);
        $from = max($from,$contract['servicing_effective_date'] ?? $first);
        $to = min($last,$contract['end_date']);
        if ($from > $to) throw new RuntimeException('В выбранном месяце нет действующего периода аренды.',409);

        try {
            MonthlyBilling::assertCoverageReady($db,$id);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'IMPORTED_COVERAGE_REVIEW_REQUIRED') throw $error;
            throw new RuntimeException('Сверьте покрытие ранее выставленных счетов по договору.',409);
        }
        if (BillingPartAdmission::selected() !== null && BillingParts::terms($db,'contract',$id))
            throw new RuntimeException('Для этого договора требуется расчёт по отдельным предметам начисления.',409);

        $query = $db->prepare('SELECT co.object_id,t.start_date,t.end_date,t.effective_date
            FROM contract_objects co LEFT JOIN contract_terms t ON t.contract_id=co.contract_id AND t.object_id=co.object_id
              AND (t.effective_date IS NULL OR t.effective_date<=?)
            WHERE co.contract_id=? ORDER BY co.object_id,t.effective_date DESC,t.id DESC');
        $query->execute([$to,$id]);
        $coverage = [];
        foreach ($query->fetchAll() as $term) {
            $object = (int)$term['object_id'];
            if (isset($coverage[$object])) continue;
            if (!$term['start_date'] || !$term['end_date'] || $term['start_date'] > $from || $term['end_date'] < $to
                || ($term['effective_date'] && $term['effective_date'] > $from))
                throw new RuntimeException('Сверьте состав и даты аренды объектов за выбранный период.',409);
            $coverage[$object] = ['object_id'=>$object,'start_date'=>$from,'end_date'=>$to];
        }
        if (!$coverage) throw new RuntimeException('Сверьте состав и даты аренды объектов за выбранный период.',409);

        $query = $db->prepare("SELECT id,period FROM invoices WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж'"
            .($db->inTransaction() ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $stored = $db->prepare('SELECT start_date,end_date FROM invoice_coverage WHERE invoice_id=?');
        foreach ($query->fetchAll() as $invoice) {
            if ($invoice['period'] === $period)
                throw new RuntimeException('За этот период уже выставлен счёт. Откройте его из реестра.',409);
            $scope = InvoiceScope::read($db,(int)$invoice['id']);
            if ($scope !== null && ($scope['availability'] !== 'KNOWN' || $scope['runtime_hold']
                || $scope['classification'] === 'UNCLASSIFIED'))
                throw new RuntimeException('Сверьте покрытие ранее выставленных счетов по договору.',409);
            if (($scope['classification'] ?? null) === 'NOT_RENTAL_SECURITY') continue;
            $stored->execute([$invoice['id']]);
            $old = $scope['coverage'] ?? $stored->fetchAll();
            if (!$old) throw new RuntimeException('Сверьте покрытие ранее выставленных счетов по договору.',409);
            foreach ($old as $line) {
                if ($line['start_date'] <= $to && $line['end_date'] >= $from)
                    throw new RuntimeException('Ранее выставленный счёт уже покрывает часть выбранного периода.',409);
            }
        }

        $quote = $preview ?? RegularInvoice::preview($db,$contract,$period,$issued,0);
        if ($quote['existing'] || isset($quote['previously_issued']))
            throw new RuntimeException('За этот период уже сохранён счёт. Откройте его из предпросмотра.',409);
        if (isset($quote['token']))
            throw new RuntimeException('Для этих условий доступен расчёт через «Создать счёт».',409);
        return ['objects'=>array_keys($coverage),'coverage'=>array_values($coverage)];
    }
}
