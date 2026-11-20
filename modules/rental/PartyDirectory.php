<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy,View};
use RuntimeException;

final class PartyDirectory
{
    private static function record(int $id,int $level=1): array
    {
        Access::pages(['parties'],$level);
        return Controller::query('SELECT * FROM parties WHERE id=?',[$id])[0] ?? throw new RuntimeException('Контрагент не найден.',404);
    }

    private static function editable(?array $party=null): bool
    {
        $user=Auth::user();
        $roles=DirectoryRules::partySaveRoles((int)($party['id'] ?? 0));
        return in_array($user['role'],['admin', ...$roles],true) && Auth::can(Service::commandGrant('party.save',$user))
            && PagePolicy::level('rental.parties',$user)>=2 && empty($party['blocked']);
    }

    private static function selected(mixed $value): bool
    {
        return in_array($value,[true,1,'1','true'],true);
    }

    private static function statusButton(array $party): string
    {
        $user=Auth::user();
        if (PagePolicy::level('rental.parties',$user)<2) return '';
        $blocked=(bool)$party['blocked'];
        if ($blocked && (!in_array($user['role'],['admin','director'],true) || !Auth::can('rental.approve.executive'))) return '';
        $grant=match($user['role']) {'lawyer'=>'rental.approve.legal','accountant'=>'finance.write','director'=>'rental.approve.executive',default=>'rental.write'};
        if (!Auth::can($grant)) return '';
        $label=($blocked?'Разблокировать':'Заблокировать').' работу с контрагентом';
        return '<button type="button" class="btn '.($blocked?'btn-secondary btn-success':'btn-danger').'" data-rental-nested-open="/rental/party-status?id='.(int)$party['id'].'&amp;action=party.'.($blocked?'unblock':'block').'">'.$label.'</button>';
    }

    private static function bank(?int $id): ?array
    {
        return $id ? (Controller::query('SELECT * FROM party_bank_accounts WHERE party_id=? AND operational=1 ORDER BY id LIMIT 1',[$id])[0] ?? null) : null;
    }

    public static function page(): string
    {
        Access::pages(['parties']);
        $query=isset($_GET['reset'])?'':trim((string)($_GET['q'] ?? ''));
        $flag=(string)($_GET['flag'] ?? 'all');
        $origin=(string)($_GET['origin'] ?? 'all');
        if (!in_array($origin,['all','onec','local'],true)) $origin='all';
        if (!in_array($flag,['all','available','blocked'],true)) $flag='all';
        $rows=[];
        foreach (Controller::query('SELECT p.*,b.bank,b.account,b.bik,b.correspondent_account,b.source_json bank_source FROM parties p LEFT JOIN party_bank_accounts b ON b.party_id=p.id AND b.operational=1 ORDER BY p.id') as $party) {
            $party['_origin']=PartyOrigin::read($party);
            if ($origin!=='all' && $party['_origin']['kind']!==$origin) continue;
            if (!WorkRegistry::matches($query,array_map(static fn($value)=>(string)($value ?? ''),array_merge(array_intersect_key($party,array_flip([
                'name','inn','contact','phone','email','additional_contact','additional_phone','account_email','edo','edo_id','description','block_reason','bank','account','bik','correspondent_account',
            ])),array_values($party['_origin']))))) continue;
            $rows[]=$party;
        }
        $tabs=['all'=>['Все контрагенты',count($rows)],'available'=>['Доступные',count(array_filter($rows,static fn($p)=>!$p['blocked']))],'blocked'=>['Заблокированные',count(array_filter($rows,static fn($p)=>(bool)$p['blocked']))]];
        $visible=[];
        foreach ($rows as $party) {
            if ($flag==='available' && $party['blocked'] || $flag==='blocked' && !$party['blocked']) continue;
            $url=self::editable($party)?'/rental/edit-party?id=':'/rental/party?id=';
            $bankName=DirectoryUi::bankName(['bank'=>$party['bank'],'source_json'=>$party['bank_source'] ?? '{}']);
            $bank=$party['account']?'<div>р/с '.View::e($party['account']).' · '.View::e($bankName ?: '—').'</div><div class="doc-meta">БИК '.View::e($party['bik'] ?: '—').' · к/с '.View::e($party['correspondent_account'] ?: '—').'</div>':'—';
            $visible[]=[
                '_attrs'=>'class="'.($party['blocked']?'registry-ui-soft-error':'').'" data-rental-url="'.$url.(int)$party['id'].'" tabindex="0"',
                '<strong>'.View::e($party['name']).'</strong><span class="registry-ui-secondary">ИНН: '.View::e($party['inn'] ?: '—').'</span><span class="registry-ui-secondary">'.View::e($party['_origin']['label']).($party['_origin']['code']?' · '.View::e($party['_origin']['code']):'').'</span>'.($party['description']?'<span class="registry-ui-secondary">'.View::e($party['description']).'</span>':''),
                self::contact($party['contact'],$party['phone'],$party['email'],self::selected($party['document_recipient'])),
                self::contact($party['additional_contact'],$party['additional_phone'],$party['account_email'],self::selected($party['additional_document_recipient'])),
                $bank,View::e($party['edo_id'] ?: '—').($party['edo']?'<span class="registry-ui-secondary">'.View::e($party['edo']).'</span>':''),
                $party['blocked']?'<strong class="registry-ui-error">БЛОК</strong>':'Доступен',
            ];
        }
        $head=Controller::heading('Контрагенты','Контакты, реквизиты и состояние работы с клиентом.',self::editable()?RentalUI::button('Создать контрагента','/rental/edit-party',true):'');
        $controls='<input type="hidden" name="flag" value="'.$flag.'">'
            .'<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query==='' && $origin==='all'?' hidden':'').'>Сбросить фильтр</button><select class="toolbar-select" name="origin" aria-label="Источник контрагента">';
        foreach (['all'=>'Все источники','onec'=>'Из 1С','local'=>'Созданы в базе'] as $value=>$label)
            $controls.='<option value="'.$value.'"'.($origin===$value?' selected':'').'>'.$label.'</option>';
        $controls.='</select>'.DirectoryUi::search($query,true);
        return View::page('Контрагенты',$head.Controller::tabs($tabs,$flag,true,['origin'=>$origin,'q'=>$query])
            .DirectoryUi::registry('rental-parties',['Контрагент','Основной контакт','Дополнительный контакт','Банковские реквизиты','ЭДО','Состояние'],$visible,['flex','flex','flex','flex','flex','short'],$controls,filterForm:true),'parties');
    }

    private static function contact(?string $name,?string $phone,?string $email,bool $selected): string
    {
        $details=[];
        if ($phone) $details[]=View::e($phone);
        if ($email) $details[]=$selected?'<strong class="registry-ui-success">'.View::e($email).'</strong>':View::e($email);
        return View::e($name ?: '—').($details?'<div class="doc-meta">'.implode(' · ',$details).'</div>':'');
    }

    public static function card(): string
    {
        $party=self::record((int)($_GET['id'] ?? 0));
        $origin=PartyOrigin::read($party);
        $body=$party['blocked']?RentalUI::alert('Контрагент заблокирован',$party['block_reason'] ?? '','error'):'';
        $body.=DirectoryUi::pairs([
            ['Наименование',$party['name']],['Источник',$origin['label']],['ID в базе',$origin['local_id']],['Код 1С',$origin['code'] ?: '—'],['GUID 1С',$origin['guid'] ?: '—'],['ИНН',$party['inn']],['Юридический адрес',$party['address']],
            ['Почтовый адрес',$party['postal_address'] ?: 'Не указан'],['Основной контакт',$party['contact']],['Телефон',$party['phone']],
            ['Электронная почта',$party['email']],['Получает документацию',self::selected($party['document_recipient'])?'Да':'Нет'],
            ['Дополнительный контакт',$party['additional_contact'] ?: 'Не указан'],['Телефон',$party['additional_phone']],
            ['Электронная почта',$party['account_email']],['Получает документацию',self::selected($party['additional_document_recipient'])?'Да':'Нет'],
            ['Система ЭДО',$party['edo']],['Идентификатор ЭДО',$party['edo_id']],
        ]).'<span class="registry-ui-modal-summary">Банковские реквизиты</span>';
        $bank=self::bank((int)$party['id']);
        $body.=Controller::table(['Основной','Банк','Расч. счёт','БИК','Корр. счёт'],$bank?[
            ['Основной',View::e(DirectoryUi::bankName($bank)),View::e($bank['account']),View::e($bank['bik']),View::e($bank['correspondent_account'])],
        ]:[],['short','text','code','code','code']);
        $actions=self::editable($party)?RentalUI::button('Редактировать','/rental/edit-party?id='.$party['id'],true):'';
        return RentalUI::modal('Контрагент: '.$party['name'],$body,$actions,'parties',false,self::statusButton($party));
    }

    public static function editor(): string
    {
        $id=(int)($_GET['id'] ?? 0);
        Access::pages(['parties'],2);
        $user=Service::roles(DirectoryRules::partySaveRoles($id));
        Auth::requirePermission(Service::commandGrant('party.save',$user));
        $party=$id?self::record($id,2):[];
        if (!empty($party['blocked'])) throw new RuntimeException('Работа с контрагентом заблокирована.',403);
        $bank=self::bank($id);
        $provenance=$id?PartyOrigin::read($party):['label'=>'Создан в базе','local_id'=>'Будет присвоен','code'=>null,'guid'=>null];
        $body=DirectoryUi::pairs([['Источник',$provenance['label']],['ID в базе',$provenance['local_id']],['Код 1С',$provenance['code'] ?: '—'],['GUID 1С',$provenance['guid'] ?: '—']]);
        $body.='<div class="registry-ui-form-sections registry-ui-form-sections--compact" data-party-editor>'
            .'<section class="registry-ui-form-section" aria-labelledby="party-organization-title"><h3 class="registry-ui-modal-summary" id="party-organization-title">Данные организации</h3>'
            .'<div class="registry-ui-form-row registry-ui-form-row--wide-code">'
            .DirectoryUi::input('name','Полное наименование',$party['name'] ?? '','text',true)
            .DirectoryUi::input('inn','ИНН',$party['inn'] ?? '').'</div></section><div class="registry-ui-form-composition"><section class="registry-ui-form-section" aria-label="Адреса"><h3 class="registry-ui-modal-summary">Адреса</h3>'
            .DirectoryUi::input('address','Юридический адрес',$party['address'] ?? '')
            .DirectoryUi::inputWithOption('postal_address','Почтовый адрес',$party['postal_address'] ?? '', 'postal_same_as_legal','Совпадает с юридическим адресом','Почтовый адрес совпадает с юридическим',$party?self::selected($party['postal_same_as_legal']):true).'</section>';
        foreach ([['Основной контакт','contact','phone','email','document_recipient'],['Дополнительный контакт','additional_contact','additional_phone','account_email','additional_document_recipient']] as $index=>[$title,$name,$phone,$email,$recipient]) {
            $selected=$party?self::selected($party[$recipient]):$index===0;
            if($index===1){
                $hasData=$selected;
                foreach([$name,$phone,$email] as $field)if(trim((string)($party[$field] ?? ''))!=='')$hasData=true;
                $body.='<details class="registry-ui-form-disclosure" data-form-disclosure'.($hasData?' open':'').'><summary class="btn btn-secondary"><span data-disclosure-collapsed>Добавить контакт</span><span data-disclosure-expanded>Дополнительный контакт · Скрыть</span></summary>';
            }
            $body.='<section class="registry-ui-form-section" aria-labelledby="party-contact-'.$index.'"><h3 class="registry-ui-modal-summary" id="party-contact-'.$index.'">'.$title.'</h3>'
                .DirectoryUi::input($name,'ФИО',$party[$name] ?? '')
                .'<div class="registry-ui-form-row">'.DirectoryUi::input($phone,'Телефон',$party[$phone] ?? '','tel')
                .DirectoryUi::input($email,'Электронная почта',$party[$email] ?? '').'</div>'
                .DirectoryUi::checkbox($recipient,'Получает документы',$selected).'</section>';
            if($index===1)$body.='</details>';
        }
        $bankValue=fn(string $key)=>$key==='bank' && $bank?DirectoryUi::bankName($bank):($bank[$key] ?? '');
        $body.='</div><section class="registry-ui-form-section" data-party-bank data-bank-prefix="" aria-labelledby="party-bank-title"><h3 class="registry-ui-modal-summary" id="party-bank-title">Банковские реквизиты</h3>'
            .'<div class="registry-ui-form-row registry-ui-form-row--wide-code">'
            .DirectoryUi::input('bank','Наименование банка',$bankValue('bank'),'text',true)
            .DirectoryUi::input('bik','БИК',$bankValue('bik'),'text',true).'</div>'
            .'<div class="registry-ui-form-row">'
            .DirectoryUi::input('account','Расчётный счёт',$bankValue('account'),'text',true)
            .DirectoryUi::input('correspondent_account','Корреспондентский счёт',$bankValue('correspondent_account'),'text',true).'</div></section>'
            .'<section class="registry-ui-form-section" aria-labelledby="party-edo-title"><h3 class="registry-ui-modal-summary" id="party-edo-title">ЭДО</h3><div class="registry-ui-form-row">'
            .DirectoryUi::input('edo','Оператор ЭДО',$party['edo'] ?? '').DirectoryUi::input('edo_id','Идентификатор ЭДО',$party['edo_id'] ?? '').'</div></section></div>';
        [$form,$actions,$formId]=DirectoryUi::form('party.save',$party,$body);
        // Temporary presentation helper, only for a new party opened from a contract.
        $demo=!$id && ($_GET['context'] ?? '')==='contract-create'
            ?'<button class="btn btn-danger" type="button" form="'.View::e($formId).'" data-party-demo-fill="company">Демо: ООО</button>'
            .'<button class="btn btn-danger" type="button" form="'.View::e($formId).'" data-party-demo-fill="entrepreneur">Демо: ИП</button>':'';
        return RentalUI::modal($id?'Редактировать контрагента':'Новый контрагент',$form,$actions,'parties',true,$party?self::statusButton($party):$demo,canvas:true);
    }

    public static function status(): string
    {
        $action=(string)($_GET['action'] ?? '');
        if (!in_array($action,['party.block','party.unblock'],true)) throw new RuntimeException('Действие не найдено.',404);
        PagePolicy::requireCommand($action); Access::pages(['parties'],2);
        $user=Service::roles($action==='party.unblock'?['director']:['manager','lawyer','accountant','director']);
        Auth::requirePermission($action==='party.unblock'?'rental.approve.executive':match($user['role']) {'lawyer'=>'rental.approve.legal','accountant'=>'finance.write','director'=>'rental.approve.executive',default=>'rental.write'});
        $party=self::record((int)($_GET['id'] ?? 0),2);
        if ((bool)$party['blocked']!==($action==='party.unblock')) throw new RuntimeException('Состояние контрагента уже изменилось.',409);
        $blocked=$action==='party.block';
        $title=($blocked?'Заблокировать':'Разблокировать').' работу с контрагентом';
        $fields='<span class="registry-ui-modal-help">'.View::e($party['name']).'</span><label class="field"><span class="field-label">Причина <span class="req">*</span></span><textarea class="field-textarea" name="reason" required></textarea></label>';
        [$body,$actions]=DirectoryUi::form($action,$party,$fields,$blocked?'Заблокировать':'Разблокировать');
        $actions=str_replace('btn-primary',$blocked?'btn-danger':'btn-secondary btn-success',$actions);
        return RentalUI::modal($title,$body,$actions,'parties',false,'','Отмена');
    }
}
