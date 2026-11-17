<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\StorageBudget;
use RuntimeException;

/** Source-compatible fixed-root technical queue; financial/domain state remains SQL. */
final class MarketJobs
{
    private string $root;

    public function __construct(private MarketSettingsStore $settings)
    {
        $this->root = $settings->directory();
    }

    private static function key(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9-]{12,80}$/D', $key)) throw new RuntimeException('Неверный ключ запуска.', 422);
    }

    private function path(string $relative): string
    {
        if (!preg_match('~^(?:requests/control\.json|(?:requests|status)/[A-Za-z0-9-]{12,80}\.json|locks/\.(?:admin|worker)\.lock)$~D', $relative)) throw new RuntimeException('Unexpected market queue path.');
        $path = $this->root.'/'.$relative;
        $parent = realpath(dirname($path));
        if (!$parent || is_link($path) || strtolower(str_replace('\\','/',$parent)) !== strtolower($this->root.'/'.dirname($relative))) throw new RuntimeException('Market queue path escaped fixed root.');
        return $path;
    }

    private static function write(string $path, array $value): void
    {
        if (is_link($path)) throw new RuntimeException('Queue cannot replace a symlink.');
        $bytes = Market::canonical($value);
        if (strlen($bytes) > 1000000) throw new RuntimeException('Queue JSON exceeds source bound.');
        $candidate = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(12));
        $stream = fopen($candidate, 'xb');
        if (!$stream) throw new RuntimeException('Cannot create queue candidate.');
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
                || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('Cannot persist queue candidate.');
        } finally { fclose($stream); }
        if (!hash_equals(hash('sha256',$bytes), hash_file('sha256',$candidate)) || !rename($candidate,$path)) throw new RuntimeException('Cannot commit queue file.');
    }

    private function read(string $relative, int $limit = 100000): array
    {
        $path = $this->path($relative);
        if (!is_file($path) || filesize($path) > $limit) throw new RuntimeException('Очередь мониторинга не настроена или повреждена.', 503);
        $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid queue JSON.');
        return $value;
    }

    private function lock(string $name)
    {
        $path = $this->path('locks/.'.$name.'.lock');
        if (!is_file($path)) throw new RuntimeException('Очередь мониторинга ещё не настроена.',503);
        $stream = fopen($path,'r+b');
        if (!$stream || !flock($stream, LOCK_EX | LOCK_NB)) { if ($stream) fclose($stream); throw new RuntimeException('Мониторинг занят. Повторите позже.',409); }
        return $stream;
    }

    private function control(): array
    {
        $value = $this->read('requests/control.json',1000000);
        if (($value['schema'] ?? null) !== 1 || !is_int($value['version'] ?? null) || $value['version'] < 1
            || !is_array($value['jobs'] ?? null) || !array_is_list($value['jobs']) || count($value['jobs']) > 2000) throw new RuntimeException('Invalid queue control.');
        $keys = [];
        foreach ($value['jobs'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['created_at'] ?? null)) throw new RuntimeException('Invalid indexed queue job.');
            self::key($row['id']);
            if (isset($keys[$row['id']])) throw new RuntimeException('Duplicate queue identity.');
            $keys[$row['id']] = true;
        }
        if (($value['active_key'] ?? null) !== null && (!is_string($value['active_key']) || !isset($keys[$value['active_key']]))) throw new RuntimeException('Unindexed active queue job.');
        return $value;
    }

    private function request(string $key): array
    {
        self::key($key);
        $request = $this->read('requests/'.$key.'.json');
        if (($request['schema'] ?? null) !== 1 || ($request['id'] ?? null) !== $key
            || !is_array($request['settings'] ?? null) || !is_string($request['settings_etag'] ?? null)
            || !hash_equals(hash('sha256',Market::canonical($request['settings'])),$request['settings_etag'])
            || !is_string($request['created_at'] ?? null)) throw new RuntimeException('Immutable market request changed.');
        MarketSettings::validate($request['settings'], $this->root, false);
        if ($request['settings']['output_root'] !== $this->root.'/results') throw new RuntimeException('Request output escaped fixed root.');
        return $request;
    }

    private function job(array $request): array
    {
        $relative = 'status/'.$request['id'].'.json';
        if (!is_file($this->path($relative))) return ['id'=>$request['id'],'state'=>'queued','started_at'=>$request['created_at'],'run_id'=>null];
        $status = $this->read($relative);
        if (($status['id'] ?? null) !== $request['id'] || !in_array($status['state'] ?? null,['running','complete','failed'],true)) throw new RuntimeException('Invalid worker status.');
        return $status;
    }

    public function status(): array
    {
        $this->settings->load(); // Current READ1 before reading job data.
        $control = $this->control();
        $jobs = [];
        foreach (array_slice($control['jobs'],-30) as $row) $jobs[] = $this->job($this->request($row['id']));
        $active = $control['active_key'] === null ? null : $this->job($this->request($control['active_key']));
        if ($active !== null && !in_array($active['state'],['queued','running'],true)) $active = null;
        return ['active'=>$active,'jobs'=>$jobs];
    }

    public function enqueue(string $key, string $expected): array
    {
        MarketSettingsStore::requireEdit(); // Current EDIT2 before validation, loading or replay.
        self::key($key);
        $lock = $this->lock('admin');
        try {
            $control = $this->control();
            foreach ($control['jobs'] as $row) if ($row['id'] === $key) return $this->job($this->request($key));
            $current = $this->settings->load();
            if (!hash_equals($current['etag'],$expected)) throw new RuntimeException('Настройки изменились. Перечитайте их перед запуском.',409);
            if ($control['active_key'] !== null && in_array($this->job($this->request($control['active_key']))['state'],['queued','running'],true)) throw new RuntimeException('Сбор уже ожидает запуска или выполняется.',409);
            if (count($control['jobs']) >= 2000) throw new RuntimeException('Историю запросов необходимо архивировать.',409);
            $relative = 'requests/'.$key.'.json';
            if (is_file($this->path($relative))) {
                $request = $this->request($key);
                if (!hash_equals($request['settings_etag'],$current['etag'])) throw new RuntimeException('Сохранённый незавершённый запрос имеет другие настройки.',409);
            } else {
                $request = ['schema'=>1,'id'=>$key,'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'settings'=>$current['settings'],'settings_etag'=>$current['etag']];
                self::write($this->path($relative),$request);
            }
            $control['jobs'][] = ['id'=>$key,'created_at'=>$request['created_at']];
            $control['active_key'] = $key; $control['version']++;
            self::write($this->path('requests/control.json'),$control);
            return $this->job($request);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function result(string $key): array
    {
        $directory = $this->root.'/results/'.$key.'/'.$key;
        if (is_link($this->root.'/results/'.$key) || is_link($directory) || !is_dir($directory)
            || strtolower(str_replace('\\','/',realpath($directory) ?: '')) !== strtolower($directory)
            || is_file($directory.'/INCOMPLETE')) throw new RuntimeException('No complete worker result.');
        $files = [];
        foreach (['COMPLETE'=>4096,'snapshot.json'=>16000000,'records.csv'=>16000000,'sources.csv'=>1000000] as $name=>$limit) {
            $path = $directory.'/'.$name;
            if (is_link($path) || !is_file($path) || filesize($path)>$limit || realpath(dirname($path)) !== realpath($directory)) throw new RuntimeException('Invalid worker result file.');
            $files[$name] = file_get_contents($path);
        }
        $hashes = json_decode($files['COMPLETE'],true,8,JSON_THROW_ON_ERROR);
        if (!is_array($hashes)) throw new RuntimeException('Invalid worker completion marker.');
        $names = array_keys($hashes); sort($names);
        if ($names !== ['records.csv','snapshot.json','sources.csv']) throw new RuntimeException('Invalid worker completion files.');
        foreach ($hashes as $name=>$hash) if (!is_string($hash) || !hash_equals(hash('sha256',$files[$name]),$hash)) throw new RuntimeException('Worker result hash changed.');
        $snapshot = json_decode($files['snapshot.json'],true,64,JSON_THROW_ON_ERROR);
        Market::validate($snapshot);
        if (($snapshot['run_id'] ?? null) !== $key) throw new RuntimeException('Worker result identity changed.');
        $expected = $this->request($key)['settings'];
        $expected['output_root'] = $this->root.'/results/'.$key;
        if (Market::canonical($snapshot['settings']) !== Market::canonical($expected)) throw new RuntimeException('Worker result settings differ from the queued snapshot.');
        return ['run_id'=>$key,'snapshot_sha256'=>$hashes['snapshot.json'],'records'=>count($snapshot['records'])];
    }

    /** CLI-only one-shot worker; running state is reconciled, never blindly retried. */
    public function runNext(callable $executor, ?callable $admit = null): ?array
    {
        $current = $this->settings->workerSnapshot();
        $worker = $this->lock('worker');
        try {
            $lock = $this->lock('admin');
            try {
                $control = $this->control();
                if ($control['active_key'] === null) return null;
                $request = $this->request($control['active_key']);
                if ($request['settings']['browser']['channel'] !== $current['settings']['browser']['channel']) throw new RuntimeException('Worker channel differs from request.');
                if ($admit !== null) $admit($request['settings']);
                $status = $this->job($request);
                if (in_array($status['state'],['complete','failed'],true)) return null;
                if ($status['state'] === 'running') {
                    try { $status = array_merge($status,$this->result($request['id']),['state'=>'complete']); }
                    catch (\Throwable) { $status['state']='failed'; $status['error_type']='InterruptedWorker'; }
                    self::write($this->path('status/'.$request['id'].'.json'),$status);
                    return $status;
                }
                $status = ['id'=>$request['id'],'state'=>'running','started_at'=>gmdate('Y-m-d\TH:i:s\Z'),'run_id'=>null];
                self::write($this->path('status/'.$request['id'].'.json'),$status);
            } finally { flock($lock,LOCK_UN); fclose($lock); }
            try {
                $settings = $request['settings'];
                $settings['output_root'] = $this->root.'/results/'.$request['id'];
                StorageBudget::target($settings['output_root'].'/worker-budget',16777216,$this->root);
                if (file_exists($settings['output_root']) || !mkdir($settings['output_root'],0700)) throw new RuntimeException('Worker result group already exists.');
                $executor($settings,$request['id'],$status['started_at']);
                $status = array_merge($status,$this->result($request['id']),['state'=>'complete']);
            } catch (\Throwable $error) { $status['state']='failed'; $status['error_type']=get_class($error); }
            $status['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            self::write($this->path('status/'.$request['id'].'.json'),$status);
            return $status;
        } finally { flock($worker,LOCK_UN); fclose($worker); }
    }

    public static function initialize(MarketSettingsStore $settings): self
    {
        $settings->workerSnapshot();
        $jobs = new self($settings);
        $lock = $jobs->lock('admin');
        try {
            $control = $jobs->path('requests/control.json');
            $worker = $jobs->path('locks/.worker.lock');
            if (file_exists($control) || file_exists($worker)) throw new RuntimeException('Queue initialization never overwrites state.');
            StorageBudget::target($settings->directory().'/queue-budget',1048576,$settings->directory());
            $stream = fopen($worker,'xb');
            if (!$stream) throw new RuntimeException('Cannot initialize worker lock.');
            fclose($stream);
            self::write($control,['schema'=>1,'version'=>1,'active_key'=>null,'jobs'=>[]]);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        return $jobs;
    }
}
