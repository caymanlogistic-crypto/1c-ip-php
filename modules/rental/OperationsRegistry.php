<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Http,View};
use RuntimeException;

final class OperationsRegistry
{
    private static function actions(array $group,array $step,bool $allowed): string
    {
        if($allowed && $step['native_status']==='blocked' && !empty($step['blocked_manual_available'])
            && empty($step['manual_marked']) && in_array($group['operation']??'',['contract-packet','addendum-packet'],true)
            && preg_match('/^[a-f0-9]{32}$/D',$step['reference'])){
            return RentalUI::button('Сделано вручную','/rental/operation-manual?step='.rawurlencode('email:'.$step['reference']));
        }
        if (!$allowed || !in_array($group['operation'] ?? '',['contract-packet','addendum-packet'],true)
            || !in_array($step['native_status'],['prepared','failed','unknown','claimed'],true)
            || !empty($step['manual_marked']) || !preg_match('/^[a-f0-9]{32}$/D',$step['reference'])) return '—';
        $reference='email:'.$step['reference'];
        return '<form method="post" action="/rental/operation-action">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="action" value="operation.retry">'
            .'<input type="hidden" name="step" value="'.View::e($reference).'">'
            .'<button class="btn btn-secondary" type="submit">Повторить</button>'
            .RentalUI::button('Сделано вручную','/rental/operation-manual?step='.rawurlencode($reference))
            .'</form>';
    }

    public static function page(): string
    {
        Access::pages(['operations']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','errors'],true)) $flag='all';
        $groups=OperationReadModel::records();
        $all=OperationReadModel::rows($groups,$query);
        $errors=OperationReadModel::rows($groups,$query,'errors');
        $rows=[];
        foreach ($flag==='errors'?$errors:$all as $row) {
            $state=$row['errors']?'Ошибок: '.count($row['errors']):($row['pending']?'Ожидает выполнения':'Выполнено');
            $rows[]=['_attrs'=>'class="'.($row['errors']?'registry-ui-soft-error':'').'" data-rental-url="/rental/operation?id='.rawurlencode($row['id']).'" tabindex="0"',
                '<strong>'.View::e($row['label']).'</strong>'.($row['created_at']?'<div class="doc-meta">'.RegistryState::date(substr($row['created_at'],0,10)).'</div>':''),
                View::e($row['party_name']),View::e($state),$row['errors']?implode('<br>',array_map([View::class,'e'],$row['errors'])):View::e($row['groups'][0]['description'])];
        }
        $controls='<form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'.$flag.'">'.DirectoryUi::search($query).'</form>';
        $body=Controller::heading('Операции и отправки','Документы, счета и отправки')
            .Controller::tabs(['all'=>['Все',count($all)],'errors'=>['Ошибки',count($errors)]],$flag)
            .DirectoryUi::registry('rental-operations',['Документ','Контрагент','Состояние','Комментарий'],$rows,['text','text','short','flex'],$controls);
        return View::page('Операции и отправки',$body,'operations');
    }

    public static function card(): string
    {
        Access::pages(['operations']);
        $id=(string)($_GET['id'] ?? '');
        if (!preg_match('/^(?:email:[a-f0-9]{32}|native:[a-f0-9-]{36})$/D',$id)) throw new RuntimeException('Операция не найдена.',404);
        $groups=OperationReadModel::records();
        $selected=null;
        foreach ($groups as $group) {
            if ($group['id']===$id || ($group['latest_attempt_id'] ?? null)===$id) {$selected=$group;break;}
        }
        if ($selected===null) throw new RuntimeException('Операция не найдена.',404);
        $related=array_values(array_filter($groups,fn($group)=>$group['row_key']===$selected['row_key']));
        $write=OperationActionController::mayOperate();
        $rows=[];
        foreach ($related as $group) foreach ($group['steps'] as $step) {
            $channel=match($step['channel']){'Email'=>'Почта','1C'=>'1С','Document'=>'Документ',default=>$step['channel']};
            $description=View::e($channel.' · '.$step['description']);
            foreach ([$step['error'],$step['file_name']] as $detail) if ($detail!=='') $description.='<div class="doc-meta">'.View::e($detail).'</div>';
            $rows[]=['_attrs'=>'class="'.($step['status']==='error'?'registry-ui-soft-error':'').'" data-operation-group="'.View::e($group['id']).'" data-operation-step="'.View::e($step['id']).'"',
                $description,View::e($step['label']),$step['attempts'],self::actions($group,$step,$write)];
        }
        $body=RentalUI::pairs([
            ['Операция',$selected['description']],
            ['Проект',$selected['document_id']?'ДС '.$selected['document_id']:'Договор '.$selected['contract_number']],
            ['Операций',(string)count($related)],
        ]).Controller::table(['Шаг','Состояние','Попытки','Действия'],$rows,['flex','text','short','text']);
        return RentalUI::modal('Операции и отправки',$body,'','operations',false,'','Закрыть',false);
    }
}
