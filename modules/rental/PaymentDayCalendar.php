<?php
declare(strict_types=1);
namespace Modules\Rental;

use DateTimeImmutable;
use RuntimeException;

final class PaymentDayCalendar
{
    public static function after(string $date, int $days, string $unit, bool $operative = false, bool $proof = false): array
    {
        $start = new DateTimeImmutable(Service::date($date));
        if ($unit === 'calendar') return ['date'=>$start->modify("+$days days")->format('Y-m-d')];
        if ($unit === 'working' && !$operative) {
            try {
                $calendar = SourceCalendar::registered();
            } catch (RuntimeException) {
                $calendar = null;
            }
            if (!$calendar) return ['question'=>'Рабочий календарь не подтверждён.'];
            $coverage = $calendar->metadata();
            $isDay = $calendar->isWorkingDate(...);
            $source = 'Локальный эталон рабочего календаря 2026 года';
        } elseif (in_array($unit,['banking','working'],true)) {
            $calendar = self::approved($unit);
            if (!$calendar) return ['question'=>$unit === 'banking'
                ? 'Нужен подтверждённый банковский календарь.' : 'Нужен подтверждённый рабочий календарь.'];
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
            if ($days === 0) return ['date'=>$day,'source'=>$source]+($proof
                ? ['revision'=>hash('sha256',Importer::canonicalJson($coverage)),'valid_from'=>$coverage['valid_from'],'valid_to'=>$coverage['valid_to']]
                : []);
            $cursor = $cursor->modify('+1 day');
            $day = $cursor->format('Y-m-d');
            if ($day <= $coverage['valid_to'] && $isDay($day)) $days--;
        }
        return ['question'=>'Срок выходит за подтверждённый охват календаря. Нужна сверка сотрудника.'];
    }

    public static function preparation(string $period): array
    {
        $target = new DateTimeImmutable(RegularInvoice::period($period).'-01');
        $day = $target->modify('-1 month')->format('Y-m-17');
        $previous = (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
        $next = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
        $source = self::approved('working');
        if ($source) {
            $days = array_fill_keys($source['days'],true);
            $isWorking = static fn(string $date): bool => isset($days[$date]);
            $label = $source['source'];
            $revision = hash('sha256',Importer::canonicalJson($source));
        } else {
            try {
                $local = in_array(PHP_SAPI,['cli','cli-server'],true) && !getenv('APP_STAND') ? SourceCalendar::registered() : null;
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException) throw $error;
                $local = null;
            }
            if (!$local) return ['question'=>'Подтвердите рабочий календарь для даты подготовки счетов.'];
            $source = $local->metadata();
            $isWorking = $local->isWorkingDate(...);
            $label = 'Локальный рабочий календарь 2026 года';
            $revision = SourceCalendar::REFERENCE;
        }
        $known = static fn(string $date): bool => $date >= $source['valid_from'] && $date <= $source['valid_to'];
        if (!$known($day)) return ['question'=>'Дата подготовки выходит за подтверждённые границы рабочего календаря.'];
        $result = ['date'=>$day,'source'=>$label,'revision'=>$revision];
        if ($isWorking($day)) return $result;
        if (!$known($previous) || !$known($next))
            return ['question'=>'Для переноса с 17-го подтвердите рабочие дни по обе стороны даты.'];
        if (!$isWorking($previous) || !$isWorking($next))
            return ['question'=>'17-е входит в несколько нерабочих дней подряд. Бухгалтеру нужно подтвердить дату подготовки.'];
        return array_replace($result,['date'=>$previous]);
    }

    private static function approved(string $unit): ?array
    {
        // The operator supplies an admitted source with explicit daily coverage. No weekday fallback.
        $prefix = 'APP_RENTAL_'.strtoupper($unit).'_CALENDAR_';
        $path = getenv($prefix.'FILE');
        $pin = getenv($prefix.'SHA256');
        if (!$path || !$pin || !is_file($path) || !is_readable($path)) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false || !hash_equals($pin, hash('sha256', $bytes))) return null;
        $data = json_decode($bytes, true);
        if (($data['unit'] ?? '') !== $unit || ($data['admitted'] ?? false) !== true
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
