<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

final class LocalTestLogin
{
    private const PROJECT = 'f6e21e3c-d974-4604-82fb-333da66559d2';
    private const ROLES = [
        'manager'=>'Раиса — менеджер',
        'lawyer'=>'Наиль — юрист',
        'accountant'=>'Наталия — бухгалтер',
        'director'=>'Андрей — директор',
        'admin'=>'Владимир — администратор',
    ];

    private static function path(string $path): string
    {
        return mb_strtolower(str_replace('\\', '/', $path), 'UTF-8');
    }

    private static function localFile(string $name): string
    {
        $expected = APP_ROOT.'/.local/'.$name;
        $resolved = realpath($expected);
        if (!$resolved || self::path($resolved) !== self::path($expected) || !is_file($resolved)) {
            throw new RuntimeException('Нужен приватный файл '.$name.' этой рабочей копии.');
        }
        return $resolved;
    }

    private static function json(string $name): array
    {
        $decoded = json_decode(file_get_contents(self::localFile($name)), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Некорректный формат '.$name.'.');
        return $decoded;
    }

    /** Both the launcher mode and the CLI installer must bind to the same isolated schema. */
    public static function verifiedDatabase(): PDO
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('APP_STAND') || getenv('IPPHP_ALLOW_EXTERNAL')
            || !is_file(APP_ROOT.'/.git')) {
            throw new RuntimeException('Режим тестовых учётных записей доступен только в локальном worktree.');
        }
        $settings = self::json('runtime-settings.json');
        $proof = self::json('copy-verification.json');
        $database = $settings['database'] ?? '';
        if (($settings['project_id'] ?? null) !== self::PROJECT
            || self::path((string)($settings['app_root'] ?? '')) !== self::path(APP_ROOT)
            || self::path((string)($proof['app_root'] ?? '')) !== self::path(APP_ROOT)
            || !is_string($database) || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', $database)
            || ($settings['database_user'] ?? null) !== $database || ($proof['database'] ?? null) !== $database
            || ($proof['isolated_test_data'] ?? null) !== true || ($proof['exact_copy_before_login'] ?? null) !== true) {
            throw new RuntimeException('Нужна подтверждённая изолированная тестовая база этой рабочей копии.');
        }
        $file = self::localFile('database.php');
        $configured = getenv('APP_DB_CONFIG_FILE', true);
        if ($configured !== false && self::path($configured) !== self::path($file)) {
            throw new RuntimeException('Подключите локальную конфигурацию БД этой рабочей копии.');
        }
        $config = require $file;
        if (is_array($config['database'] ?? null)) $config = $config['database'];
        if (!is_array($config) || ($config['host'] ?? null) !== '127.0.0.1'
            || array_key_exists('dsn', $config) || getenv('DB_DSN')
            || !filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]])
            || ($config['name'] ?? $config['database'] ?? $config['dbname'] ?? null) !== $database
            || ($config['username'] ?? $config['user'] ?? null) !== $database
            || !is_string($config['password'] ?? null)) {
            throw new RuntimeException('БД тестовых учётных записей должна быть явно привязана к loopback.');
        }
        $db = DB::pdo();
        $identity = $db->query('SELECT DATABASE(), CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        if ($identity !== [$database, $database.'@127.0.0.1']
            || count($grants) !== 2 || !str_starts_with($grants[0], 'GRANT USAGE ON *.*')
            || !str_contains($grants[1], ' ON `'.$database.'`.* TO ')) {
            throw new RuntimeException('Фактические реквизиты и права тестовой базы отличаются от подтверждённых.');
        }
        return $db;
    }

    public static function profile(): array
    {
        $profile = self::json('test-login.json');
        if (count($profile) !== count(self::ROLES)) throw new RuntimeException('Нужны ровно пять согласованных тестовых ролей.');
        $accounts = [];
        $logins = [];
        foreach (self::ROLES as $role => $label) {
            $a = $profile[$role] ?? null;
            if (!is_array($a) || !is_string($a['username'] ?? null) || !filter_var($a['username'], FILTER_VALIDATE_EMAIL)
                || strlen($a['username']) > 100 || !is_string($a['name'] ?? null)
                || trim($a['name']) === '' || mb_strlen($a['name']) > 160
                || !is_string($a['password'] ?? null) || $a['password'] === '' || strlen($a['password']) > 72
                || preg_match('/[\x00-\x1f\x7f]/', $a['password']) || isset($logins[strtolower($a['username'])])) {
                throw new RuntimeException('Приватный профиль тестовых ролей заполнен неверно.');
            }
            $logins[strtolower($a['username'])] = true;
            $accounts[$role] = ['label'=>$label, 'username'=>$a['username'], 'name'=>$a['name'], 'password'=>$a['password']];
        }
        return $accounts;
    }

    public static function presets(): array
    {
        $port = getenv('IPPHP_LOCAL_TEST_LOGIN_PORT', true);
        if (PHP_SAPI !== 'cli-server' || PHP_OS_FAMILY !== 'Windows'
            || !$port || !ctype_digit($port) || (string)($_SERVER['SERVER_PORT'] ?? '') !== $port
            || ($_SERVER['SERVER_NAME'] ?? null) !== '127.0.0.1'
            || ($_SERVER['REMOTE_ADDR'] ?? null) !== '127.0.0.1'
            || ($_SERVER['HTTP_HOST'] ?? null) !== '127.0.0.1:'.$port) {
            return [];
        }
        try {
            $settings = self::json('runtime-settings.json');
            $receipt = self::json('runtime.json');
            $expectedSession = $receipt['sessionName'] ?? '';
            if (($settings['test_login_presets'] ?? null) !== true || ($settings['port'] ?? null) !== (int)$port
                || ($receipt['testLoginPresets'] ?? null) !== true || ($receipt['port'] ?? null) !== (int)$port
                || ($receipt['pid'] ?? null) !== getmypid() || self::path((string)($receipt['appRoot'] ?? '')) !== self::path(APP_ROOT)
                || !is_string($expectedSession) || !preg_match('/^IPPHPSESSID_[a-f0-9]{16}$/D', $expectedSession)
                || session_name() !== $expectedSession || getenv('APP_LOCAL_SESSION_NAME', true) !== $expectedSession) {
                return [];
            }
            self::verifiedDatabase();
            return self::profile();
        } catch (\Throwable) {
            return [];
        }
    }
}
