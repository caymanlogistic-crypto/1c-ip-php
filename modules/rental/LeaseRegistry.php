<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,People,View};

final class LeaseRegistry
{
    public static function section(array $contract): string
    {
        if (in_array($contract['status'],['closed','ended'],true)) return 'contracts-completed';
        if ($contract['status']==='cancelled') return 'approvals';
        if ($contract['finish_date']!==null && $contract['status']==='reconciling') return 'contracts-active';
        $raw=json_decode($contract['source_json'],true) ?: [];
        if (!RegistryState::managed($contract) && !empty($raw['imported'])) {
            return ($raw['sourceStatus'] ?? '')==='Действует'?'contracts-active':'approvals';
        }
        return in_array($contract['status'],['active','reconciling'],true)?'contracts-active':($contract['status']==='draft'?'contracts':'approvals');
    }

    public static function expiry(?string $end,string $today): string
    {
        if ($end===null) return '';
        $last=new \DateTimeImmutable($end);
        $now=new \DateTimeImmutable($today);
        if ((int)$now->diff($last)->format('%r%a')<=7) return 'red';
        $previous=$last->modify('first day of previous month');
        $previous=$previous->setDate((int)$previous->format('Y'),(int)$previous->format('m'),min((int)$last->format('d'),(int)$previous->format('t')));
        return $today>=$previous->format('Y-m-d')?'yellow':'';
    }

    public static function expiryComment(?string $end,string $today): string
    {
        if (!$end) return '';
        $days=(int)(new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($end))->format('%r%a');
        return $days===0?'Аренда заканчивается сегодня':($days>0
            ?ObjectFacts::days($days).' до окончания аренды'
            :'Аренда закончилась '.ObjectFacts::days(-$days).' назад');
    }

    public static function commentHtml(array $view): string
    {
        $notice=$view['expiry_comment'];
        return ($notice!==''?'<div><strong class="registry-ui-error">'.View::e($notice).'</strong></div>':'')
            .View::e($view['comment'] ?: ($notice===''?'—':''));
    }

    public static function projection(array $contract,string $role,array $tasks,bool $closed,bool $ending,array $reviews=[]): array
    {
        $mine=array_values(array_filter($tasks,fn($task)=>$task['entity']==='contract' && (int)$task['id']===(int)$contract['id']
            && ($task['role']===$role || $role==='admin') && ($role!=='director' || $contract['status']==='reconciling' || !in_array($task['severity'],['red','yellow'],true))));
        $comment=implode('; ',array_unique(array_column($mine,'text')));
        $completed=self::section($contract)==='contracts-completed';
        if ($comment==='' && $closed) $comment=!empty($reviews['return_not_applicable'])
            ?'Взаиморасчёты и юридическая проверка подтверждены. Физический возврат не применяется.'
            :'Взаиморасчёты, юридическая проверка и акт возврата подтверждены.';
        elseif ($comment==='' && $completed) $comment='Аренда завершена. Подтверждения финальной сверки пока не представлены.';
        if ($contract['status']==='reconciling') {
            $responsible=[];
            foreach (['finance'=>'accountant','legal'=>'lawyer','act'=>'manager'] as $key=>$owner) if (empty($reviews[$key])) $responsible[]=$owner;
        } else {
            $responsible=array_values(array_unique(array_column(array_filter($mine,fn($task)=>in_array($task['severity'],['red','yellow'],true)),'role')));
            if (!$responsible && !$completed) $responsible=[RegistryState::owner($contract) ?: 'manager'];
        }
        $stage=RegistryState::stage($contract);
        if (!RegistryState::managed($contract) && $completed && empty($contract['finish_date'])) {
            $raw=json_decode($contract['source_json'],true) ?: [];
            $stage=!empty($raw['sourceStatus']) && $raw['sourceStatus']!=='Действует'?$raw['sourceStatus']:Controller::status($contract['status']);
        }
        return ['severity'=>RegistryState::severity($mine),'comment'=>$comment,'responsible'=>$responsible,
            'expiry_comment'=>$ending?self::expiryComment($contract['end_date'],date('Y-m-d')):'',
            'stage'=>$stage,'closed'=>$closed,'ending'=>$ending,
            'end'=>$closed && $contract['finish_date']?$contract['finish_date']:$contract['end_date']];
    }

    public static function page(bool $active): string
    {
        $page=$active?'contracts-active':'contracts-completed';
        Access::pages([$page]);
        $user=Auth::user();
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $types=['office'=>'Офис','parking'=>'Машиноместо'];
        $typeFilter=$active && !isset($_GET['reset']) && is_string($_GET['type'] ?? null) && isset($types[$_GET['type']])
            ?$_GET['type']:'all';
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,$active?['all','ending','review']:['all','ended','closed'],true)) $flag='all';
        $tasks=Indicators::tasks(null,null,[$page]);
        $items=[];
        $states=$active?"('active','reconciling')":"('ended','closed')";
        foreach (Controller::query('SELECT c.*,p.name party_name,p.inn party_inn FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status IN '.$states.' ORDER BY c.id DESC') as $contract) {
            if (self::section($contract)!==$page) continue;
            $closed=false;$reviews=[];
            if ($contract['status']==='closed' || $contract['status']==='reconciling') {
                $signature=Evidence::signature(DB::pdo(),(int)$contract['id']);
                $proof=Controller::query("SELECT after_json FROM audit_log WHERE action='contract.completed' AND entity_type='contract' AND entity_id=? ORDER BY id DESC LIMIT 1",[(string)$contract['id']])[0]['after_json'] ?? null;
                $proof=$proof?json_decode($proof,true):null;
                $closed=$contract['status']==='closed' && isset($proof['closure_proof']['closed_baseline'])
                    && hash_equals($signature,$proof['closure_proof']['closed_baseline']);
                foreach (Controller::query('SELECT kind,baseline_hash FROM rental_reviews WHERE contract_id=?',[$contract['id']]) as $review) $reviews[$review['kind']]=hash_equals($signature,$review['baseline_hash']);
                try {
                    $file=Evidence::latest(DB::pdo(),'contract',(int)$contract['id'],'return-act');
                    $reviews['act']=$contract['return_confirmed'] && hash_equals($signature,$file['baseline_hash'] ?? '');
                } catch (\RuntimeException $error) {if($error instanceof \PDOException)throw $error;}
                if (OccupiedBilling::noPhysicalReturn(DB::pdo(),$contract)) $reviews['act']=$reviews['return_not_applicable']=true;
            }
            $planned=Controller::query("SELECT COUNT(*) n FROM rental_documents d WHERE d.contract_id=? AND d.kind='renewal' AND d.applied=0 AND d.status<>'cancelled' AND d.end_date>? AND (d.status IN ('sent','signed') OR EXISTS(SELECT 1 FROM reservations r WHERE r.document_id=d.id AND r.status='active'))",[$contract['id'],$contract['end_date']])[0]['n'];
            $ending=$contract['status']==='active' && !$planned && self::expiry($contract['end_date'],date('Y-m-d'))!=='';
            $view=self::projection($contract,$user['role'],$tasks,$closed,$ending,$reviews);
            $groups=[];
            foreach (Controller::query('SELECT o.type,o.name FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=? ORDER BY o.id',[$contract['id']]) as $object) {
                $groups[$object['type'] ?: 'Объекты'][]=$object['name'];
            }
            if ($typeFilter!=='all' && !isset($groups[$types[$typeFilter]])) continue;
            $objects='';
            foreach ($groups as $type=>$names) {
                $prefix=match($type){'Офис'=>'Офисы','Машиноместо'=>'Машиноместа',default=>$type};
                $objects.='<div class="registry-ui-primary"><span class="registry-executor-secondary">'.View::e($prefix).':</span> '.View::e(implode(', ',$names)).'</div>';
            }
            if ($objects==='') $objects='—';
            $responsible=implode('; ',array_map(fn($role)=>People::workflowName($role),$view['responsible']));
            if (!WorkRegistry::matches($query,[$contract['number'],$contract['document_date'],$contract['party_name'],$contract['party_inn'],
                RegistryState::date($contract['start_date']),RegistryState::date($view['end']),strip_tags($objects),$view['stage'],$view['comment'],$view['expiry_comment'],$contract['amount'],$responsible])) continue;
            $items[]=compact('contract','view','objects','responsible');
        }
        $tabs=self::tabs($items,$active);
        $rows=[];$sum='0.00';$known=0;
        foreach ($items as $item) {
            ['contract'=>$contract,'view'=>$view,'objects'=>$objects,'responsible'=>$responsible]=$item;
            if (!self::selected($item,$flag)) continue;
            if ($contract['amount']!==null) {$sum=bcadd($sum,$contract['amount'],2);$known++;}
            $rows[]=['_attrs'=>'class="'.RegistryState::rowClass($view['severity']).'" data-rental-url="/rental/contract?id='.(int)$contract['id'].'" tabindex="0"',
                View::e($contract['number']),View::e($contract['party_name']),RegistryState::date($contract['start_date']),RegistryState::date($view['end']),$objects,
                $view['closed']?'<strong class="registry-ui-success">'.View::e($view['stage']).'</strong>':View::e($view['stage']),
                self::commentHtml($view),Controller::money($contract['amount']),View::e($responsible ?: '—')];
        }
        $actions=$active && in_array($user['role'],['manager','admin'],true) && Auth::can('rental.write')
            && \App\PagePolicy::level('rental.contracts',$user)>=2?RentalUI::button('Создать договор','/rental/new-contract',true):'';
        $title=$active?'Действующие договоры':'Завершённые договоры';
        $typeSelect='';
        if ($active) {
            $typeSelect='<select class="toolbar-select" id="lease-object-type" name="type" aria-label="Тип объекта">';
            foreach (['all'=>'Показать все','office'=>'Офисы','parking'=>'Машиноместа'] as $value=>$label) {
                $typeSelect.='<option value="'.$value.'"'.($typeFilter===$value?' selected':'').'>'.$label.'</option>';
            }
            $typeSelect.='</select>';
        }
        $filterState='<input type="hidden" name="flag" value="'.View::e($flag).'">';
        $reset='<button type="submit" name="reset" value="1" class="registry-ui-reset"'
            .($query==='' && $typeFilter==='all'?' hidden':'').'>'.($active?'Сбросить фильтр':'Сбросить фильтры').'</button>';
        $controls=$active?$filterState.$reset.$typeSelect.DirectoryUi::search($query,true)
            :'<form method="get" data-rental-registry-filter>'.$filterState.DirectoryUi::search($query).$reset.'</form>';
        $body=Controller::heading($title,$active?'Действующие условия аренды, сроки и назначенные действия.':'История завершённой аренды и подтверждений расчётов.',$actions)
            .Controller::tabs($tabs,$flag,!$active,$active?['q'=>$query,'type'=>$typeFilter]:[]).DirectoryUi::registry('rental-'.$page,
                ['Договор','Контрагент','Начало','Окончание','Объекты аренды','Стадия','Комментарий','Аренда с НДС','Ответственный'],$rows,
                ['short','flex','short','code','flex','text','flex','money','text'],$controls,
                ' · Аренда с НДС: <b>'.Controller::money($known?$sum:($rows?null:'0.00')).'</b>',filterForm:$active);
        return View::page($title,$body,$page);
    }

    private static function selected(array $item,string $flag): bool
    {
        return match($flag) {
            'ending'=>$item['view']['ending'],
            'review'=>$item['contract']['status']==='reconciling',
            'ended'=>$item['contract']['status']==='ended',
            'closed'=>$item['view']['closed'],
            default=>true,
        };
    }

    public static function tabs(array $items,bool $active): array
    {
        $tabs=[];
        $labels=$active?['all'=>'Все','ending'=>'Заканчиваются','review'=>'На завершении']
            :['all'=>'Все','ended'=>'Аренда завершена','closed'=>'Расчёты закрыты'];
        $colors=$active?[2=>'green',3=>'blue']:['red','yellow','green','blue'];
        foreach ($labels as $flag=>$label) {
            $visible=array_filter($items,fn($item)=>self::selected($item,$flag));
            $counts=[0,0,0,0];
            foreach ($visible as $item) {
                $index=array_search($item['view']['severity'],$colors,true);
                if ($index!==false) $counts[$index]++;
            }
            $tabs[$flag]=[$label,count($visible),$counts];
        }
        return $tabs;
    }
}
