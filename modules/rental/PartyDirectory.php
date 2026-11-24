<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,PagePolicy,View};
use RuntimeException;

final class PartyDirectory
{
    private static function record(int $id,int $level=1): array
    {
        Access::pages(['parties'],$level);
        return Controller::query('SELECT * FROM parties p WHERE p.id=? AND '.WorkspaceReset::visibleSql('p'),[$id])[0]
            ?? throw new RuntimeException('Контрагент не найден.',404);
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

    private static function accessibleContracts(int $partyId): iterable
    {
        foreach (Controller::query('SELECT id,number,document_date,end_date,finish_date,status,source_json FROM contracts c WHERE party_id=? AND '
            .ContractDraft::visibleSql('c').' ORDER BY document_date DESC,id DESC',[$partyId]) as $contract) {
            try {Access::contract((int)$contract['id']);}
            catch (RuntimeException $error) {if ($error->getCode()===403) continue; throw $error;}
            yield $contract;
        }
    }

    private static function contracts(int $partyId): string
    {
        $links=[];
        foreach (self::accessibleContracts($partyId) as $contract) {
            $label='№ '.$contract['number'].($contract['document_date']?' от '.Documents::dateText($contract['document_date']):'');
            $links[]=RentalUI::button($label,'/rental/contract?id='.(int)$contract['id']);
            array_push($links,...Documents::signedContractLinks((int)$contract['id'],' '.$label));
        }
        return $links?'<div class="registry-ui-secondary">Договоры · '.implode(' · ',$links).'</div>':'';
    }

    private static function contractSection(int $partyId): string
    {
        $rows=[];
        foreach (self::accessibleContracts($partyId) as $contract) {
            $id=(int)$contract['id'];
            $signed=Documents::signedContractLinks($id);
            $section=LeaseRegistry::section($contract);
            $stage=RegistryState::stage($contract);
            $completed=$section==='contracts-completed';
            $status=match (true) {
                $completed=>'<strong class="registry-ui-error">ЗАВЕРШЕННЫЙ</strong>',
                $section==='contracts-active' && $stage==='Действует'=>'<strong class="registry-ui-success">ДЕЙСТВУЮЩИЙ</strong>',
                default=>View::e($stage),
            };
            $rows[]=[
                RentalUI::button('№ '.$contract['number'],'/rental/contract?id='.$id),
                View::e(RegistryState::date($contract['document_date'])),
                View::e(RegistryState::date($completed && $contract['finish_date']?$contract['finish_date']:$contract['end_date'])),
                $status,
                $signed?'<div class="demo-row">'.implode('',$signed).'</div>':'—',
            ];
        }
        $body=$rows?'<div class="registry-ui-review-table">'
            .Controller::table(['Номер','Дата','Окончание','Статус','Подписанный экземпляр'],$rows,
                ['short','short','short','text','flex'],inferRowLinks:false).'</div>'
            :'<p class="registry-ui-modal-text">Нет доступных договоров.</p>';
        return RentalUI::reviewSection('Договоры',$body);
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
        foreach (Controller::query("SELECT p.*,b.bank,b.account,b.bik,b.correspondent_account,b.source_json bank_source,"
            ."NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.fact_json,'$.search_name')),'') search_name FROM parties p "
            ."LEFT JOIN party_bank_accounts b ON b.party_id=p.id AND b.operational=1 "
            ."LEFT JOIN rental_facts d ON d.namespace='directory_parties' AND d.source_key=CAST(p.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND d.operative=1 "
            .'WHERE '.WorkspaceReset::visibleSql('p').' ORDER BY p.id') as $party) {
            $party['_origin']=PartyOrigin::read($party);
            if ($origin!=='all' && $party['_origin']['kind']!==$origin) continue;
            if (!WorkRegistry::matches($query,array_map(static fn($value)=>(string)($value ?? ''),array_merge(array_intersect_key($party,array_flip([
                'name','search_name','inn','contact','phone','email','additional_contact','additional_phone','account_email','edo','edo_id','description','block_reason','bank','account','bik','correspondent_account',
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
                '<strong>'.Controller::link('/rental/party?id='.(int)$party['id'],$party['search_name'] ?: $party['name']).'</strong>'
                    .($party['search_name']&&$party['search_name']!==$party['name']?'<span class="registry-ui-secondary">'.View::e($party['name']).'</span>':'')
                    .'<span class="registry-ui-secondary">ИНН: '.View::e($party['inn'] ?: '—').'</span><span class="registry-ui-secondary">'.View::e($party['_origin']['label']).($party['_origin']['code']?' · '.View::e($party['_origin']['code']):'').'</span>'
                    .($party['description']?'<span class="registry-ui-secondary">'.View::e($party['description']).'</span>':'')
                    .($query!==''?self::contracts((int)$party['id']):''),
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
            .DirectoryUi::registry('rental-parties',['Контрагент','Основной контакт','Дополнительный контакт','Банковские реквизиты','ЭДО','Состояние'],
                $visible,['flex','flex','flex','flex','flex','short'],$controls,filterForm:true,inferRowLinks:false),'parties');
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
        $body.='<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .self::reviewDetails($party,true).PartyLegalReview::presentation($party,structured:true)
            .self::contractSection((int)$party['id']).'</div>';
        $actions=self::editAction($party).PartyLegalReview::actions($party);
        return RentalUI::modal('Контрагент: '.$party['name'],$body,$actions,'parties',false,self::statusButton($party));
    }

    private static function editAction(array $party): string
    {
        return self::editable($party)?RentalUI::button('Редактировать','/rental/edit-party?id='.$party['id'],true):'';
    }

    public static function reviewModal(array $party): string
    {
        return RentalUI::modal('Контрагент · '.$party['name'],self::reviewDetails($party),self::editAction($party),
            active:'approvals',closeLabel:'Вернуться');
    }

    public static function reviewDetails(array $party,bool $technical=false): string
    {
        $details=DirectoryDetails::read(DB::pdo(),'parties',(int)$party['id']);
        $type=$details['party_type'] ?? '';
        $requisites=match($type) {
            'company'=>[['КПП',$party['kpp']],['ОГРН',$details['ogrn'] ?? '']],
            'entrepreneur'=>[['ОГРНИП',$details['ogrnip'] ?? '']],
            'person'=>[['Почта для чека',$details['receipt_email'] ?? '']],
            default=>[],
        };
        if ($type==='person' && in_array(Auth::user()['role'],['manager','lawyer','admin'],true))
            $requisites[]=['Паспортные сведения',$details['passport'] ?? ''];
        $originFields=[];
        if ($technical) {
            $origin=PartyOrigin::read($party);
            $originFields=[['Источник',$origin['label']],['ID в базе',$origin['local_id']],
                ['Код 1С',$origin['code'] ?: '—'],['GUID 1С',$origin['guid'] ?: '—']];
        }
        $client=[
            ['Тип клиента',DirectoryRules::PARTY_TYPES[$type] ?? '—'],
            ['Название для поиска',($details['search_name'] ?? '') ?: $party['name']],['Название в документах',$party['name']],
            ...$originFields,['ИНН',$party['inn']],['Адрес',$party['address']],
            ...$requisites,['Почтовый адрес',$party['postal_address'] ?: 'Не указан'],
        ];
        $primary=[
            ['Основной контакт',$party['contact']],['Телефон',$party['phone']],
            ['Должность',$details['contact_position'] ?? ''],['Роль контакта',$details['contact_role'] ?? ''],
            ['Электронная почта',$party['email']],['Получает документацию',self::selected($party['document_recipient'])?'Да':'Нет'],
        ];
        $additional=[
            ['Дополнительный контакт',$party['additional_contact'] ?: 'Не указан'],['Телефон',$party['additional_phone']],
            ['Должность',$details['additional_contact_position'] ?? ''],['Роль контакта',$details['additional_contact_role'] ?? ''],
            ['Электронная почта',$party['account_email']],['Получает документацию',self::selected($party['additional_document_recipient'])?'Да':'Нет'],
        ];
        $signer=[
            [$technical?'ФИО':'Подписант',$details['signer'] ?? ''],
            [$technical?'Должность':'Должность подписанта',$details['signer_position'] ?? ''],
            ['Основание полномочий',$details['signer_basis'] ?? ''],
        ];
        $edo=[['Система ЭДО',$party['edo']],['Идентификатор ЭДО',$party['edo_id']]];
        $bank=self::bank((int)$party['id']);
        $accounts=Controller::table(['Основной','Банк','Расч. счёт','БИК','Корр. счёт'],$bank?[
            ['Основной',View::e(DirectoryUi::bankName($bank)),View::e($bank['account']),View::e($bank['bik']),View::e($bank['correspondent_account'])],
        ]:[],['short','text','code','code','code']);
        if (!$technical) {
            return DirectoryUi::pairs([...$client,...$primary,...$additional,...$signer,...$edo])
                .'<span class="registry-ui-modal-summary">Банковские реквизиты</span>'.$accounts;
        }
        return RentalUI::reviewSection('Данные клиента',DirectoryUi::pairs($client))
            .RentalUI::reviewSection('Контакты','<div class="registry-ui-form-row">'
                .DirectoryUi::pairs($primary).DirectoryUi::pairs($additional).'</div>')
            .RentalUI::reviewSection('Подписант',DirectoryUi::pairs($signer))
            .RentalUI::reviewSection('Банковские реквизиты',$accounts)
            .RentalUI::reviewSection('ЭДО',DirectoryUi::pairs($edo));
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
        $details=$id?DirectoryDetails::read(DB::pdo(),'parties',$id):[];
        $sectionClass='registry-ui-form-section registry-ui-review-section';
        $body='<div class="registry-ui-modal-grid" data-party-editor'.(!$id?' data-form-error-notification':'').'>'
            .'<section class="'.$sectionClass.'" aria-labelledby="party-organization-title"><h3 class="modal-title" id="party-organization-title">Данные клиента</h3>'
            .'<div class="registry-ui-form-section" role="group" aria-label="Основные данные">'
            .'<div class="registry-ui-form-row">'
            .DirectoryUi::input('name','Название в документах / ФИО',$party['name'] ?? '','text',true)
            .'<div class="field"><div class="field-label-row"><label class="field-label" for="party-reference-inn">ИНН</label></div>'
            .'<div class="registry-ui-modal-address-row"><input class="field-input" id="party-reference-inn" name="inn" type="text" value="'.View::e($party['inn'] ?? '').'">'
            .'<button class="btn btn-secondary" type="button" data-party-reference="inn">'.($id?'Обновить реквизиты':'Заполнить по ИНН').'</button>'
            .'</div>'
            .'<strong id="party-reference-inn-error" class="field-msg is-error" role="alert" hidden></strong>'
            .'</div></div>'
            .'<div class="registry-ui-modal-grid" data-party-reference-result="inn" aria-live="polite" hidden></div>'
            .'<div class="form-grid-4">'.DirectoryUi::select('party_type','Тип клиента',[''=>$id?'Уточнить тип':'Выберите тип']+DirectoryRules::PARTY_TYPES,
                $details['party_type'] ?? '',!$id,alignedLabel:true)
            .'<div data-party-type-fields="company">'.DirectoryUi::input('kpp','КПП',$party['kpp'] ?? '').'</div>'
            .'<div data-party-type-fields="company">'.DirectoryUi::input('ogrn','ОГРН',$details['ogrn'] ?? '').'</div>'
            .'<div data-party-type-fields="entrepreneur">'.DirectoryUi::input('ogrnip','ОГРНИП',$details['ogrnip'] ?? '').'</div>'
            .(DirectoryRules::canEditPartyPassport($id,$user['role'])
                ?'<div data-party-type-fields="person">'.DirectoryUi::input('passport','Паспортные сведения',$details['passport'] ?? '').'</div>':'')
            .'<div data-party-type-fields="person">'.DirectoryUi::input('receipt_email','Почта для чека',$details['receipt_email'] ?? '','email').'</div>'
            .DirectoryUi::input('search_name','Название для поиска',$details['search_name'] ?? '').'</div></div>'
            .'<section class="registry-ui-form-section" aria-labelledby="party-signer-title"><div class="registry-ui-form-section-heading">'
            .'<h4 class="registry-ui-modal-summary" id="party-signer-title">Подписант</h4></div>'
            .'<div class="form-grid-3">'
            .DirectoryUi::input('signer','ФИО подписанта',$details['signer'] ?? '')
            .DirectoryUi::input('signer_position','Должность подписанта',$details['signer_position'] ?? '')
            .DirectoryUi::input('signer_basis','Основание полномочий',$details['signer_basis'] ?? '').'</div></section>'
            .'<section class="registry-ui-form-section" aria-labelledby="party-address-title"><div class="registry-ui-form-section-heading">'
            .'<h4 class="registry-ui-modal-summary" id="party-address-title">Адреса</h4></div>'
            .'<div class="registry-ui-form-row">'.DirectoryUi::input('address','Адрес / юридический адрес',$party['address'] ?? '')
            .'<div class="registry-ui-form-section">'.DirectoryUi::input('postal_address','Почтовый адрес',$party['postal_address'] ?? '')
            .DirectoryUi::checkbox('postal_same_as_legal','Совпадает с юридическим адресом',!$id || self::selected($party['postal_same_as_legal']))
            .'</div></div></section></section>';
        $body.='<section class="'.$sectionClass.'" aria-labelledby="party-contacts-title"><div class="registry-ui-form-section-heading">'
            .'<h3 class="modal-title" id="party-contacts-title">Контакты</h3>'
            .'<button class="btn btn-secondary" type="button" data-form-disclosure-control aria-expanded="false" aria-controls="party-additional-contact" hidden>Добавить контакт</button></div>';
        foreach ([['Основной контакт','contact','phone','email','document_recipient'],['Дополнительный контакт','additional_contact','additional_phone','account_email','additional_document_recipient']] as $index=>[$title,$name,$phone,$email,$recipient]) {
            $selected=$party?self::selected($party[$recipient]):$index===0;
            if($index===1){
                $hasData=$selected;
                foreach([$name,$phone,$email] as $field)if(trim((string)($party[$field] ?? ''))!=='')$hasData=true;
                $body.='<details id="party-additional-contact" class="registry-ui-form-disclosure" data-form-disclosure'.($hasData?' open':'').'><summary class="btn btn-secondary"><span data-disclosure-collapsed>Добавить контакт</span><span data-disclosure-expanded>Дополнительный контакт · Скрыть</span></summary>';
            }
            $nameField=DirectoryUi::input($name,'ФИО',$party[$name] ?? '');
            $phoneField=DirectoryUi::input($phone,'Телефон',$party[$phone] ?? '','tel');
            $emailField=DirectoryUi::input($email,'Электронная почта',$party[$email] ?? '');
            $positionField=DirectoryUi::input($name.'_position','Должность',$details[$name.'_position'] ?? '');
            $roleField=DirectoryUi::input($name.'_role','Роль контакта',$details[$name.'_role'] ?? '');
            $recipientField=DirectoryUi::checkbox($recipient,'Получает документы',$selected);
            $body.='<section class="registry-ui-form-section" aria-label="'.$title.'"><div class="form-grid-3">';
            foreach ([$nameField.$positionField,$phoneField.$roleField] as $column) {
                $body.='<div><div class="registry-ui-form-section">'.$column.'</div></div>';
            }
            $body.='<div class="field">'.$emailField.$recipientField.'</div></div></section>';
            if($index===1)$body.='</details>';
        }
        $bankValue=fn(string $key)=>$key==='bank' && $bank?DirectoryUi::bankName($bank):($bank[$key] ?? '');
        $body.='</section>'
            .'<section class="'.$sectionClass.'" data-party-bank data-bank-required="'.(!$id || $bank?'1':'0').'" data-bank-prefix="" aria-labelledby="party-bank-title">'
            .'<div class="registry-ui-form-section-heading"><h3 class="modal-title" id="party-bank-title">Банковские реквизиты</h3>'
            .'<button class="btn btn-secondary" type="button" data-party-reference="bik">Найти банк по БИК</button></div>'
            .'<div class="form-grid-4">'.DirectoryUi::input('account','Расчётный счёт',$bankValue('account'),'text',true)
            .'<div class="field"><div class="field-label-row"><label class="field-label" for="party-reference-bik">БИК <span class="req">*</span></label></div>'
            .'<input class="field-input" id="party-reference-bik" name="bik" type="text" value="'.View::e($bankValue('bik')).'" required>'
            .'<strong id="party-reference-bik-error" class="field-msg is-error" role="alert" hidden></strong></div>'
            .DirectoryUi::input('bank','Наименование банка',$bankValue('bank'),'text',true)
            .DirectoryUi::input('correspondent_account','Корреспондентский счёт',$bankValue('correspondent_account'),'text',true).'</div>'
            .'<div class="registry-ui-modal-grid" data-party-reference-result="bik" aria-live="polite" hidden></div></section>'
            .'<section class="'.$sectionClass.'" aria-labelledby="party-edo-title"><h3 class="modal-title" id="party-edo-title">ЭДО</h3><div class="registry-ui-form-row">'
            .self::edoOperator($party['edo'] ?? '').DirectoryUi::input('edo_id','Идентификатор ЭДО',$party['edo_id'] ?? '').'</div>'
            .'<div class="registry-ui-modal-grid" data-edo-conflict hidden><span role="status"></span>'
            .'<button class="btn btn-secondary" type="button" data-edo-apply>Подставить оператора</button></div></section></div>';
        [$form,$actions]=DirectoryUi::form('party.save',$party,$body);
        return RentalUI::modal($id?'Редактировать контрагента':'Новый контрагент',$form,$actions,'parties',
            leadingActions:$party?self::statusButton($party):'',tall:true);
    }

    private static function edoOperator(string $current): string
    {
        $catalog=json_decode(file_get_contents(__DIR__.'/data/edo-operators.json'),true,16,JSON_THROW_ON_ERROR);
        $html='<label class="field"><span class="field-label-row"><span class="field-label">Оператор ЭДО</span></span>'
            .'<select class="field-select field-select--input-height" name="edo" data-rental-picker="edo" aria-label="Оператор ЭДО"><option value="">Не указан</option>';
        $found=false;
        foreach ($catalog['operators'] as $operator) {
            $name=$operator['name'];
            $selected=$name===$current;
            $found=$found || $selected;
            $prefixes=implode(' ',$operator['prefixes']);
            $html.='<option value="'.View::e($name).'" data-prefixes="'.View::e($prefixes).'" data-description="'
                .View::e(implode(', ',$operator['prefixes'])).'"'.($selected?' selected':'').'>'.View::e($name).'</option>';
        }
        if (!$found && $current!=='') $html.='<option selected value="'.View::e($current).'">'.View::e($current).'</option>';
        return $html.'</select></label>';
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
