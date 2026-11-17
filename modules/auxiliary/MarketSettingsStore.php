<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Auth,PagePolicy,StorageBudget};
use RuntimeException;

/** Source S rental_market/store.py fixed-root configuration; never executes a job. */
final class MarketSettingsStore
{
    public const PROJECT = 'f6e21e3c-d974-4604-82fb-333da66559d2';
    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if (!$real || !is_dir($real) || is_link($root)) throw new RuntimeException('Мониторинг ещё не настроен.', 503);
        $this->root = rtrim(str_replace('\\', '/', $real), '/');
        $own = strtolower(rtrim(str_replace('\\', '/', APP_ROOT), '/'));
        if (strtolower($this->root) === $own || str_starts_with(strtolower($this->root), $own.'/')) throw new RuntimeException('Market runtime must be outside application source.');
    }

    private static function authorize(int $level): void
    {
        Auth::requirePermission('rental.read');
        if ($level === 2) Auth::requirePermission('premises.write');
        $user = Auth::user();
        if (!empty($user['grants_configured']) && PagePolicy::level('auxiliary.market-settings', $user) < $level) throw new RuntimeException('Нет доступа к настройкам мониторинга.', 403);
    }

    public static function editable(): bool
    {
        try { self::authorize(2); return true; }
        catch (RuntimeException) { return false; }
    }

    public static function requireEdit(): void { self::authorize(2); }

    public static function configured(): self
    {
        self::authorize(1);
        $explicit = getenv('APP_MARKET_ROOT');
        if ($explicit !== false && $explicit !== '') return new self($explicit);
        $path = APP_ROOT.'/.local/market-collector.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 2000) throw new RuntimeException('Параметры сборщика ещё не настроены. Обратитесь к администратору.', 503);
        $value = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (($value['project_id'] ?? null) !== self::PROJECT || !is_string($value['root'] ?? null)) throw new RuntimeException('Market private configuration differs.');
        return new self($value['root']);
    }

    private function path(string $relative): string
    {
        if (!preg_match('~^(?:policy\.json|config/settings\.json|locks/\.admin\.lock)$~D', $relative)) throw new RuntimeException('Unexpected market settings path.');
        $path = $this->root.'/'.$relative;
        $parent = realpath(dirname($path));
        if (!$parent || is_link($path) || !str_starts_with(strtolower(str_replace('\\', '/', $parent)).'/', strtolower($this->root).'/')) throw new RuntimeException('Market settings path escaped its fixed root.');
        return $path;
    }

    private function json(string $relative, int $limit): array
    {
        $path = $this->path($relative);
        if (!is_file($path) || filesize($path) > $limit) throw new RuntimeException('Market settings file invalid.');
        $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Market settings file is not an object.');
        return $value;
    }

    private static function atomic(string $path, array $value): void
    {
        if (is_link($path)) throw new RuntimeException('Market settings cannot replace a symlink.');
        $bytes = Market::canonical($value);
        $candidate = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(12));
        $stream = fopen($candidate, 'xb');
        if (!$stream) throw new RuntimeException('Cannot create candidate market settings.');
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) throw new RuntimeException('Cannot write candidate market settings.');
            if (function_exists('fsync') && !fsync($stream)) throw new RuntimeException('Cannot sync candidate market settings.');
        } finally { fclose($stream); }
        if (!hash_equals(hash('sha256', $bytes), hash_file('sha256', $candidate))) throw new RuntimeException('Candidate market settings changed.');
        if (!rename($candidate, $path)) throw new RuntimeException('Cannot atomically replace market settings.');
    }

    private function current(): array
    {
        $policy = $this->json('policy.json', 2000);
        if (($policy['schema'] ?? null) !== 1 || ($policy['project_id'] ?? null) !== self::PROJECT
            || !in_array($policy['channel'] ?? '', ['msedge','chrome','chromium'], true)) throw new RuntimeException('Market runtime policy invalid.');
        $raw = $this->json('config/settings.json', 100000);
        if (($raw['output_root'] ?? null) !== $this->root.'/results'
            || ($raw['browser']['channel'] ?? null) !== $policy['channel']
            || !is_dir($this->root.'/results') || is_link($this->root.'/results')) throw new RuntimeException('Fixed market output/channel changed.');
        // Reading a fixed existing configuration does not reserve space or create a job.
        $settings = MarketSettings::validate($raw, $this->root, false);
        return ['settings' => $settings, 'etag' => hash('sha256', Market::canonical($settings))];
    }

    public function load(): array
    {
        self::authorize(1);
        return $this->current();
    }

    public function directory(): string { return $this->root; }

    /** Separate local worker identity reads fixed policy; it does not impersonate a web user. */
    public function workerSnapshot(): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Explicit local worker required.', 403);
        return $this->current();
    }

    public function save(array $settings, string $expected): array
    {
        // Current page2/coarse grants precede validation, file loading and replay.
        self::authorize(2);
        $checked = MarketSettings::validate($settings, $this->root);
        $lock = fopen($this->path('locks/.admin.lock'), 'r+b');
        if (!$lock) throw new RuntimeException('Market settings lock unavailable.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Мониторинг занят. Повторите через несколько секунд.', 409);
            $current = $this->current();
            if (!hash_equals($current['etag'], $expected)) throw new RuntimeException('Настройки уже изменились. Перечитайте сохранённые значения.', 409);
            if ($checked['output_root'] !== $current['settings']['output_root']
                || $checked['browser']['channel'] !== $current['settings']['browser']['channel']) throw new RuntimeException('Каталог и установленный браузер задаёт администратор.', 422);
            self::atomic($this->path('config/settings.json'), $checked);
            return $this->current();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Explicit operator setup only. GET/load/save never initialize a runtime. */
    public static function initialize(string $root, string $channel = 'msedge'): self
    {
        if (PHP_SAPI !== 'cli' || !in_array($channel, ['msedge','chrome','chromium'], true)) throw new RuntimeException('Explicit CLI setup required.');
        StorageBudget::target(rtrim($root, '/\\').'/market-initialize', 16777216);
        $ancestor = $root;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
        $resolved = str_replace('\\', '/', realpath($ancestor) ?: '').substr(str_replace('\\', '/', $root), strlen(str_replace('\\', '/', $ancestor)));
        $own = strtolower(rtrim(str_replace('\\', '/', APP_ROOT), '/'));
        if (strtolower($resolved) === $own || str_starts_with(strtolower($resolved), $own.'/')) throw new RuntimeException('Market initialization requires an external runtime.');
        if (file_exists($root) || is_link($root)) throw new RuntimeException('Market runtime already exists; initialization never overwrites it.');
        if (!mkdir($root, 0700, true)) throw new RuntimeException('Cannot create private market runtime.');
        $store = new self($root);
        foreach (['config','requests','status','results','locks','worker'] as $directory) {
            if (!mkdir($store->root.'/'.$directory, 0700)) throw new RuntimeException('Cannot initialize market runtime directory.');
        }
        $settings = json_decode(file_get_contents(__DIR__.'/source-ui/market-settings.example.json'), true, 32, JSON_THROW_ON_ERROR);
        $settings['output_root'] = $store->root.'/results';
        $settings['browser']['channel'] = $channel;
        $settings = MarketSettings::validate($settings, $store->root);
        self::atomic($store->root.'/config/settings.json', $settings);
        self::atomic($store->root.'/policy.json', ['schema' => 1, 'project_id' => self::PROJECT, 'channel' => $channel]);
        if (file_put_contents($store->root.'/locks/.admin.lock', '0', LOCK_EX) !== 1) throw new RuntimeException('Cannot initialize market lock.');
        $store->current();
        return $store;
    }
}
