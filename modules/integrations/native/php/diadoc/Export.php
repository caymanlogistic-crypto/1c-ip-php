<?php
declare(strict_types=1);

namespace Diadoc;

use RuntimeException;

final class Export
{
    public const COLUMNS = ['message_id', 'document_id', 'category', 'message_date', 'name', 'type',
        'sender', 'sender_inn', 'sender_kpp', 'recipient', 'recipient_inn', 'recipient_kpp',
        'status', 'file_size', 'is_read', 'url', 'metadata_json'];

    public static function rows(array $page): array
    {
        $rows = [];
        foreach ($page['documentsByMessage'] as $message) {
            if (!is_string($message['messageId'] ?? null) || !is_array($message['documents'] ?? null)) {
                throw new RuntimeException('DOCUMENT_LIST_SCHEMA_CHANGED');
            }
            $sender = $message['participants']['sender']['box'] ?? [];
            $recipient = $message['participants']['recipient']['box'] ?? [];
            foreach ($message['documents'] as $document) {
                if (!is_string($document['documentId'] ?? null) || !is_string($document['name'] ?? null)) {
                    throw new RuntimeException('DOCUMENT_LIST_SCHEMA_CHANGED');
                }
                // A document URL is data only. Never fetch/open cards during an export.
                $url = $document['url'] ?? '';
                $rows[] = [
                    'message_id' => $message['messageId'], 'document_id' => $document['documentId'],
                    'category' => $message['category'] ?? '', 'message_date' => $message['date'] ?? '',
                    'name' => $document['name'], 'type' => $document['typedNamedId'] ?? '',
                    'sender' => $sender['fullName'] ?? $sender['shortName'] ?? '',
                    'sender_inn' => $sender['inn'] ?? '', 'sender_kpp' => $sender['kpp'] ?? '',
                    'recipient' => $recipient['fullName'] ?? $recipient['shortName'] ?? '',
                    'recipient_inn' => $recipient['inn'] ?? '', 'recipient_kpp' => $recipient['kpp'] ?? '',
                    'status' => $document['status']['primary']['text'] ?? '',
                    'file_size' => $document['fileSize'] ?? null, 'is_read' => $document['isRead'] ?? null,
                    'url' => $url,
                    'metadata_json' => json_encode($document['metadata'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
            }
        }
        return $rows;
    }

    /** Protect spreadsheet users from text interpreted as a CSV formula. JSON stays lossless. */
    public static function csvCell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $text = $value === null ? '' : (string) $value;
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) ? "'" . $text : $text;
    }

    public static function writeJson(string $path, mixed $value): void
    {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($bytes)>16*1024*1024) throw new RuntimeException('ONLINE_JSON_LIMIT');
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) {
            throw new RuntimeException('CANNOT_SAVE_PRIVATE_OUTPUT');
        }
    }

    /** Bounded export; cursor is preserved in manifest when the requested limit is reached. */
    public static function run(object $client, string $box, string $category, int $limit, int $maxPages, string $output): array
    {
        if ($maxPages < 1 || $maxPages > 10 || $limit < 1 || $limit > 50) {
            throw new RuntimeException('INVALID_EXPORT_BUDGET');
        }
        if (file_exists($output) || !mkdir($output, 0700)) {
            throw new RuntimeException('OUTPUT_MUST_BE_A_NEW_DIRECTORY');
        }
        $rows = [];
        $seen = [];
        $cursors = [];
        $from = null;
        $pageCount = 0;
        $complete = false;
        $totalCounters = null;
        $duplicates = 0;
        for ($i = 0; $i < $maxPages; $i++) {
            $page = $client->page($box, $category, $limit, $from);
            $pageRows = self::rows($page);
            if (count($pageRows) > $limit) {
                throw new RuntimeException('SERVER_EXCEEDED_REQUESTED_DOCUMENT_LIMIT');
            }
            self::writeJson($output . '/page-' . ($i + 1) . '.json', $page);
            $pageCount++;
            $totalCounters = $page['totalCounters'];
            foreach ($pageRows as $row) {
                $key = $row['message_id'] . '/' . $row['document_id'];
                if (isset($seen[$key])) {
                    $duplicates++;
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
            $next = $page['paging']['next'] ?? null;
            if ($next !== null && !is_string($next)) {
                throw new RuntimeException('INVALID_PAGE_CURSOR');
            }
            $from = $next === '' ? null : $next;
            if ($from === null) {
                $complete = true;
                break;
            }
            if ($pageRows === [] || isset($cursors[$from])) {
                throw new RuntimeException('PAGING_STALLED');
            }
            $cursors[$from] = true;
        }
        self::writeJson($output . '/documents.json', $rows);
        $csv = fopen($output . '/documents.csv', 'xb');
        if ($csv === false) {
            throw new RuntimeException('CANNOT_SAVE_CSV');
        }
        try {
            if (fwrite($csv, "\xEF\xBB\xBF") !== 3 || fputcsv($csv, self::COLUMNS, ';', '"', '', "\r\n") === false) {
                throw new RuntimeException('CANNOT_SAVE_CSV');
            }
            foreach ($rows as $row) {
                if (fputcsv($csv, array_map([self::class, 'csvCell'], array_values($row)), ';', '"', '', "\r\n") === false) {
                    throw new RuntimeException('CANNOT_SAVE_CSV');
                }
            }
            if (!fflush($csv)) {
                throw new RuntimeException('CANNOT_SAVE_CSV');
            }
        } finally {
            fclose($csv);
        }
        $manifest = [
            'exported_at' => date(DATE_ATOM), 'category' => $category, 'pages' => $pageCount,
            'documents' => count($rows), 'duplicates_removed' => $duplicates,
            'complete' => $complete, 'next_cursor' => $from, 'total_counters' => $totalCounters,
            'scope' => 'Registry only. No document card, file, receipt, read-mark or signing endpoints called.',
            'files' => [],
        ];
        foreach (glob($output . '/*') as $file) {
            $manifest['files'][] = ['name' => basename($file), 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)];
        }
        self::writeJson($output . '/manifest.json', $manifest);
        return $manifest;
    }
}
