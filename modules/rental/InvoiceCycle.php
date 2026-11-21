<?php
declare(strict_types=1);
namespace Modules\Rental;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class InvoiceCycle
{
    public const SAVE = 'invoice.confirm-cycle';
    private const NS = 'rental_confirmed_invoice_cycle';

    public static function quarter(string $period): array
    {
        $start = new DateTimeImmutable(RegularInvoice::period($period).'-01');
        $offset = ((int)$start->format('n') - 1) % 3;
        $start = $start->modify("-$offset months");
        return [$start->format('Y-m'),$start->modify('+1 month')->format('Y-m'),$start->modify('+2 months')->format('Y-m')];
    }

    public static function read(PDO $db, int $id, string $period): array
    {
        $quarter = self::quarter($period)[0];
        $q = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key IN (?,?) AND operative=1
            ORDER BY source_key=? DESC LIMIT 1');
        $quarterKey = $id.':quarter:'.$quarter;
        $q->execute([self::NS,$quarterKey,$id.':month:'.$period,$quarterKey]);
        $json = $q->fetchColumn();
        return $json === false ? [] : Importer::decodeExact($json);
    }

    public static function assertIndividual(PDO $db, int $id, string $period): void
    {
        $mode = self::read($db,$id,$period)['mode'] ?? null;
        if ($mode === 'quarterly')
            throw new RuntimeException('Этот месяц входит в согласованный квартальный график. Откройте «Сформировать за месяц».',409);
        if ($mode === 'self')
            throw new RuntimeException('На этот месяц подтверждена самостоятельная оплата.',409);
    }

    public static function save(PDO $db, array $actor, array $input): array
    {
        RegularInvoice::requireAccess(2);
        $id = (int)($input['contract_id'] ?? 0);
        RegularInvoice::contract($id);
        $contract = Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        $period = RegularInvoice::period((string)($input['period'] ?? ''));
        $previous = self::read($db,$id,$period);
        if (!hash_equals(self::revision($previous),(string)($input['revision'] ?? '')))
            throw new RuntimeException('График изменился. Откройте форму заново.',409);
        $mode = $input['mode'] ?? '';
        if (!in_array($mode,['monthly','quarterly','self'],true))
            throw new RuntimeException('Выберите согласованный порядок выставления.',422);
        if (($previous['mode'] ?? '') === 'quarterly' && $mode !== 'quarterly')
            throw new RuntimeException('Действует подтверждённый график квартала. Уточните его отдельно.',409);
        if (($previous['mode'] ?? '') === 'quarterly') {
            foreach (self::quarter($period) as $month) {
                if (RegularInvoice::existing($db,$id,$month))
                    throw new RuntimeException('По графику уже есть счета. Изменение оформляется отдельно.',409);
            }
        }
        $reason = DirectoryDetails::text($input,'reason',600);
        if ($reason === '' || ($input['confirmed'] ?? '') !== '1')
            throw new RuntimeException('Укажите документ или переписку и подтвердите согласованный график.',422);
        $periods = self::quarter($period);
        $documents = $mode === 'quarterly' ? self::documents($input,$periods) : [];
        $sourceKey = $id.($mode === 'quarterly' ? ':quarter:'.$periods[0] : ':month:'.$period);
        $fact = ['mode'=>$mode,'period'=>$mode === 'quarterly' ? $periods[0] : $period,'documents'=>$documents,
            'reason'=>$reason,'actor_id'=>(int)$actor['id'],'command_key'=>$input['command_key'],'confirmed_at'=>date(DATE_ATOM)];
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json),operative=1')->execute([self::NS,$sourceKey,Importer::json($fact)]);
        \App\Audit::record(self::SAVE,'contract',$id,$previous,$fact);
        Service::event($db,$actor,self::SAVE,'Подтверждён порядок выставления за '.$fact['period'].'.',$id);
        return ['message'=>'Порядок выставления сохранён.'];
    }

    private static function documents(array $input, array $quarter): array
    {
        $count = $input['document_count'] ?? '';
        if (!in_array($count,['1','2','3'],true))
            throw new RuntimeException('Укажите согласованное количество квартальных счетов.',422);
        $documents = [];
        $offset = 0;
        for ($index=0; $index<3; $index++) {
            $from = $input['from'][$index] ?? '';
            $to = $input['to'][$index] ?? '';
            $due = $input['due'][$index] ?? '';
            if ($from === '' && $to === '' && $due === '') continue;
            $end = array_search($to,$quarter,true);
            if (($quarter[$offset] ?? null) !== $from || $end === false || $end < $offset || $due === '')
                throw new RuntimeException('Укажите покрытие и срок каждого счёта. Месяцы квартала должны идти подряд без пропусков и пересечений.',422);
            $documents[] = ['periods'=>array_slice($quarter,$offset,$end-$offset+1),'due'=>Service::date((string)$due)];
            $offset = $end+1;
        }
        if ($offset !== 3 || count($documents) !== (int)$count)
            throw new RuntimeException('Покрытие квартала и число указанных счетов должны совпасть с согласованным порядком.',422);
        return $documents;
    }

    public static function revision(array $fact): string
    {
        return hash('sha256',Importer::canonicalJson($fact));
    }
}
