<?php
declare(strict_types=1);
namespace Modules\Rental;

final class RegistryState
{
    public static function date(?string $date): string
    {
        return $date?Documents::dateText($date):'—';
    }

    public static function stage(array $contract): string
    {
        $raw=json_decode($contract['source_json'],true) ?: [];
        if (!self::managed($contract) && empty($contract['finish_date'])) return $raw['sourceStatus'] ?? '—';
        return match($contract['status']) {
            'draft'=>'Черновик','legal'=>'На проверке у юриста','accounting'=>'На проверке у бухгалтера','returned'=>'Возвращён на доработку',
            'approved'=>'Подготовка отправки','sent'=>'Ожидает подписи / оплаты','active'=>'Действует','reconciling'=>'Финальная сверка',
            'ended'=>'Аренда завершена','closed'=>'Расчёты закрыты','cancelled'=>'Не прошёл согласование','signed'=>'Подписан обеими сторонами',default=>$contract['status'],
        };
    }
    public static function managed(array $contract): bool
    {
        $raw=json_decode($contract['source_json'],true) ?: [];
        return empty($raw['imported']) || !empty($raw['workflowTouched']) || !empty($contract['servicing_document_id']);
    }

    public static function owner(array $record): ?string
    {
        return match($record['status']) {
            'legal'=>'lawyer','accounting','approved'=>'accountant','returned'=>($record['return_to'] ?? null) ?: 'manager',
            'draft','sent'=>'manager',default=>null,
        };
    }

    public static function severity(array $tasks): string
    {
        foreach (['red','yellow','blue','green'] as $severity) foreach ($tasks as $task) if ($task['severity']===$severity) return $severity;
        return '';
    }

    public static function rowClass(string $severity): string
    {
        return match($severity) {'red'=>'registry-ui-soft-error','yellow'=>'registry-ui-warning','blue'=>'registry-ui-soft-info','green'=>'registry-ui-soft-success',default=>''};
    }

    public static function approval(string $kind,array $record,array $contract,string $role,array $tasks): array
    {
        $cancelled=$record['status']==='cancelled';
        $source=$kind==='contract' && !self::managed($contract);
        $target=$source || $cancelled?null:self::owner($record);
        $mine=array_values(array_filter($tasks,static fn($task)=>($role==='admin'||$task['role']===$role)
            && ($kind==='document'?$task['entity']==='document' && (int)$task['id']===(int)$record['id']:$task['entity']==='contract' && (int)$task['id']===(int)$record['id'] && $task['page']==='approvals')
            && ($role!=='director' || !in_array($task['severity'],['red','yellow'],true))));
        $raw=json_decode($record['source_json'],true) ?: [];
        $stage=$source?($raw['sourceStatus'] ?? '—'):($kind==='document'?match($record['status']) {
            'draft'=>'Черновик','legal'=>'У юриста','accounting'=>'У бухгалтера','returned'=>'Возвращено','approved'=>'Подготовка отправки',
            'sent'=>'Ожидает подписи','signed'=>!empty($record['applied'])?'Условия действуют':'Подписано, ожидает вступления','cancelled'=>'Аннулировано',default=>Controller::status($record['status']),
        }:self::stage($record));
        $severity=self::severity($mine);
        if ($kind==='document' && $record['status']==='signed' && !$record['applied'] && in_array($role,['manager','director'],true)) $severity='blue';
        $comment=$cancelled?($record['reason'] ?: 'Проект аннулирован. История сохранена.')
            :($source?'Исходные сведения. Назначение этапа согласования не подтверждено.':implode('; ',array_unique(array_column($mine,'text'))));
        return ['source'=>$source,'cancelled'=>$cancelled,'target'=>$target,'actionNeeded'=>$target!==null && $target===$role,
            'stage'=>$stage,'severity'=>$severity,'comment'=>$comment,'responsible'=>$target];
    }
}
