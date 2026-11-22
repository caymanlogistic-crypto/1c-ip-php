<?php
declare(strict_types = 1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
/** C workflow-v2.js 51–71: whole object segments, explicit coverage before month fallback. */
final class MonthlyBilling {
    /** Imported DTOs may omit their archive; origin is always read from the current SQL row. */
    public static function assertCoverageReady(PDO $p,int $contractId): void
    {
        $query=$p->prepare('SELECT source_json FROM contracts WHERE id=?');
        $query->execute([$contractId]);
        $json=$query->fetchColumn();
        if ($json===false) throw new RuntimeException('Договор не найден.',404);
        $source=json_decode($json,true) ?: [];
        if (empty($source['imported'])) return;
        if (!\App\InvoiceScope::enabled()) throw new RuntimeException('IMPORTED_COVERAGE_REVIEW_REQUIRED');
        $query=$p->prepare("SELECT id FROM invoices WHERE contract_id=? AND status<>'revoked' ORDER BY id");
        $query->execute([$contractId]);
        $ids=$query->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) throw new RuntimeException('IMPORTED_COVERAGE_REVIEW_REQUIRED');
        foreach ($ids as $id) {
            $scope=\App\InvoiceScope::metadata($p,(int)$id);
            if ($scope===null || $scope['availability']!=='KNOWN' || !empty($scope['runtime_hold']) || $scope['classification']==='UNCLASSIFIED') {
                throw new RuntimeException('IMPORTED_COVERAGE_REVIEW_REQUIRED');
            }
        }
    }
    public static function coverage(PDO $p, array $c, array $invoice):array {
        if ($invoice['status'] === 'revoked')return [
        ];
        $scope = \App\InvoiceScope::read($p, (int)$invoice['id']);
        if ($scope !== null) {
            if (!empty($scope['runtime_hold']) || $scope['availability'] !== 'KNOWN' || $scope['classification'] === 'UNCLASSIFIED')throw new RuntimeException('INVOICE_SCOPE_UNKNOWN');
            return $scope['classification'] === 'NOT_RENTAL_SECURITY'?[
            ]:$scope['coverage'];
        }
        return self::sourceCoverage($p, $c, $invoice);
    }
    public static function sourceCoverage(PDO $p, array $c, array $invoice):array {
        if ($invoice['kind'] === 'Обеспечительный платёж' || $invoice['status'] === 'revoked')return [
        ];
        if (str_starts_with($invoice['coverage_key'] ?? '', 'billable-monthly:')) {
            $receipt=\App\BillingInvoiceTargets::read($p,(int)$invoice['id']);
            if ($receipt===null)throw new RuntimeException('BILLABLE_INVOICE_PLAN_UNAVAILABLE');
            return $receipt['plan']['physical_coverage'];
        }
        $q = $p->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date');
        $q->execute([
            $invoice['id']
        ]);
        $explicit = $q->fetchAll();
        if ($explicit)return $explicit;
        if (!preg_match('/^\d{4}-\d{2}$/D', $invoice['period'] ?? ''))return [
        ];
        $first = Service::date($invoice['period']
        . '-01');
        $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $q = $p->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id');
        $q->execute([
            $invoice['id']
        ]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            $q = $p->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');
            $q->execute([
                $c['id']
            ]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        }
        $result = [
        ];
        foreach ($ids as $id) {
            $q = $p->prepare('SELECT start_date,end_date FROM contract_terms WHERE contract_id=? AND object_id=? AND (effective_date IS NULL OR effective_date<=?) ORDER BY effective_date DESC,id DESC LIMIT 1');
            $q->execute([
                $c['id'],
                $id,
                $last
            ]);
            $t = $q->fetch()?:$c;
            $from = max($first, $t['start_date'] ?? $first);
            $to = min($last, $t['end_date'] ?? $last);
            if ($from <= $to)$result[] = [
                'object_id' => (int)$id,
                'start_date' => $from,
                'end_date' => $to
            ];
        }
        return $result;
    }
    public static function plan(PDO $p, array $c, string $period):array {
        HandoverQuestion::assertBillingReady($p, (int)$c['id'], $period);
        self::assertCoverageReady($p,(int)$c['id']);
        if (\App\BillingPartAdmission::selected()!==null && \App\BillingParts::terms($p,'contract',(int)$c['id'])) {
            return self::billablePlan($p,$c,$period);
        }
        $first = Service::date($period
        . '-01');
        $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $q = $p->prepare('SELECT t.* FROM contract_terms t JOIN contract_objects co ON co.contract_id=t.contract_id AND co.object_id=t.object_id WHERE t.contract_id=? AND (t.effective_date IS NULL OR t.effective_date<=?) ORDER BY t.effective_date DESC,t.id DESC');
        $q->execute([
            $c['id'],
            $last
        ]);
        $terms = $q->fetchAll();
        $seen = [
        ];
        $segments = [
        ];
        foreach ($terms as $t) {
            $id = (int)$t['object_id'];
            if (isset($seen[$id]))continue;
            $seen[$id] = true;
            $from = max($first, $t['start_date'], $c['servicing_effective_date'] ?? $first);
            $to = min($last, $t['end_date']);
            if ($from <= $to)$segments[] = [
                'object_id' => $id,
                'start_date' => $from,
                'end_date' => $to,
                'amount' => $t['amount']
            ];
        }
        if (!$terms) {
            $q = $p->prepare('SELECT object_id FROM contract_objects WHERE contract_id=? ORDER BY object_id');
            $q->execute([
                $c['id']
            ]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $from = max($first, $c['start_date']);
                $to = min($last, $c['end_date']);
                if ($from <= $to)$segments[] = [
                    'object_id' => (int)$id,
                    'start_date' => $from,
                    'end_date' => $to,
                    'amount' => count($ids) === 1?$c['amount']:null
                ];
            }
        }
        $q = $p->prepare("SELECT * FROM invoices WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж' ORDER BY id");
        $q->execute([
            $c['id']
        ]);
        $coverage = [
        ];
        foreach ($q->fetchAll() as $invoice)$coverage = array_merge($coverage, self::coverage($p, $c, $invoice));
        $missing = [
        ];
        foreach ($segments as $t) {
            $covered = false;
            foreach ($coverage as $x)if ((int)$x['object_id'] === $t['object_id'] && $x['start_date'] <= $t['start_date'] && $x['end_date'] >= $t['end_date']) {
                $covered = true;
                break;
            }
            if (!$covered)$missing[] = $t;
        }
        $sum = '0';
        $known = true;
        foreach ($missing as $t) {
            if ($t['amount'] === null) {
                $known = false;
                break;
            }
            $days = (int)(new \DateTimeImmutable($t['start_date']))->diff(new \DateTimeImmutable($t['end_date']))->days+1;
            $sum = bcadd($sum, bcdiv(bcmul($t['amount'], (string)$days, 12), (new \DateTimeImmutable($t['start_date']))->format('t'), 12), 12);
        }
        if (!$known) {
            if (count($missing) !== count($segments) || $c['amount'] === null)throw new RuntimeException('Часть помещений имеет неподтверждённую цену.');
            $sum = $c['amount'];
        }
        $ids = array_column($missing, 'object_id');
        sort($ids, SORT_NUMERIC);
        return [
            'key' => $c['id']
            . ':Аренда:'
            . $period
            . ':'
            . implode(',',
            $ids)
            . ':',
            'period' => $period,
            'amount' => Billing::round($sum),
            'coverage' => $missing,
            'objects' => $ids
        ];
    }
    public static function issue(PDO $p, array $c, array $plan, string $date,?array $single = null):?int {
        if ((isset($plan['invoice_targets']) || $single!==null) && !$p->inTransaction())
            throw new RuntimeException('Явный план счёта требует общей транзакции.',409);
        if (!self::hasTargets($plan))return null;
        HandoverQuestion::assertBillingReady($p, (int)$c['id'], $plan['period']);
        if (isset($plan['invoice_targets']) && bccomp($plan['amount'],'0',2)<=0)throw new RuntimeException('Сумма ежемесячного счёта должна быть положительной.',409);
        $q = $p->prepare("SELECT id FROM invoices WHERE contract_id=? AND coverage_key=? AND status<>'revoked' LIMIT 1 FOR UPDATE");
        $q->execute([
            $c['id'],
            $plan['key']
        ]);
        if ($id = $q->fetchColumn()) {
            if (isset($plan['invoice_targets'])) {
                $receipt=\App\BillingInvoiceTargets::read($p,(int)$id);
                if ($receipt===null)throw new RuntimeException('Не подтверждён сохранённый план счёта.',409);
                \App\BillingInvoiceTargets::assertCurrent($p,(int)$id,$receipt,$plan['invoice_targets']);
                $invoice=NativeInvoiceScope::assertSelected($p,(int)$id,$receipt['plan']);
                if ($invoice['amount']===null||bccomp($invoice['amount'],$plan['amount'],2)!==0||$invoice['period']!==$plan['period']) {
                    throw new RuntimeException('Сумма или период ранее начисленного счёта изменились.',409);
                }
            }
            return (int)$id;
        }
        if (isset($plan['invoice_targets'])) \App\BillingInvoiceTargets::assertSelection($p,(int)$c['id'],$plan['invoice_targets']);
        $id = \App\BusinessIds::next($p,'invoices',900000);
        $creation=NativeOperatingWriter::begin($p,'invoice',$id,'native-c-monthly','monthly.invoice.create',$date,$plan['key']);
        $p->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,due,status,coverage_key,source_json,purpose) VALUES(?,?,?,'Аренда',?,?,0,?,?,'active',?,?,?)")->execute([
            $id,
            'Д-'
            . $id,
            $c['id'],
            $plan['period'],
            $plan['amount'],
            $date,
            $single['due'] ?? InvoiceDueDate::monthlyRent($plan['period']),
            $plan['key'],
            $single ? Importer::json(['regular_invoice'=>$single['source']]) : '{}',
            $single['purpose'] ?? null,
        ]);
        if (isset($plan['invoice_targets'])) foreach($plan['objects'] as $object) {
            $p->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([$id,$object]);
        }
        foreach ($plan['coverage'] as $t) {
            if (!isset($plan['invoice_targets'])) $p->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([
                $id,
                $t['object_id']
            ]);
            $p->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)')->execute([
                $id,
                $t['object_id'],
                $t['start_date'],
                $t['end_date']
            ]);
        }
        Billing::snapshotInvoice($p, $id);
        if (isset($single['vat_rate'])) $p->prepare('UPDATE invoices SET vat=? WHERE id=?')->execute([$single['vat_rate'],$id]);
        if (isset($single['report_charges'])) InvoiceReport::capture($p,$id,$single['report_charges']);
        NativeInvoiceScope::record($p, $id, 'native-c-monthly',null,$plan['invoice_targets'] ?? null);
        if (isset($plan['invoice_targets'])) \App\BillingInvoiceTargets::record($p,$id,$plan['invoice_targets'],[
            'source_sha256'=>\App\BillingPartAdmission::NATIVE_SOURCE,'source_version'=>'workflow-v2:51-71','producer'=>'monthly',
            'source_identity'=>'monthly:'.hash('sha256',$plan['key']),'invoice_version'=>1,'coverage_key'=>$plan['key'],
        ]);
        NativeOperatingWriter::finish($p,'invoice',$id,'native-c-monthly',$creation);
        return $id;
    }

    public static function hasTargets(array $plan): bool
    {
        return isset($plan['invoice_targets']) ? $plan['invoice_targets']['selected_terms']!==[] : !empty($plan['objects']);
    }

    private static function billablePlan(PDO $p,array $c,string $period): array
    {
        $first=Service::date($period.'-01');$last=(new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $terms=\App\BillableTerms::forContract($p,(int)$c['id']);
        $partIds=array_values(array_unique(array_column(array_filter($terms,static fn($term)=>$term['target_kind']==='billing-part'),'part_id')));
        $query=$p->prepare("SELECT * FROM invoices WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж' ORDER BY id");$query->execute([$c['id']]);
        $coverage=[];
        foreach($query->fetchAll() as $invoice){
            if(self::otherTypedPeriod($invoice['coverage_key'] ?? '',(int)$c['id'],$period))continue;
            foreach(self::coverage($p,$c,$invoice) as $segment)$coverage[]=[
                'target_key'=>'object:'.$segment['object_id'],'start_date'=>$segment['start_date'],'end_date'=>$segment['end_date']];
        }
        $query=$p->prepare("SELECT i.id,i.coverage_key FROM invoices i LEFT JOIN rental_facts f ON f.namespace='native_invoice_targets' AND BINARY f.source_key=BINARY CAST(i.id AS CHAR)
            WHERE i.contract_id=? AND i.status<>'revoked' AND i.kind<>'Обеспечительный платёж' AND (f.id IS NOT NULL OR i.coverage_key LIKE 'billable-monthly:%') ORDER BY i.id");
        $query->execute([$c['id']]);$relevant=[];$receipts=[];
        foreach($query->fetchAll() as $invoice){
            if(self::otherTypedPeriod($invoice['coverage_key'] ?? '',(int)$c['id'],$period))continue;
            $receipt=\App\BillingInvoiceTargets::read($p,(int)$invoice['id']);
            if($receipt===null){
                if(preg_match('/^billable-monthly:[1-9][0-9]*:([0-9]{4}-[0-9]{2}):[a-f0-9]{64}$/D',$invoice['coverage_key'] ?? '',$match)&&$match[1]!==$period)continue;
                throw new RuntimeException('Не подтверждён исходный план ранее начисленного счёта.',409);
            }
            $byKey=[];foreach($receipt['plan']['selected_terms'] as $term)$byKey[$term['source_term_key']]=$term;
            foreach($receipt['plan']['selected_intervals'] as $interval){
                $term=$byKey[$interval['source_term_key']];
                if($term['target_kind']==='billing-part'&&in_array($term['part_id'],$partIds,true)&&$interval['start_date']<=$last&&$interval['end_date']>=$first){
                    $relevant[]=(int)$invoice['id'];$receipts[(int)$invoice['id']]=$receipt;break;
                }
            }
        }
        $partCoverage=\App\BillingPartCoverage::read($p,$relevant,$partIds,$first,$last);
        if ($partCoverage===null || $partCoverage['gaps'] || $partCoverage['holds'])throw new RuntimeException('Покрытие ранее начисленных частей требует проверки.',409);
        foreach($partCoverage['invoice_scopes'] as $entry){
            $actual=$entry['scope']['coverage'];$expected=$receipts[$entry['invoice_id']]['plan']['part_scope_rows'];
            $normalize=static function(array $rows):array{$rows=array_map(static fn($row)=>array_intersect_key($row,array_flip(['part_id','part_version','scope_state','start_date','end_date'])),$rows);
                usort($rows,static fn($a,$b)=>strcmp(Importer::canonicalJson($a),Importer::canonicalJson($b)));return $rows;};
            if(Importer::canonicalJson($normalize($actual))!==Importer::canonicalJson($normalize($expected)))throw new RuntimeException('Состав частей ранее начисленного счёта изменился.',409);
        }
        foreach($partCoverage['coverage'] as $entry){$row=$entry['coverage'];$coverage[]=['target_key'=>'part:'.$row['part_id'],'start_date'=>$row['start_date'],'end_date'=>$row['end_date']];}
        return BillableMonthlyPlan::calculate($c,$terms,$coverage,$period)+['part_coverage_basis'=>$partCoverage];
    }

    /** This is the persisted native producer identity, never an issue date or display label. */
    private static function otherTypedPeriod(string $key,int $contractId,string $period): bool
    {
        if(!str_starts_with($key,'billable-monthly:'))return false;
        if(!preg_match('/^billable-monthly:([1-9][0-9]*):([0-9]{4}-[0-9]{2}):[a-f0-9]{64}$/D',$key,$match)
            ||(int)$match[1]!==$contractId)throw new RuntimeException('Не подтверждена принадлежность плана ранее начисленного счёта.',409);
        return $match[2]!==$period;
    }
}
