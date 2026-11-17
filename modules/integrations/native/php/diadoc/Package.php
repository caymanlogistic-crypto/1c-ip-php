<?php
declare(strict_types=1);

namespace Diadoc;

use RuntimeException;
use ZipArchive;

final class Package
{
    public static function fileName(string $name, string $key): string
    {
        $name = preg_replace('/[\x00-\x1f<>:"\/\\\\|?*]/u', '_', $name);
        $name = trim((string) $name, " .\t");
        $name = $name === '' ? 'document.bin' : $name;
        $extension = preg_match('/\.[a-z][a-z0-9]{0,9}$/i', $name, $match) ? $match[0] : '';
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension));
        $name = mb_strcut($base, 0, 150 - strlen($extension), 'UTF-8') . $extension;
        return substr($key, 0, 12) . '-' . $name;
    }

    public static function create(object $client, string $box, array $documents, string $state): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('PHP_ZIP_REQUIRED');
        }
        $id = 'download-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $directory = $state . '/' . $id;
        if (!mkdir($directory, 0700) || !mkdir($directory . '/files', 0700)) {
            throw new RuntimeException('CANNOT_CREATE_DOWNLOAD_DIRECTORY');
        }
        $files = [];
        $errors = [];
        $downloadedRows = [];
        foreach ($documents as $row) {
            try {
                $response = $client->original($box, $row);
                $filename = self::fileName($response['name'] ?? $row['name'], $row['key']);
                if (file_put_contents($directory . '/files/' . $filename, $response['body']) !== strlen($response['body'])) {
                    throw new RuntimeException('CANNOT_SAVE_ORIGINAL');
                }
                $files[] = ['key' => $row['key'], 'name' => $row['name'], 'original_name' => substr($filename, 13), 'file' => $filename,
                    'bytes' => strlen($response['body']), 'sha256' => hash('sha256', $response['body'])];
                $downloadedRows[] = $row;
                unset($response);
            } catch (\Throwable $error) {
                if ($error->getMessage()==='SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN' || in_array($error->getMessage(),['ONLINE_TIME_LIMIT','ONLINE_REQUEST_BUDGET'],true)) throw $error;
                $code = preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $error->getMessage()) ? $error->getMessage() : 'DOCUMENT_DOWNLOAD_FAILED';
                $errors[] = ['key' => $row['key'], 'name' => $row['name'], 'error' => $code];
            }
        }
        $manifest = ['id' => $id, 'at' => date(DATE_ATOM), 'requested' => count($documents),
            'documents' => count($files), 'files' => $files, 'errors' => $errors];
        Export::writeJson($directory . '/manifest.json', $manifest);
        if (!$files) {
            throw new RuntimeException('NO_DOCUMENTS_DOWNLOADED');
        }
        Export::writeJson($directory . '/documents.json', $downloadedRows);
        $csv = fopen($directory . '/documents.csv', 'xb');
        if ($csv === false) {
            throw new RuntimeException('CANNOT_SAVE_CSV');
        }
        try {
            if (fwrite($csv, "\xEF\xBB\xBF") !== 3 || fputcsv($csv, Export::COLUMNS, ';', '"', '', "\r\n") === false) {
                throw new RuntimeException('CANNOT_SAVE_CSV');
            }
            foreach ($downloadedRows as $row) {
                $values = array_map(static fn($column) => Export::csvCell($row[$column] ?? ''), Export::COLUMNS);
                if (fputcsv($csv, $values, ';', '"', '', "\r\n") === false) {
                    throw new RuntimeException('CANNOT_SAVE_CSV');
                }
            }
        } finally {
            fclose($csv);
        }
        $zip = new ZipArchive();
        if ($zip->open($directory . '/documents.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('CANNOT_CREATE_ZIP');
        }
        foreach ($files as $file) {
            if (!$zip->addFile($directory . '/files/' . $file['file'], 'files/' . $file['file'])) {
                throw new RuntimeException('CANNOT_CREATE_ZIP');
            }
        }
        foreach (['documents.json', 'documents.csv', 'manifest.json'] as $file) {
            if (!$zip->addFile($directory . '/' . $file, $file)) {
                throw new RuntimeException('CANNOT_CREATE_ZIP');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('CANNOT_CREATE_ZIP');
        }
        if (filesize($directory.'/documents.zip')>24*1024*1024) throw new RuntimeException('ONLINE_DOWNLOAD_LIMIT');
        $ledger = Catalog::read($state . '/downloads.json');
        foreach ($files as $file) {
            $ledger[$file['key']] = ['package' => $id, 'file' => $file['file'], 'at' => $manifest['at'],
                'bytes' => $file['bytes'], 'sha256' => $file['sha256'], 'count' => ($ledger[$file['key']]['count'] ?? 0) + 1];
        }
        Export::writeJson($state . '/downloads.json', $ledger);
        Export::writeJson($directory . '/complete.json', ['complete' => true]);
        return $manifest;
    }
}
