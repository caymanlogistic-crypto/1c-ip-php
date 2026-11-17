<?php
declare(strict_types=1);
namespace App;

final class Runtime
{
    public static function localCaFile(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') return null;
        $path = APP_ROOT.'/.local/certs/windows-trusted-roots.pem';
        if (!is_file($path)) return null;
        $approved = 'b846c5f49517b6de22fcfaa239a9c0561185f22cb9ffc7e2b39ef8fb806c59e0';
        if (is_link($path) || !hash_equals($approved, hash_file('sha256', $path))) {
            throw new \RuntimeException('Project CA bundle differs from the approved local receipt.');
        }
        return $path;
    }

    public static function phpFlags(): array
    {
        foreach (['runtime/upload-tmp', 'runtime/tmp'] as $directory) {
            if (!is_dir(APP_ROOT.'/'.$directory)) mkdir(APP_ROOT.'/'.$directory, 0700, true);
        }
        $flags = [
            '-d', 'zend.exception_ignore_args=1',
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            '-d', 'memory_limit=512M',
            '-d', 'upload_max_filesize=64M',
            '-d', 'post_max_size=80M',
            '-d', 'max_file_uploads=10',
            '-d', 'upload_tmp_dir='.APP_ROOT.'/runtime/upload-tmp',
            '-d', 'sys_temp_dir='.APP_ROOT.'/runtime/tmp',
        ];
        if (!extension_loaded('gd')) array_push($flags, '-d', 'extension=gd');
        if ($ca = self::localCaFile()) {
            array_push($flags, '-d', 'curl.cainfo='.$ca, '-d', 'openssl.cafile='.$ca);
        }
        return $flags;
    }
}
