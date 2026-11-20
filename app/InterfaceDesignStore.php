<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

final class InterfaceDesignStore
{
    private static function path(string $id): string
    {
        if (!isset(InterfaceDesign::SCREENS[$id])) throw new RuntimeException('Неизвестный экран.',404);
        return APP_ROOT.'/storage/interface-design/'.$id.'.json';
    }

    public static function read(string $id): array
    {
        $path = self::path($id);
        clearstatcache(true,$path);
        if (!is_file($path)) return ['schema'=>1,'revision'=>0,'applied'=>null,'draft'=>null,'previous'=>null];
        $state = json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (($state['schema']??null)!==1 || !is_int($state['revision']??null)) throw new RuntimeException('Unsupported interface design storage.');
        return $state;
    }

    public static function change(string $id, int $revision, string $action, mixed $layout=null): array
    {
        $path = self::path($id);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Interface design directory unavailable.');
        $lock = fopen($path.'.lock','c');
        if (!$lock) throw new RuntimeException('Interface design lock unavailable.');
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('Interface design lock unavailable.');
            $state = self::read($id);
            if ($state['revision']!==$revision) throw new RuntimeException('Компоновка изменилась. Откройте экран заново.',409);
            switch ($action) {
                case 'draft':
                    $state['draft'] = InterfaceDesign::normalize($id,$layout);
                    break;
                case 'apply':
                    if ($state['draft']===null) throw new RuntimeException('Сначала сохраните черновик.',409);
                    $state['previous'] = $state['applied'] ?? InterfaceDesign::defaults($id);
                    $state['applied'] = InterfaceDesign::normalize($id,$state['draft']);
                    $state['draft'] = null;
                    break;
                case 'restore':
                    if ($state['previous']===null) throw new RuntimeException('Предыдущая версия отсутствует.',409);
                    [$state['applied'],$state['previous']] = [$state['previous'],$state['applied']];
                    break;
                default:
                    throw new RuntimeException('Неизвестное действие.',400);
            }
            $state['revision']++;
            $bytes = json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try {
                if (file_put_contents($temp,$bytes)!==strlen($bytes) || !rename($temp,$path)) throw new RuntimeException('Interface design could not be stored.');
            } finally {
                if (is_file($temp)) unlink($temp);
            }
            return $state;
        } finally {
            fclose($lock);
        }
    }
}
