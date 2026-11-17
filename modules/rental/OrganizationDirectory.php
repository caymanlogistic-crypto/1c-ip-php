<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,PagePolicy,View};
use RuntimeException;

final class OrganizationDirectory
{
    private static function record(int $level=1): array
    {
        Access::pages(['organization'],$level);
        return Controller::query('SELECT * FROM organization WHERE id=1')[0] ?? throw new RuntimeException('Организация не найдена.',404);
    }

    private static function editable(): bool
    {
        $actor=Auth::user();
        return in_array($actor['role'],['admin','accountant'],true) && Auth::can('finance.write')
            && PagePolicy::level('rental.organization',$actor)>=2;
    }

    public static function page(): string
    {
        $organization=self::record();
        $edit=self::editable();
        $body=Controller::heading('Наша организация','Реквизиты ИП и банковские счета.',
            $edit?RentalUI::button('Редактировать','/rental/edit-organization?id=1',true):'');
        $vat=$organization['vat']===null?'—':rtrim(rtrim($organization['vat'],'0'),'.').' %';
        if ($vat===' %') $vat='0 %';
        $body.='<section class="table-card registry-ui-card"><section class="panel-section"><h2 class="section-title">'.View::e($organization['name']).'</h2>'
            .DirectoryUi::pairs([
                ['ИНН',$organization['inn']],['ОГРНИП',$organization['ogrnip']],['Юридический адрес',$organization['address']],
                ['Почтовый адрес',$organization['postal_address'] ?: 'Не указан'],['Контактное лицо',$organization['contact']],
                ['Телефон',$organization['phone']],['Электронная почта',$organization['email']],
                ['НДС',$vat.' с '.($organization['vat_from']?(new \DateTimeImmutable($organization['vat_from']))->format('d.m.Y'):'—')],
                ['ЭДО',($organization['edo'] ?? '—').' · '.($organization['edo_id'] ?? '—')],
            ]).'</section></section>';
        $rows=[];
        foreach (Controller::query('SELECT * FROM organization_bank_accounts WHERE organization_id=1 ORDER BY is_primary DESC,id') as $bank) {
            $rows[]=['_attrs'=>$edit?'data-rental-url="/rental/edit-organization?id=1&amp;bank=1&amp;bank_id='.View::e($bank['id']).'" tabindex="0"':'',
                $bank['is_primary']?'Основной':'—',View::e(DirectoryUi::bankName($bank)),View::e($bank['account']),View::e($bank['bik']),View::e($bank['correspondent_account'])];
        }
        $body.='<section class="table-card registry-ui-card"><section class="panel-section"><div class="demo-row registry-ui-toolbar mb-compact"><h2 class="section-title">Банковские счета</h2><div class="toolbar-right">'
            .($edit?'<button type="button" class="registry-ui-reset" data-rental-open="/rental/edit-organization?id=1&amp;bank=1">Добавить счёт</button>':'').'</div></div><div class="table-card registry-ui-card">'
            .Controller::table(['Основной','Банк','Расч. счёт','БИК','Корр. счёт'],$rows,['short','flex','account','code','account']).'</div></section></section>';
        return View::page('Наша организация',$body,'organization');
    }

    public static function editor(): string
    {
        $organization=self::record(2);
        Auth::requirePermission('finance.write');
        Service::roles(['accountant']);
        if (isset($_GET['bank'])) return self::bankEditor($organization);
        $body='<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">';
        foreach (['name'=>'Наименование','inn'=>'ИНН','ogrnip'=>'ОГРНИП','contact'=>'Контактное лицо','phone'=>'Телефон','email'=>'Электронная почта','vat'=>'НДС, %','vat_from'=>'Ставка действует с','edo'=>'Система ЭДО','edo_id'=>'Идентификатор ЭДО'] as $key=>$label) {
            $type=match($key) {'email'=>'email','phone'=>'tel','vat'=>'number','vat_from'=>'date',default=>'text'};
            $body.=DirectoryUi::input($key,$label,$organization[$key] ?? '',$type,in_array($key,['name','inn','email','vat','vat_from'],true));
        }
        $body.='</div><fieldset class="registry-ui-modal-grid"><legend class="registry-ui-modal-summary">Адреса</legend>'
            .DirectoryUi::input('address','Юридический адрес',$organization['address'])
            .DirectoryUi::checkbox('postal_same_as_legal','Почтовый адрес совпадает с юридическим',in_array($organization['postal_same_as_legal'],[1,'1',true,'true'],true))
            .DirectoryUi::input('postal_address','Почтовый адрес',$organization['postal_address']).'</fieldset>';
        [$form,$actions]=DirectoryUi::form('organization.save',$organization,$body);
        return RentalUI::modal('Редактировать реквизиты ИП',$form,$actions,'organization');
    }

    private static function bankEditor(array $organization): string
    {
        $key=(string)($_GET['bank_id'] ?? '');
        $bank=$key!==''?(Controller::query('SELECT * FROM organization_bank_accounts WHERE organization_id=1 AND id=?',[$key])[0] ?? throw new RuntimeException('Банковский счёт не найден.',404)):null;
        if (isset($_GET['delete'])) {
            if (!$bank) throw new RuntimeException('Банковский счёт не найден.',404);
            $fields='<input type="hidden" name="bank_id" value="'.View::e($key).'"><input type="hidden" name="intent" value="delete"><p>Удалить банковский счёт '.View::e($bank['account']).'?</p>';
            [$form,$actions]=DirectoryUi::form('organization.bank.save',$organization,$fields,'Удалить');
            return RentalUI::modal('Удалить банковский счёт',$form,str_replace('btn-primary','btn-danger',$actions),'organization',false,'','Отмена');
        }
        $body='<input type="hidden" name="bank_id" value="'.View::e($key).'"><section class="registry-ui-modal-grid registry-ui-modal-grid--reading" data-party-bank>';
        foreach (['bank'=>'Наименование банка','account'=>'Расчётный счёт','bik'=>'БИК','correspondent_account'=>'Корреспондентский счёт'] as $field=>$label) {
            $value=$field==='bank' && $bank?DirectoryUi::bankName($bank):($bank[$field] ?? '');
            $body.=DirectoryUi::input($field,$label,$value,'text',true);
        }
        $body.='</section>';
        [$form,$save,$formId]=DirectoryUi::form('organization.bank.save',$organization,$body);
        $leading='';
        if ($bank) {
            $leading=RentalUI::button('Удалить','/rental/edit-organization?id=1&bank=1&delete=1&bank_id='.rawurlencode($key));
            if (!$bank['is_primary']) $save='<button class="btn btn-secondary" type="submit" form="'.$formId.'" name="intent" value="primary">Сделать основным</button>'.$save;
        }
        return RentalUI::modal($bank?'Редактировать банковский счёт':'Добавить банковский счёт',$form,$save,'organization',false,$leading);
    }
}
