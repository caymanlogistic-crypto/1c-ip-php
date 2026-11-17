<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\View;
use DateTimeImmutable;
use DateTimeZone;

/** Source C journal components; accepts only the controller's public event DTO. */
final class JournalView
{
    private static function time(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})?$/D', $value, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
            || (int)$parts[4]>23 || (int)$parts[5]>59 || (int)$parts[6]>59) {
            return null;
        }
        try {
            $moscow=new DateTimeZone('Europe/Moscow');
            return (new DateTimeImmutable($value, $moscow))->setTimezone($moscow);
        } catch (\Exception) {
            return null;
        }
    }

    public static function registry(array $entries, string $query): string
    {
        $rows=[];
        foreach ($entries as $entry) {
            $time=self::time((string)$entry['created_at']);
            $contract=trim((string)($entry['contract_number'] ?? ''));
            $rows[]=[
                '_attrs'=>'data-rental-url="/rental/journal-entry?id='.(int)$entry['id'].'" tabindex="0"',
                $time?$time->format('d.m.Y').'<br>'.$time->format('H:i'):'—',
                View::e($entry['employee']),
                View::e($contract!==''?$contract:'Общие сведения'),
                View::e($entry['description']),
            ];
        }
        $controls='<form method="get" data-rental-registry-filter>'.DirectoryUi::search($query).'</form>';
        return Controller::heading('Журнал','Действия, решения, изменения и отправленные документы.')
            .DirectoryUi::registry('rental-journal', ['Дата / время','Сотрудник','Договор','Действие и сведения'],
                $rows, ['code','text','short','flex'], $controls);
    }

    public static function entry(array $entry): string
    {
        $time=self::time((string)$entry['created_at']);
        $date=$time?$time->format('d.m').'<br>'.$time->format('Y').'<br>'.$time->format('H:i'):'—';
        $title=$time?$time->format('d.m.Y H:i').' Москва':'Дата не подтверждена';
        $body='<div class="activity-list"><div class="activity-row">'
            .'<span class="activity-date" title="'.View::e($title).'">'.$date.'</span>'
            .'<div class="activity-main"><span class="activity-title">'.View::e($entry['description']).'</span>'
            .'<span class="registry-ui-secondary">'.View::e($entry['employee']).'</span></div></div></div>';
        return RentalUI::modal('Запись журнала', $body, '', 'journal');
    }
}
