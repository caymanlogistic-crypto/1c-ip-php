<?php
declare(strict_types = 1);
namespace Modules\Rental;
final class People {
    public const LABELS = [
        'manager' => 'Менеджер по аренде',
        'lawyer' => 'Юрист',
        'accountant' => 'Глав. бухгалтер',
        'director' => 'Руководитель',
        'admin' => 'Администратор'
    ];

    public static function team(): array {
        return Controller::query("SELECT u.id,u.username,u.name,u.role role_id,u.version,u.auth_version,CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END workflow_kind FROM users u LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.active=1 AND (u.role='admin' OR rp.kind IN ('manager','lawyer','accountant','director')) ORDER BY u.id");
    }

    public static function label(string $kind): string {
        return \App\People::workflowName($kind);
    }
}
