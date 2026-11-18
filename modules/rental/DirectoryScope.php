<?php
declare(strict_types = 1);
namespace Modules\Rental;
final class DirectoryScope {

    public static function realCadastre(array $row): bool {
        $raw=json_decode($row['source_json'] ?? '{}',true) ?: [];
        return empty($raw['workflowDemo']) && empty($raw['synthetic']) && !preg_match('/демо|demo/iu',$row['number'] ?? '');
    }

    public static function realObject(array $object,array $parent): bool {
        $raw=json_decode($object['source_json'] ?? '{}',true) ?: [];
        return empty($raw['workspace_cleared']) && empty($raw['workflowDemo']) && empty($raw['synthetic']) && self::realCadastre($parent);
    }

    public static function cadastreAvailable(array $row): bool {
        return ($row['rentable'] ?? null)!==null ? (bool)$row['rentable'] : ($row['type'] ?? '')!=='Коридор';
    }

    public static function signals(array $object, array $cadastre): bool {
        $o = json_decode($object['source_json'] ?? '{}', true) ?? [
        ];
        return !empty($object['active']) && self::realObject($object,$cadastre) && self::cadastreAvailable($cadastre)
            && ($o['rentable'] ?? true) !== false && ($o['availableForRent'] ?? true) !== false && ($o['unavailable'] ?? false) !== true;
    }
}
