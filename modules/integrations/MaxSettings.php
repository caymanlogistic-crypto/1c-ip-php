<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class MaxSettings
{
    /** Saving identical fields must preserve the credential/admission revision. */
    public static function unchanged(array $old, array $secret, array $nextSecret, array $groups, array $storedGroups, bool $enabled): bool
    {
        if ((bool)$old['enabled'] !== $enabled || $secret !== $nextSecret) return false;
        $stored = [];
        foreach ($storedGroups as $group) $stored[$group['chat_id']] = ['name'=>$group['name'], 'enabled'=>(bool)$group['enabled']];
        ksort($stored, SORT_STRING);
        ksort($groups, SORT_STRING);
        return $stored === $groups;
    }
}
