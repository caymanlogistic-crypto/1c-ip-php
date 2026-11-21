<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth};
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class ExpiryNotice
{
    public const PREPARE='contract.expiry-draft';
    public const SENT='contract.expiry-sent';
    public const RESPONSE='contract.expiry-response';
    public const ACTIONS=[self::PREPARE,self::SENT,self::RESPONSE];
    public const FILE='expiry-send-proof';
    public const CHANNELS=['edo'=>'ЭДО','email'=>'Почта'];
    public const MODES=['same'=>'Прежняя цена','amount'=>'Новая сумма','percent'=>'Согласованный процент'];
    public const DECISIONS=['addendum'=>'Допсоглашение','contract'=>'Связанный новый договор','exit'=>'Выезд'];
    public const NS='rental_expiry_notice';
    private const MAX='rental_expiry_notice_max_fact';

    public static function access(int $id,int $level=1): array
    {
        Service::roles(['manager','lawyer']);
        if ($level===2) Auth::requirePermission(Auth::user()['role']==='lawyer'?'rental.approve.legal':'rental.write');
        return Access::contract($id,$level);
    }

    public static function canRead(): bool
    {
        return in_array(Auth::user()['role'] ?? '',['manager','lawyer','admin'],true);
    }

    public static function canWrite(array $contract): bool
    {
        if (!self::canRead()) return false;
        try {self::access((int)$contract['id'],2);}
        catch (RuntimeException $e) {if ($e->getCode()===403) return false; throw $e;}
        return true;
    }

    public static function all(PDO $db,int $contract): array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1 AND source_key LIKE ? ORDER BY source_key DESC');
        $q->execute([self::NS,$contract.':%']);
        return array_map(Importer::decodeExact(...),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function read(PDO $db,int $contract,string $end): array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::NS,self::key($contract,$end)]);
        return Importer::decodeExact($q->fetchColumn() ?: '{}');
    }

    public static function key(int $contract,string $end): string
    {
        return $contract.':'.Service::date($end);
    }

    public static function availableChannels(PDO $db,array $party): array
    {
        $invoiceRule=InvoiceDeliveryPreference::read($db,$party);
        $edo=mb_strtolower(trim((string)($party['edo'] ?? '')));
        $knownEdo=trim((string)($party['edo_id'] ?? ''))!==''
            || in_array($invoiceRule['mode'],['edo','both'],true)
            || ($edo!=='' && !in_array($edo,['нет','не подключено','не используется','0','no','false','—','-'],true));
        return $knownEdo ? ['edo'=>self::CHANNELS['edo']] : self::CHANNELS;
    }

    public static function terms(PDO $db,array $contract): array
    {
        $q=$db->prepare('SELECT o.id,o.name,o.type,o.area,t.start_date,t.end_date,t.amount
            FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id
            LEFT JOIN contract_terms t ON t.id=(SELECT MAX(t2.id) FROM contract_terms t2
                WHERE t2.contract_id=co.contract_id AND t2.object_id=co.object_id
                AND (t2.effective_date IS NULL OR t2.effective_date<=?) AND (t2.superseded_on IS NULL OR t2.superseded_on>=?))
            WHERE co.contract_id=? ORDER BY o.id');
        $q->execute([$contract['end_date'],$contract['end_date'],$contract['id']]);
        return $q->fetchAll();
    }

    public static function nextDay(string $day): string
    {
        return (new DateTimeImmutable(Service::date($day)))->modify('+1 day')->format('Y-m-d');
    }

    public static function files(PDO $db,array $notice): array
    {
        $q=$db->prepare("SELECT * FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind=? AND baseline_hash=? ORDER BY upload_order DESC");
        $q->execute([$notice['contract_id'],self::FILE,self::fingerprint($notice)]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function fingerprint(array $notice): string
    {
        return hash('sha256',Importer::canonicalJson($notice['offer']+['revision'=>$notice['offer_revision']]));
    }

    public static function draftForAttachment(PDO $db,array $contract,array $input): array
    {
        self::access((int)$contract['id'],2);
        $notice=self::read($db,(int)$contract['id'],(string)($input['notice_end'] ?? ''));
        if (!$notice || $notice['state']!=='draft' || (int)$notice['version']!==(int)($input['notice_version'] ?? 0))
            throw new RuntimeException('Откройте актуальный проект уведомления перед загрузкой.',409);
        if ($contract['status']!=='active' || $contract['end_date']!==$notice['old_end']
            || (int)$contract['party_id']!==$notice['offer']['party_id'])
            throw new RuntimeException('Срок договора изменился после подготовки уведомления.',409);
        return $notice;
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        $id=(int)($input['id'] ?? 0);
        self::access($id,2);
        $contract=Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        $end=Service::date((string)($input['notice_end'] ?? ''));
        $notice=self::read($db,$id,$end);
        if ((int)($notice['version'] ?? 0)!==(int)($input['notice_version'] ?? 0))
            throw new RuntimeException('Уведомление изменилось. Откройте актуальную карточку.',409);
        if ($action===self::PREPARE) {
            if ($contract['status']!=='active' || $contract['end_date']!==$end || ($notice && $notice['state']!=='draft'))
                throw new RuntimeException('Подготовка доступна по действующему сроку до фиксации отправки.',409);
            $offer=self::offer($db,$contract,$input);
            if ($notice && Importer::canonicalJson($notice['offer'])===Importer::canonicalJson($offer))
                return ['id'=>$id,'message'=>'Этот проект уже сохранён.'];
            $notice=['contract_id'=>$id,'old_end'=>$end,'state'=>'draft','version'=>(int)($notice['version'] ?? 0)+1,
                'offer_revision'=>(int)($notice['offer_revision'] ?? 0)+1,'offer'=>$offer];
            $message='Проект уведомления сохранён.';
        } elseif ($action===self::SENT) {
            if (!$notice || $notice['state']!=='draft' || $contract['status']!=='active' || $contract['end_date']!==$end
                || (int)$contract['party_id']!==$notice['offer']['party_id'])
                throw new RuntimeException('Доступен только актуальный проект без подтверждённой отправки.',409);
            $party=Service::get($db,'parties',(int)$contract['party_id']);
            if (!isset(self::availableChannels($db,$party)[$notice['offer']['channel']]))
                throw new RuntimeException('Для клиента с ЭДО почтовый дубль уведомления запрещён. Обновите канал в проекте.',422);
            $time=self::actualTime($input,'sent_at');
            if (substr($time,0,10)<$notice['offer']['notice_date']) throw new RuntimeException('Отправка датирована раньше уведомления.',422);
            $file=self::files($db,$notice)[(string)($input['file_id'] ?? '')] ?? null;
            $reference=DirectoryDetails::text($input,'reference',240);
            if (!$file || $reference==='' || ($input['confirmed'] ?? '')!=='1')
                throw new RuntimeException('Выберите доказательство, укажите идентификатор или реквизиты отправки и подтвердите факт.',422);
            Evidence::integrity($file);
            $notice['state']='sent';
            $notice['version']++;
            $notice['sent']=['when'=>$time,'channel'=>$notice['offer']['channel'],'recipient'=>$notice['offer']['recipient'],
                'reference'=>$reference,'file_id'=>$file['id'],'sha256'=>$file['sha256'],'actor_id'=>(int)$actor['id']];
            $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
                ->execute([self::MAX,self::key($id,$end),Importer::json(['contract_id'=>$id,'contract_number'=>$contract['number'],
                    'notice_end'=>$end,'sent_at'=>$time,'channel'=>$notice['sent']['channel'],'offer_revision'=>$notice['offer_revision'],
                    'status'=>'internal_only'])]);
            $message='Фактическая отправка подтверждена по доказательству.';
        } else {
            if (!$notice || $notice['state']!=='sent') throw new RuntimeException('Сначала подтвердите отправку уведомления.',409);
            $decision=(string)($input['decision'] ?? '');
            $answer=DirectoryDetails::text($input,'response',1200);
            $time=self::actualTime($input,'answered_at');
            if (!isset(self::DECISIONS[$decision]) || $answer==='' || $time<$notice['sent']['when'])
                throw new RuntimeException('Укажите ответ, время после отправки и выбранный дальнейший маршрут.',422);
            $notice['state']='replied';
            $notice['version']++;
            $notice['response']=['when'=>$time,'text'=>$answer,'decision'=>$decision,'actor_id'=>(int)$actor['id']];
            $message='Ответ и выбранный маршрут сохранены.';
        }
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute([self::NS,self::key($id,$end),Importer::json($notice)]);
        Audit::record($action,'contract',$id,null,['end'=>$end,'version'=>$notice['version'],'state'=>$notice['state']]);
        return ['id'=>$id,'message'=>$message];
    }

    private static function actualTime(array $input,string $key): string
    {
        $value=(string)($input[$key] ?? '');
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
        if (!$time || $time->format('Y-m-d\TH:i')!==$value || $time>new DateTimeImmutable())
            throw new RuntimeException('Укажите фактическую дату и время по Москве.',422);
        return $time->format(DATE_ATOM);
    }

    private static function offer(PDO $db,array $contract,array $input): array
    {
        $mode=(string)($input['price_mode'] ?? '');
        $channel=(string)($input['channel'] ?? '');
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        if (!isset(self::MODES[$mode],self::availableChannels($db,$party)[$channel]))
            throw new RuntimeException('Выберите способ расчёта цены и допустимый канал. Для клиента с ЭДО используйте ЭДО.',422);
        $recipient=DirectoryDetails::text($input,'recipient',240);
        $number=DirectoryDetails::text($input,'notice_number',80);
        $signer=DirectoryDetails::text($input,'signer',180);
        if ($number==='' || $signer==='' || $recipient==='' || ($channel==='email' && !filter_var($recipient,FILTER_VALIDATE_EMAIL)))
            throw new RuntimeException('Укажите номер, подписанта и получателя по выбранному каналу.',422);
        $oldAmount=$contract['amount'];
        if ($oldAmount===null || bccomp($oldAmount,'0',2)<=0)
            throw new RuntimeException('Уточните действующую ставку по договору.',422);
        $amount=$oldAmount;
        $percent=null;
        $priceBasis=DirectoryDetails::text($input,'price_basis',350);
        if ($mode==='amount') $amount=Service::money($input['new_amount'] ?? '');
        if ($mode==='percent') {
            $percent=str_replace(',','.',DirectoryDetails::text($input,'percent',14));
            if (!preg_match('/^-?\d{1,3}(?:\.\d{1,4})?$/D',$percent) || bccomp($percent,'-100',4)<=0
                || bccomp($percent,'0',4)===0 || $priceBasis==='' || ($input['percent_confirmed'] ?? '')!=='1')
                throw new RuntimeException('Укажите согласованный процент и документальное основание.',422);
            $amount=Billing::round(bcmul($amount,bcadd('1',bcdiv($percent,'100',8),8),8));
        }
        if (bccomp($amount,'0',2)<=0 || strlen(strstr($amount,'.',true) ?: '')>18) throw new RuntimeException('Укажите допустимую положительную цену.',422);
        $newStart=self::nextDay($contract['end_date']);
        $newEnd=Service::date((string)($input['new_end'] ?? ''));
        $noticeDate=Service::date((string)($input['notice_date'] ?? ''));
        $responseDue=Service::date((string)($input['response_due'] ?? ''));
        if ($newEnd<$newStart || $noticeDate<$contract['document_date'] || $responseDue<$noticeDate)
            throw new RuntimeException('Проверьте даты уведомления, ответа и нового срока.',422);
        $sender=$db->query('SELECT name,inn,address FROM organization WHERE id=1')->fetch();
        if (!$sender || !$sender['name'] || !$contract['document_date'])
            throw new RuntimeException('Подтвердите отправителя и дату договора.',422);
        $texts=[];
        foreach (['recipient_title','renewal_basis','notice_basis','payment_terms','security_terms','utilities_terms','index_terms'] as $field) {
            $texts[$field]=DirectoryDetails::text($input,$field,600);
        }
        return $texts+[
            'contract_id'=>(int)$contract['id'],'party_id'=>(int)$party['id'],'party'=>$party['name'],'channel'=>$channel,'recipient'=>$recipient,
            'number'=>$number,'signer'=>$signer,'notice_date'=>$noticeDate,'response_due'=>$responseDue,
            'contract_number'=>$contract['number'],'contract_date'=>$contract['document_date'],'old_start'=>$contract['start_date'],
            'old_end'=>$contract['end_date'],'new_start'=>$newStart,'new_end'=>$newEnd,'old_amount'=>$oldAmount,
            'new_amount'=>$amount,'percent'=>$percent,'price_basis'=>$priceBasis,'price_mode'=>$mode,'template'=>$mode==='same'?'D26':'D27',
            'landlord'=>$sender['name'],'landlord_ids'=>$sender['inn']?'ИНН '.$sender['inn']:'','landlord_address'=>$sender['address'] ?? '',
            'terms'=>self::terms($db,$contract),
        ];
    }
}
