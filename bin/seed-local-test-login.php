<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Audit, DB, LocalTestLogin};

try {
    if (PHP_SAPI !== 'cli' || $argv !== [$argv[0], '--apply']) {
        throw new RuntimeException('Использование: php bin/seed-local-test-login.php --apply');
    }
    LocalTestLogin::verifiedDatabase();
    $accounts = LocalTestLogin::profile();
    $changed = DB::transaction(static function(PDO $db) use ($accounts): int {
        if (!$db->query('SELECT id FROM identity_write_guard WHERE id=1 FOR UPDATE')->fetchColumn()) {
            throw new RuntimeException('Блокировка записей пользователей недоступна.');
        }
        $roleQuery = $db->prepare('SELECT r.id,p.kind FROM roles r LEFT JOIN role_profiles p ON p.role_id=r.id WHERE r.id=?');
        $find = $db->prepare('SELECT id,name,role,active,password_hash FROM users WHERE username=? FOR UPDATE');
        $insert = $db->prepare('INSERT INTO users(username,name,role,password_hash,active) VALUES(?,?,?,?,1)');
        $update = $db->prepare('UPDATE users SET name=?,role=?,password_hash=?,active=1,version=version+1,auth_version=auth_version+1 WHERE id=?');
        $changed = 0;
        foreach ($accounts as $role=>$account) {
            $roleQuery->execute([$role]);
            $currentRole = $roleQuery->fetch();
            if (!$currentRole || ($role !== 'admin' && $currentRole['kind'] !== $role)) {
                throw new RuntimeException('Одна из согласованных тестовых ролей отсутствует в локальной базе.');
            }
            $username = $account['username'];
            $name = $account['name'];
            $password = $account['password'];
            $find->execute([$username]);
            $old = $find->fetch();
            if ($old && $old['name'] === $name && $old['role'] === $role && (int)$old['active'] === 1
                && password_verify($password, $old['password_hash'])) continue;
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($old) {
                $id = (int)$old['id'];
                $update->execute([$name, $role, $hash, $id]);
            } else {
                $insert->execute([$username, $name, $role, $hash]);
                $id = (int)$db->lastInsertId();
            }
            Audit::record($old ? 'user.update' : 'user.create', 'user', $id, null, [
                'username'=>$username, 'role'=>$role, 'source'=>'local-test-login',
            ]);
            ++$changed;
        }
        return $changed;
    });
    echo 'Локальный профиль применён. Изменено записей: '.$changed.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RuntimeException && !($error instanceof PDOException)
        ? $error->getMessage() : 'Не удалось применить локальный тестовый профиль.').PHP_EOL);
    exit(1);
}
