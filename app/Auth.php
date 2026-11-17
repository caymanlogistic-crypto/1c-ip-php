<?php
declare(strict_types = 1);
namespace App;
final class Auth {
    public static function user():?array {
        if (empty($_SESSION['user_id'])) return null;
        $q = DB::pdo()->prepare("SELECT u.id,u.username,u.name,u.role role_id,u.version,u.auth_version,r.name role_label,r.grants_configured,CASE WHEN rp.kind IN('manager','lawyer','accountant','director') THEN rp.kind ELSE NULL END workflow_role,CASE WHEN u.role='admin' THEN 'admin' WHEN rp.kind IN('manager','lawyer','accountant','director') THEN rp.kind ELSE 'viewer' END role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=? AND u.active=1");
        $q->execute([
            $_SESSION['user_id']
        ]);
        $u = $q->fetch();
        if (!$u || (int)($_SESSION['auth_version'] ?? 0) !== (int)$u['auth_version']) {
            unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['csrf']);
            return null;
        }
        return $u;
    }
    public static function requirePermission(string $key):void {
        $u = self::user();
        if (!$u) {
            if (PHP_SAPI !== 'cli') Http::redirect('/login');
            throw new \RuntimeException('Требуется вход', 401);
        }
        if (!self::can($key))throw new \RuntimeException('Недостаточно прав', 403);
    }
    public static function can(string $key):bool {
        $u = self::user();
        if (!$u)return false;
        $q = DB::pdo()->prepare('SELECT 1 FROM role_permissions WHERE role_id=? AND (permission_key=? OR permission_key=?) LIMIT 1');
        $q->execute([
            $u['role_id'],
            $key,
            '*'
        ]);
        if (!$q->fetchColumn())return false;
        if (!empty($u['grants_configured']) && in_array($key, [
            'rental.read',
            'rental.write'
        ], true)) {
            $q = DB::pdo()->prepare("SELECT 1 FROM role_page_grants WHERE role_id=? AND (page_key LIKE 'rental.%' OR page_key LIKE 'auxiliary.%') AND level>=? LIMIT 1");
            $q->execute([
                $u['role_id'],
                $key === 'rental.read'?1:2
            ]);
            return (bool)$q->fetchColumn();
        }
        return true;
    }
    public static function landing():string {
        if (!self::can('rental.read'))return '/profile';
        return in_array(self::user()['role'] ?? null, [
            'admin',
            'manager'
        ], true)?'/rental/contracts':'/rental/contracts-active';
    }
    public static function login(string $name, string $password):bool {
        $q = DB::pdo()->prepare('SELECT * FROM users WHERE username=? AND active=1');
        $q->execute([
            $name
        ]);
        $u = $q->fetch();
        if (!$u || !password_verify($password, $u['password_hash']))return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['auth_version'] = (int)$u['auth_version'];
        unset($_SESSION['csrf']);
        try {
            Audit::record('login', 'user', $u['id']);
        }
        catch (\Throwable $error) {
            unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['csrf']);
            throw $error;
        }
        return true;
    }
}
