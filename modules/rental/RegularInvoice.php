<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, BusinessIds};
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RegularInvoice
{
    public const CREATE = 'invoice.create-period';
    public const ACTIONS = [self::CREATE,InvoiceDeliveryPreference::SAVE];
    private const NS = 'rental_single_period_invoice';

    public static function requireAccess(int $level): void
    {
        Service::roles(['accountant']);
        Auth::requirePermission('finance.write');
        Access::finance($level);
    }

    public static function contract(int $id): array
    {
        $contract = Access::contract($id);
        if (!in_array($contract['status'],['active','reconciling'],true))
            throw new RuntimeException('Регулярный счёт доступен по действующей аренде или договору на финальной сверке.',409);
        return $contract;
    }

    public static function period(string $value): string
    {
        return substr(Service::date($value.'-01'),0,7);
    }

    private static function party(PDO $db,int $id): array
    {
        $query = $db->prepare('SELECT * FROM parties WHERE id=?');
        $query->execute([$id]);
        return $query->fetch() ?: throw new RuntimeException('Контрагент не найден.',404);
    }

    private static function result(PDO $db,int $id,string $period): ?array
    {
        $query = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute([self::NS,$id.':'.$period]);
        $data = $query->fetchColumn();
        return $data === false ? null : Importer::decodeExact($data);
    }

    public static function existing(PDO $db,int $id,string $period): array
    {
        $q = $db->prepare("SELECT id,number,kind,amount,issued,due FROM invoices WHERE contract_id=? AND
            ((period=? AND status<>'revoked' AND kind IN ('Аренда','Первый период')) OR id=?) ORDER BY id");
        $q->execute([$id,$period,self::result($db,$id,$period)['invoice_id'] ?? 0]);
        return $q->fetchAll();
    }

    public static function paymentChoices(PDO $db,array $contract): array
    {
        $query = $db->prepare('SELECT p.id,p.amount,p.payment_date FROM payments p WHERE p.party_id=? AND p.payment_date<=CURRENT_DATE
            AND NOT EXISTS (SELECT 1 FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id
                WHERE a.payment_id=p.id AND i.contract_id<>?) ORDER BY p.payment_date DESC,p.id DESC LIMIT 30');
        $query->execute([$contract['party_id'],$contract['id']]);
        return $query->fetchAll();
    }

    private static function reference(PDO $db,array $contract,int $paymentId,string $issued): array
    {
        $query = $db->prepare('SELECT id,amount,payment_date,version FROM payments WHERE id=? AND party_id=? AND payment_date<=CURRENT_DATE');
        $query->execute([$paymentId,$contract['party_id']]);
        $payment = $query->fetch();
        if (!$payment || $issued >= $payment['payment_date'])
            throw new RuntimeException('Нужен ранее полученный приход этого клиента и дата счёта до даты оплаты.',422);
        $query = $db->prepare('SELECT a.invoice_id,a.amount,i.contract_id FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id
            WHERE a.payment_id=? ORDER BY a.invoice_id');
        $query->execute([$paymentId]);
        foreach ($query->fetchAll() as $row) {
            if ((int)$row['contract_id'] !== (int)$contract['id'])
                throw new RuntimeException('Приход относится к другому договору.',422);
        }
        return ['id'=>(int)$payment['id'],'amount'=>$payment['amount'],'date'=>$payment['payment_date'],'version'=>(int)$payment['version']];
    }

    public static function due(string $form,string $period,array $calculation): array
    {
        if (isset(LeasePaymentTerms::FORMS[$form])) {
            return ['date'=>InvoiceDueDate::monthlyRent($period)];
        }
        return ['question'=>'Уточните договорный срок оплаты аренды.'];
    }

    public static function preview(PDO $db,array $contract,string $period,string $issued,int $paymentId,bool $batch = false,?string $quarterDue = null): array
    {
        $id = (int)$contract['id'];
        $period = self::period($period);
        $issued = Service::date($issued);
        $party = self::party($db,(int)$contract['party_id']);
        $quote = ['period'=>$period,'issued'=>$issued,'delivery'=>InvoiceDeliveryPreference::read($db,$party),'questions'=>[]];
        $q = $db->prepare("SELECT id,number,amount,issued,due FROM invoices WHERE contract_id=? AND period=?
            AND status<>'revoked' AND kind IN ('Аренда','Первый период') ORDER BY id");
        $q->execute([$id,$period]);
        $quote['existing'] = $q->fetchAll();
        if ($receipt = self::result($db,$id,$period)) $quote['previously_issued'] = $receipt['invoice_id'];
        if ($quote['existing'] || isset($quote['previously_issued'])) return $quote;
        if (!$batch) {
            try {
                InvoiceCycle::assertIndividual($db,$id,$period);
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException || $error->getCode() !== 409) throw $error;
                $quote['questions'][] = $error->getMessage();
                $quote['cycle_only'] = true;
                return $quote;
            }
        }
        if ($question = InitialInvoice::dateError($contract,$issued)) array_push($quote['questions'],$question);
        if ($paymentId) {
            try {
                $quote['payment'] = self::reference($db,$contract,$paymentId,$issued);
            } catch (RuntimeException $error) {
                if ($error->getCode() !== 422) throw $error;
                $quote['questions'][] = $error->getMessage();
            }
        }
        $facts = LeasePaymentTerms::facts($db,$id);
        $form = $facts['base']['form'] ?? '';
        $transfer = LeasePaymentTerms::actDate(PremisesActs::history($db,$id),'transfer');
        $accrual = $facts['accrual']['date'] ?? ($form === 'D09' ? $transfer : null);
        if (($accrual && $period <= substr($accrual,0,7))
            || ($contract['start_date'] && $period <= substr($contract['start_date'],0,7))) {
            $quote['questions'][] = 'Первый период оформляется из карточки договора действием «Выставить первый счёт».';
            $quote['initial_only'] = true;
            return $quote;
        }
        $objects = Occupancy::objects($db,$id);
        if (empty($contract['signed']) || !isset(LeasePaymentTerms::allowedForms($db,$contract)[$form])) {
            $quote['questions'][] = 'Подтвердите подписанный договор, ставку и договорный срок оплаты.';
            return $quote;
        }
        $terms = LeasePaymentTerms::period($contract,$facts,$period,$transfer,LeasePaymentTerms::documents($db,$id,'renewal'));
        if (isset($terms['question']) || isset($terms['payment_question'])) {
            $quote['questions'][] = $terms['question'] ?? $terms['payment_question'];
            return $quote;
        }
        $segment = $terms['segments'][0];
        $source = $segment['source'];
        if (!self::signedEvidence($db,$id,$source)) {
            $quote['questions'][] = 'Сверьте файл подписанного договора или ДС, на котором основана ставка.';
            return $quote;
        }
        $quote['source'] = ['label'=>isset($source['number']) ? 'Подписанное ДС № '.$source['number'] : 'Подписанный договор № '.$contract['number'],
            'file_id'=>$source['file_id'],'from'=>$segment['from'],'to'=>$segment['to'],
            'rate'=>$segment['monthly'],'net_rate'=>isset($source['net'])];
        $quote['amount'] = $terms['gross'];
        $quote['purpose'] = $terms['purpose'] ?? 'Арендная плата за '.$period.' по договору № '.$contract['number']
            .' от '.Documents::dateText($contract['document_date']).(isset($source['number']) ? ', ДС № '.$source['number'] : '').'.';
        if ($terms['vat'] !== null && !isset($terms['purpose'])) {
            $quote['purpose'] .= ' В том числе НДС '.$source['vat_rate'].' % — '.Documents::amountText($terms['vat']).' ₽.';
        }
        if ($form === 'D09' && ($facts['decision']['decision'] ?? '') !== 'invoice') {
            $quote['questions'][] = 'Уточните необходимость счёта по парковочному договору с самостоятельной оплатой.';
        }
        $deadline = $batch && $quarterDue !== null
            ? ['date'=>Service::date($quarterDue)]
            : self::due($form,$period,$terms);
        $quote['due'] = $deadline['date'] ?? null;
        if (!$quote['due']) $quote['questions'][] = $deadline['question'] ?? 'Подтвердите срок платежа.';
        $quote['number'] = 'Д-'.BusinessIds::next($db,'invoices',900000);
        try {
            $billing = MonthlyBilling::plan($db,$contract,$period);
        } catch (RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            $quote['questions'][] = match ($error->getMessage()) {
                'IMPORTED_COVERAGE_REVIEW_REQUIRED','INVOICE_SCOPE_UNKNOWN'=>'Сверьте покрытие ранее выставленных счетов по договору.',
                default=>$error->getMessage(),
            };
            return $quote;
        }
        $expectedObjects = array_map('intval',array_keys($objects));
        $plannedObjects = array_map('intval',$billing['objects']);
        sort($expectedObjects,SORT_NUMERIC);
        sort($plannedObjects,SORT_NUMERIC);
        $wholeCoverage = $billing['coverage'] && !array_filter($billing['coverage'],static fn(array $line): bool =>
            $line['start_date'] !== $segment['from'] || $line['end_date'] !== $segment['to']);
        // The gross signed rate applies to the entire object set. Never overwrite a partial or unknown billing plan.
        $needsRateAgreement = !isset($source['net']) || isset($billing['invoice_targets']);
        if ($plannedObjects !== $expectedObjects || !$wholeCoverage
            || ($needsRateAgreement && bccomp($billing['amount'],$quote['amount'],2)!==0)
            || self::overlaps($db,$contract,$billing['coverage'])) {
            $quote['questions'][] = 'Сверьте предмет начисления, сумму и покрытие уже выставленных счетов за этот период.';
        }
        if (bccomp($quote['amount'],'0',2)<=0) $quote['questions'][] = 'Сумма счёта должна быть положительной.';
        $billing['amount'] = $quote['amount'];
        $quote['vat_rate'] = $source['vat_rate'] ?? null;
        if ($batch) $quote['vat_amount'] = $terms['vat'];
        if (!$quote['questions']) {
            $quote['billing'] = $billing;
            $category = ['D17'=>'office','D03'=>'apartment','D09'=>'parking'][$form];
            $quote['report_charges'] = [['period'=>$period,'category'=>$category,'gross'=>$quote['amount'],'tax'=>$terms['vat']]];
            $quote['token'] = hash('sha256',Importer::canonicalJson([$contract['version'],$quote]));
        }
        return $quote;
    }

    private static function signedEvidence(PDO $db,int $contractId,array $source): bool
    {
        $documentId = $source['document_id'] ?? null;
        $query = $db->prepare('SELECT * FROM rental_files WHERE id=? AND entity_type=? AND entity_id=? AND kind=?');
        $query->execute([$source['file_id'] ?? '',$documentId ? 'document' : 'contract',$documentId ?? $contractId,
            $documentId ? 'signed-addendum' : 'signed-contract']);
        $file = $query->fetch();
        if (!$file || !hash_equals((string)($source['file_sha256'] ?? ''),$file['sha256'])) return false;
        try {
            Evidence::integrity($file);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private static function overlaps(PDO $db,array $contract,array $proposed): bool
    {
        $query = $db->prepare("SELECT * FROM invoices WHERE contract_id=? AND status<>'revoked' AND kind<>'Обеспечительный платёж'");
        $query->execute([$contract['id']]);
        foreach ($query->fetchAll() as $invoice) {
            foreach (MonthlyBilling::coverage($db,$contract,$invoice) as $old) {
                foreach ($proposed as $line) {
                    if ((int)$old['object_id'] === (int)$line['object_id']
                        && $old['start_date'] <= $line['end_date'] && $old['end_date'] >= $line['start_date']) return true;
                }
            }
        }
        return false;
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        self::requireAccess(2);
        $id = (int)($input['contract_id'] ?? 0);
        self::contract($id);
        $contract = Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        if ($action === InvoiceDeliveryPreference::SAVE) {
            return InvoiceDeliveryPreference::save($db,$actor,$contract,$input);
        }
        $period = self::period((string)($input['period'] ?? ''));
        $issued = Service::date((string)($input['issued'] ?? ''));
        if ($error = InitialInvoice::dateError($contract,$issued)) throw new RuntimeException($error,422);
        if ($old = self::result($db,$id,$period)) return ['invoice_id'=>$old['invoice_id'],'message'=>'Счёт за этот период уже сохранён.'];
        $quote = self::preview($db,$contract,$period,$issued,(int)($input['payment_id'] ?? 0));
        if ($quote['existing']) throw new RuntimeException('За этот период уже выставлен счёт. Откройте его из предпросмотра.',409);
        if (empty($quote['token'])) throw new RuntimeException($quote['questions'][0] ?? 'Счёт ждёт сверки.',422);
        if (!hash_equals($quote['token'],(string)($input['plan_token'] ?? '')))
            throw new RuntimeException('Основания или номер счёта изменились. Обновите предпросмотр.',409);
        if (($input['confirmed'] ?? '') !== '1') throw new RuntimeException('Подтвердите реквизиты счёта.',422);
        $payment = $quote['payment'] ?? null;
        if ($payment) {
            $reason = DirectoryDetails::text($input,'payment_reason',400);
            if ($reason === '' || ($input['earlier_payment_confirmed'] ?? '') !== '1')
                throw new RuntimeException('Подтвердите основание для даты счёта до полученной оплаты.',422);
            $payment['reason'] = $reason;
        }
        $invoice = self::issue($db,$actor,$contract,$quote,[$period],$input['command_key'],$payment);
        return ['invoice_id'=>$invoice,'message'=>'Счёт сохранён. Отправка не подтверждена.'];
    }

    public static function issue(PDO $db,array $actor,array $contract,array $quote,array $periods,string $commandKey,?array $payment = null): int
    {
        $id = (int)$contract['id'];
        $source = ['created_at'=>date(DATE_ATOM),'document_date'=>$quote['issued'],'rate'=>$quote['source'],
            'payment'=>$payment,'delivery'=>$quote['delivery'],'actor_id'=>(int)$actor['id'],'command_key'=>$commandKey];
        if (isset($quote['preparation'])) $source['preparation'] = $quote['preparation'];
        if (isset($quote['cycle'])) $source['cycle'] = $quote['cycle'];
        if (isset($quote['rates'])) $source['rates'] = $quote['rates'];
        $invoice = MonthlyBilling::issue($db,$contract,$quote['billing'],$quote['issued'],
            ['due'=>$quote['due'],'purpose'=>$quote['purpose'],'vat_rate'=>$quote['vat_rate'],'source'=>$source,
                'report_charges'=>$quote['report_charges']]);
        if (!$invoice) throw new RuntimeException('Покрытие изменилось. Откройте предпросмотр заново.',409);
        $record = $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)');
        foreach ($periods as $period) {
            $record->execute([self::NS,$id.':'.$period,Importer::json(['invoice_id'=>$invoice,'command_key'=>$commandKey])]);
        }
        Service::event($db,$actor,self::CREATE,'Сохранён счёт за '.implode(', ',$periods).'.',$id,['invoice_id'=>$invoice]);
        return $invoice;
    }
}
