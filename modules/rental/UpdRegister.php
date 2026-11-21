<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class UpdRegister
{
    public const SAVE='upd.prepare';
    public const DRAFT='rental_upd_draft';
    public const CREDIT='rental_upd_credit';
    private const SERVICE='rental_upd_service_coverage';
    public const TYPES=['rent'=>'Аренда','regular'=>'Регулярный клининг','extra'=>'Дополнительная уборка',
        'fob'=>'Брелок','pass'=>'Пропуск','address'=>'Юридический адрес','security'=>'Обеспечение'];

    public static function month(mixed $value): string
    {
        return InvoicePeriods::month($value) ?: throw new RuntimeException('Выберите месяц начислений.',422);
    }

    public static function invoices(PDO $db,string $month,int $id=0): array
    {
        $query=$db->prepare('SELECT i.*,c.party_id,c.number AS contract_number FROM invoices i
            JOIN contracts c ON c.id=i.contract_id WHERE (?=0 OR i.id=?) AND ('.InvoicePeriods::rentalPredicate().'
            OR i.period=? OR EXISTS (SELECT 1 FROM rental_facts f WHERE f.operative=1 AND f.namespace IN (?,?)
                AND f.source_key=CONCAT(i.id,\':\',?))) ORDER BY i.id DESC');
        $query->execute([$id,$id,...InvoicePeriods::rentalParameters($month),$month,UpdEvidence::BASIS,self::DRAFT,$month]);
        $rows=[];
        foreach ($query->fetchAll() as $row) {
            try { Access::contract((int)$row['contract_id']); }
            catch (RuntimeException $e) {
                if ($e instanceof \PDOException || !in_array($e->getCode(),[403,404],true)) throw $e;
                continue;
            }
            $rows[]=$row;
        }
        return $rows;
    }

    public static function row(PDO $db,array $invoice,string $month): array
    {
        $key=$invoice['id'].':'.$month;
        $base=['invoice'=>$invoice,'month'=>$month,'kind'=>null,'amount'=>null];
        $draft=UpdEvidence::fact($db,self::DRAFT,$key);
        if ($invoice['kind']==='Обеспечительный платёж')
            return array_replace($base,['kind'=>'security','amount'=>$invoice['amount'],'status'=>'Исключён',
                'question'=>'На обеспечительный платёж УПД не формируется.']);
        $basis=UpdEvidence::basis($db,$invoice,$month);
        $source=UpdEvidence::original($db,$invoice,$basis,$month);
        if (isset($source['original'])) return array_replace($base,$source,[
            'status'=>'Имеется УПД 1С','amount'=>UpdEvidence::money($source['original']['record']['amount']),
            'local_draft_exists'=>(bool)$draft,'reference'=>$basis['reference'] ?? 'Связь с сохранённым счётом 1С']);
        if ($draft) return array_replace($base,$draft,['status'=>'Проект сохранён','draft'=>true]+$source);
        if (isset($source['question'])) return $base+['status'=>'Нужна сверка','question'=>$source['question']];
        if ($invoice['status']!=='active') return $base+['status'=>'Нужна сверка','question'=>'Уточните, действует ли основание по этому счёту.'];

        $stored=Importer::decodeExact($invoice['source_json']);
        if (!$invoice['issued'] || !empty($stored['issueError']) || !empty($stored['mismatch']))
            return $base+['status'=>'Нужна сверка','question'=>'Сверьте дату и подтверждение выставления счёта.'];
        $chargeKey=$basis['service_charge'] ?? '';
        if ($chargeKey==='' && in_array($invoice['kind'],array_merge(['Клининг','Регулярный клининг'],array_values(RentalServices::ORDERS)),true))
            return $base+['status'=>'Нужна сверка','question'=>'Свяжите счёт с сохранённым начислением услуги по договору.'];
        $plan=$chargeKey!=='' ? self::service($db,$invoice,$month,$chargeKey) : self::rent($db,$invoice,$month);
        $considered=array_replace($base,$plan);
        if (isset($basis['reference'])) $considered['reference']=$basis['reference'];
        if (isset($plan['question'])) return $considered+['status'=>'Нужна сверка'];
        if (!isset($source['checked_source_invoice']) && empty($stored['regular_invoice']))
            return $considered+['status'=>'Нужна сверка','question'=>'Подтвердите источник счёта и покрытие существующих УПД 1С.'];
        $payer=Importer::decodeExact($invoice['payer_snapshot'] ?: '{}');
        $snapshotType=$payer['party_type'] ?? null;
        $confirmedType=$basis['party_type'] ?? null;
        if ($snapshotType && $confirmedType && $snapshotType!==$confirmedType)
            return $considered+['status'=>'Нужна сверка','question'=>'Тип плательщика расходится со снимком счёта.'];
        $type=$confirmedType ?: $snapshotType;
        $when=self::datePlan($db,$invoice,$month,$plan['kind'],$type);
        if (isset($when['question'])) return $considered+$when+['status'=>'Нужна сверка'];
        $advance=$plan['kind']==='rent' ? UpdEvidence::advance($db,$invoice,$month,$when['date'],$plan['amount']) : [];
        if (isset($advance['question'])) return $considered+$when+$advance+['status'=>'Нужна сверка'];
        $proof=[
            'month'=>$month,'contract_id'=>(int)$invoice['contract_id'],'contract_number'=>$invoice['contract_number'],
            'invoice_id'=>(int)$invoice['id'],'invoice_number'=>$invoice['number'],'invoice_version'=>(int)$invoice['version'],
            'invoice_issued'=>$invoice['issued'],'invoice_amount'=>$invoice['amount'],'invoice_paid'=>$invoice['paid'],'payer_type'=>$type,
            'reference'=>$basis['reference'] ?? 'Снимок выпуска счёта и сохранённое покрытие',
            'source_invoice_id'=>$source['checked_source_invoice'] ?? null,'realizations_checked'=>$source['realizations_checked'] ?? null,
            'balance'=>bcsub($plan['amount'],$advance['credit']['amount'] ?? '0',2),
        ]+$plan+$when+$advance;
        return array_replace($base,$proof,['status'=>'Готов к формированию','token'=>hash('sha256',Importer::canonicalJson($proof))]);
    }

    private static function service(PDO $db,array $invoice,string $month,string $chargeKey): array
    {
        $charge=RentalServices::charges($db,(int)$invoice['contract_id'])[$chargeKey] ?? [];
        $kind=$charge['kind'] ?? null;
        $amount=UpdEvidence::money($charge['amount'] ?? null);
        $result=['kind'=>$kind,'amount'=>$amount];
        if (!isset(self::TYPES[$kind]) || ($charge['period'] ?? null)!==$month || $amount===null || bccomp($amount,'0',2)<=0
            || $amount!==UpdEvidence::money($invoice['amount']))
            return $result+['question'=>'Сверьте услугу, её месяц и сумму со счётом по этому договору.'];
        if (UpdEvidence::fact($db,self::SERVICE,$chargeKey))
            return $result+['question'=>'По этому начислению услуги уже сохранён проект УПД.'];
        return $result+['service_charge'=>$chargeKey,'agreement_id'=>$charge['agreement_id'] ?: null,
            'service_evidence'=>$charge,'lines'=>[['description'=>self::TYPES[$kind],'gross'=>$amount]]];
    }

    private static function rent(PDO $db,array $invoice,string $month): array
    {
        $proof=InvoiceReport::evidence($invoice);
        if (isset($proof['reason'])) return ['question'=>$proof['reason']];
        $lines=array_values(array_filter($proof['lines'],static fn(array $line):bool=>$line['period']===$month));
        if (!$lines) return ['kind'=>'rent','question'=>'Подтвердите строки аренды за выбранный месяц.'];
        [$from,$to]=InvoicePeriods::bounds($month);
        $q=$db->prepare('SELECT object_id,GREATEST(start_date,?) AS start_date,LEAST(end_date,?) AS end_date
            FROM invoice_coverage WHERE invoice_id=? AND start_date<=? AND end_date>=? ORDER BY object_id,start_date,end_date');
        $q->execute([$from,$to,$invoice['id'],$to,$from]);
        $coverage=$q->fetchAll();
        $amount=array_reduce($lines,static fn(string $sum,array $line):string=>bcadd($sum,$line['gross'],2),'0.00');
        $plan=['kind'=>'rent','amount'=>$amount];
        if (!$coverage || bccomp($amount,'0',2)<=0) return $plan+['question'=>'Подтвердите покрытие аренды по объектам и положительную сумму за месяц.'];
        $overlap=$db->prepare("SELECT 1 FROM invoice_coverage ic JOIN invoices i ON i.id=ic.invoice_id
            WHERE i.contract_id=? AND i.id<>? AND i.status='active' AND ic.object_id=? AND ic.start_date<=? AND ic.end_date>=? LIMIT 1");
        foreach ($coverage as $item) {
            $overlap->execute([$invoice['contract_id'],$invoice['id'],$item['object_id'],$item['end_date'],$item['start_date']]);
            if ($overlap->fetchColumn()) return $plan+['question'=>'Этот период и объект покрывает другой счёт. Сверьте основания УПД.'];
        }
        return $plan+compact('lines','coverage');
    }

    private static function datePlan(PDO $db,array $invoice,string $month,string $kind,?string $type): array
    {
        if ($type==='entrepreneur') return ['question'=>'Для ИП подтвердите правило даты УПД по этому договору.'];
        if (!in_array($type,['person','company'],true)) return ['question'=>'Подтвердите тип плательщика по снимку счёта.'];
        if ($type==='company' && in_array($kind,['rent','regular','extra'],true))
            return ['date'=>InvoicePeriods::bounds($month)[1],'date_rule'=>'Последний календарный день месяца'];
        if ($type==='person' && in_array($kind,['regular','extra','pass'],true))
            return ['question'=>'Пересекаются правило оплаты физлица и правило услуги. Согласуйте дату по этому договору.'];
        if (!in_array($kind,['rent','fob','pass'],true))
            return ['question'=>'Подтвердите правило УПД для этого вида услуги.'];
        $payment=UpdEvidence::postings($db,$invoice);
        if (isset($payment['question'])) return $payment;
        if ($type==='person') {
            if ($payment['posted_at']===$payment['date'].'T00:00:00+03:00')
                return ['question'=>'Проведение в 00:00. Уточните последовательность за дату оплаты.'];
            return ['date'=>$payment['date'],'date_rule'=>'День оплаты. Учётное время должно предшествовать проведению',
                'before'=>$payment['posted_at'],'payment'=>$payment];
        }
        if ($kind==='fob') {
            $calendar=PaymentDayCalendar::after($payment['date'],1,'working',true);
            if (!isset($calendar['date'])) return ['question'=>$calendar['question']];
            return ['date'=>$calendar['date'],'date_rule'=>'Следующий рабочий день после оплаты',
                'calendar'=>$calendar['source'],'payment'=>$payment];
        }
        return ['date'=>(new DateTimeImmutable($payment['date']))->modify('+1 day')->format('Y-m-d'),
            'date_rule'=>'Следующий календарный день после оплаты','payment'=>$payment];
    }

    public static function save(PDO $db,array $actor,array $input): array
    {
        RegularInvoice::requireAccess(2);
        $month=self::month($input['month'] ?? null);
        $choices=$input['choices'] ?? null;
        if (($input['confirmed'] ?? '')!=='1' || !is_array($choices) || !$choices || count($choices)>100)
            throw new RuntimeException('Выберите до 100 счетов и подтвердите сохранение проектов.',422);
        $saved=0;
        foreach ($choices as $id=>$token) {
            if (!ctype_digit((string)$id) || (int)$id<1 || !is_string($token) || !preg_match('/^[0-9a-f]{64}$/D',$token))
                throw new RuntimeException('Обновите предпросмотр УПД.',422);
            Service::get($db,'invoices',(int)$id);
            $invoice=self::invoices($db,$month,(int)$id)[0] ?? throw new RuntimeException('Счёт недоступен за выбранный месяц.',404);
            $row=self::row($db,$invoice,$month);
            if (!empty($row['draft']) && hash_equals($row['token'],$token)) continue;
            if (!isset($row['token']) || !hash_equals($row['token'],$token))
                throw new RuntimeException('Основание, версия или покрытие изменились. Обновите список УПД.',409);
            $key=$id.':'.$month;
            unset($row['invoice'],$row['status']);
            $row['created_at']=(new DateTimeImmutable())->format(DATE_ATOM);
            $row['actor_id']=(int)$actor['id'];
            self::insert($db,self::DRAFT,$key,$row);
            if (isset($row['service_charge'])) self::insert($db,self::SERVICE,$row['service_charge'],['draft'=>$key]);
            if (isset($row['credit'])) self::insert($db,self::CREDIT,(string)$row['credit']['payment_id'],['draft'=>$key]+$row['credit']);
            Audit::record(self::SAVE,'invoice',(int)$id,null,$row);
            $saved++;
        }
        return ['message'=>$saved ? 'Проекты УПД сохранены: '.$saved.'.' : 'Выбранные проекты уже сохранены.'];
    }

    private static function insert(PDO $db,string $namespace,string $key,array $proof): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([$namespace,$key,Importer::json($proof)]);
    }
}
