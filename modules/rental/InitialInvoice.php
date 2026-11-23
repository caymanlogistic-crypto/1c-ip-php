<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, BillableTerms, BillingPartTerms, PagePolicy};
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class InitialInvoice
{
    public const ISSUE = 'contract.initial-issue';
    public const TERMS = 'contract.initial-terms';
    public const DUE = 'contract.initial-due';
    public const ACTIONS = [self::ISSUE,self::TERMS,self::DUE];
    public const POLICY = 'separate-initial-invoices-v1';
    public const DUE_PARTS = ['rent'=>'Аренда за первый период','security'=>'Обеспечительный платёж',
        'next'=>'Аренда за полный следующий месяц'];
    private const NS = 'rental_initial_invoice';
    private const STATUSES = ['draft','returned','legal','accounting','approved','sent','active'];

    public static function grant(string $action, string $role): string
    {
        return match (true) {
            $action === self::TERMS => 'rental.approve.legal',
            $action === self::ISSUE && $role === 'manager' => 'rental.write',
            default => 'finance.write',
        };
    }

    public static function can(array $contract, string $action): bool
    {
        $actor = Auth::user();
        $managerIssue = $action === self::ISSUE && ($actor['role'] ?? '') === 'manager';
        $role = $action === self::TERMS ? 'lawyer' : 'accountant';
        if ((!$managerIssue && !in_array($actor['role'] ?? '',[$role,'admin'],true))
            || !Auth::can(self::grant($action,$actor['role']))
            || !self::available($contract)) return false;
        if ($managerIssue && (!empty($contract['signed']) || $contract['status'] === 'active')) return false;
        try {
            Access::contract((int)$contract['id'],2);
        } catch (RuntimeException $error) {
            if ($error->getCode() === 403) return false;
            throw $error;
        }
        return $managerIssue || $action === self::TERMS || empty($actor['grants_configured'])
            || $actor['role'] === 'admin' || PagePolicy::level('rental.invoice-list',$actor) >= 2;
    }

    public static function assertIssueParty(PDO $db, array $contract, string $role): void
    {
        if ($role !== 'manager') return;
        if (empty($contract['party_id'])) throw new RuntimeException('Сначала укажите контрагента и передайте его карточку на согласование юристу.',409);
        PartyLegalReview::assertCurrent($db,(int)$contract['party_id'],requireApproval:true);
    }

    public static function available(array $contract): bool
    {
        return in_array($contract['status'],self::STATUSES,true) && !WorkspaceReset::hidden($contract)
            && empty(Importer::decodeExact($contract['source_json'])['imported']);
    }

    private static function read(PDO $db, int $id, string $kind): ?array
    {
        $q = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::NS,$id.':'.$kind]);
        $value = $q->fetchColumn();
        return $value === false ? null : Importer::decodeExact($value);
    }

    private static function remember(PDO $db, int $id, string $kind, array $fact): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([self::NS,$id.':'.$kind,Importer::json($fact)]);
    }

    public static function receipt(PDO $db, int $id): ?array
    {
        return self::read($db,$id,'issue');
    }

    public static function issuedFor(PDO $db, int $id): array
    {
        $q = $db->prepare("SELECT id,number,kind,period,amount,paid,issued,due,status,coverage_key FROM invoices
            WHERE contract_id=? AND document_id IS NULL AND planning_document_id IS NULL
            AND (coverage_key LIKE ? OR (initial_invoice=1 AND kind IN ('Первый период','Обеспечительный платёж')))
            ORDER BY issued,id");
        $q->execute([$id,'initial:'.$id.':%']);
        return $q->fetchAll();
    }

    public static function approvalTerms(PDO $db,int $id): ?array
    {
        return self::read($db,$id,'terms');
    }

    public static function separateIds(PDO $db, int $id): array
    {
        return self::receipt($db,$id)['invoice_ids'] ?? [];
    }

    public static function stillCoversIssue(PDO $db,array $contract,array $receipt): bool
    {
        $terms = InitialBilling::orderedTerms(BillableTerms::forContract($db,(int)$contract['id']));
        return self::basis($contract,$terms) === ($receipt['condition']['basis'] ?? null);
    }

    private static function basis(array $contract, array $terms): string
    {
        $header = array_intersect_key($contract,array_flip(['id','number','party_id','document_date','start_date','end_date','amount','template_type']));
        $economic = array_flip(['target_key','start_date','end_date','amount','effective_date','superseded_on','physical_occupancy','evidence_sha256']);
        return self::hash([$header,array_map(static fn(array $term): array => array_intersect_key($term,$economic),$terms)]);
    }

    private static function hash(array $value): string
    {
        return hash('sha256',Importer::canonicalJson($value));
    }

    public static function dateError(array $contract,string $issued): ?string
    {
        if (empty($contract['document_date'])) return 'Сначала укажите дату договора.';
        return $issued < $contract['document_date'] ? 'Дата первого счёта не может быть раньше даты договора.' : null;
    }

    public static function assertBeyondInitial(PDO $db,array $contract,string $period,array $facts): array
    {
        if (!$contract['start_date'] || !$contract['end_date'])
            throw new RuntimeException('Сверьте даты начала и окончания аренды.',409);
        $id = (int)$contract['id'];
        $transfer = ($facts['base']['form'] ?? '') === 'D09'
            ? LeasePaymentTerms::actDate(PremisesActs::history($db,$id),'transfer') : null;
        $starts = array_unique(array_filter([$contract['start_date'],$facts['accrual']['date'] ?? null,$transfer]));
        $firstAlreadyIssued = array_filter(self::issuedFor($db,$id),
            static fn(array $invoice): bool => $invoice['kind'] === 'Первый период' && $invoice['status'] !== 'revoked');
        foreach ($starts as $start) {
            $next = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m');
            if ($period <= substr($start,0,7) || (!$firstAlreadyIssued && substr($start,8,2) >= '16' && $period === $next))
                throw new RuntimeException('Проверьте начисление в карточке договора действием «Выставить первый счёт».',409);
        }
        return $starts;
    }

    /** Source threshold and inclusive calendar periods, independent of the payment clock. */
    public static function periods(string $start, string $end): array
    {
        $at = new DateTimeImmutable(Service::date($start));
        Service::date($end);
        if ($end < $start) throw new RuntimeException('Проверьте согласованные даты начисления и окончания договора.',422);
        $monthEnd = $at->modify('last day of this month')->format('Y-m-d');
        $rows = [['kind'=>'Первый период','period'=>$at->format('Y-m'),'from'=>$start,'to'=>min($monthEnd,$end),'initial'=>1]];
        $question = null;
        if ((int)$at->format('d') >= 16 && $end > $monthEnd) {
            $next = $at->modify('first day of next month');
            $nextEnd = $next->modify('last day of this month')->format('Y-m-d');
            if ($end >= $nextEnd) {
                $rows[] = ['kind'=>'Аренда','period'=>$next->format('Y-m'),'from'=>$next->format('Y-m-d'),'to'=>$nextEnd,'initial'=>0];
            } else {
                $question = 'Уточните начисление за '.$next->format('m.Y').': договор заканчивается '.Documents::dateText($end).' внутри месяца.';
            }
        }
        return ['rows'=>$rows,'question'=>$question];
    }

    public static function prorate(string $gross,string $from,string $to): string
    {
        $begin = new DateTimeImmutable($from);
        $days = (int)$begin->diff(new DateTimeImmutable($to))->days+1;
        return Billing::round(bcdiv(bcmul($gross,(string)$days,12),$begin->format('t'),12));
    }

    public static function preview(PDO $db,array $contract,string $issued): array
    {
        $id = (int)$contract['id'];
        $issued = Service::date($issued);
        $plan = ['issued'=>$issued,'rows'=>[],'questions'=>[],'existing'=>self::issuedFor($db,$id),'receipt'=>self::receipt($db,$id)];
        if ($plan['existing'] || $plan['receipt']) return $plan;
        if ($error = self::dateError($contract,$issued)) {
            $plan['questions'][] = $error;
            return $plan;
        }
        $terms = InitialBilling::orderedTerms(BillableTerms::forContract($db,$id));
        $basis = self::basis($contract,$terms);
        $condition = self::read($db,$id,'terms');
        $forms = LeasePaymentTerms::allowedForms($db,$contract);
        if (!$condition || ($condition['basis'] ?? null) !== $basis || !isset($forms[$condition['form']])) {
            $plan['questions'][] = 'Юристу нужно подтвердить применимость порядка первого начисления и кратность обеспечения по текущим условиям договора.';
            return $plan;
        }
        $partState = BillingPartTerms::state($db,'contract',$id);
        $gross = $contract['amount'];
        if (!$terms || !$gross || bccomp($gross,'0',2)<=0 || ($partState !== null && $partState['state'] !== 'KNOWN')) {
            $plan['questions'][] = 'Сверьте согласованную ставку и состав предметов начисления.';
            return $plan;
        }
        $plan['condition'] = $condition;
        $plan['signed'] = !empty($contract['signed']);
        $form = $condition['form'];
        $facts = LeasePaymentTerms::facts($db,$id);
        $transfer = LeasePaymentTerms::actDate(PremisesActs::history($db,$id),'transfer');
        $start = $contract['start_date'];
        if ($plan['signed']) {
            $start = $form === 'D09' ? $transfer : ($facts['accrual']['date'] ?? null);
            if (!$start || ($facts['base']['form'] ?? null) !== $form || bccomp($facts['base']['gross'],$gross,2) !== 0) {
                $plan['questions'][] = 'Сверьте подписанную ставку, форму и фактическое начало начисления по этому договору.';
                return $plan;
            }
        }
        if (!$start || !$contract['end_date'] || $start > $contract['end_date']) {
            $plan['questions'][] = 'Сверьте даты начала начисления и окончания аренды.';
            return $plan;
        }
        $end = $contract['end_date'];
        $plan['from'] = $start;
        $signature = $plan['signed'] ? ($facts['signature']['date'] ?? null) : null;
        if (!$plan['signed']) $transfer = null;
        $periods = self::periods($start,$end);
        if ($periods['question']) $plan['questions'][] = $periods['question'];
        $signedChanges = $plan['signed'] ? LeasePaymentTerms::documents($db,$id,'renewal') : [];
        foreach ($periods['rows'] as $row) {
            $problem = self::periodQuestion($db,$id,$row,$terms,$gross,$signedChanges,$facts);
            if ($problem !== null) {
                $plan['questions'][] = $problem;
                continue;
            }
            $row['amount'] = self::prorate($gross,$row['from'],$row['to']);
            $plan['rows'][] = $row+self::plannedDeadline($db,$id,$basis,$form,$row['initial'] ? 'rent' : 'next',$signature,$transfer,$issued);
        }
        if ($condition['multiple'] > 0) {
            $security = bcmul($gross,(string)$condition['multiple'],2);
            $plan['rows'][] = ['kind'=>'Обеспечительный платёж','period'=>'Обеспечение','from'=>$start,'to'=>$end,
                'amount'=>$security,'initial'=>1]+self::plannedDeadline($db,$id,$basis,$form,'security',$signature,$transfer);
        }
        $plan['rows'] = InitialBilling::planned($db,$contract,$plan['rows']);
        $plan['token'] = self::hash([$basis,$plan]);
        return $plan;
    }

    public static function ruleDeadline(string $form,string $part,?string $signature,?string $transfer): array
    {
        if ($part === 'next') {
            return ['question'=>'Сверьте срок аванса за полный следующий месяц по этому договору.'];
        }
        if ($part === 'security' && in_array($form,['D17','D03'],true)) {
            $result = $signature ? PaymentDayCalendar::after($signature,2,'working',true)
                : ['question'=>'Нужна фактическая подпись договора.'];
            return ['rule'=>'Обеспечение: 2 рабочих дня от подписания договора.']+$result;
        }
        $label = $part === 'security' ? 'Обеспечение одновременно с первым платежом' : 'Аренда';
        return ['rule'=>$label.': 5 банковских дней от '.($form === 'D09' ? 'подписания акта передачи.' : 'подписания договора.')]
            + LeasePaymentTerms::firstDue($form,$signature,$transfer);
    }

    private static function plannedDeadline(PDO $db,int $id,string $basis,string $form,string $part,?string $signature,?string $transfer,?string $issued = null): array
    {
        $individual = self::read($db,$id,'due-'.$part);
        if (($individual['basis'] ?? null) === $basis) {
            return ['due'=>$individual['date'],'due_basis'=>self::DUE_PARTS[$part].'. Индивидуальное основание: '.$individual['reason'],
                'due_part'=>$part];
        }
        $result = $issued && $part!=='security' ? self::issueBasedRentDeadline($issued)
            : self::ruleDeadline($form,$part,$signature,$transfer);
        return ['due'=>$result['date'] ?? null,
            'due_basis'=>trim(($result['rule'] ?? '').' '.($result['question'] ?? $result['source'] ?? '')),
            'due_part'=>$part];
    }

    private static function issueBasedRentDeadline(string $issued): array
    {
        return ['date'=>InvoiceDueDate::firstRent($issued),'rule'=>'Аренда при заключении: два календарных дня после выставления счёта.'];
    }

    public static function issuedDeadline(PDO $db,array $contract,array $proof,string $issued): array
    {
        $id = (int)$contract['id'];
        $facts = LeasePaymentTerms::facts($db,$id);
        if (!isset(LeasePaymentTerms::allowedForms($db,$contract)[$proof['form']])
            || (isset($facts['base']['form']) && $facts['base']['form'] !== $proof['form'])) {
            return ['question'=>'Сверьте подтверждённую форму этого договора.'];
        }
        if ($proof['due_part']!=='security') return self::issueBasedRentDeadline($issued);
        $signature = !empty($contract['signed']) ? ($facts['signature']['date'] ?? null) : null;
        $transfer = !empty($contract['signed']) ? LeasePaymentTerms::actDate(PremisesActs::history($db,$id),'transfer') : null;
        return self::ruleDeadline($proof['form'],$proof['due_part'],$signature,$transfer);
    }

    private static function periodQuestion(PDO $db,int $id,array $row,array $terms,string $gross,array $changes,array $facts): ?string
    {
        foreach ($changes as $document) {
            if ($document['effective_date'] !== null && $document['effective_date'] <= $row['to']) {
                return 'Сверьте ставку по подписанному ДС № '.$document['number'].' за '.$row['period'].'.';
            }
        }
        foreach ($facts as $key=>$fact) {
            if (str_starts_with($key,'rate:') && $fact['rate_from'] <= $row['to']) {
                return 'Сверьте порядок начисления после смены ставки за '.$row['period'].'.';
            }
        }
        $active = [];
        foreach ($terms as $term) {
            if (($term['end_date'] !== null && $term['end_date'] < $row['from'])
                || ($term['superseded_on'] !== null && $term['superseded_on'] < $row['from'])
                || ($term['start_date'] !== null && $term['start_date'] > $row['to'])
                || ($term['effective_date'] !== null && $term['effective_date'] > $row['to'])) continue;
            if ($term['start_date'] === null || $term['end_date'] === null || $term['amount'] === null
                || $term['start_date'] > $row['from'] || $term['end_date'] < $row['to']
                || ($term['effective_date'] !== null && $term['effective_date'] > $row['from'])
                || ($term['superseded_on'] !== null && $term['superseded_on'] < $row['to'])
                || isset($active[$term['target_key']])) {
                return 'Сверьте смену ставки или неполное покрытие предметов аренды за '.$row['period'].'.';
            }
            $active[$term['target_key']] = $term['amount'];
        }
        $total = '0';
        foreach ($active as $amount) $total = bcadd($total,$amount,12);
        if (!$active || bccomp($total,$gross,12)!==0) return 'Сверьте ставку по предметам аренды за '.$row['period'].'.';
        $q = $db->prepare("SELECT 1 FROM invoices WHERE contract_id=? AND period=? AND status<>'revoked'
            AND kind IN ('Первый период','Аренда') LIMIT 1");
        $q->execute([$id,$row['period']]);
        return $q->fetchColumn() ? 'За '.$row['period'].' уже выставлен счёт за аренду. Сверьте покрытие.' : null;
    }

    public static function execute(PDO $db,array $actor,string $action,array $contract,array $input): array
    {
        if (!self::can($contract,$action)) throw new RuntimeException('Действие недоступно для этой роли или этапа.',403);
        $id = (int)$contract['id'];
        if ($action === self::ISSUE) {
            $issued = Service::date((string)($input['issued'] ?? ''));
            if ($error = self::dateError($contract,$issued)) throw new RuntimeException($error,422);
            if ($receipt = self::receipt($db,$id)) {
                return ['id'=>$id,'invoice_ids'=>$receipt['invoice_ids'],'message'=>'Первоначальные счета уже зарегистрированы.'];
            }
            if (self::issuedFor($db,$id)) throw new RuntimeException('Первоначальные счета уже есть. Откройте сохранённые документы.',409);
            self::assertIssueParty($db,$contract,$actor['role']);
            $plan = self::preview($db,$contract,$issued);
            if (!$plan['rows']) throw new RuntimeException($plan['questions'][0] ?? 'Нет подтверждённых начислений.',422);
            if (!hash_equals($plan['token'],(string)($input['plan_token'] ?? '')))
                throw new RuntimeException('Расчёт изменился. Откройте предпросмотр заново.',409);
            if (($input['confirmed'] ?? null) !== '1') throw new RuntimeException('Подтвердите дату и состав начислений.',422);
            $physical = array_keys(array_filter(Occupancy::relations($db,$id),Occupancy::physical(...)));
            Service::available($db,$physical,$plan['from'],$contract['end_date'],$id,(int)$contract['party_id'],false,null,true);
            $created = date(DATE_ATOM);
            $ids = InitialBilling::write($db,$contract,$plan['rows'],$plan['issued'],
                ['policy'=>self::POLICY,'confirmed_at'=>$created,'signed'=>$plan['signed'],'lease_start'=>$plan['from'],'form'=>$plan['condition']['form'],
                    'plan_sha256'=>$plan['token'],'command_key'=>$input['command_key'],'actor_id'=>(int)$actor['id']]);
            self::remember($db,$id,'issue',['invoice_ids'=>$ids,'issued'=>$plan['issued'],'created_at'=>$created,
                'from'=>$plan['from'],'signed'=>$plan['signed'],'questions'=>$plan['questions'],'condition'=>$plan['condition'],
                'command_key'=>$input['command_key'],'plan_sha256'=>$plan['token']]);
            Service::event($db,$actor,$action,'Отдельным действием зарегистрированы первоначальные счета.', $id,
                ['invoice_ids'=>$ids,'plan_sha256'=>$plan['token']]);
            return ['id'=>$id,'invoice_ids'=>$ids,'message'=>'Первоначальные счета зарегистрированы.'];
        }

        if (self::issuedFor($db,$id) || self::receipt($db,$id))
            throw new RuntimeException('Первоначальные счета уже есть. Срок оплаты уточняется в карточке соответствующего счёта.',409);
        $basis = self::basis($contract,InitialBilling::orderedTerms(BillableTerms::forContract($db,$id)));
        $reason = DirectoryDetails::text($input,'reason',600);
        if ($reason === '' || ($input['confirmed'] ?? '') !== '1') throw new RuntimeException('Подтвердите конкретное основание по документу.',422);
        $fact = ['basis'=>$basis,'reason'=>$reason,'actor_id'=>(int)$actor['id'],'command_key'=>$input['command_key']];
        if ($action === self::TERMS) {
            $form = (string)($input['lease_form'] ?? '');
            $multiple = filter_var($input['multiple'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if (!isset(LeasePaymentTerms::allowedForms($db,$contract)[$form]) || $multiple === false)
                throw new RuntimeException('Укажите подходящую форму договора и согласованную кратность обеспечения.',422);
            if (bccomp(bcmul($contract['amount'] ?? '0',(string)$multiple,2),'999999999999999999.99',2)>0)
                throw new RuntimeException('Сумма обеспечения превышает допустимую сумму счёта.',422);
            $kind = 'terms';
            $fact += ['form'=>$form,'multiple'=>$multiple];
            $message = 'Условия первого начисления подтверждены.';
        } else {
            $part = (string)($input['due_part'] ?? '');
            if (!isset(self::DUE_PARTS[$part])) throw new RuntimeException('Укажите, к какому начислению относится срок.',422);
            $kind = 'due-'.$part;
            $fact['date'] = Service::date((string)($input['due'] ?? ''));
            $message = 'Подтверждён индивидуальный срок оплаты.';
        }
        self::remember($db,$id,$kind,$fact);
        Service::event($db,$actor,$action,$message,$id,['basis'=>$basis,'command_key'=>$input['command_key']]);
        return ['id'=>$id,'message'=>$message];
    }
}
