<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\StorageBudget;
use DateTimeImmutable;
use RuntimeException;

/** Native source-compatible closure; COMPLETE means durable files, not a complete market. */
final class MarketRunClosure
{
    private static function put(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if (!$stream) throw new RuntimeException('Unique market closure file required.');
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $size = fwrite($stream, substr($bytes, $offset));
                if ($size === false || $size === 0) throw new RuntimeException('Market closure write failed.');
                $offset += $size;
            }
            if (!fflush($stream)) throw new RuntimeException('Market closure flush failed.');
        } finally { fclose($stream); }
        if (!hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) throw new RuntimeException('Market closure byte verification failed.');
    }

    private static function csv(array $fields, array $rows): string
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!$stream) throw new RuntimeException('Cannot allocate market CSV buffer.');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            if (fputcsv($stream, $fields, ',', '"', '', "\r\n") === false) throw new RuntimeException('Cannot write market CSV header.');
            foreach ($rows as $row) {
                $values = [];
                foreach ($fields as $field) {
                    $value = $row[$field] ?? '';
                    if (!is_scalar($value)) throw new RuntimeException('Market CSV field is not scalar.');
                    $values[] = is_bool($value) ? ($value ? 'True' : 'False') : $value;
                }
                if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) throw new RuntimeException('Cannot write market CSV row.');
                if (ftell($stream) > 16000000) throw new RuntimeException('Market CSV exceeds closure limit.');
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    private static function exact(mixed $value): void
    {
        if (is_float($value)) throw new RuntimeException('Native market closure requires exact decimal strings.');
        if (is_array($value)) foreach ($value as $child) self::exact($child);
    }

    public static function write(array $settings, array $attempts, array $records, string $runId,
        string $observedAt, ?callable $step = null): array
    {
        $settings = MarketSettings::validate($settings);
        if (!preg_match('/^[A-Za-z0-9-]{1,80}$/D', $runId)) throw new RuntimeException('Invalid market run identity.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $observedAt)) throw new RuntimeException('Market observation requires an explicit timestamp.');
        new DateTimeImmutable($observedAt);
        if (!array_is_list($attempts) || !array_is_list($records)) throw new RuntimeException('Market closure needs ordered rows and attempts.');
        self::exact($attempts); self::exact($records);
        $dependencies = [];
        foreach (['MarketRunClosure.php','MarketSettings.php','MarketSourceUrls.php','MarketPrimaryCards.php',
            'MarketCollectionWorker.php','MarketOfficeHtml.php','MarketHttpClient.php','MarketRobots.php'] as $file) {
            $dependencies[$file] = hash_file('sha256', __DIR__.'/'.$file);
        }
        $payload = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => $observedAt,
            'settings' => $settings, 'collector_sha256' => $dependencies['MarketRunClosure.php'],
            'code_sha256' => $dependencies, 'not_a_complete_market' => true,
            'attempts' => $attempts, 'records' => $records];
        Market::validate($payload);
        $files = [
            'snapshot.json' => Market::canonical($payload)."\n",
            'records.csv' => self::csv(['source','scope','source_url','source_id','observed_at','address','area_m2',
                'price_rub','price_period','annual_rate_rub_m2','object_type','floor','listing_status','extraction_method'], $records),
            'sources.csv' => self::csv(['source','object_type','scope','outcome','records','pages_loaded','coverage',
                'partial','stop_reason','error_type'], $attempts),
        ];
        if (strlen($files['snapshot.json']) > 16000000 || strlen($files['sources.csv']) > 1000000) throw new RuntimeException('Market snapshot exceeds import bounds.');
        $directory = rtrim($settings['output_root'], '/\\').'/'.$runId;
        StorageBudget::target($directory, array_sum(array_map('strlen', $files)) + 1048576);
        if (file_exists($directory) || is_link($directory)) throw new RuntimeException('Market run identity already exists.');
        if (!mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create unique market closure directory.');
        self::put($directory.'/INCOMPLETE', "snapshot in progress\n");
        $hashes = [];
        foreach ($files as $name => $bytes) {
            self::put($directory.'/'.$name, $bytes);
            $hashes[$name] = hash('sha256', $bytes);
            if ($step !== null) $step($name);
        }
        // A failed run keeps INCOMPLETE. Neither a CSV nor a progress event closes it.
        json_decode(file_get_contents($directory.'/snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
        ksort($hashes);
        self::put($directory.'/COMPLETE', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        if (!unlink($directory.'/INCOMPLETE')) throw new RuntimeException('Cannot finalize owned market closure marker.');
        return ['directory' => $directory, 'run_id' => $runId, 'sha256' => $hashes['snapshot.json'],
            'records' => count($records), 'attempts' => count($attempts), 'not_a_complete_market' => true];
    }
}
