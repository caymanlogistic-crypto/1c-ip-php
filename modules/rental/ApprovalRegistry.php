<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy,View};

final class ApprovalRegistry
{
    public static function page(): string
    {
        Access::pages(['approvals']);
        $role=Auth::user()['role'];
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $filter=isset($_GET['reset'])?'all':(string)($_GET['filter'] ?? 'all');
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','mine','returned','cancelled'],true)) $flag='all';
        $tasks=Indicators::tasks(null,null,['approvals']);$entries=[];$stages=[];
        foreach (Controller::query("SELECT c.*,p.name party_name,p.inn party_inn FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status IN ('legal','accounting','returned','approved','sent','cancelled') ORDER BY c.id") as $contract) {
            $entries[]=self::entry('contract',$contract,$contract,$role,$tasks);
        }
        foreach (Controller::query("SELECT d.* FROM rental_documents d WHERE d.status<>'draft' ORDER BY d.id") as $document) {
            $contract=Controller::query('SELECT c.*,p.name party_name,p.inn party_inn FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?',[$document['contract_id']])[0] ?? null;
            if ($contract) $entries[]=self::entry('document',$document,$contract,$role,$tasks);
        }
        foreach ($entries as $entry) $stages[$entry['filter']]=$entry['view']['stage'];
        $searched=[];
        foreach ($entries as $entry) {
            if ($filter!=='all' && $filter!==$entry['filter']) continue;
            $record=$entry['record'];$contract=$entry['contract'];$view=$entry['view'];
            if (!WorkRegistry::matches($query,[$entry['kind']==='document'?'ДС':'Договор',$record['number'],$record['document_date'],RegistryState::date($record['document_date']),
                $contract['number'],$contract['party_name'],$contract['party_inn'],$record['start_date'],$record['end_date'],RegistryState::date($record['start_date']),RegistryState::date($record['end_date']),
                $entry['objects'],$view['stage'],$view['comment'],$record['amount'],$view['responsible']?\App\People::workflowName($view['responsible']):''])) continue;
            $searched[]=$entry;
        }
        $tabs=['all'=>['Все',count($searched)],'mine'=>['Моё действие',count(array_filter($searched,fn($entry)=>$entry['view']['actionNeeded']))],
            'returned'=>['Возвращённые',count(array_filter($searched,fn($entry)=>$entry['record']['status']==='returned'))],
            'cancelled'=>['Аннулированные',count(array_filter($searched,fn($entry)=>$entry['view']['cancelled']))]];
        $rows=[];
        foreach ($searched as $entry) {
            $record=$entry['record'];$contract=$entry['contract'];$view=$entry['view'];$kind=$entry['kind'];
            if ($flag==='mine' && !$view['actionNeeded'] || $flag==='returned' && $record['status']!=='returned' || $flag==='cancelled' && !$view['cancelled']) continue;
            $title=$kind==='document'?($record['number']?'ДС №'.$record['number']:'ДС'):$record['number'];
            $comment=($kind==='document'?'<div>К договору №'.View::e($contract['number']).($contract['document_date']?' от '.RegistryState::date($contract['document_date']):'').'</div>':'').View::e($view['comment'] ?: '—');
            $rows[]=['_attrs'=>'class="'.($view['cancelled']?'registry-ui-soft-error':RegistryState::rowClass($view['severity'])).'" data-rental-url="/rental/'.$kind.'?id='.(int)$record['id'].'" tabindex="0"',
                '<strong>'.View::e($title).'</strong>'.($record['document_date']?'<div class="doc-meta">'.RegistryState::date($record['document_date']).'</div>':''),
                View::e($contract['party_name']),RegistryState::date($record['start_date']),RegistryState::date($record['end_date']),View::e($entry['objects']),
                View::e($view['stage']),$comment,Controller::money($record['amount']),View::e($view['responsible']?\App\People::workflowName($view['responsible']):'—')];
        }
        $controls='<form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'.View::e($flag).'">'
            .'<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query==='' && $filter==='all'?' hidden':'').'>Сбросить фильтры</button>'
            .'<select class="field-select toolbar-select" id="status-filter" name="filter" aria-label="Стадия"><option value="all">Все стадии</option>';
        foreach ($stages as $key=>$label) $controls.='<option value="'.View::e($key).'"'.($filter===$key?' selected':'').'>'.View::e($label).'</option>';
        $controls.='</select>'.DirectoryUi::search($query).'</form>';
        $create=in_array($role,['admin','manager'],true) && PagePolicy::level('rental.contracts',Auth::user())>=2?RentalUI::button('Создать договор','/rental/document-creator',true):'';
        $body=Controller::heading('Согласование документов','Назначенные проверки, ожидание подписания и история решений.',$create)
            .Controller::tabs($tabs,$flag,true,['filter'=>$filter])
            .DirectoryUi::registry('rental-approvals',['Документ','Контрагент','Начало','Окончание','Объекты аренды','Стадия','Комментарий','Аренда с НДС','Ответственный'],$rows,
                ['short','flex','short','code','flex','text','flex','money','text'],$controls);
        return View::page('Согласование документов',$body,'approvals');
    }

    private static function entry(string $kind,array $record,array $contract,string $role,array $tasks): array
    {
        $view=RegistryState::approval($kind,$record,$contract,$role,$tasks);
        $objects=Controller::query($kind==='contract'?'SELECT o.name FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=? ORDER BY o.id'
            :'SELECT o.name FROM document_terms t JOIN rental_objects o ON o.id=t.object_id WHERE t.document_id=? ORDER BY o.id',[$record['id']]);
        return ['kind'=>$kind,'record'=>$record,'contract'=>$contract,'view'=>$view,'objects'=>implode(', ',array_column($objects,'name')),
            'filter'=>$view['source']?'source:'.$view['stage']:$record['status']];
    }
}
