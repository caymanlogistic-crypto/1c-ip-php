<?php
declare(strict_types=1);

namespace Modules\Integrations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class CabinetSchedule
{
    public const DEFAULT_TIMES = ['09:00', '14:00'];

    public function __construct(private readonly string $directory) {}

    public static function current(): self
    {
        return new self(Config::root().'/private/integrations');
    }

    public function settings(): array
    {
        $state = $this->read();
        $settings = [];
        foreach (CabinetSyncAdmission::PROVIDERS as $provider) {
            $settings[$provider] = $state['settings'][$provider] ?? [
                'times'=>self::DEFAULT_TIMES, 'enabled'=>true, 'version'=>0, 'effective_at'=>null,
            ];
        }
        return $settings;
    }

    public function save(string $provider, mixed $first, mixed $second, bool $enabled, int $version): void
    {
        if (!in_array($provider,CabinetSyncAdmission::PROVIDERS,true)
            || !is_string($first) || !is_string($second)
            || !self::validTime($first) || ($second!==''&&!self::validTime($second)) || $first===$second) {
            throw new InvalidArgumentException('Укажите разные времена в формате ЧЧ:ММ.');
        }
        $times = $second==='' ? [$first] : [$first,$second];
        sort($times);
        $this->update(static function(array &$state) use($provider,$times,$enabled,$version): bool {
            $old = $state['settings'][$provider]??null;
            if (($old['version']??0)!==$version) throw new RuntimeException('Расписание уже изменилось. Обновите страницу.',409);
            if (($old['times']??self::DEFAULT_TIMES)===$times&&($old['enabled']??true)===$enabled) return false;
            $state['settings'][$provider]=[
                'times'=>$times, 'enabled'=>$enabled, 'version'=>$version+1,
                'effective_at'=>(new DateTimeImmutable())->getTimestamp(),
            ];
            return true;
        });
    }

    public function due(array $admitted, DateTimeImmutable $now): array
    {
        return $this->slots($this->read(),$admitted,$now);
    }

    public function claim(array $admitted, DateTimeImmutable $now): array
    {
        $claimed=[];
        $this->update(function(array &$state)use($admitted,$now,&$claimed): bool {
            $claimed=$this->slots($state,$admitted,$now);
            if (!$claimed) return false;
            $earliest=$now->getTimestamp()-172800;
            foreach ($state['claimed'] as $provider=>$slots) {
                $state['claimed'][$provider]=array_filter($slots,static fn(int $time):bool=>$time >= $earliest);
            }
            foreach($claimed as $provider=>$slot) {
                $state['claimed'][$provider][$slot]=$now->getTimestamp();
            }
            return true;
        });
        return $claimed;
    }

    private function slots(array $state,array $admitted,DateTimeImmutable $now): array
    {
        $now=$now->setTimezone(new DateTimeZone('Europe/Moscow'));
        $window=$now->modify('-60 minutes');
        $due=[];
        foreach (array_intersect(CabinetSyncAdmission::PROVIDERS,$admitted) as $provider) {
            $setting=$state['settings'][$provider]??null;
            if ($setting!==null&&!$setting['enabled']) continue;
            foreach (array_unique([$window->format('Y-m-d'),$now->format('Y-m-d')]) as $date) {
                foreach ($setting['times']??self::DEFAULT_TIMES as $time) {
                    $slot=$date.' '.$time;
                    $when=new DateTimeImmutable($slot,$now->getTimezone());
                    if ($when>$now||$when<=$window||$when->getTimestamp()<($setting['effective_at']??0)
                        || isset($state['claimed'][$provider][$slot])) continue;
                    $due[$provider]=$slot;
                    continue 3;
                }
            }
        }
        return $due;
    }

    private static function validTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)===1;
    }

    private function read(): array
    {
        $path=$this->directory.'/cabinet-schedule.json';
        if (is_link($path)) throw new RuntimeException('CABINET_SCHEDULE_INVALID');
        if (!is_file($path)) return ['settings'=>[], 'claimed'=>[]];
        if (filesize($path)>131072) throw new RuntimeException('CABINET_SCHEDULE_INVALID');
        $state=Support::decode(file_get_contents($path));
        if (!is_array($state)||!is_array($state['settings']??null)||!is_array($state['claimed']??null)) {
            throw new RuntimeException('CABINET_SCHEDULE_INVALID');
        }
        return $state;
    }

    private function update(callable $edit): void
    {
        if (!is_dir($this->directory)&&!@mkdir($this->directory,0700,true)&&!is_dir($this->directory)) {
            throw new RuntimeException('CABINET_SCHEDULE_WRITE_FAILED');
        }
        $lockPath=$this->directory.'/cabinet-schedule.lock';
        if (is_link($lockPath)) throw new RuntimeException('CABINET_SCHEDULE_INVALID');
        $lock=fopen($lockPath,'c');
        if ($lock===false) throw new RuntimeException('CABINET_SCHEDULE_WRITE_FAILED');
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('CABINET_SCHEDULE_WRITE_FAILED');
            $state=$this->read();
            if (!$edit($state)) return;
            $tmp=$this->directory.'/cabinet-schedule-'.bin2hex(random_bytes(12));
            try {
                $bytes=Support::json($state);
                if (file_put_contents($tmp,$bytes)!==strlen($bytes)
                    || !chmod($tmp,0600) || !rename($tmp,$this->directory.'/cabinet-schedule.json')) {
                    throw new RuntimeException('CABINET_SCHEDULE_WRITE_FAILED');
                }
            } finally {
                if (is_file($tmp)) unlink($tmp);
            }
        } finally {
            fclose($lock);
        }
    }
}
