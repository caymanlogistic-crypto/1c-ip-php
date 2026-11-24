<?php
declare(strict_types=1);
namespace Modules\Integrations;

use PDO;
use RuntimeException;

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

    /** Register/edit inactive rows without rewriting the token or invalidating active admissions. */
    public static function stageDisabled(PDO $db,int $revision,array $secret,array $groups,bool $enabled): bool
    {
        $db->beginTransaction();
        try {
            $row=$db->query("SELECT enabled,revision FROM integration_connections WHERE provider='max' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            if ((int)$row['revision']!==$revision) throw new RuntimeException('REVISION_CONFLICT');
            $stored=$db->query("SELECT chat_id,name,enabled FROM messaging_groups WHERE provider='max' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $active=array_filter($groups,static fn(array $g):bool=>$g['enabled']);
            $storedActive=array_values(array_filter($stored,static fn(array $g):bool=>(bool)$g['enabled']));
            if (!self::unchanged($row,Config::secrets('max'),$secret,$active,$storedActive,$enabled)) return false;
            $write=$db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name,enabled) VALUES(?,'max',?,?,0)
                ON DUPLICATE KEY UPDATE name=VALUES(name)");
            foreach (array_filter($groups,static fn(array $g):bool=>!$g['enabled']) as $chat=>$group) {
                $write->execute([Support::uuid(),$chat,$group['name']]);
            }
            $db->commit();
            return true;
        } finally {
            if ($db->inTransaction()) $db->rollBack();
        }
    }
}
