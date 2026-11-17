<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\{Auth,DB,Http,View};

final class OnecOperationsView
{
    public static function render(string $connection): string
    {
        $definition=OnecOperations::definition($connection);$e=Support::e(...);$db=DB::pdo();$user=Auth::user();
        $service=new OnecOperations($db,Config::store());
        $canOperate=true;try{$service->authorize($connection,(int)$user['id'],'prepare');}catch(\RuntimeException){$canOperate=false;}
        $canCheck=true;try{$service->authorize($connection,(int)$user['id'],'check');}catch(\RuntimeException){$canCheck=false;}
        $c=Config::get($db,'onec');$config=Support::decode($c['config_json']);
        $tab=($_GET['tab']??'data')==='history'?'history':'data';
        $body='<section data-onec-operation="'.$e($connection).'" data-onec-csrf="'.$e(Http::csrf()).'" data-onec-write-enabled="'.(OnecWriteAdmission::createAllowed($db)?'true':'false').'">';
        $body.=PageView::header($definition['title'],$definition['description'],'<button class="btn btn-secondary" type="button" data-dialog="onec-settings">Настройки</button>'.($canCheck?'<button class="btn btn-primary" type="button" data-onec-check>Проверить</button>':''));
        $body.='<div id="onec-operation-feedback" role="status" aria-live="polite"></div>'.PageView::tabs(['data'=>'Данные','history'=>'История'],$tab);
        if ($definition['kind']==='print') {
            $body.=$tab==='history'
                ?'<section role="tabpanel" class="table-card registry-ui-card"><div class="table-scroll"><table class="table registry-ui-table registry-ui-data"><thead><tr><th scope="col">Дата</th><th scope="col">Файл</th><th scope="col">Результат</th></tr></thead><tbody><tr><td colspan="3">Файлы пока не формировались.</td></tr></tbody></table></div></section>'
                :'<section role="tabpanel" class="table-card registry-ui-card"><div class="panel-section"><h2 class="section-title">Образец счёта</h2><p class="registry-ui-secondary">Образец ещё не добавлен. После его согласования здесь будет формироваться печатный файл счёта.</p></div></section>';
        } else $body.=self::registry($connection,$definition['kind'],$tab,(int)$user['id'],$canOperate);
        $body.=PageView::onecSettings($c,$config,PageView::canEdit('onec','integrations.write'),false);
        $body.='<dialog class="modal registry-ui-modal registry-ui-modal--confirmation ui-detail-card" id="onec-operation-dialog" aria-labelledby="onec-operation-title"><div class="modal-head"><h2 class="modal-title" id="onec-operation-title">'.$e($definition['title']).'</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть действие">×</button></div><div class="modal-body registry-ui-modal-grid"><p class="registry-ui-modal-text" data-onec-selection></p><div data-onec-operation-result role="status" aria-live="polite"></div></div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Закрыть</button>'.($canOperate?'<button type="button" class="btn btn-primary" data-onec-command="prepare" hidden>Подготовить</button><button type="button" class="btn btn-primary" data-onec-command="dispatch" hidden>Внести в 1С</button><button type="button" class="btn btn-secondary" data-onec-command="reconcile" hidden>Сверить результат</button>':'').'</div></div></dialog></section>';
        $body.='<script defer src="/design/system/palette-runtime.js"></script><script defer src="/design/system/registry-interactions.js"></script>';
        return View::page($definition['title'],$body,'connection-'.$connection,['connection-onec-ui.js','onec-operations.js']);
    }

    private static function registry(string $connection,string $kind,string $tab,int $actor,bool $canOperate): string
    {
        $db=DB::pdo();$e=Support::e(...);$search=trim((string)($_GET['q']??''));
        if (mb_strlen($search)>200) throw new \RuntimeException('Слишком длинный поиск',400);
        $page=max(1,min(1000000,(int)($_GET['page']??1)));$offset=($page-1)*50;$args=[];
        if ($tab==='history') {
            $from='integration_native_intents n';$where='n.kind=? AND n.actor_id=?';$args=[$kind,$actor];
            if($search!==''){$where.=' AND (n.native_number LIKE ? OR n.state LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
            $select="n.id,n.created_at,n.state,n.native_number";
            $order='n.created_at DESC,n.id';$labels=['Дата','Номер в 1С','Результат'];
        } else {
            $invoice=$kind==='customer_invoice';
            $from=($invoice?'invoices i JOIN contracts c ON c.id=i.contract_id':'contracts c').' JOIN parties p ON p.id=c.party_id';
            // Only the approvals context is listed here; callers from other pages
            // retain the normal owner/packet authorization for their own identity.
            $where="c.status IN ('legal','accounting','returned','approved','sent')";
            if(\App\PagePolicy::level('rental.approvals',Auth::user())<1)$where='1=0';
            if($search!==''){$where.=' AND (p.name LIKE ? OR c.number LIKE ?'.($invoice?' OR i.number LIKE ?':'').')';$args=['%'.$search.'%','%'.$search.'%'];if($invoice)$args[]='%'.$search.'%';}
            $select='c.id AS record_id,c.number AS contract_number,p.name,p.id AS party_id,p.inn'.($invoice?',i.id AS invoice_id,i.number,i.amount':',c.number,c.amount');
            $order=$invoice?'i.id DESC':'c.id DESC';$labels=$invoice?['Счёт','Договор','Контрагент','Сумма']:['Контрагент','ИНН','Договор'];
        }
        $q=$db->prepare('SELECT COUNT(*) FROM '.$from.' WHERE '.$where);$q->execute($args);$count=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT '.$select.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$order.' LIMIT 50 OFFSET '.$offset);$q->execute($args);$rows=$q->fetchAll();
        $spec=array_map(static fn($label,$i)=>['key'=>'column-'.$i,'type'=>$label==='Сумма'?'money':'text','size'=>$label==='Контрагент'?'flex':'long'],$labels,array_keys($labels));
        $html='<section role="tabpanel" class="table-card registry-ui-card"><div class="table-toolbar registry-ui-toolbar"><div class="registry-ui-checkboxes found-label">Записей: <strong>'.$count.'</strong> · показано: <strong id="visible-count">'.count($rows).'</strong></div><form method="get" class="toolbar-right"><input type="hidden" name="tab" value="'.$e($tab).'"><div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" name="q" value="'.$e($search).'" placeholder="Поиск" aria-label="Поиск по данным подключения"></div></form></div><div class="table-scroll" role="region" tabindex="0" aria-label="Данные подключения"><table class="table registry-ui-table registry-ui-data registry-ui-data--columns" data-column-profile="'.$e($connection.'-'.$tab).'" data-column-spec="'.$e(Support::json($spec)).'"><thead><tr>';
        foreach($labels as $label)$html.='<th scope="col">'.$label.'</th>';
        $html.='</tr></thead><tbody id="records-body" data-onec-next="'.($offset+50<$count?$e('?tab='.$tab.'&q='.rawurlencode($search).'&page='.($page+1)):'').'">';
        foreach($rows as $row){
            if($tab==='history'){$payload=['intent_id'=>$row['id']];$key=$row['id'];$values=[date('d.m.Y H:i',strtotime($row['created_at'])),$row['native_number']?:'Не присвоен',self::state($row['state'])];}
            else{$payload=['record_kind'=>'contract','record_id'=>(string)$row['record_id'],'subject_id'=>($kind==='counterparty'?'party:'.$row['party_id']:'invoice:'.$row['invoice_id'])];$key=$row['record_id'].':'.$payload['subject_id'];$values=$kind==='counterparty'?[$row['name'],$row['inn']?:'Не указан',$row['contract_number']]:[$row['number'],$row['contract_number'],$row['name'],RegistryView::money($row['amount'])];}
            $html.='<tr tabindex="0" data-onec-row-key="'.$e($key).'" data-onec-payload="'.$e(Support::json($payload)).'" aria-label="Открыть '.$e($values[0]).'">';
            foreach($values as $i=>$value)$html.='<td><div class="registry-ui-value"><span class="'.($labels[$i]==='Сумма'?'registry-ui-money':'registry-ui-primary').'">'.$e($value).'</span></div></td>';
            $html.='</tr>';
        }
        if(!$rows)$html.='<tr><td colspan="'.count($labels).'">'.($tab==='history'?'Операций пока нет.':'Нет данных.').'</td></tr>';
        return $html.'</tbody></table></div></section><div id="onec-load-feedback" aria-live="polite"></div>';
    }

    private static function state(string $state): string
    {
        return match($state){'prepared'=>'Подготовлено','saved'=>'Сохранено в 1С','existing_verified'=>'Найдено в 1С','unknown','unconfirmed','posting','ack_received'=>'Требуется сверка','preflight'=>'Проверка перед записью',default=>'Требуется проверка'};
    }
}
