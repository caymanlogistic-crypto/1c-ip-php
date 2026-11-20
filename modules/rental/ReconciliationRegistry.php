<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,View};

final class ReconciliationRegistry
{
    /** Closure changes the native end date; the joined receipt binds both baselines. */
    public static function closedProof(array $contract,array $reviews,?array $file,?array $proof,string $baseline): bool
    {
        if ($contract['status']!=='closed' || empty($contract['return_confirmed']) || !$proof || !$file
            || !is_array($proof['act'] ?? null)
            || (int)($proof['record_version'] ?? 0)!==(int)$contract['version']
            || !hash_equals((string)($proof['closed_baseline'] ?? ''),$baseline)) return false;
        $approved=(string)($proof['approved_baseline'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D',$approved)) return false;
        foreach (['finance','legal'] as $kind) {
            if (!is_array($reviews[$kind] ?? null) || !is_array($proof[$kind]['row'] ?? null)
                || !is_string($proof[$kind]['sha256'] ?? null)) return false;
            $row=$reviews[$kind];unset($row['actor_name']);
            $snapshot=$proof[$kind]['row'];
            if (!is_array($snapshot) || (int)($row['contract_id'] ?? 0)!==(int)$contract['id']
                || ($row['kind'] ?? '')!==$kind || ($row['baseline_hash'] ?? '')!==$approved
                || !hash_equals((string)$proof[$kind]['sha256'],hash('sha256',Importer::canonicalJson($snapshot)))
                || !hash_equals((string)$proof[$kind]['sha256'],hash('sha256',Importer::canonicalJson($row)))) return false;
        }
        if (($file['entity_type'] ?? '')!=='contract' || (int)($file['entity_id'] ?? 0)!==(int)$contract['id']
            || ($file['kind'] ?? '')!=='signed-return-act' || ($file['baseline_hash'] ?? '')!==$approved) return false;
        foreach (['id','version','sha256','upload_order','baseline_hash'] as $key) {
            if (!array_key_exists($key,$file) || !array_key_exists($key,$proof['act'] ?? [])) return false;
            if ($file[$key]===null || $proof['act'][$key]===null) {
                if ($file[$key]!==$proof['act'][$key]) return false;
            } elseif ((string)$file[$key]!== (string)$proof['act'][$key]) return false;
        }
        return true;
    }

    public static function state(array $contract,bool $closed,bool $finance,bool $legal,bool $act): array
    {
        $known=RegistryState::managed($contract) || $contract['finish_date']!==null;
        $open=$contract['status']==='reconciling' || $known && $contract['status']==='ended';
        $missing=[];
        if (!$finance) $missing[]='финансовая проверка';
        if (!$legal) $missing[]='юридическая проверка';
        if (!$act) $missing[]='акт возврата';
        $comment=$contract['status']==='reconciling'
            ?($missing?'Нужны: '.implode(', ',$missing).'.':'Подтверждения получены. Завершите сверку.')
            :($closed?'Все подтверждения получены.':'Подтверждения в исходных данных не представлены.');
        return ['open'=>$open,'closed'=>$closed,'known'=>$known,'severity'=>$contract['status']==='reconciling'?'red':'','comment'=>$comment];
    }

    private static function review(array $row,bool $current,string $positive,bool $known): string
    {
        if (!$current) return $known?'Ожидается':'Нет сведений';
        $meta=array_filter([$row['actor_name'] ?? null,isset($row['confirmed_at'])?RegistryState::date(substr($row['confirmed_at'],0,10)):null]);
        return $positive.($meta?'<div class="doc-meta">'.View::e(implode(' · ',$meta)).'</div>':'');
    }

    public static function page(): string
    {
        Access::pages(['reconciliation']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $party=isset($_GET['reset'])?'all':(string)($_GET['party'] ?? 'all');
        if ($party!=='all' && !ctype_digit($party)) $party='all';
        $flag=isset($_GET['reset'])?'all':(string)($_GET['flag'] ?? 'all');
        if (!in_array($flag,['all','open','closed'],true)) $flag='all';
        $contracts=Controller::query("SELECT c.*,p.name party_name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status IN ('reconciling','ended','closed') ORDER BY c.id DESC");
        $parties=[];$views=[];
        foreach ($contracts as $contract) {
            $parties[(string)$contract['party_id']]=$contract['party_name'];
            if ($party!=='all' && $party!==(string)$contract['party_id']) continue;
            $baseline=Evidence::signature(DB::pdo(),(int)$contract['id']);
            $reviews=[];
            foreach (Controller::query('SELECT r.*,u.name actor_name FROM rental_reviews r LEFT JOIN users u ON u.id=r.actor_id WHERE r.contract_id=?',[$contract['id']]) as $review) $reviews[$review['kind']]=$review;
            $finance=isset($reviews['finance']) && hash_equals($baseline,$reviews['finance']['baseline_hash']);
            $legal=isset($reviews['legal']) && hash_equals($baseline,$reviews['legal']['baseline_hash']);
            $actState=ReturnAct::state(DB::pdo(),$contract,$baseline);
            $file=$actState['file'];
            $act=$actState['confirmed'] && hash_equals($baseline,(string)$file['baseline_hash'])?$file:null;
            $pendingAct=$contract['status']==='reconciling' && !$actState['confirmed']?$file:null;
            $proof=Controller::query("SELECT after_json FROM audit_log WHERE action='contract.completed' AND entity_type='contract' AND entity_id=? ORDER BY id DESC LIMIT 1",[(string)$contract['id']])[0]['after_json'] ?? null;
            $proof=$proof?json_decode($proof,true):null;
            $closed=self::closedProof($contract,$reviews,$file,$proof['closure_proof'] ?? null,$baseline);
            if ($closed) {
                $finance=true;$legal=true;$act=$file;
            }
            $view=self::state($contract,$closed,$finance,$legal,$act!==null);
            $views[]=compact('contract','reviews','finance','legal','act','pendingAct','view');
        }
        $tabs=['all'=>['Все',count($views)],
            'open'=>['Требуют завершения',count(array_filter($views,fn($row)=>!$row['view']['closed'] || $row['view']['open']))],
            'closed'=>['Расчёты закрыты',count(array_filter($views,fn($row)=>$row['view']['closed'] && !$row['view']['open']))]];
        $rows=[];
        foreach ($views as $row) {
            ['contract'=>$contract,'reviews'=>$reviews,'finance'=>$finance,'legal'=>$legal,'act'=>$act,'pendingAct'=>$pendingAct,'view'=>$view]=$row;
            if ($flag==='open' && $view['closed'] && !$view['open'] || $flag==='closed' && (!$view['closed'] || $view['open'])) continue;
            if (!WorkRegistry::matches($query,[$contract['number'],$contract['party_name'],$view['comment'],$contract['finish_reason']])) continue;
            $status=$view['closed'] && !$view['open']?'<strong class="registry-ui-success">Расчёты закрыты</strong>':View::e(Controller::status($contract['status']));
            $rows[]=['_attrs'=>'class="'.RegistryState::rowClass($view['severity']).'" data-rental-url="/rental/contract?id='.(int)$contract['id'].'" tabindex="0"',
                '<strong>'.View::e($contract['number']).'</strong>'.($contract['document_date']?'<div class="doc-meta">'.RegistryState::date($contract['document_date']).'</div>':''),
                View::e($contract['party_name']),RegistryState::date($contract['finish_date'] ?: $contract['end_date']),
                self::review($reviews['finance'] ?? [],$finance,'Подтверждено',$view['known']),
                self::review($reviews['legal'] ?? [],$legal,'Претензий нет',$view['known']),
                ($act?'Акт подтверждён<div class="doc-meta">'.View::e($act['filename']).'</div>':
                    ($pendingAct?'Файл загружен, ожидается подтверждение<div class="doc-meta">'.View::e($pendingAct['filename']).'</div>':
                        ($view['known']?'Ожидается':'Нет сведений'))),
                $status.'<div class="doc-meta">'.View::e($view['comment']).'</div>'];
        }
        $options='<option value="all">Все контрагенты</option>';
        foreach ($parties as $id=>$name) $options.='<option value="'.View::e($id).'"'.((string)$id===$party?' selected':'').'>'.View::e($name).'</option>';
        $controls='<input type="hidden" name="flag" value="'.View::e($flag).'">'
            .'<button type="submit" name="reset" value="1" class="registry-ui-reset"'.($query==='' && $party==='all'?' hidden':'').'>Сбросить фильтры</button>'
            .'<select name="party" id="party-filter" class="field-select toolbar-select" aria-label="Контрагент">'.$options.'</select>'
            .DirectoryUi::search($query,true);
        $body=Controller::heading('Финальная сверка','Взаиморасчёты, юридическая проверка и возврат помещения.')
            .Controller::tabs($tabs,$flag,false,['party'=>$party,'q'=>$query])
            .DirectoryUi::registry('rental-reconciliation',['Договор','Контрагент','Завершение','Бухгалтер','Юрист','Акт возврата','Состояние'],
                $rows,['short','flex','code','text','text','flex','flex'],$controls,filterForm:true);
        return View::page('Финальная сверка',$body,'reconciliation');
    }
}
