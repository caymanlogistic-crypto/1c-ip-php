<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Operator configuration for the website's bounded OneC check, never sends or imports. */
final class OnecReadAdmission
{
    public static function settings(): ?array
    {
        if (getenv('APP_STAND') !== 'ui') return null;
        $file = Config::root().'/.local/onec-read-admission.json';
        if (!file_exists($file)) return null;
        if (is_link($file) || !is_readable($file) || filesize($file) > 4096) throw new \RuntimeException('ONEC_READ_ADMISSION_INVALID');
        $value = json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        $keys = is_array($value) ? array_keys($value) : []; sort($keys);
        if ($keys !== ['enabled','profile_reference','project_id','provider','scope','stand']
            || $value['enabled'] !== true || $value['stand'] !== 'ui' || $value['provider'] !== 'onec'
            || $value['project_id'] !== 'f6e21e3c-d974-4604-82fb-333da66559d2'
            || $value['scope'] !== 'bounded-check-only'
            || !is_string($value['profile_reference']) || Config::reference($value['profile_reference']) !== $value['profile_reference']) {
            throw new \RuntimeException('ONEC_READ_ADMISSION_INVALID');
        }
        return $value;
    }

    public static function allows(\PDO $db, string $provider, string $operation, ?array $secret = null): bool
    {
        if ($provider !== 'onec' || !in_array($operation,['check','authenticate','records'],true)) return false;
        $admission = self::settings();
        if ($admission === null) return false;
        $connection = Config::get($db,'onec');
        $public = Support::decode($connection['config_json']);
        $reference = $admission['profile_reference'];
        if (!$connection['enabled'] || ($public['access_reference']??null) !== $reference) return false;
        if ($secret !== null) {
            if (($secret['_reference']??null) !== $reference) return false;
            $selected = Config::profile('onec',$reference);
            foreach (['username','password'] as $field) {
                if (!is_string($secret[$field]??null) || empty($selected[$field]) || !hash_equals($selected[$field],$secret[$field])) return false;
            }
        }
        return true;
    }
}
