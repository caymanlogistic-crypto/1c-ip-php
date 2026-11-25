<?php
declare(strict_types=1);

namespace App;

final class ServerLoginPresets
{
    private const ROLES = [
        'manager' => 'Менеджер',
        'lawyer' => 'Юрист',
        'accountant' => 'Бухгалтер',
        'admin' => 'Администратор',
    ];

    public static function view(): array
    {
        if (getenv('APP_STAND') !== 'ui') return [];

        $path = APP_ROOT.'/.local/server-login-names.json';
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > 2048) return [];

        $names = json_decode((string)file_get_contents($path), true);
        if (!is_array($names) || count($names) !== count(self::ROLES)) return [];

        $presets = [];
        foreach (self::ROLES as $role => $label) {
            $username = $names[$role] ?? null;
            if (!is_string($username) || strlen($username) > 100) return [];
            if (!preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/D', $username)
                && filter_var($username, FILTER_VALIDATE_EMAIL) === false) return [];
            $presets[] = ['username' => $username, 'label' => $label];
        }
        return $presets;
    }
}
