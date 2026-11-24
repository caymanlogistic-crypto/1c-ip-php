<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{BusinessIds,DB};
use DateTimeImmutable;
use DateTimeZone;
use Modules\Integrations\{Config,Support};
use PDO;
use RuntimeException;

final class BankPaymentRefresh
{
    public const WINDOWS = 'rental_bank_read_window';
    public const HISTORY = 'rental_bank_read_attempt';
    private const RECEIPTS = 'rental_bank_payment_identity';

    public static function local(PDO $db): bool
    {
        return !getenv('APP_STAND') && !Config::liveAllowed()
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'ipphp_task_rent27';
    }

    public static function now(PDO $db): DateTimeImmutable
    {
        $zone = new DateTimeZone('Europe/Moscow');
        $clock = APP_ROOT.'/.local/payment-review-clock.txt';
        if (PHP_SAPI === 'cli-server' && is_file($clock) && self::local($db)) {
            $value = trim(file_get_contents($clock));
            $time = DateTimeImmutable::createFromFormat(DATE_ATOM,$value);
            if ($time && $time->format(DATE_ATOM) === $value) return $time->setTimezone($zone);
        }
        return new DateTimeImmutable('now',$zone);
    }

    public static function window(string $account,string $date): string
    {
        return hash('sha256',$account).':'.$date;
    }

    /** Explicit local capture using the fixed archiveBank read interface. No public trigger or live admission. */
    public static function capture(PDO $db,array $scope,DateTimeImmutable $time,callable $fixtureTransport): array
    {
        if (PHP_SAPI !== 'cli' || !self::local($db) || $db->inTransaction())
            throw new RuntimeException('В этом этапе доступна только изолированная проверка чтения.',403);
        $date = $time->setTimezone(new DateTimeZone('Europe/Moscow'));
        $day = $date->format('Y-m-d');
        $cutoff = $day.'T11:00:00';
        if ($date->format('H:i:s') < '11:00:00')
            return ['state'=>'before_window','day'=>$day,'cutoff'=>$cutoff,'new_payments'=>0];

        $account = $scope['account'] ?? '';
        $from = Service::date((string)($scope['from'] ?? ''));
        if ($from > $day || !preg_match('/^[0-9]{20}$/D',$account)
            || !self::guid($scope['organization'] ?? null) || !self::guid($scope['account_id'] ?? null)
            || !is_string($scope['source'] ?? null) || !preg_match('/^[a-z0-9-]{1,80}$/D',$scope['source']))
            throw new RuntimeException('Не подтверждён контекст банковской выписки.',422);

        $receipt = ['state'=>'incomplete','date'=>$day,'from'=>$from,'cutoff'=>$cutoff,'captured_at'=>$date->format(DATE_ATOM),
            'source'=>$scope['source'],'account_tail'=>substr($account,-4),'payment_ids'=>[]];
        $key = self::window($account,$day);
        try {
            require_once APP_ROOT.'/modules/integrations/native/php/onec/ReadOnly.php';
            $reader = new \NativeOnec\Reader('local-rental-fixture','local-rental-fixture',null,$fixtureTransport);
            $items = self::collect($scope,$day,$cutoff,$reader->archiveBank(...));
            if (($scope['complete_through'] ?? null) !== $cutoff) {
                return self::storeWindow($db,$key,$receipt+['code'=>'SOURCE_WATERMARK_MISSING']);
            }
        } catch (RuntimeException|\InvalidArgumentException $error) {
            if ($error instanceof \PDOException) throw $error;
            $code = $error->getMessage();
            $receipt['state'] = in_array($code,['AUTH_REQUIRED','NETWORK_OR_TLS_FAILED','SOURCE_HTTP_FAILED','TIME_LIMIT'],true)
                ? 'unavailable' : 'incomplete';
            return self::storeWindow($db,$key,$receipt+['code'=>in_array($code,['AUTH_REQUIRED','NETWORK_OR_TLS_FAILED','SOURCE_HTTP_FAILED','TIME_LIMIT'],true)
                ? $code : 'SOURCE_SCHEMA_OR_COVERAGE']);
        }

        try {
            return DB::transaction(function(PDO $p) use($items,$key,$receipt,$scope): array {
                $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
                $created = 0;
                foreach ($items as $row) {
                    [$id,$inserted] = self::rememberPayment($p,$scope,$row);
                    $receipt['payment_ids'][] = $id;
                    $created += (int)$inserted;
                }
                $receipt['state'] = 'complete';
                $receipt['source_sha256'] = hash('sha256',Importer::canonicalJson($items));
                self::saveWindow($p,$key,$receipt);
                return $receipt+['new_payments'=>$created];
            });
        } catch (RuntimeException $error) {
            if ($error instanceof \PDOException || $error->getMessage() !== 'PAYMENT_IDENTITY_CHANGED') throw $error;
            return self::storeWindow($db,$key,$receipt+['code'=>'PAYMENT_IDENTITY_CHANGED']);
        }
    }

    private static function guid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value)
            && $value !== '00000000-0000-0000-0000-000000000000';
    }

    private static function collect(array $scope,string $day,string $cutoff,callable $read): array
    {
        $skip = 0;
        $total = null;
        $seen = [];
        $selected = [];
        $previous = null;
        do {
            $page = $read($scope['from'],$day,$skip);
            $total ??= $page['total'];
            $rows = $page['items'];
            if ($page['total'] !== $total || count($rows)!==min(100,$total-$skip))
                throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
            foreach ($rows as $row) {
                $key = $row['Ref_Key'];
                $date = $row['Date'];
                $order = [$date,$key];
                if (isset($seen[$key]) || ($previous !== null && $order <= $previous))
                    throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
                $seen[$key] = true;
                $previous = $order;
                if ($date > $cutoff) continue;
                $organization = $row['Организация_Key'] ?? null;
                if (!self::guid($organization)) throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
                if ($organization !== $scope['organization']) continue;
                $account = $row['БанковскийСчетОрганизации_Key'] ?? $row['СчетОрганизации_Key'] ?? null;
                if (!self::guid($account)) throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
                if ($account !== $scope['account_id']) continue;
                if (($row['Posted'] ?? null) !== true || ($row['BankAccount'] ?? null) !== $scope['account'])
                    throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
                $money = Support::money($row['СуммаДокумента'] ?? null);
                if ($money === null || bccomp($money,'0',2)<=0 || bccomp($money,bcadd($money,'0',2),8)!==0)
                    throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
                $purpose = $row['НазначениеПлатежа'] ?? $row['НазначениеПлатежаСтрокой'] ?? null;
                $selected[] = ['external_id'=>$key,'amount'=>bcadd($money,'0',2),'date'=>substr($date,0,10),
                    'account'=>$scope['account'],'inn'=>(string)($row['BankPayerINN'] ?? ''),
                    'bankPurpose'=>is_string($purpose) ? trim($purpose) : null];
            }
            $skip += count($rows);
            if ($page['next'] !== ($skip < $total ? $skip : null)) throw new RuntimeException('SOURCE_SCHEMA_OR_COVERAGE');
        } while ($skip < $total);
        return $selected;
    }

    private static function rememberPayment(PDO $p,array $scope,array $row): array
    {
        $key = hash('sha256',$scope['source']."\0".$row['external_id']);
        $identity = hash('sha256',Importer::canonicalJson($row));
        $query = $p->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE");
        $query->execute([self::RECEIPTS,$key]);
        $raw = $query->fetchColumn();
        if ($raw !== false) {
            $receipt = Importer::decodeExact($raw);
            $id = (int)$receipt['payment_id'];
            $query = $p->prepare('SELECT amount,payment_date FROM payments WHERE id=?');
            $query->execute([$id]);
            $payment = $query->fetch();
            if ($receipt['sha256'] !== $identity || !$payment || $payment['amount'] !== $row['amount']
                || $payment['payment_date'] !== $row['date']) throw new RuntimeException('PAYMENT_IDENTITY_CHANGED');
            return [$id,false];
        }
        $query = $p->prepare("SELECT id FROM payments WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.Ref_Key'))=?
            OR JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.bank_external_id'))=? LIMIT 1");
        $query->execute([$row['external_id'],$row['external_id']]);
        if ($query->fetchColumn()) throw new RuntimeException('PAYMENT_IDENTITY_CHANGED');
        $party = null;
        if (preg_match('/^(?:\d{10}|\d{12})$/D',$row['inn'])) {
            $query = $p->prepare('SELECT id FROM parties p WHERE inn=? AND '.WorkspaceReset::visibleSql('p').' ORDER BY id LIMIT 2');
            $query->execute([$row['inn']]);
            $matches = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) $party = (int)$matches[0];
        }
        $id = BusinessIds::next($p,'payments',9000000,false);
        $p->prepare("INSERT INTO payments(id,party_id,amount,payment_date,source,source_json) VALUES(?,?,?,?,'1С: сохранённая банковская выписка',?)")
            ->execute([$id,$party,$row['amount'],$row['date'],Importer::json(['bank_external_id'=>$row['external_id'],
                'bank_source'=>$scope['source'],'bankPurpose'=>$row['bankPurpose']])]);
        self::write($p,self::RECEIPTS,$key,['payment_id'=>$id,'sha256'=>$identity]);
        return [$id,true];
    }

    private static function storeWindow(PDO $db,string $key,array $receipt): array
    {
        DB::transaction(function(PDO $p) use($key,$receipt): void {
            $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            self::saveWindow($p,$key,$receipt);
        });
        return $receipt+['new_payments'=>0];
    }

    private static function saveWindow(PDO $db,string $key,array $receipt): void
    {
        $digest = hash('sha256',Importer::canonicalJson([$key,$receipt]));
        $db->prepare('INSERT IGNORE INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::HISTORY,$digest,Importer::json($receipt)]);
        $q = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE');
        $q->execute([self::WINDOWS,$key]);
        $previous = Importer::decodeExact($q->fetchColumn() ?: '{}');
        if (($previous['captured_at'] ?? '') <= $receipt['captured_at'])
            self::write($db,self::WINDOWS,$key,$receipt);
    }

    private static function write(PDO $db,string $namespace,string $key,array $receipt): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([$namespace,$key,Importer::json($receipt)]);
    }
}
