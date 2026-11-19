<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Explicit server opt-in for the scheduler. This does not enable sign-in or external writes. */
final class CabinetSyncAdmission
{
    public const PROVIDERS = ['diadoc','mosenergo','mosvodokanal','pik'];
    public const FROM = '2026-10-01';

    public static function configured(): array
    {
        $path = Config::root().'/.local/cabinet-hourly-read.json';
        if (PHP_SAPI!=='cli' || getenv('APP_STAND')!=='ui' || !is_file($path)) return [];
        if (is_link($path) || !is_readable($path) || filesize($path)>4096) {
            throw new \RuntimeException('CABINET_ADMISSION_INVALID');
        }
        $value = json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['scope']??null)!=='hourly-cabinet-read' || !is_array($value['profiles']??null)
            || array_diff(array_keys($value['profiles']),self::PROVIDERS)) {
            throw new \RuntimeException('CABINET_ADMISSION_INVALID');
        }
        foreach ($value['profiles'] as $profile) {
            if (!is_array($profile) || count($profile)!==2 || !is_string($profile['reference']??null)
                || Config::reference($profile['reference'])!==$profile['reference']
                || !is_int($profile['revision']??null) || $profile['revision']<1) {
                throw new \RuntimeException('CABINET_ADMISSION_INVALID');
            }
        }
        return $value['profiles'];
    }

    public static function allows(\PDO $db,string $provider,string $operation,?string $reference=null): bool
    {
        $operations = ['sync','accounts','records','original','receipt_print_metadata','receipt_source_file','receipt_pdf','selected_native_print_pdf'];
        if ($operation==='authenticate' && getenv('IPPHP_CABINET_OWNER_AUTH')==='approved-interactive-restore') {
            $operations[]='authenticate';
        }
        if (!in_array($provider,self::PROVIDERS,true) || !in_array($operation,$operations,true)) return false;
        $profile = self::configured()[$provider]??null;
        if (!$profile) return false;
        $connection = Config::get($db,$provider);
        $selected = Config::reference(Support::decode($connection['config_json'])['access_reference']??'');
        return (bool)$connection['enabled'] && (int)$connection['revision']===$profile['revision']
            && $selected===$profile['reference'] && ($reference===null||$reference===$selected);
    }
}
