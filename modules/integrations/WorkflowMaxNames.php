<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\People;
use PDO;

/** Display names for contract notifications, independent of workflow authorization. */
final class WorkflowMaxNames
{
    // Proven user IDs from the historical Core copy test import.
    private const CORE_COPY_TEST_USERS = [990001202, 990001203, 990001204, 990001205, 990001206, 990001207];
    private const ROLES = ['manager', 'lawyer', 'accountant', 'director'];

    public static function fromDatabase(PDO $db): array
    {
        $people = $db->query('SELECT u.id,u.name,rp.kind FROM users u JOIN role_profiles rp ON rp.role_id=u.role
            WHERE u.active=1 ORDER BY u.id');
        return self::byRole($people->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function byRole(iterable $people): array
    {
        $names = array_fill_keys(self::ROLES, '');
        $testRoles = [];
        foreach ($people as $person) {
            $role = $person['kind'];
            if (!array_key_exists($role, $names)) continue;
            if (self::isCoreCopyUser((int)$person['id'])) {
                $testRoles[$role] = true;
                continue;
            }
            $names[$role] .= ($names[$role] !== '' ? ', ' : '').$person['name'];
        }
        foreach ($testRoles as $role => $_) {
            if ($names[$role] === '') $names[$role] = People::roleLabel($role);
        }
        return $names;
    }

    public static function display(int $id, string $name, string $role): string
    {
        return self::isCoreCopyUser($id) ? People::roleLabel($role) : $name;
    }

    private static function isCoreCopyUser(int $id): bool
    {
        return in_array($id, self::CORE_COPY_TEST_USERS, true);
    }
}
