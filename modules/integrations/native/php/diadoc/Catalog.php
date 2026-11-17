<?php
declare(strict_types=1);

namespace Diadoc;

use RuntimeException;

/** Registry metadata and downloaded originals are separate stores. */
final class Catalog
{
    public function __construct(private string $state, private string $catalog='catalog.json')
    {
        if (!in_array($catalog,['catalog.json','catalog-online.json'],true)) throw new RuntimeException('CATALOG_PATH_REJECTED');
    }

    public static function read(string $file, array $fallback = []): array
    {
        if (!is_file($file)) {
            return $fallback;
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('INVALID_SAVED_DATA');
        }
        return $data;
    }

    public function start(): array
    {
        $catalog = ['documents' => [], 'pages' => 0, 'next' => null, 'cursors' => [],
            'complete' => false, 'reported_total' => null, 'updated_at' => date(DATE_ATOM)];
        Export::writeJson($this->state . '/'.$this->catalog, $catalog);
        return $this->progress($catalog);
    }

    public function step(object $client, string $box): array
    {
        $catalog = self::read($this->state . '/'.$this->catalog);
        if (!isset($catalog['documents'], $catalog['pages'])) {
            throw new RuntimeException('CATALOG_REFRESH_REQUIRED');
        }
        if ($catalog['complete']) {
            return $this->progress($catalog);
        }
        if ($catalog['pages'] >= 1000) {
            throw new RuntimeException('CATALOG_PAGE_BUDGET_REACHED');
        }
        $page = $client->page($box, 'Any', 50, $catalog['next']);
        $rows = Export::rows($page);
        $index = [];
        foreach ($catalog['documents'] as $row) {
            $index[$row['key']] = $row;
        }
        foreach ($rows as $row) {
            $row['key'] = hash('sha256', $row['message_id'] . '/' . $row['document_id']);
            $index[$row['key']] = $row;
        }
        $next = $page['paging']['next'] ?? null;
        if ($next !== null && !is_string($next)) {
            throw new RuntimeException('INVALID_PAGE_CURSOR');
        }
        $next = $next === '' ? null : $next;
        if ($next !== null && ($rows === [] || in_array($next, $catalog['cursors'], true))) {
            throw new RuntimeException('PAGING_STALLED');
        }
        if ($next !== null) {
            $catalog['cursors'][] = $next;
        }
        $catalog['documents'] = array_values($index);
        $catalog['pages']++;
        $catalog['next'] = $next;
        $catalog['complete'] = $next === null;
        $catalog['reported_total'] = $page['totalCounters']['all'] ?? null;
        $catalog['updated_at'] = date(DATE_ATOM);
        Export::writeJson($this->state . '/'.$this->catalog, $catalog);
        return $this->progress($catalog);
    }

    private function progress(array $catalog): array
    {
        $count = count($catalog['documents']);
        return ['loaded' => $count, 'pages' => $catalog['pages'], 'complete' => $catalog['complete'],
            'reported_total' => $catalog['reported_total'], 'updated_at' => $catalog['updated_at'],
            'total_matches' => $catalog['reported_total'] === null ? null : $count === $catalog['reported_total']];
    }

    public function view(): array
    {
        $catalog = self::read($this->state . '/'.$this->catalog);
        $catalog += ['documents' => [], 'pages' => 0, 'complete' => false, 'reported_total' => null, 'updated_at' => null];
        $ledger = self::read($this->state . '/downloads.json');
        $downloaded = 0;
        $rows = [];
        foreach ($catalog['documents'] as $row) {
            $entry = $ledger[$row['key']] ?? null;
            $available = is_array($entry) && is_string($entry['package'] ?? null)
                && preg_match('/^download-\d{8}-\d{6}-[a-f0-9]{8}$/D', $entry['package'])
                && is_file($this->state . '/' . $entry['package'] . '/complete.json')
                && is_file($this->state . '/' . $entry['package'] . '/files/' . ($entry['file'] ?? ''));
            $row['downloaded'] = (bool) $available;
            $row['downloaded_at'] = $available ? $entry['at'] : null;
            $row['download_count'] = $available ? $entry['count'] : 0;
            $downloaded += $available ? 1 : 0;
            $rows[] = $row;
        }
        return ['progress' => $this->progress($catalog), 'documents' => $rows,
            'downloaded' => $downloaded, 'remaining' => count($rows) - $downloaded];
    }

    public function selected(array $keys): array
    {
        if (!$keys || count($keys) > 50 || count(array_unique($keys, SORT_REGULAR)) !== count($keys)) {
            throw new RuntimeException('SELECT_1_TO_50_DOCUMENTS');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
                throw new RuntimeException('INVALID_DOCUMENT_SELECTION');
            }
        }
        $catalog = self::read($this->state . '/'.$this->catalog);
        $index = [];
        foreach ($catalog['documents'] ?? [] as $row) {
            $index[$row['key']] = $row;
        }
        $result = [];
        $bytes = 0;
        foreach ($keys as $key) {
            if (!isset($index[$key])) {
                throw new RuntimeException('DOCUMENT_NOT_IN_CATALOG');
            }
            $row = $index[$key];
            if (!is_int($row['file_size']) || $row['file_size'] < 1 || $row['file_size'] > 24 * 1024 * 1024) {
                throw new RuntimeException('DOCUMENT_SIZE_NOT_SUPPORTED');
            }
            $bytes += $row['file_size'];
            $result[] = $row;
        }
        if ($bytes > 24 * 1024 * 1024) {
            throw new RuntimeException('SELECTION_SIZE_LIMIT_24_MB');
        }
        return $result;
    }
}
