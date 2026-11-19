<?php
declare(strict_types=1);

namespace Modules\Integrations;

final class YakovlevImport
{
    public const PROVIDER = 'onec-yakovlev';
    public const TITLE = '1С ИП Яковлев';
    public const FIRST_DAY = '1900-01-01';
    public const DEFAULT_FROM = '2026-09-15';
    public const PARTY_MISSING = 'Отсутствует в 1С';
    public const TABS = [
        'parties' => 'Контрагенты',
        'bank' => 'Банковские выписки',
        'invoices' => 'Выставленные счета',
    ];
    private const ORGANIZATION = '5b2b76f9-0ae3-11ea-80e4-005056953851';
    private const INN = '390613939405';
    private const LABELS = [
        'parties' => 'Контрагент', 'bank_in' => 'Поступление',
        'bank_out' => 'Списание', 'invoices' => 'Счёт покупателю',
    ];
    private const PAGE = 100;

    public function __construct(private \PDO $db, private PrivateStore $store) {}

    public function import(string $tab, string $from, string $to, int $actor, string $requestKey, bool $scheduled = false): array
    {
        if (!isset(self::TABS[$tab])) throw new \InvalidArgumentException('INVALID_IMPORT_TAB');
        if ($tab !== 'parties') self::validatePeriod($from, $to);
        Support::identity($requestKey);
        $scope = ['kind' => 'yakovlev-'.$tab, 'scope_limited' => true];
        if ($tab !== 'parties') $scope += ['from' => $from,'to' => $to];
        if ($scheduled) {
            if (PHP_SAPI !== 'cli' || $actor !== 0) throw new \RuntimeException('SCHEDULED_IMPORT_NOT_ALLOWED');
            $scope['trigger'] = 'hourly';
            if ($tab === 'parties') $scope['party_mirror'] = true;
        }
        $fingerprint = hash('sha256',Support::json($scope));
        $id = Support::uuid();
        $start = $this->db->prepare(
            "INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json)
             VALUES(?,?,'yakovlev-load',?,?,1,?,'pending',?) ON DUPLICATE KEY UPDATE id=id"
        );
        $start->execute([$id,self::PROVIDER,$requestKey,$actor,$fingerprint,Support::json(['scope'=>$scope])]);
        $query = $this->db->prepare('SELECT id,operation,fingerprint,status,coverage_json FROM integration_runs WHERE provider=? AND actor_id=? AND request_key=?');
        $query->execute([self::PROVIDER,$actor,$requestKey]);
        $existing = $query->fetch(\PDO::FETCH_ASSOC);
        if (($existing['id']??null) !== $id) {
            if (!$existing || $existing['operation'] !== 'yakovlev-load' || !hash_equals($existing['fingerprint'],$fingerprint)) {
                throw new \RuntimeException('IDEMPOTENCY_CONFLICT');
            }
            return ['id'=>$existing['id'],'status'=>$existing['status'],'coverage'=>json_decode($existing['coverage_json'],true),'reused'=>true];
        }
        $locked = false;
        $received = [];
        try {
            $locked = (int)$this->db->query("SELECT GET_LOCK('ipphp:onec-yakovlev-import',0)")->fetchColumn() === 1;
            if (!$locked) throw new \RuntimeException('IMPORT_BUSY');
            $reader = self::reader();
            $orgs = self::rows($reader->yakovlevPage('organization', [
                '$format' => 'json', '$top' => '2', '$select' => 'Ref_Key,ИНН,DeletionMark',
                '$filter' => "DeletionMark eq false and ИНН eq '".self::INN."'",
            ]));
            if (count($orgs) !== 1 || ($orgs[0]['Ref_Key'] ?? '') !== self::ORGANIZATION
                || ($orgs[0]['ИНН'] ?? '') !== self::INN || ($orgs[0]['DeletionMark'] ?? null) !== false) {
                throw new \RuntimeException('ORGANIZATION_NOT_CONFIRMED');
            }
            $documents = [];
            foreach ($tab === 'bank' ? ['bank_in','bank_out'] : [$tab] as $kind) {
                $records = self::collect($reader, $kind, $from, $to);
                $received[$kind] = count($records);
                array_push($documents, ...$records);
            }
            $snapshot = Support::json(['provider' => self::PROVIDER, 'documents' => $documents, 'scope' => $scope,
                'coverage' => ['received_by_kind'=>$received]]);
            return (new Registry($this->db,$this->store))->import(self::PROVIDER,$snapshot,$actor,$requestKey,$id);
        } catch (\Throwable $error) {
            $this->db->prepare("UPDATE integration_runs SET status='failed',result_code=?,coverage_json=?,finished_at=NOW(6)
                WHERE id=? AND status='pending'")->execute([self::safeCode($error),
                Support::json(['scope'=>$scope,'evidence'=>['received_by_kind'=>$received],'records_imported'=>0]),$id]);
            throw $error;
        } finally {
            if ($locked) $this->db->query("SELECT RELEASE_LOCK('ipphp:onec-yakovlev-import')");
        }
    }

    public static function safeCode(\Throwable $error): string
    {
        return match($error->getMessage()) {
            'IMPORT_BUSY','AUTH_REQUIRED','YAKOVLEV_READ_NOT_ENABLED','ORGANIZATION_NOT_CONFIRMED',
            'NETWORK_OR_TLS_FAILED','TIME_LIMIT','REQUEST_BUDGET','YAKOVLEV_IMPORT_LIMIT','RESPONSE_TOO_LARGE',
            'SOURCE_HTTP_FAILED','INVALID_ODATA_ROWS' => $error->getMessage(),
            default => 'IMPORT_FAILED',
        };
    }

    private static function reader(): \NativeOnec\Reader
    {
        $file = Config::root().'/.local/onec-yakovlev-read.json';
        if (getenv('APP_STAND') !== 'ui' || is_link($file) || !is_file($file) || filesize($file)>1024) {
            throw new \RuntimeException('YAKOVLEV_READ_NOT_ENABLED');
        }
        $config = json_decode(file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
        if (($config['scope']??null) !== 'yakovlev-get-only' || ($config['organization']??null) !== self::ORGANIZATION) {
            throw new \RuntimeException('YAKOVLEV_READ_NOT_ENABLED');
        }
        $profile = Config::profile('onec',Config::reference($config['profile']??''));
        require_once __DIR__.'/native/php/onec/ReadOnly.php';
        return \NativeOnec\Reader::yakovlev($profile['username']??'', $profile['password']??'', Support::caFile());
    }

    private static function validatePeriod(string $from, string $to): void
    {
        $today = new \DateTimeImmutable('today',new \DateTimeZone('Europe/Moscow'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to)
            || Support::day($from)!==$from || Support::day($to)!==$to || $from<self::FIRST_DAY
            || $to<$from || $to>$today->format('Y-m-d')
            || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>366) {
            throw new \InvalidArgumentException('INVALID_IMPORT_PERIOD');
        }
    }

    private static function rows(string $raw): array
    {
        return self::envelope(Support::decode($raw));
    }

    private static function envelope(array $data): array
    {
        $rows = $data['value'] ?? $data['d']['results'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) throw new \RuntimeException('INVALID_ODATA_ROWS');
        return $rows;
    }

    private static function collect(\NativeOnec\Reader $reader, string $kind, string $from, string $to): array
    {
        $pageSize = $kind === 'parties' ? 50 : self::PAGE;
        $until = '';
        if ($kind === 'parties') {
            $filter = 'IsFolder eq false';
        } else {
            $until = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $filter = "Организация_Key eq guid'".self::ORGANIZATION
                ."' and Date ge datetime'".$from."T00:00:00' and Date lt datetime'".$until."T00:00:00'";
        }
        $records = [];
        $expectedTotal = null;
        for ($skip = 0; $skip <= 10000; $skip += $pageSize) {
            $query = [
                '$format' => 'json', '$top' => (string)$pageSize, '$skip' => (string)$skip,
                '$inlinecount' => 'allpages',
                '$orderby' => $kind === 'parties' ? 'Ref_Key asc' : 'Date asc,Ref_Key asc',
                '$filter' => $filter,
            ];
            $data = Support::decode($reader->yakovlevPage($kind,$query));
            $rows = self::envelope($data);
            $total = $data['odata.count'] ?? $data['@odata.count'] ?? $data['d']['__count'] ?? null;
            if ((!is_int($total) && !is_string($total)) || !preg_match('/^\d{1,9}$/D',(string)$total)) {
                throw new \RuntimeException('INVALID_ODATA_ROWS');
            }
            $total = (int)$total;
            if ($total>10000) throw new \RuntimeException('YAKOVLEV_IMPORT_LIMIT');
            $expectedTotal ??= $total;
            if ($total!==$expectedTotal || count($rows)!==min($pageSize,max(0,$total-$skip))) {
                throw new \RuntimeException('INVALID_ODATA_ROWS');
            }
            foreach ($rows as $row) {
                $document = self::normalize(['kind'=>$kind,'fields'=>['record'=>$row]]);
                $id = $document['source_id'];
                if (isset($records[$id]) || ($kind !== 'parties'
                    && ($document['document_date']<$from || $document['document_date'] >= $until))) {
                    throw new \RuntimeException('INVALID_ODATA_ROWS');
                }
                $records[$id] = $document['raw'];
            }
            if (count($records)===$total) return array_values($records);
        }
        throw new \RuntimeException('YAKOVLEV_IMPORT_LIMIT');
    }

    public static function normalize(array $row): array
    {
        $kind = $row['kind'] ?? '';
        $record = $row['fields']['record'] ?? null;
        if (!isset(self::LABELS[$kind]) || !is_array($record) || !is_bool($record['DeletionMark']??null)) {
            throw new \InvalidArgumentException('INVALID_YAKOVLEV_RECORD');
        }
        $key = OnecMapping::guid($record['Ref_Key']??'');
        $party = $kind === 'parties';
        if ($party ? ($record['IsFolder']??null)!==false
            : (!is_bool($record['Posted']??null) || ($record['Организация_Key']??null)!==self::ORGANIZATION)) {
            throw new \InvalidArgumentException('INVALID_YAKOVLEV_RECORD');
        }
        $name = trim((string)($record['Description']??''));
        $amount = $party ? null : Support::money($record['СуммаДокумента']??null);
        if (!$party && $amount === null) throw new \InvalidArgumentException('INVALID_EXACT_DECIMAL');
        return [
            'provider'=>self::PROVIDER, 'source_id'=>$kind.':'.$key, 'account_id'=>$kind,
            'document_date'=>$party?'1000-01-01':Support::day($record['Date']??null),
            'number'=>$party?(string)($record['ИНН']??''):Support::identity($record['Number']??null),
            'title'=>$party?mb_substr($name?:'Без наименования',0,300):self::LABELS[$kind],
            'amount'=>$amount,'status'=>$record['DeletionMark']?'Помечен на удаление':($party?'Действующий':($record['Posted']?'Проведён':'Не проведён')),
            'raw'=>$row,
        ];
    }
}
