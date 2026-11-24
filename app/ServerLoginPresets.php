<?php
declare(strict_types=1);

namespace App;

final class ServerLoginPresets
{
    private const COOKIE='__Host-IPPHPRolePresets';
    private const ROLES=[
        'manager'=>'Раиса — менеджер',
        'lawyer'=>'Наиль — юрист',
        'accountant'=>'Наталия — бухгалтер',
        'director'=>'Андрей — директор',
        'admin'=>'Владимир — администратор',
    ];

    public static function view(): array
    {
        $profile=self::profile();
        return $profile!==null && self::authorized($profile)?self::ROLES:[];
    }

    public static function fill(): never
    {
        Http::checkCsrf();
        header('Cache-Control: no-store');
        $profile=self::profile();
        if ($profile===null || !self::authorized($profile)) {
            Http::json(['error'=>'Кнопки ролей недоступны.'],403);
        }
        $role=$_POST['role']??null;
        if (!is_string($role) || !isset(self::ROLES[$role])) {
            Http::json(['error'=>'Выберите роль.'],422);
        }
        Http::json($profile['accounts'][$role]);
    }

    private static function profile(): ?array
    {
        if (getenv('APP_STAND')!=='ui') return null;
        $path=APP_ROOT.'/.local/server-login-presets.json';
        if (is_link($path)||!is_file($path)||!is_readable($path)||filesize($path)>8192) return null;
        $profile=json_decode((string)file_get_contents($path),true);
        if (!is_array($profile) || !is_string($profile['key']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$profile['key']) || !is_array($profile['accounts']??null)) return null;
        foreach (self::ROLES as $role=>$label) {
            $account=$profile['accounts'][$role]??null;
            if (!is_array($account)||!is_string($account['username']??null)||trim($account['username'])===''
                ||!is_string($account['password']??null)||$account['password']==='') return null;
            $profile['accounts'][$role]=['username'=>$account['username'],'password'=>$account['password']];
        }
        return $profile;
    }

    private static function signature(string $principal,array $profile): string
    {
        return hash_hmac('sha256','server-role-presets:'.$principal,$profile['key']);
    }

    private static function authorized(array $profile): bool
    {
        $cookie=$_COOKIE[self::COOKIE]??null;
        if (!is_string($cookie)||!preg_match('/^([0-9]{1,10})\.([0-9]{1,10})\.([a-f0-9]{64})$/D',$cookie,$match)
            ||!hash_equals(self::signature($match[1].'.'.$match[2],$profile),$match[3])) return false;
        $q=DB::pdo()->prepare("SELECT 1 FROM users WHERE id=? AND auth_version=? AND username=? AND role='admin' AND active=1");
        $q->execute([(int)$match[1],(int)$match[2],$profile['accounts']['admin']['username']]);
        return (bool)$q->fetchColumn();
    }
}
