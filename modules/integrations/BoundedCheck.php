<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Bounded observation only: no canonical rows, original files or response bodies are persisted. */
final class BoundedCheck
{
    public const ACCOUNTS = 3;
    public const PAGES = 2;
    public const RECORDS = 10;

    public function __construct(private mixed $call,private ?SourceFileProbe $fileProbe=null) {}

    public function inspect(string $provider, array $auth, string $from, string $to): array
    {
        Support::provider($provider);
        Support::day($from);
        Support::day($to);
        if ($to < $from) throw new \InvalidArgumentException('INVALID_PERIOD');
        $probeFrom = $provider === 'mosenergo'
            ? max($from, (new \DateTimeImmutable($to))->modify('-30 days')->format('Y-m-d')) : $from;
        $accounts = [null];
        $accountRecords=[];
        $accountTotal = null;
        if (in_array($provider, ['pik', 'mosvodokanal', 'mosenergo'], true)) {
            $rows = $auth['data']['items'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) throw new \RuntimeException('ACCOUNT_LIST_NOT_CONFIRMED');
            $accounts = [];
            $seen = [];
            $accountTotal = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
                $key = Support::identity($provider === 'pik' ? ($row['_uid'] ?? null) : ($row['ID'] ?? $row['ACCOUNT_ID'] ?? $row['id'] ?? null));
                if (isset($seen[$key])) throw new \RuntimeException('DUPLICATE_ACCOUNT');
                $seen[$key] = true;
                $accountRecords[$key]=$row;
                if (count($accounts) < self::ACCOUNTS) $accounts[] = $key;
            }
        }
        $pages = 0;
        $records = 0;
        $returned = 0;
        $terminal = 0;
        $sampleHashes = [];
        $fileCandidates = 0;
        $accountsRead = 0;
        $fileSamples=[];
        foreach ($accounts as $account) {
            if ($pages >= self::PAGES || $records >= self::RECORDS) break;
            $accountsRead++;
            $cursor = null;
            $seen = [];
            while ($pages < self::PAGES && $records < self::RECORDS) {
                $identity = Support::canonical($cursor);
                if (isset($seen[$identity])) throw new \RuntimeException('REPEATED_CURSOR');
                $seen[$identity] = true;
                $response = ($this->call)('records', [
                    'account' => $account, 'cursor' => $cursor, 'check_probe' => true,
                    'from' => $probeFrom, 'to' => $to,
                ]);
                $data = $response['data'] ?? null;
                $items = $data['items'] ?? null;
                if (!is_array($items) || !array_is_list($items) || count($items) > 1000) throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
                $pages++;
                $returned += count($items);
                foreach (array_slice($items, 0, self::RECORDS - $records) as $row) {
                    if (!is_array($row) || array_is_list($row)) throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
                    $sampleHashes[] = hash('sha256', Support::canonical($row));
                    $records++;
                    if (isset($row['document_id']) || isset($row['_uid']) || isset($row['ID']) || isset($row['fields']['record'])) {
                        $fileCandidates++;
                        if(count($fileSamples)<SourceFileProbe::FILES)$fileSamples[]=['record'=>$row,'account'=>$account===null?'':(string)$account,'account_record'=>$accountRecords[$account]??[]];
                    }
                }
                $next = $data['next'] ?? null;
                if ($next === null) { $terminal++; break; }
                if ((!is_string($next) && !is_int($next)) || is_string($next) && strlen($next) > 2048) throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');
                $cursor = $next;
            }
            if ($pages >= self::PAGES || $records >= self::RECORDS) break;
        }
        $fileCoverage=['state'=>'METADATA_ONLY_ORIGINAL_BYTES_NOT_READ','candidates_in_sample'=>$fileCandidates,'originals_verified'=>0];
        if($this->fileProbe!==null){
            $observations=[];
            foreach($fileSamples as$sample)$observations[]=$this->fileProbe->inspect($provider,$sample['record'],$sample['account'],$sample['account_record']);
            $full=count(array_filter($observations,static fn(array $item):bool=>$item['full_body_read']));
            $fileCoverage=['state'=>$full?'SAMPLED_BODIES_NOT_INDEPENDENTLY_VERIFIED':'NO_ORIGINAL_BODY_VERIFIED',
                'candidates_in_sample'=>$fileCandidates,'sampled_files'=>count($observations),'full_bodies_read'=>$full,
                'originals_verified'=>0,'complete'=>false,'observations'=>$observations];
        }
        return [
            'scope' => 'bounded_record_metadata', 'complete' => false, 'scope_limited' => true,
            'requested_period_from' => $from, 'requested_period_to' => $to,
            'source_period_filter_applied' => in_array($provider,['bank','mosenergo'],true),
            'source_period_from' => in_array($provider,['bank','mosenergo'],true)?$probeFrom:null,
            'source_period_to' => in_array($provider,['bank','mosenergo'],true)?$to:null,
            'accounts_available' => $accountTotal,
            'account_scopes_selected' => $accountTotal===null?0:count($accounts), 'pages_read' => $pages,
            'account_scopes_read' => $accountTotal===null?0:$accountsRead,
            'terminal_pages_observed' => $terminal, 'records_returned' => $returned,
            'records_checked' => $records, 'sample_sha256' => $sampleHashes,
            'file_coverage' => $fileCoverage,
            'canonical_document_imports' => 0, 'raw_response_archives' => 0,
        ];
    }
}
