<?php
declare(strict_types=1);
namespace Modules\Rental;

use DateTimeImmutable;
use RuntimeException;

final class PaymentDayCalendar
{
    public static function after(string $date, int $days, string $unit): array
    {
        $start = new DateTimeImmutable(Service::date($date));
        if ($unit === 'calendar') return ['date'=>$start->modify("+$days days")->format('Y-m-d')];
        if ($unit === 'working') {
            try {
                $calendar = SourceCalendar::registered();
            } catch (RuntimeException) {
                $calendar = null;
            }
            if (!$calendar) return ['question'=>'Рабочий календарь не подтверждён.'];
            $coverage = $calendar->metadata();
            $isDay = $calendar->isWorkingDate(...);
            $source = 'Локальный эталон рабочего календаря 2026 года';
        } elseif ($unit === 'banking') {
            $calendar = self::banking();
            if (!$calendar) return ['question'=>'Нужен подтверждённый банковский календарь.'];
            $coverage = $calendar;
            $bankDays = array_fill_keys($calendar['days'], true);
            $isDay = static fn(string $day): bool => isset($bankDays[$day]);
            $source = $calendar['source'];
        } else {
            throw new RuntimeException('Неизвестный вид календаря.');
        }
        $day = $date;
        $cursor = $start;
        while ($day >= $coverage['valid_from'] && $day <= $coverage['valid_to']) {
            if ($days === 0) return ['date'=>$day, 'source'=>$source];
            $cursor = $cursor->modify('+1 day');
            $day = $cursor->format('Y-m-d');
            if ($day <= $coverage['valid_to'] && $isDay($day)) $days--;
        }
        return ['question'=>'Срок выходит за подтверждённый охват календаря. Нужна сверка сотрудника.'];
    }

    private static function banking(): ?array
    {
        // The operator supplies an admitted source with explicit daily coverage. No weekday fallback.
        $path = getenv('APP_RENTAL_BANKING_CALENDAR_FILE');
        $pin = getenv('APP_RENTAL_BANKING_CALENDAR_SHA256');
        if (!$path || !$pin || !is_file($path) || !is_readable($path)) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false || !hash_equals($pin, hash('sha256', $bytes))) return null;
        $data = json_decode($bytes, true);
        if (($data['unit'] ?? '') !== 'banking' || ($data['admitted'] ?? false) !== true
            || !is_string($data['source'] ?? null) || trim($data['source']) === ''
            || !is_array($data['days'] ?? null) || !array_is_list($data['days'])) return null;
        if (!empty($data['test_only'])
            && (PHP_SAPI !== 'cli-server' || getenv('APP_STAND')
                || !str_starts_with(str_replace('\\','/',(string)realpath($path)),str_replace('\\','/',APP_ROOT).'/.local/'))) return null;
        try {
            $from = Service::date($data['valid_from'] ?? '');
            $to = Service::date($data['valid_to'] ?? '');
            if ($to < $from) return null;
            foreach ($data['days'] as $value) {
                $day = Service::date($value);
                if ($day < $from || $day > $to) return null;
            }
        } catch (\TypeError|RuntimeException) {
            return null;
        }
        return $data;
    }
}
