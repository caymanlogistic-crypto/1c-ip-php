<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Validates a whole archive before exposing any member. Never extracts paths. */
final class RawZip
{
    public const INPUT = 67108864;
    public const EXPANDED = 536870912;
    public const MEMBER = 67108864;
    public const ENTRIES = 10000;

    public static function name(string $name): string
    {
        if ($name === '' || strlen($name) > 240 || preg_match('/[\\\\:\x00-\x1f\x7f]/', $name)
            || str_starts_with($name, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|/$~', $name)
            || !mb_check_encoding($name, 'UTF-8')) {
            throw new \RuntimeException('ARCHIVE_PATH_REJECTED');
        }
        return $name;
    }

    /** Export accepts only explicit bytes or verified immutable paths, never source filenames as paths. */
    public static function build(string $destination, string $provider, iterable $entries, array $context, array $documentFiles = []): array
    {
        Support::provider($provider);
        if (file_exists($destination) || is_link($destination) || !is_dir(dirname($destination))) throw new \RuntimeException('ARCHIVE_TARGET_REQUIRED');
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) throw new \RuntimeException('ARCHIVE_CREATE_FAILED');
        $files = [];
        $names = [];
        $expanded = 0;
        try {
            foreach ($entries as $entry) {
                $name = self::name($entry['name'] ?? '');
                $folded = mb_strtolower($name, 'UTF-8');
                if ($folded === 'manifest.json' || isset($names[$folded]) || count($names) >= self::ENTRIES - 1) throw new \RuntimeException('ARCHIVE_DUPLICATE_OR_LIMIT');
                $names[$folded] = true;
                if (isset($entry['bytes'])) {
                    $raw = $entry['bytes'];
                    if (!is_string($raw)) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                    $size = strlen($raw);
                    $sha = hash('sha256', $raw);
                    if (!$zip->addFromString($name, $raw)) throw new \RuntimeException('ARCHIVE_CREATE_FAILED');
                } else {
                    $path = $entry['path'] ?? null;
                    if (!is_string($path) || !is_file($path) || is_link($path)) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                    $size = filesize($path);
                    $sha = hash_file('sha256', $path);
                    if (!is_int($entry['size'] ?? null) || $entry['size'] !== $size || !hash_equals($entry['sha256'] ?? '', $sha)) throw new \RuntimeException('ARCHIVE_MEMBER_CHANGED');
                    if (!$zip->addFile($path, $name)) throw new \RuntimeException('ARCHIVE_CREATE_FAILED');
                }
                if ($size > self::MEMBER || ($expanded += $size) > self::EXPANDED) throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
                // Stored members make resource use predictable and cannot become ratio bombs.
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                $files[] = ['name' => $name, 'bytes' => $size, 'sha256' => $sha];
            }
            $manifest = ['schema' => 'ipphp.raw-archive.v1', 'provider' => $provider, 'files' => $files, 'context' => $context];
            if ($documentFiles) {
                $manifest['count'] = $context['record_count'] ?? null;
                $manifest['document_files'] = $documentFiles;
            }
            Support::business($manifest);
            $raw = Support::json($manifest);
            if (strlen($raw) > 4194304 || !$zip->addFromString('manifest.json', $raw)) throw new \RuntimeException('ARCHIVE_MANIFEST_INVALID');
            if (!$zip->close()) throw new \RuntimeException('ARCHIVE_CREATE_FAILED');
            // Verify bytes read by libzip, including any change between addFile and close.
            $verified = self::inspect($destination, $provider, false);
            return ['sha256' => hash_file('sha256', $destination), 'bytes' => filesize($destination), 'manifest' => $verified];
        } catch (\Throwable $error) {
            try { $zip->close(); } catch (\Throwable) {}
            if (is_file($destination) && !is_link($destination)) unlink($destination);
            throw $error;
        }
    }

    /** Stream every member once; no filesystem member names are materialized. */
    public static function inspect(string $path, string $provider, bool $upload = true): array
    {
        Support::provider($provider);
        if (!is_file($path) || is_link($path) || filesize($path) > ($upload ? self::INPUT : self::EXPANDED)) throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) throw new \RuntimeException('ARCHIVE_INVALID');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::ENTRIES) throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
            $members = [];
            $folded = [];
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) throw new \RuntimeException('ARCHIVE_INVALID');
                $name = self::name($stat['name']);
                $key = mb_strtolower($name, 'UTF-8');
                if (isset($folded[$key])) throw new \RuntimeException('ARCHIVE_DUPLICATE_OR_LIMIT');
                $folded[$key] = true;
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($opsys === \ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== 0100000) throw new \RuntimeException('ARCHIVE_LINK_REJECTED');
                $size = $stat['size'];
                if (!is_int($size) || $size < 0 || $size > self::MEMBER || ($expanded += $size) > self::EXPANDED
                    || !empty($stat['encryption_method']) || $size > max(1048576, $stat['comp_size'] * 256)) throw new \RuntimeException('ARCHIVE_SIZE_LIMIT');
                if ($name === 'manifest.json' && $size > 4194304) throw new \RuntimeException('ARCHIVE_MANIFEST_INVALID');
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                $hash = hash_init('sha256');
                $count = 0;
                $head = '';
                $manifestRaw = '';
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || $chunk === '' && !feof($stream)) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                        $count += strlen($chunk);
                        if ($count > $size) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                        if ($head === '') $head = substr($chunk, 0, 8);
                        hash_update($hash, $chunk);
                        if ($name === 'manifest.json') $manifestRaw .= $chunk;
                    }
                } finally { fclose($stream); }
                if ($count !== $size) throw new \RuntimeException('ARCHIVE_MEMBER_INVALID');
                // OOXML is an opaque business original; it is never recursively extracted.
                if (preg_match('/\.(?:zip|7z|rar|tar|gz)$/iD', $name)
                    || str_starts_with($head, "PK\x03\x04") && !preg_match('/\.docx$/iD', $name)
                    || str_starts_with($head, "7z\xBC\xAF\x27\x1C") || str_starts_with($head, "Rar!")) throw new \RuntimeException('ARCHIVE_NESTED_REJECTED');
                $members[$name] = ['bytes' => $size, 'sha256' => hash_final($hash)];
                if ($name === 'manifest.json') $manifest = Support::decode($manifestRaw);
            }
            if (!isset($manifest) || ($manifest['provider'] ?? null) !== $provider || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])) throw new \RuntimeException('ARCHIVE_MANIFEST_INVALID');
            $listed = [];
            foreach ($manifest['files'] as $file) {
                if (!is_array($file) || !is_string($file['name'] ?? null)) throw new \RuntimeException('ARCHIVE_MANIFEST_INVALID');
                $name = self::name($file['name']);
                if ($name === 'manifest.json' || isset($listed[$name]) || !isset($members[$name])
                    || !is_int($file['bytes'] ?? null) || $file['bytes'] !== $members[$name]['bytes']
                    || !hash_equals($members[$name]['sha256'], $file['sha256'] ?? '')) throw new \RuntimeException('ARCHIVE_HASH_OR_BINDING_FAILED');
                $listed[$name] = true;
            }
            if (count($listed) + 1 !== count($members)) throw new \RuntimeException('ARCHIVE_UNLISTED_MEMBER');
            if (isset($manifest['document_files'])) {
                if (!is_array($manifest['document_files']) || !array_is_list($manifest['document_files'])
                    || !isset($listed['records.json'])) throw new \RuntimeException('ARCHIVE_DOCUMENT_BINDING_FAILED');
                $report = Support::decode($zip->getFromName('records.json', $members['records.json']['bytes']));
                if (($report['provider'] ?? null) !== $provider || !is_array($report['records'] ?? null)
                    || !array_is_list($report['records']) || ($manifest['count'] ?? null) !== count($report['records'])) throw new \RuntimeException('ARCHIVE_DOCUMENT_BINDING_FAILED');
                $used = [];
                foreach ($manifest['document_files'] as $file) {
                    $name = $file['name'] ?? null;
                    $index = $file['record'] ?? null;
                    if (!is_string($name) || !isset($members[$name]) || isset($used[$name])
                        || !is_int($index) || $index < 1 || $index > count($report['records'])) throw new \RuntimeException('ARCHIVE_DOCUMENT_BINDING_FAILED');
                    $used[$name] = true;
                    $record = $report['records'][$index - 1];
                    if (($file['record_key'] ?? null) !== ($record['key'] ?? null)
                        || ($file['account_id'] ?? null) !== ($record['account_id'] ?? '')
                        || !in_array($file['kind'] ?? null, ['pdf', 'original', 'signature'], true)
                        || ($file['bytes'] ?? null) !== $members[$name]['bytes']
                        || ($file['sha256'] ?? null) !== $members[$name]['sha256']) throw new \RuntimeException('ARCHIVE_DOCUMENT_BINDING_FAILED');
                }
            }
            Support::business($manifest);
            return $manifest;
        } finally { $zip->close(); }
    }

    /** Caller must inspect the whole archive first; this remains bounded per member. */
    public static function read(string $path, string $name, array $manifest): string
    {
        $name = self::name($name);
        $expected = null;
        foreach ($manifest['files'] as $file) if ($file['name'] === $name) $expected = $file;
        if ($expected === null) throw new \RuntimeException('ARCHIVE_MEMBER_UNBOUND');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new \RuntimeException('ARCHIVE_INVALID');
        try {
            $raw = $zip->getFromName($name, $expected['bytes']);
            if (!is_string($raw) || strlen($raw) !== $expected['bytes'] || !hash_equals($expected['sha256'], hash('sha256', $raw))) throw new \RuntimeException('ARCHIVE_HASH_OR_BINDING_FAILED');
            return $raw;
        } finally { $zip->close(); }
    }
}
