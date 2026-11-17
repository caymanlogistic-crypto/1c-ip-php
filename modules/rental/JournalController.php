<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{People,View};
use RuntimeException;

/** Current journal permission and director role are checked before every event query. */
final class JournalController
{
    private static function guard(): void
    {
        Access::pages(['journal']);
        Service::roles(['director']);
    }

    public static function entryRow(array $row): array
    {
        $employee=trim((string)($row['actor_name'] ?? ''));
        if ($employee==='') {
            $employee=($row['actor_role'] ?? '')==='system'
                ?'Система':People::roleLabel($row['actor_role'] ?? null);
        }
        return [
            'id'=>(int)$row['id'],
            'created_at'=>$row['created_at'],
            'employee'=>$employee,
            'contract_number'=>$row['contract_number'] ?? null,
            'description'=>$row['description'],
            'action'=>$row['action'],
        ];
    }

    public static function page(): string
    {
        self::guard();
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $entries=[];
        foreach (Controller::query('SELECT e.id,e.created_at,e.actor_name,e.actor_role,e.description,e.action,c.number contract_number '
            .'FROM rental_events e LEFT JOIN contracts c ON c.id=e.contract_id '
            .'WHERE e.contract_id IS NULL OR c.id IS NOT NULL ORDER BY e.created_at DESC,e.id DESC') as $row) {
            $entry=self::entryRow($row);
            if (WorkRegistry::matches($query,[$entry['employee'],$entry['description'],$entry['contract_number']])) {
                $entries[]=$entry;
            }
        }
        return View::page('Журнал',JournalView::registry($entries,$query),'journal');
    }

    public static function entry(): string
    {
        self::guard();
        $id=(string)($_GET['id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || bccomp($id,(string)PHP_INT_MAX,0)>0) {
            throw new RuntimeException('Запись журнала не найдена.',404);
        }
        $row=Controller::query('SELECT e.id,e.created_at,e.actor_name,e.actor_role,e.description,e.action,c.number contract_number '
            .'FROM rental_events e LEFT JOIN contracts c ON c.id=e.contract_id '
            .'WHERE e.id=? AND (e.contract_id IS NULL OR c.id IS NOT NULL)',[$id])[0] ?? throw new RuntimeException('Запись журнала не найдена.',404);
        return JournalView::entry(self::entryRow($row));
    }
}
