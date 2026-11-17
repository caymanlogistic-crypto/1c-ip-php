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
        return in_array($user['role'],['admin','manager'],true) && Auth::can('rental.write')
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
        if (!in_array($flag,['all','available','blocked'],true)) $flag='all';
        $rows=[];
        foreach (Controller::query('SELECT p.*,b.bank,b.account,b.bik,b.correspondent_account,b.source_json bank_source FROM parties p LEFT JOIN party_bank_accounts b ON b.party_id=p.id AND b.operational=1 ORDER BY p.id') as $party) {
            if (!WorkRegistry::matches($query,array_map(static fn($value)=>(string)($value ?? ''),array_intersect_key($party,array_flip([
                'name','inn','contact','phone','email','additional_contact','additional_phone','account_email','edo','edo_id','description','block_reason','bank','account','bik','correspondent_account',
            ]))))) continue;
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
                '<strong>'.View::e($party['name']).'</strong><span class="registry-ui-secondary">ИНН: '.View::e($party['inn'] ?: '—').'</span>'.($party['description']?'<span class="registry-ui-secondary">'.View::e($party['description']).'</span>':''),
                self::contact($party['contact'],$party['phone'],$party['email'],self::selected($party['document_recipient'])),
                self::contact($party['additional_contact'],$party['additional_phone'],$party['account_email'],self::selected($party['additional_document_recipient'])),
                $bank,View::e($party['edo_id'] ?: '—').($party['edo']?'<span class="registry-ui-secondary">'.View::e($party['edo']).'</span>':''),
                $party['blocked']?'<strong class="registry-ui-error">БЛОК</strong>':'Доступен',
            ];
        }
        $head=Controller::heading('Контрагенты','Контакты, реквизиты и состояние работы с клиентом.',self::editable()?RentalUI::button('Создать контрагента','/rental/edit-party',true):'');
        $controls='<form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'.$flag.'">'
            .'<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query===''?' hidden':'').'>Сбросить фильтр</button>'.DirectoryUi::search($query).'</form>';
        return View::page('Контрагенты',$head.Controller::tabs($tabs,$flag,true)
            .DirectoryUi::registry('rental-parties',['Контрагент','Основной контакт','Дополнительный контакт','Банковские реквизиты','ЭДО','Состояние'],$visible,['flex','flex','flex','flex','flex','short'],$controls),'parties');
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
        $body=$party['blocked']?RentalUI::alert('Контрагент заблокирован',$party['block_reason'] ?? '','error'):'';
        $body.=DirectoryUi::pairs([
            ['Наименование',$party['name']],['ИНН',$party['inn']],['Юридический адрес',$party['address']],
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
        Access::pages(['parties'],2); Auth::requirePermission('rental.write'); Service::roles(['manager']);
        $id=(int)($_GET['id'] ?? 0); $party=$id?self::record($id,2):[];
        if (!empty($party['blocked'])) throw new RuntimeException('Работа с контрагентом заблокирована.',403);
        $bank=self::bank($id);
        $body='<div class="registry-ui-modal-grid" data-party-editor=""><div class="registry-ui-tabs" role="tablist" aria-label="Разделы карточки контрагента">';
        foreach (['details'=>'Реквизиты','contacts'=>'Контакты','banks'=>'Банковские реквизиты'] as $key=>$label) {
            $body.='<button type="button" role="tab" data-party-tab="'.$key.'" id="party-'.$key.'-tab" aria-controls="party-'.$key.'-panel" aria-selected="'.($key==='details'?'true':'false').'" class="'.($key==='details'?'is-active':'').'">'.$label.'</button>';
        }
        $body.='</div><section class="registry-ui-modal-grid" role="tabpanel" data-party-panel="details" id="party-details-panel" aria-labelledby="party-details-tab">'
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'.DirectoryUi::input('name','Полное наименование',$party['name'] ?? '','text',true).DirectoryUi::input('inn','ИНН',$party['inn'] ?? '').'</div>'
            .'<fieldset class="registry-ui-modal-grid"><legend class="registry-ui-modal-summary">Адреса</legend>'
            .DirectoryUi::input('address','Юридический адрес',$party['address'] ?? '')
            .DirectoryUi::checkbox('postal_same_as_legal','Почтовый адрес совпадает с юридическим',$party?self::selected($party['postal_same_as_legal']):true)
            .DirectoryUi::input('postal_address','Почтовый адрес',$party['postal_address'] ?? '').'</fieldset>'
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'.DirectoryUi::input('edo','Оператор ЭДО',$party['edo'] ?? '').DirectoryUi::input('edo_id','Идентификатор ЭДО',$party['edo_id'] ?? '').'</div></section>'
            .'<section class="registry-ui-modal-grid registry-ui-modal-grid--reading" role="tabpanel" data-party-panel="contacts" id="party-contacts-panel" aria-labelledby="party-contacts-tab" hidden>';
        foreach ([['Основной контакт','contact','phone','email','document_recipient'],['Дополнительный контакт (необязательно)','additional_contact','additional_phone','account_email','additional_document_recipient']] as $index=>[$title,$name,$phone,$email,$recipient]) {
            $body.='<fieldset class="registry-ui-modal-grid"><legend class="registry-ui-modal-summary">'.$title.'</legend>'
                .DirectoryUi::input($name,'ФИО',$party[$name] ?? '').DirectoryUi::input($phone,'Телефон',$party[$phone] ?? '','tel')
                .DirectoryUi::input($email,'Электронная почта',$party[$email] ?? '')
                .DirectoryUi::checkbox($recipient,'Получает документацию на email',$party?self::selected($party[$recipient]):$index===0).'</fieldset>';
        }
        $body.='</section><section class="registry-ui-modal-grid" role="tabpanel" data-party-panel="banks" id="party-banks-panel" aria-labelledby="party-banks-tab" hidden>'
            .'<section class="registry-ui-modal-grid registry-ui-modal-grid--reading" data-party-bank data-bank-prefix="">';
        foreach (['bank'=>'Наименование банка','account'=>'Расчётный счёт','bik'=>'БИК','correspondent_account'=>'Корреспондентский счёт'] as $key=>$label) {
            $body.=DirectoryUi::input($key,$label,$key==='bank' && $bank?DirectoryUi::bankName($bank):($bank[$key] ?? ''),'text',true);
        }
        $body.='</section></section></div>';
        [$form,$actions]=DirectoryUi::form('party.save',$party,$body);
        return RentalUI::modal($id?'Редактировать контрагента':'Новый контрагент',$form,$actions,'parties',false,$party?self::statusButton($party):'');
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
