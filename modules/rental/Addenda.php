<?php
declare(strict_types = 1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
final class Addenda {
    public static function terms(PDO $p, array $c, array $in, ?array $old = null):array {
        $parts = PartTerms::input($p, $old?'document':'contract', (int)($old['id'] ?? $c['id']), $in,
            (string)($in['start_date'] ?? $old['start_date'] ?? $c['start_date']),
            (string)($in['end_date'] ?? $old['end_date'] ?? $c['end_date']), 'document');
        $replacePartBranch = $parts !== null;
        $partRows = $parts ?? \App\BillingParts::terms($p, $old?'document':'contract', (int)($old['id'] ?? $c['id']));
        if (!$old && $parts === null && $partRows) $parts = \App\BillingPartTerms::raw($p, 'contract', (int)$c['id']);
        $ids = array_values(array_map('intval', (array)($in['objects'] ?? [
        ])));
        if (!$ids && !array_key_exists('part_terms_present', $in)) {
            $q = $p->prepare('SELECT object_id FROM '
            . ($old?'document_terms WHERE document_id':'contract_objects WHERE contract_id')
            . '=? ORDER BY object_id');
            $q->execute([
                $old['id'] ?? $c['id']
            ]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        }
        if ((!$ids && !$partRows) || count($ids) !== count(array_unique($ids)))throw new RuntimeException('Добавьте объекты без повторов.');
        sort($ids, SORT_NUMERIC);
        $relations = Occupancy::relations($p, (int)$c['id']);
        if ($old) {
            $q = $p->prepare('SELECT * FROM document_terms WHERE document_id=?');
            $q->execute([
                $old['id']
            ]);
            foreach ($q->fetchAll() as $r)$relations[(int)$r['object_id']] = $r;
        }
        $terms = [
        ];
        $total = PartTerms::total($partRows, $replacePartBranch);
        foreach ($ids as $oid) {
            $start = Service::date($in['term_start'][$oid] ?? $in['start_date'] ?? $old['start_date'] ?? '');
            $end = Service::date($in['term_end'][$oid] ?? $in['end_date'] ?? $old['end_date'] ?? '');
            if ($start>$end)throw new RuntimeException('Проверьте срок каждого помещения.');
            $amount = Service::money($in['term_amount'][$oid] ?? '');
            if (bccomp($amount, '0', 2) <= 0)throw new RuntimeException('Укажите положительную цену каждого помещения.');
            if (Occupancy::physical($relations[$oid] ?? null))Service::available($p, [
                $oid
            ], $start, $end, (int)$c['id'], null, false, null, true);
            $total = bcadd($total, $amount, 2);
            $terms[] = [
                'object_id' => $oid,
                'start_date' => $start,
                'end_date' => $end,
                'amount' => $amount
            ]+\App\ContractOccupancy::insertFields($relations[$oid] ?? null);
        }
        if (isset($in['amount']) && bccomp(Service::money($in['amount']), $total, 2) !== 0)throw new RuntimeException('Сумма цен помещений не равна общей месячной плате.');
        $datedRows = [...$terms, ...$partRows];
        return [
            'rows' => $terms,
            ...($parts !== null?['part_rows' => $parts, 'replace_part_branch' => $replacePartBranch]:[]),
            'amount' => $total,
            'start' => min(array_column($datedRows,
            'start_date')),
            'end' => max(array_column($datedRows,
            'end_date'))
        ];
    }
    public static function saveTerms(PDO $p, int $id, array $terms):void {
        $q = $p->prepare('SELECT * FROM document_terms WHERE document_id=?');
        $q->execute([
            $id
        ]);
        $previous = [
        ];
        foreach ($q->fetchAll() as $row) $previous[(int)$row['object_id']] = $row;
        $p->prepare('DELETE FROM document_terms WHERE document_id=?')->execute([
            $id
        ]);
        foreach ($terms as $term) Occupancy::insert($p, 'document_terms', [
            'document_id' => $id,
            'object_id' => $term['object_id'],
            'start_date' => $term['start_date'],
            'end_date' => $term['end_date'],
            'amount' => $term['amount'],
        ], array_key_exists('physical_occupancy', $term)?$term:($previous[(int)$term['object_id']] ?? null));
    }
    public static function reserve(PDO $p, array $c, int $id):void {
        $q = $p->prepare('SELECT * FROM document_terms WHERE document_id=? ORDER BY object_id');
        $q->execute([
            $id
        ]);
        $terms = $q->fetchAll();
        $p->prepare("UPDATE reservations SET status='cancelled',version=version+1 WHERE document_id=? AND status='active'")->execute([
            $id
        ]);
        foreach ($terms as $t) {
            if (!Occupancy::physical($t))continue;
            Service::available($p, [
                (int)$t['object_id']
            ], $t['start_date'], $t['end_date'], (int)$c['id'], null, false, null, true);
            $q = $p->prepare('SELECT id FROM reservations WHERE document_id=? AND object_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([
                $id,
                $t['object_id']
            ]);
            $rid = $q->fetchColumn();
            if ($rid)$p->prepare("UPDATE reservations SET start_date=?,end_date=?,until_date=?,status='active',version=version+1 WHERE id=?")->execute([
                $t['start_date'],
                $t['end_date'],
                $t['end_date'],
                $rid
            ]);
            else {
                $rid = \App\BusinessIds::next($p,'reservations',900000);
                $p->prepare("INSERT INTO reservations(id,object_id,party_id,document_id,contract_id,start_date,end_date,until_date,status,comment,source_json) VALUES(?,?,?,?,?,?,?,?,'active',?,'{}')")->execute([
                    $rid,
                    $t['object_id'],
                    $c['party_id'],
                    $id,
                    $c['id'],
                    $t['start_date'],
                    $t['end_date'],
                    $t['end_date'],
                    'Продление договора '
                    . $c['number']
                ]);
            }
        }
    }
    public static function apply(PDO $p, array $c, array $d, ?string $applicationAction = null, ?array $actor = null, ?string $commandKey = null):void {
        $relations = Occupancy::relations($p, (int)$c['id']);
        $previous = array_keys($relations);
        $q = $p->prepare('SELECT * FROM document_terms WHERE document_id=? ORDER BY object_id');
        $q->execute([
            $d['id']
        ]);
        $terms = $q->fetchAll();
        $ids = array_map('intval', array_column($terms, 'object_id'));
        \App\Audit::record('document.objects-applied','document',$d['id'],['object_ids'=>$previous],[
            'contract_id'=>(int)$c['id'],'added_object_ids'=>array_values(array_diff($ids,$previous)),
            'effective_date'=>$d['effective_date'],
        ]);
        foreach ($terms as $term)if (Occupancy::physical($term))Service::available($p, [
            (int)$term['object_id']
        ], $term['start_date'], $term['end_date'], (int)$c['id'], null, false, null, true);
        $documentParts = \App\BillingParts::terms($p, 'document', (int)$d['id']);
        $contractParts = \App\BillingParts::terms($p, 'contract', (int)$c['id']);
        $documentState = \App\BillingPartTerms::state($p, 'document', (int)$d['id']);
        $contractState = \App\BillingPartTerms::state($p, 'contract', (int)$c['id']);
        if ($documentParts || $contractParts || $documentState!==null || $contractState!==null) {
            if (($documentState['state'] ?? null)!=='KNOWN') throw new RuntimeException('Не подтверждён полный состав частей в подписанном ДС.', 409);
            if ($actor === null || $commandKey === null || !in_array($applicationAction, ['document.sign','document.apply'], true)) {
                throw new RuntimeException('Применение частей требует подтверждённого действия подписанного ДС.', 409);
            }
            ContractHistory::remember($p, $c, $actor, $commandKey);
            $before = \App\BillingPartTerms::raw($p, 'contract', (int)$c['id'], true);
            \App\BillingPartTerms::applyDocument($p, (int)$d['id'], (int)$c['id'], (int)$d['version'], (int)$c['version'], [
                'actor_id' => (int)$actor['id'], 'command_key' => $commandKey,
                'source_rule_sha256' => \App\BillingPartAdmission::NATIVE_SOURCE, 'record_version' => (int)$c['version'],
                'history_before_sha256' => \App\OperatingScopeArtifact::hash($before),
            ], $applicationAction);
        }
        $before = (new \DateTimeImmutable($d['effective_date']))->modify('-1 day')->format('Y-m-d');
        $p->prepare('UPDATE contract_terms SET superseded_on=? WHERE contract_id=? AND (superseded_on IS NULL OR superseded_on>?)')->execute([
            $before,
            $c['id'],
            $before
        ]);
        foreach ($terms as $term)$p->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,effective_date,source_json) VALUES(?,?,?,?,?,?,'{}')")->execute([
            $c['id'],
            $term['object_id'],
            $term['start_date'],
            $term['end_date'],
            $term['amount'],
            $d['effective_date']
        ]);
        foreach (array_diff($previous, $ids) as $oid)Occupancy::insert($p, 'rental_object_holds', [
            'contract_id' => $c['id'],
            'document_id' => $d['id'],
            'object_id' => $oid,
            'effective_date' => $d['effective_date']
        ], $relations[$oid]);
        $p->prepare('DELETE FROM contract_objects WHERE contract_id=?')->execute([
            $c['id']
        ]);
        foreach ($terms as $term)Occupancy::insert($p, 'contract_objects', [
            'contract_id' => $c['id'],
            'object_id' => $term['object_id']
        ], $term);
        $p->prepare('UPDATE contracts SET start_date=?,end_date=?,amount=?,version=version+1 WHERE id=?')->execute([
            $d['start_date'],
            $d['end_date'],
            $d['amount'],
            $c['id']
        ]);
        $p->prepare("UPDATE reservations SET status='converted',version=version+1 WHERE document_id=? AND status='active'")->execute([
            $d['id']
        ]);
        CustomerBookings::cancelOthers($p,(int)$c['id']);
        Billing::reviewAfterTerms($p, (int)$c['id']);
        if (!empty(json_decode($c['source_json'], true)['imported']))$p->prepare('UPDATE contracts SET servicing_document_id=?,servicing_effective_date=? WHERE id=?')->execute([
            $d['id'],
            $d['effective_date'],
            $c['id']
        ]);
    }
    public static function addedInvoices(PDO $p, array $c, array $d):array {
        $q = $p->prepare('SELECT t.* FROM document_terms t WHERE t.document_id=? AND NOT EXISTS(SELECT 1 FROM contract_objects co WHERE co.contract_id=? AND co.object_id=t.object_id) ORDER BY t.object_id');
        $q->execute([
            $d['id'],
            $c['id']
        ]);
        $added = $q->fetchAll();
        if (!$added)return [
        ];
        $security = '0.00';
        $rent = '0';
        $periods = [
        ];
        foreach ($added as $t) {
            $start = max($t['start_date'], $d['effective_date']);
            $end = min($t['end_date'], (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'));
            if ($end<$start)throw new RuntimeException('Срок добавленного помещения заканчивается раньше вступления ДС.');
            $security = bcadd($security, $t['amount'], 2);
            $days = (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days+1;
            $rent = bcadd($rent, bcdiv(bcmul($t['amount'], (string)$days, 12), (new \DateTimeImmutable($start))->format('t'), 12), 12);
            $periods[] = substr($start, 0, 7);
        }
        $periods = array_unique($periods);
        sort($periods);
        $ids = [
        ];
        foreach ([
            [
                'Обеспечительный платёж',
                'Обеспечение',
                $security
            ],
            [
                'Первый период',
                implode(' / ',
                $periods),
                Billing::round($rent)
            ]
        ] as [
            $kind,
            $period,
            $amount
        ]) {
            $key = 'addendum:'
            . $d['id']
            . ':'
            . $kind;
            $q = $p->prepare('SELECT id FROM invoices WHERE coverage_key=?');
            $q->execute([
                $key
            ]);
            if ($old = $q->fetchColumn()) {
                $ids[] = (int)$old;
                continue;
            }
            $id = \App\BusinessIds::next($p,'invoices',900000);
            $issued=NativeOperatingWriter::today($p);
            $creation=NativeOperatingWriter::begin($p,'invoice',$id,'native-c-initial','addenda.initial-invoice',$issued,$key);
            $p->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,due,status,initial_invoice,coverage_key,source_json) VALUES(?,?,?,?,?,?,0,?,?,'active',1,?,'{}')")->execute([
                $id,
                'Д-'
                . $id,
                $c['id'],
                $kind,
                $period,
                $amount,
                $issued,
                InvoiceDueDate::automatic($issued,$period,true),
                $key
            ]);
            $p->prepare('UPDATE invoices SET document_id=? WHERE id=?')->execute([
                $d['id'],
                $id
            ]);
            foreach ($added as $term) {
                $p->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)')->execute([
                    $id,
                    $term['object_id']
                ]);
                if ($kind === 'Первый период') {
                    $from = max($term['start_date'], $d['effective_date']);
                    $to = min($term['end_date'], (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d'));
                    $p->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)')->execute([
                        $id,
                        $term['object_id'],
                        $from,
                        $to
                    ]);
                }
            }
            Billing::snapshotInvoice($p, $id);
            NativeInvoiceScope::record($p, $id, 'native-c-initial');
            NativeOperatingWriter::finish($p,'invoice',$id,'native-c-initial',$creation);
            $ids[] = $id;
        }
        return $ids;
    }
}
