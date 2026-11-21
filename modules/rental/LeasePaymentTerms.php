<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\Auth;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class LeasePaymentTerms
{
    public const SAVE = 'contract.payment-fact';
    public const FORMS = ['D09'=>'Машино-место','D17'=>'Офис','D03'=>'Апартаменты'];
    private const NS = 'rental_payment_terms';

    public static function canEdit(array $contract, string $kind): bool
    {
        $user = Auth::user();
        $roles = $kind === 'signature' ? ['manager','admin'] : ['accountant','admin'];
        $grant = $kind === 'signature' ? 'rental.write' : 'finance.write';
        if (!in_array($user['role'] ?? '', $roles, true) || !Auth::can($grant)
            || !in_array($contract['status'], ['approved','sent','active','reconciling','closed','ended'], true)) return false;
        try {
            Access::contract((int)$contract['id'], 2);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 403) return false;
            throw $exception;
        }
        return true;
    }

    public static function facts(PDO $db, int $id): array
    {
        $query = $db->prepare('SELECT source_key, fact_json FROM rental_facts WHERE namespace=? AND source_key LIKE ? AND operative=1');
        $query->execute([self::NS, "$id:%"]);
        $facts = [];
        foreach ($query as $row) {
            $facts[substr($row['source_key'], strlen((string)$id)+1)] = Importer::decodeExact($row['fact_json']);
        }
        return $facts;
    }

    private static function save(PDO $db, array $actor, int $id, string $key, array $fact, array $known): array
    {
        if (isset($known[$key])) {
            if (Importer::canonicalJson($known[$key]) !== Importer::canonicalJson($fact))
                throw new RuntimeException('Факт уже подтверждён. Для изменения нужно сверить исходное основание.', 409);
            return ['id'=>$id,'message'=>'Этот факт уже сохранён.'];
        }
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::NS,"$id:$key",Importer::json($fact)]);
        Service::event($db,$actor,self::SAVE,'Подтверждены условия оплаты: '.self::label($key),$id,
            ['fact'=>$key,'actor_id'=>(int)$actor['id']]);
        return ['id'=>$id,'message'=>'Факт сохранён.'];
    }

    private static function label(string $key): string
    {
        return match (explode(':', $key)[0]) {
            'signature','signature-revision'=>'подписание договора','base'=>'условия договора','rate'=>'ставка по подписанному ДС',
            'prior-rate'=>'прежняя ставка по подписанному ДС',
            'accrual'=>'начало начисления','exception'=>'порядок выставления счёта','decision'=>'решение по счёту',
            'return-rule'=>'срок возврата обеспечения','advance'=>'аванс по договору',
        };
    }

    private static function actualDate(string $value): string
    {
        $date = Service::date($value);
        if ($date > date('Y-m-d')) throw new RuntimeException('Фактическая дата не может быть в будущем.',422);
        return $date;
    }

    public static function contractSigned(PDO $db, array $actor, array $contract, array $file, string $date): void
    {
        $id = (int)$contract['id'];
        $known = self::facts($db,$id);
        if (isset($known['signature']) && $known['signature']['file_id'] !== $file['id']) {
            // An already recorded initial signature remains the first-payment anchor.
            self::save($db,$actor,$id,'signature-revision:'.$file['id'],self::proof($file)+['date'=>self::actualDate($date)],$known);
            return;
        }
        self::save($db,$actor,$id,'signature',self::proof($file)+['date'=>self::actualDate($date)],$known);
    }

    private static function proof(array $file): array
    {
        return ['file_id'=>$file['id'],'file_sha256'=>$file['sha256'],'file_version'=>(int)$file['version']];
    }

    public static function contractFiles(PDO $db, array $contract): array
    {
        if (empty($contract['signed'])) return [];
        $query = $db->prepare("SELECT * FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind='signed-contract' AND version<? ORDER BY upload_order DESC");
        $query->execute([$contract['id'],$contract['version']]);
        return $query->fetchAll();
    }

    public static function documents(PDO $db, int $id, string $kind): array
    {
        $query = $db->prepare("SELECT * FROM rental_documents WHERE contract_id=? AND kind=? AND status IN ('signed','applied') ORDER BY effective_date,id");
        $query->execute([$id,$kind]);
        $documents = [];
        foreach ($query as $row) {
            if (!WorkspaceReset::hidden($row)) $documents[(int)$row['id']] = $row;
        }
        return $documents;
    }

    private static function signedContractFile(PDO $db, array $contract, string $id): array
    {
        foreach (self::contractFiles($db,$contract) as $file) {
            if ($file['id'] !== $id) continue;
            Evidence::integrity($file);
            return $file;
        }
        throw new RuntimeException('Выберите подписанный файл этого договора.',422);
    }

    private static function signedAddendum(PDO $db, int $id, int $documentId, string $kind): array
    {
        $document = self::documents($db,$id,$kind)[$documentId] ?? null;
        if (!$document) throw new RuntimeException('Нужно подписанное ДС этого договора.',422);
        Access::document($documentId);
        try {
            $file = Evidence::latest($db,'document',$documentId,'signed-addendum');
        } catch (RuntimeException) {
            throw new RuntimeException('Нужен доступный подписанный файл этого ДС.',422);
        }
        if ((int)$file['version'] >= (int)$document['version'])
            throw new RuntimeException('Подписание выбранного файла ещё не подтверждено.',422);
        return [$document,$file];
    }

    public static function allowedForms(PDO $db, array $contract): array
    {
        $objects = Occupancy::objects($db,(int)$contract['id']);
        $forms = [];
        foreach ($objects as $object) {
            $forms[] = match (true) {
                PremisesActs::parking($object) && count($objects) === 1 => 'D09',
                (bool)preg_match('/^(?:апартамент|apartment)/iu',$object['type']) => 'D03',
                (bool)preg_match('/^(?:офис|office)/iu',$object['type']) => 'D17',
                default => null,
            };
        }
        $forms = array_unique($forms);
        return count($forms) === 1 && isset($forms[0]) ? [$forms[0]=>self::FORMS[$forms[0]]] : [];
    }

    public static function execute(PDO $db, array $actor, array $contract, array $input): array
    {
        $id = (int)$contract['id'];
        $kind = (string)($input['fact'] ?? '');
        if (!self::canEdit($contract,$kind)) throw new RuntimeException('Действие недоступно для этой роли или этапа.',403);
        $known = self::facts($db,$id);
        $key = $kind;
        $form = (string)($input['lease_form'] ?? '');
        $date = (string)($input['fact_date'] ?? '');
        $fileId = (string)($input['file_id'] ?? '');
        $documentId = (int)($input['document_id'] ?? 0);
        $reason = DirectoryDetails::text($input,'reason',600);
        $parking = TemplateAssets::contractKind($db,$contract) === 'parking';
        if (($input['verified'] ?? '') !== '1') throw new RuntimeException('Подтвердите сверку с основанием.',422);
        switch ($kind) {
            case 'signature':
                if (isset($known['base']['file_id']) && $known['base']['file_id'] !== $fileId)
                    throw new RuntimeException('Выберите файл, по которому уже подтверждены условия договора.',422);
                $fact = self::proof(self::signedContractFile($db,$contract,$fileId))+['date'=>self::actualDate($date)];
                break;
            case 'base':
                if (!isset(self::allowedForms($db,$contract)[$form]))
                    throw new RuntimeException('Форма не соответствует виду договора.',422);
                if (isset($known['signature']['file_id']) && $known['signature']['file_id'] !== $fileId)
                    throw new RuntimeException('Выберите файл, по которому уже подтверждена подпись.',422);
                $gross = Service::money($input['amount'] ?? '');
                if (bccomp($gross,'0',2) <= 0) throw new RuntimeException('Укажите положительную месячную ставку.',422);
                $fact = self::proof(self::signedContractFile($db,$contract,$fileId))+['form'=>$form,'gross'=>$gross];
                break;
            case 'accrual':
                $file = self::signedContractFile($db,$contract,$fileId);
                if ($reason === '') throw new RuntimeException('Укажите пункт, по которому подтверждено начало начисления.',422);
                $fact = self::proof($file)+['date'=>Service::date($date),'reason'=>$reason];
                break;
            case 'prior-rate':
            case 'rate':
                if (!$parking || ($known['base']['form'] ?? '') !== 'D09')
                    throw new RuntimeException('Условия нового парковочного ДС требуют подтверждённого договора D09.',422);
                [$document,$file] = self::signedAddendum($db,$id,$documentId,'renewal');
                $signed = self::actualDate($date);
                $effective = Service::date((string)($input['rate_from'] ?? ''));
                $amount = Service::money($input['amount'] ?? '');
                if ($effective < $signed || $effective !== $document['effective_date'] || bccomp($amount,'0',2) <= 0)
                    throw new RuntimeException('Дата начала ставки должна совпадать с датой применения условий ДС и следовать за подписью или совпадать с ней.',422);
                $new = $kind === 'rate';
                $gross = $new ? bcadd($amount,Billing::round(bcmul($amount,'0.05',12)),2) : $amount;
                if ($document['amount'] === null || bccomp($gross,$document['amount'],2) !== 0)
                    throw new RuntimeException('Итоговая ставка должна совпадать с общей суммой подписанного ДС.',422);
                foreach ($known as $savedKey=>$saved) {
                    if ($savedKey !== "rate:$documentId" && str_starts_with($savedKey,'rate:') && $saved['rate_from'] === $effective)
                        throw new RuntimeException('На эту дату уже подтверждена ставка другого соглашения.',409);
                }
                $fact = self::proof($file)+['document_id'=>$documentId,'number'=>$document['number'],
                    'signed'=>$signed,'rate_from'=>$effective]
                    + ($new ? ['net'=>$amount,'vat_rate'=>'5'] : ['gross'=>$gross]);
                $key = "rate:$documentId";
                break;
            case 'return-rule':
                [$document,$file] = self::signedAddendum($db,$id,$documentId,'termination');
                $fact = self::proof($file)+['document_id'=>$documentId,'number'=>$document['number'],'signed'=>self::actualDate($date)];
                break;
            case 'exception':
                if (!$parking || $reason === '') throw new RuntimeException('Укажите вопрос о счёте по договору машино-места.',422);
                $fact = ['reason'=>$reason];
                break;
            case 'decision':
                if (!$parking || !isset($known['exception']) || $reason === '')
                    throw new RuntimeException('Нужны открытый вопрос и основание решения по этому договору.',422);
                $decision = (string)($input['decision'] ?? '');
                if (!in_array($decision,['self','invoice'],true)) throw new RuntimeException('Уточните порядок оплаты.',422);
                $fact = ['reason'=>$reason,'decision'=>$decision];
                break;
            case 'advance':
                $paymentId = (int)($input['payment_id'] ?? 0);
                $payment = Service::get($db,'payments',$paymentId);
                $query = $db->prepare('SELECT DISTINCT i.contract_id FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id WHERE a.payment_id=?');
                $query->execute([$paymentId]);
                $contracts = array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
                if ((int)$payment['party_id'] !== (int)$contract['party_id'] || !$payment['payment_date']
                    || $payment['payment_date'] > date('Y-m-d') || $reason === ''
                    || array_diff($contracts,[$id])) throw new RuntimeException('Приход и его назначение должны относиться к этому договору.',422);
                $key = "advance:$paymentId";
                $used = $db->prepare('SELECT 1 FROM rental_facts WHERE namespace=? AND source_key LIKE ? AND source_key<>? AND operative=1');
                $used->execute([self::NS,'%:'.$key,"$id:$key"]);
                if ($used->fetchColumn()) throw new RuntimeException('Этот аванс уже связан с другим договором.',409);
                $fact = ['payment_id'=>$paymentId,'date'=>$payment['payment_date'],'amount'=>$payment['amount'],'reason'=>$reason];
                break;
            default:
                throw new RuntimeException('Неизвестный факт оплаты.',422);
        }
        return self::save($db,$actor,$id,$key,$fact,$known);
    }

    public static function advances(PDO $db, array $contract, array $facts, ?string $transfer): array
    {
        $query = $db->prepare("SELECT p.id, p.payment_date AS date, SUM(a.amount) AS amount
            FROM payments p JOIN payment_allocations a ON a.payment_id=p.id JOIN invoices i ON i.id=a.invoice_id
            WHERE i.contract_id=? AND p.payment_date IS NOT NULL GROUP BY p.id,p.payment_date");
        $query->execute([$contract['id']]);
        $byId = array_column($query->fetchAll(),null,'id');
        foreach ($facts as $key=>$fact) {
            if (str_starts_with($key,'advance:') && !isset($byId[$fact['payment_id']])) $byId[$fact['payment_id']] = $fact;
        }
        return array_values(array_filter($byId,static fn(array $payment): bool => !$transfer || $payment['date'] < $transfer));
    }

    public static function actDate(array $acts, string $kind): ?string
    {
        $dates = array_values(array_unique(array_column(array_filter($acts,
            static fn(array $act): bool => $act['kind'] === $kind),'date')));
        return count($dates) === 1 ? $dates[0] : null;
    }

    public static function firstDue(string $form, ?string $signature, ?string $transfer): array
    {
        $parking = $form === 'D09';
        $origin = $parking ? 'Подписание акта передачи' : 'Подписание договора';
        $date = $parking ? $transfer : $signature;
        return ['origin'=>$origin,'trigger'=>$date]+($date
            ? PaymentDayCalendar::after($date,5,'banking')
            : ['question'=>$parking ? 'Нужна подтверждённая дата подписания акта передачи.' : 'Нужна фактическая дата подписания договора.']);
    }

    public static function period(array $contract, array $facts, string $month, ?string $transfer, array $signedChanges = []): array
    {
        $first = new DateTimeImmutable(Service::date($month.'-01'));
        $last = $first->modify('last day of this month')->format('Y-m-d');
        $monthStart = $first->format('Y-m-d');
        $accrual = $facts['accrual']['date'] ?? null;
        $base = $facts['base'] ?? null;
        $parking = ($base['form'] ?? '') === 'D09';
        $accrual ??= $parking ? $transfer : null; // D09 starts at the signed handover.
        $rates = array_values(array_filter($facts,static fn(string $key): bool => str_starts_with($key,'rate:'),ARRAY_FILTER_USE_KEY));
        usort($rates,static fn(array $a,array $b): int => $a['rate_from'] <=> $b['rate_from']);
        if (!$base || !$accrual || !$contract['end_date'])
            return ['issue_ready'=>false,'question'=>'Подтвердите условия договора, начало начисления и срок аренды.'];
        $from = max($monthStart,$accrual);
        $to = min($last,$contract['finish_date'] ?? $contract['end_date']);
        if ($from > $to) return ['issue_ready'=>false,'question'=>'В этом месяце нет подтверждённого периода начисления.'];
        $lastKnownRate = $accrual;
        foreach ($rates as $rate) {
            if ($rate['rate_from'] <= $from) $lastKnownRate = $rate['rate_from'];
        }
        foreach ($signedChanges as $document) {
            if (!isset($facts['rate:'.$document['id']]) && $document['effective_date'] !== null
                && $document['effective_date'] >= $lastKnownRate && $document['effective_date'] <= $to) {
                return ['issue_ready'=>false,'question'=>'Сверьте условия подписанного ДС № '.$document['number'].' для этого периода.'];
            }
        }
        $segments = [];
        $cursor = $from;
        $rate = $base;
        foreach ($rates as $next) {
            if ($next['rate_from'] > $to) break;
            if ($next['rate_from'] > $cursor) {
                $segments[] = self::segment($cursor,(new DateTimeImmutable($next['rate_from']))->modify('-1 day')->format('Y-m-d'),$rate,$first->format('t'));
                $cursor = $next['rate_from'];
            }
            $rate = $next;
        }
        $segments[] = self::segment($cursor,$to,$rate,$first->format('t'));
        $gross = '0.00';
        $vat = '0.00';
        $allVatKnown = true;
        foreach ($segments as $segment) {
            $gross = bcadd($gross,$segment['gross'],2);
            $allVatKnown = $allVatKnown && $segment['vat'] !== null;
            $vat = bcadd($vat,$segment['vat'] ?? '0.00',2);
        }
        $result = ['segments'=>$segments,'gross'=>$gross,'vat'=>$allVatKnown ? $vat : null,
            'accrual'=>$accrual,'issue_ready'=>false];
        if (count($segments) > 1) {
            $result['payment_question'] = 'Бухгалтеру нужно подтвердить по этому договору порядок разделения платежа между ставками.';
        }
        if ($parking && $transfer && substr($transfer,0,7) === $month) {
            $result['days'] = array_sum(array_column($segments,'days'));
            $result['due'] = self::firstDue('D09',null,$transfer);
        } elseif ($parking && $transfer && $monthStart > $transfer) {
            $unknownDue = array_filter($segments,static fn(array $segment): bool =>
                isset($segment['source']['document_id']) && !isset($segment['source']['vat_rate']));
            $result['due'] = $unknownDue
                ? ['question'=>'Сверьте срок платежа по прежнему подписанному ДС.']
                : ['date'=>$first->modify('-1 month')->format('Y-m-25')];
        }
        if ($parking && $allVatKnown && !isset($result['payment_question'])
            && (!isset($facts['exception']) || ($facts['decision']['decision'] ?? '') === 'self')) {
            $result['purpose'] = 'Арендная плата за '.$month.', согласно договору аренды машино-места № '
                .$contract['number'].' от '.Documents::dateText($contract['document_date']).', в т. ч. НДС 5 % — '.Documents::amountText($vat).' ₽.';
        }
        return $result;
    }

    private static function segment(string $from, string $to, array $rate, string $monthDays): array
    {
        $days = (string)((int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days+1);
        $netRate = $rate['net'] ?? null;
        $monthly = $netRate ?? $rate['gross'];
        $amount = bcdiv(bcmul($monthly,$days,12),$monthDays,12);
        $net = $netRate === null ? null : Billing::round($amount);
        $vat = $net === null ? null : Billing::round(bcdiv(bcmul($amount,$rate['vat_rate'],12),'100',12));
        $gross = $vat === null ? Billing::round($amount) : bcadd($net,$vat,2);
        return ['from'=>$from,'to'=>$to,'days'=>$days,'monthly'=>$monthly,'net'=>$net,'vat'=>$vat,'gross'=>$gross,'source'=>$rate];
    }
}
