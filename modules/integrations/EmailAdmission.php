<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Operator-owned server opt-in for manual SMTP, independent of rental delivery. */
final class EmailAdmission
{
    public static function settings(): ?array
    {
        if(getenv('APP_STAND')!=='ui')return null;
        $file=Config::root().'/.local/email-admission.json';
        if(!file_exists($file))return null;
        if(is_link($file)||!is_readable($file)||filesize($file)>4096)throw new \RuntimeException('EMAIL_ADMISSION_INVALID');
        $value=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        self::validate($value);
        return $value;
    }

    public static function validate(mixed $value): void
    {
        $expected=['enabled'=>true,'project_id'=>'f6e21e3c-d974-4604-82fb-333da66559d2',
            'provider'=>'email','scope'=>'manual-smtp','stand'=>'ui','smtp_host'=>'smtp.spaceweb.ru','smtp_port'=>465];
        if(!is_array($value)||count($value)!==count($expected))throw new \RuntimeException('EMAIL_ADMISSION_INVALID');
        foreach($expected as $key=>$item)if(($value[$key]??null)!==$item)throw new \RuntimeException('EMAIL_ADMISSION_INVALID');
    }

    public static function allows(array $config): bool
    {
        $settings=self::settings();
        return $settings!==null&&($config['host']??null)===$settings['smtp_host']
            &&($config['port']??null)===$settings['smtp_port']&&($config['security']??null)==='ssl';
    }
}
