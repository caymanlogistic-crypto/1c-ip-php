<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,View};

final class SummaryRegistry
{
    public static function taskContext(array $tasks,int $contractId,string $role): array
    {
        $related=array_values(array_filter($tasks,static fn($task)=>(int)($task['contract_id'] ?? 0)===$contractId && in_array($task['entity'],['contract','document'],true)));
        return ['related'=>$related,
            'contract'=>array_values(array_filter($related,static fn($task)=>$task['entity']==='contract')),
            'visible'=>array_values(array_filter($related,static fn($task)=>$task['role']===$role))];
    }

    public static function page(): string
    {
        Access::pages(['summary']);Service::roles(['director']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','draft','booking','approval'],true)) $flag='all';
        $contracts=Controller::query("SELECT c.*,p.name party_name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status NOT IN ('closed','cancelled') ORDER BY c.id");
        $bookings=Controller::query("SELECT r.*,o.name object_name,COALESCE(NULLIF(r.client_name,''),p.name) client FROM reservations r JOIN rental_objects o ON o.id=r.object_id LEFT JOIN parties p ON p.id=r.party_id WHERE r.status='active' ORDER BY r.id");
        $pending=[];
        foreach (Controller::query("SELECT DISTINCT contract_id FROM rental_documents WHERE status IN ('legal','accounting','returned','approved','sent')") as $row) $pending[(int)$row['contract_id']]=true;
        $approval=static fn($contract)=>in_array($contract['status'],['legal','accounting','returned','approved','sent'],true) || isset($pending[(int)$contract['id']]);
        $tabs=['all'=>['Все документы',count($contracts)+count($bookings)],'draft'=>['Черновики',count(array_filter($contracts,fn($c)=>$c['status']==='draft'))],
            'booking'=>['Брони',count($bookings)],'approval'=>['На согласовании',count(array_filter($contracts,$approval))]];
        $tasks=Indicators::tasks(null,null,['contracts','approvals','contracts-active','reconciliation']);
        $rows=[];
        if ($flag!=='booking') foreach ($contracts as $contract) {
            if ($flag==='draft' && $contract['status']!=='draft' || $flag==='approval' && !$approval($contract)) continue;
            $stage=RegistryState::stage($contract);
            if (!WorkRegistry::matches($query,[$contract['number'],$contract['party_name'],$stage])) continue;
            $context=self::taskContext($tasks,(int)$contract['id'],Auth::user()['role']);
            $mine=$context['contract'];$visible=$context['visible'];
            $responsible='По реестру';
            if (RegistryState::managed($contract)) {
                $owners=array_unique(array_column(array_filter($mine,fn($task)=>in_array($task['severity'],['red','yellow'],true)),'role'));
                if (!$owners && RegistryState::owner($contract)) $owners=[RegistryState::owner($contract)];
                $responsible=$owners?implode('; ',array_map(fn($role)=>\App\People::workflowName($role),$owners)):'Действия завершены';
            }
            $raw=json_decode($contract['source_json'],true) ?: [];
            $comment=implode('; ',array_unique(array_column($visible,'text')));
            if ($comment==='' && !RegistryState::managed($contract)) $comment=($raw['sourceStatus'] ?? null)==='Действует'?'Аренда по исходному реестру':'Сведения исходного реестра';
            if ($comment==='') $comment='Текущих действий нет';
            $severity=RegistryState::severity($visible);
            $rows[]=['_attrs'=>'class="'.RegistryState::rowClass($severity).'" data-rental-url="/rental/contract?id='.(int)$contract['id'].'" tabindex="0"',
                View::e($contract['number']),View::e($contract['party_name']),View::e($stage),View::e($responsible),View::e($comment)];
        }
        if (in_array($flag,['all','booking'],true)) foreach ($bookings as $booking) {
            if (!WorkRegistry::matches($query,[$booking['client'],$booking['comment'],$booking['object_name']])) continue;
            $url=$booking['document_id']?'/rental/document?id='.(int)$booking['document_id']:'/rental/booking?id='.(int)$booking['id'];
            $rows[]=['_attrs'=>'class="registry-ui-soft-info" data-rental-url="'.$url.'" tabindex="0"','Бронь',View::e($booking['client'] ?: '—'),
                'До '.RegistryState::date($booking['until_date']),View::e(\App\People::workflowName('manager')),View::e($booking['object_name'].' · '.$booking['comment'])];
        }
        $controls='<form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'.View::e($flag).'">'.DirectoryUi::search($query).'</form>';
        $body=Controller::heading('Обзор документов','Срез черновиков, броней, согласований и действующей аренды.')
            .Controller::tabs($tabs,$flag)
            .DirectoryUi::registry('rental-summary',['Документ','Контрагент','Стадия','Ответственный','Пояснение'],$rows,['short','flex','text','text','flex'],$controls);
        return View::page('Обзор документов',$body,'summary');
    }
}
