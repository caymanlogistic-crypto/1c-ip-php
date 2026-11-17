<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{People,View};

final class ErrorsRegistry
{
    private const ROLES=['manager','lawyer','accountant','director'];
    private const SECTIONS=['contracts'=>'Договоры в работе','approvals'=>'Согласования','invoices'=>'Счета и оплаты',
        'reconciliation'=>'Финальная сверка','contracts-active'=>'Действующие договоры','objects'=>'Объекты аренды'];

    public static function redTasks(array $tasks): array
    {
        return array_values(array_filter($tasks,static fn($task)=>$task['severity']==='red'
            && in_array($task['role'],self::ROLES,true) && ($task['entity']!=='object' || $task['role']==='manager')));
    }

    public static function filtered(array $tasks,string $actor,string $color,string $query): array
    {
        return array_values(array_filter($tasks,static fn($task)=>($actor==='all' || $task['role']===$actor)
            && ($color==='all' || $task['severity']===$color)
            && WorkRegistry::matches($query,[$task['employee'],$task['text'],$task['contract_number'] ?? ''])));
    }

    private static function timing(array $task): string
    {
        $last=null;
        if ($task['entity']==='document') {
            $last=Controller::query('SELECT v.recorded_at created_at,u.name actor_name FROM rental_document_versions v LEFT JOIN users u ON u.id=v.actor_id WHERE v.document_id=? ORDER BY v.version DESC LIMIT 1',[$task['id']])[0] ?? null;
        } elseif ($task['contract_id']) {
            $last=Controller::query('SELECT created_at,actor_name,action FROM rental_events WHERE contract_id=? ORDER BY id DESC LIMIT 1',[$task['contract_id']])[0] ?? null;
        }
        $issued=$task['entity']==='invoice'?(Controller::query('SELECT issued FROM invoices WHERE id=?',[$task['id']])[0]['issued'] ?? null):null;
        $since=$issued ?: ($last?substr($last['created_at'],0,10):date('Y-m-d'));
        $text='Ожидает с '.RegistryState::date($since);
        if ($last) {
            $short=match($last['action'] ?? '') {
                'contract.return','document.return','contract.forward','document.forward'=>'возврат на доработку',
                'contract.legal','document.legal'=>'юридическая проверка завершена',
                'contract.submit','document.submit'=>'направлен юристу',
                'contract.packet','document.packet'=>'бухгалтерия согласовала',
                'contract.reconcile'=>'начато завершение',
                'payment.allocate','payment.create'=>'обновлены сведения об оплате',
                'invoice.issue','invoice.edit','invoice.create','invoice.monthly'=>'обновлены сведения по счёту',
                'contract.activated'=>'договор активирован',default=>'обновлены сведения',
            };
            $text.=' · Последнее: '.RegistryState::date(substr($last['created_at'],0,10)).', '.($last['actor_name'] ?? '').' — '.$short;
        }
        return $text;
    }

    public static function page(): string
    {
        Access::pages(['errors']);
        Service::roles(['director']);
        $actor=(string)($_GET['actor'] ?? 'all');
        if (!in_array($actor,array_merge(['all'],self::ROLES),true)) $actor='all';
        $color=(string)($_GET['color'] ?? 'all');
        if (!in_array($color,['all','red','yellow','blue','green'],true)) $color='all';
        $query=trim((string)($_GET['q'] ?? ''));
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','actions'],true)) $flag='all';
        $tasks=[];
        foreach (self::redTasks(Indicators::tasks(null,null,['errors'])) as $task) {
            $contract=$task['contract_id']?Controller::query('SELECT number FROM contracts WHERE id=?',[$task['contract_id']])[0] ?? null:null;
            $task['employee']=People::workflowName($task['role']);
            $task['contract_number']=$contract['number'] ?? null;
            $task['label']=$task['contract_number'] ?: 'Бронь';
            if ($task['entity']==='object') {
                $object=Controller::query('SELECT o.*,c.number parent_number,c.rentable parent_rentable,c.type parent_type,c.source_json parent_source FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id=?',[$task['id']])[0] ?? null;
                if (!$object || !DirectoryScope::signals($object,['number'=>$object['parent_number'],'rentable'=>$object['parent_rentable'],'type'=>$object['parent_type'],'source_json'=>$object['parent_source']])) continue;
                $task['label']=$object['name'];
            }
            $task['timing']=self::timing($task);
            $tasks[]=$task;
        }
        $summary=[];
        foreach (self::ROLES as $role) {
            $red=count(array_filter($tasks,fn($task)=>$task['role']===$role));
            $summary[]=['_attrs'=>'data-rental-employee-filter="'.$role.'" tabindex="0"',View::e(People::workflowName($role)),
                View::e(People::roleLabel($role)),$red?DirectoryUi::counts([$red,0,0,0]):'Нет текущих ошибок',
                $red?'Есть вопросы для исправления':'Работа без текущих проблем'];
        }
        $rows=[];
        foreach (self::filtered($tasks,$actor,$color,$query) as $task) {
            $target=match($task['entity']) {'object'=>'object','invoice'=>'invoice','document'=>'document','reservation'=>'booking',default=>'contract'};
            $rows[]=['_attrs'=>'class="'.RegistryState::rowClass($task['severity']).'" data-rental-url="/rental/'.$target.'?id='.(int)$task['id'].'" tabindex="0"',
                View::e($task['employee']),View::e($task['label']),View::e(self::SECTIONS[$task['page']] ?? $task['page']),
                View::e($task['text']),View::e($task['timing'])];
        }
        $actors='<option value="all">Все сотрудники</option>';
        foreach (self::ROLES as $role) $actors.='<option value="'.$role.'"'.($role===$actor?' selected':'').'>'.View::e(People::workflowName($role).' · '.People::roleLabel($role)).'</option>';
        $colors='';
        foreach (['all'=>'Все цвета','red'=>'Красные','yellow'=>'Жёлтые','blue'=>'Голубые','green'=>'Зелёные'] as $key=>$label) $colors.='<option value="'.$key.'"'.($key===$color?' selected':'').'>'.$label.'</option>';
        $controls='<form method="get" data-rental-registry-filter data-rental-error-filter><input type="hidden" name="flag" value="'.$flag.'">'.DirectoryUi::search($query)
            .'<select class="field-select toolbar-select" id="actor-filter" name="actor" aria-label="Сотрудник">'.$actors.'</select>'
            .'<select class="field-select toolbar-select" id="color-filter" name="color" aria-label="Цвет ошибки">'.$colors.'</select></form>';
        $body=Controller::heading('Контроль ошибок','Текущие действия и задержки по сотрудникам · '.RegistryState::date(date('Y-m-d')).' · 13:00')
            .'<section class="table-card registry-ui-card">'.Controller::table(['Сотрудник','Роль','Ошибки','Текущий срез'],$summary,['text','text','code','flex']).'</section>'
            .Controller::tabs(['all'=>['Все события',count($tasks)],'actions'=>['Требуют действия',count($tasks)]],$flag,true,['actor'=>$actor,'color'=>$color])
            .DirectoryUi::registry('rental-errors',['Сотрудник','Документ','Раздел','Статус / пояснение','Ожидание / реакция'],$rows,['text','short','text','flex','flex'],$controls);
        return View::page('Контроль ошибок',$body,'errors');
    }
}
