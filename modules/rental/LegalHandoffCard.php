<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB, PagePolicy, View};
use RuntimeException;

final class LegalHandoffCard
{
    public static function render(array $contract): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        $details=DirectoryDetails::read($db,'parties',(int)$party['id']);
        $review=LegalHandoff::checks($db,$contract);
        $files=LegalHandoff::files($db,$contract);
        $ready=$review['party'] && $review['terms'];
        $body=RentalUI::notice();
        if ($contract['status']==='returned') {
            $body.=RentalUI::alert('Бухгалтерия: отказ в согласовании',(string)$contract['reason'],'warning');
        }
        $body.='<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .self::party($contract,$party,$details,$review['party'])
            .self::terms($contract,$review['terms'])
            .self::documents($contract,$files,$ready).self::history($id,true).'</div>';
        $formId='legal-handoff';
        $body.='<form id="'.$formId.'" method="post" action="/rental/action">'
            .self::fields($contract,'contract.legal')
            .PremisesActsCard::hidden('packet_fingerprint',LegalHandoff::fingerprint($files)).'</form>';
        $footer='<button class="btn btn-primary" type="submit" form="'.$formId.'"'
            .($ready && $files?'':' disabled').'>Согласовать и передать бухгалтеру</button>';
        return RentalUI::modal('Договор №'.$contract['number'].' · проверка юристом',$body,$footer,'approvals',wide:true);
    }

    public static function partyDetails(): string
    {
        if ((Auth::user()['role']??null)!=='lawyer') throw new RuntimeException('Действие доступно юристу.',403);
        Auth::requirePermission('rental.approve.legal');
        PagePolicy::requireCommand('contract.legal');
        Access::pages(['parties']);
        $contract=Access::contract((int)($_GET['contract_id']??0),2);
        if (!LegalReview::assigned($contract) || !RegistryState::managed($contract)) {
            throw new RuntimeException('Договор не у юриста.',409);
        }
        $party=Service::get(DB::pdo(),'parties',(int)$contract['party_id']);
        return PartyDirectory::reviewModal($party);
    }

    private static function party(array $contract,array $party,array $details,bool $checked): string
    {
        $facts=RentalUI::pairs([
            ['Контрагент',$party['name']],
            ['ИНН / КПП',implode(' / ',array_filter([$party['inn'],$party['kpp']]))?:'Не указаны'],
            ['Подписант',$details['signer']??'Не указан'],
        ]);
        $blocked=($party['blocked'] && !LegalHandoff::blocksOnlyNew($party));
        if ($party['blocked']) {
            $facts.=RentalUI::alert($blocked?'Работа с контрагентом заблокирована':'Новые договоры заблокированы',
                (string)$party['block_reason'],$blocked?'error':'warning');
        }
        $links=PagePolicy::level('rental.parties',Auth::user())>=1
            ?RentalUI::button('Полные данные','/rental/legal-party-details?contract_id='.(int)$contract['id']):'';
        $actions=(!$party['blocked']?self::toggle('review-party-block','Заблокировать работу',true):'')
            .self::toggle('review-party-documents','Дозапросить документы',true,true)
            .self::confirm($contract,LegalHandoff::PARTY,'Разрешить работу',$checked || $blocked,
                extra:PremisesActsCard::hidden('party_basis',LegalHandoff::partyBasis(DB::pdo(),$contract)));
        $panels=self::reason($contract,'contract.return','review-party-documents','Дозапросить документы','Закрыть форму запроса',
            'Договор вернётся менеджеру с указанным списком.','Отправить запрос',danger:false,reasonLabel:'Какие документы нужны');
        if (!$party['blocked']) {
            $panels.=self::reason($contract,LegalHandoff::BLOCK,'review-party-block','Заблокировать работу','Отменить блокировку',
                'Новые договоры будут заблокированы. Текущий договор вернётся на доработку с указанной причиной.',
                'Заблокировать и вернуть');
        }
        return self::section('Согласование контрагента',$facts
            .'<div class="registry-ui-review-actions"><div>'.$links.'</div><div class="demo-row">'.$actions.'</div></div>'
            .$panels.ContractReviewFiles::legalPresentation($contract,$party),
            $checked?'Работа разрешена':($blocked?'Работа заблокирована':'Ожидает решения'),$checked);
    }

    private static function terms(array $contract,bool $checked): string
    {
        $db=DB::pdo();
        $terms=BillableTerms::forContract($db,(int)$contract['id']);
        $rows=[];
        foreach ($terms as $term) {
            $rows[]=[View::e($term['name']),View::e($term['type']??''),View::e((string)($term['area']??'')!==''
                ?DirectoryUi::area((string)$term['area']).' м²':'—'),Controller::money($term['amount'])];
        }
        $facts=RentalUI::pairs([
            ['Договор','№'.$contract['number'].' от '.Documents::dateText($contract['document_date'])],
            ['Период аренды',Documents::dateText($contract['start_date']).' — '.Documents::dateText($contract['end_date'])],
            ...RenewalIndexation::facts($db,$contract,'contract'),
            ['Аренда за месяц с НДС',Controller::money($contract['amount'])],
        ]);
        if ($rows) {
            $facts.='<div class="registry-ui-review-terms">'
                .Controller::table(['Объект','Тип','Площадь','Аренда с НДС'],$rows,['text','text','short','money'])
                .'</div>';
        }
        $form=InitialInvoice::approvalTerms($db,(int)$contract['id']);
        if ($form) {
            $facts.=RentalUI::pairs([
                ['Условия первого начисления',$form['reason']],
                ['Кратность обеспечительного платежа',(string)$form['multiple']],
            ]);
        }
        $initial=self::initialTerms($contract);
        $facts.='<div class="registry-ui-review-actions"><div>'
            .($initial?self::toggle('legal-initial-terms','Подтвердить условия первого начисления'):'')
            .'</div><div class="demo-row">'
            .self::toggle('review-terms-return','Условия неверны',true,true)
            .self::confirm($contract,LegalHandoff::TERMS,'Условия верны',$checked)
            .'</div></div>'
            .$initial
            .self::reason($contract,'contract.return','review-terms-return','Условия неверны','Закрыть форму возврата',
                'Укажите, каких данных не хватает или что нужно уточнить.','Вернуть на доработку');
        return self::section('Проверка условий',$facts,$checked?'Условия подтверждены':'Ожидает решения',$checked);
    }

    private static function initialTerms(array $contract): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        if (!InitialInvoice::can($contract,InitialInvoice::TERMS) || InitialInvoice::issuedFor($db,$id)
            || InitialInvoice::receipt($db,$id)) return '';
        $forms=LeasePaymentTerms::allowedForms($db,$contract);
        if (!$forms) return '';
        $labels=[];
        foreach ($forms as $form=>$label) $labels[$form]=$form.' · '.$label;
        $fields='<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .DirectoryUi::select('lease_form','Согласованная форма',[''=>'Выберите форму']+$labels,'',true,alignedLabel:true)
            .'<label class="field"><span class="field-label-row"><span class="field-label">Кратность обеспечения <span class="req">*</span></span></span>'
            .'<input class="field-input" type="number" name="multiple" min="0" step="1" placeholder="0 — без обеспечения" required></label></div>'
            .'<label class="field"><span class="field-label">Пункт договора о первом начислении и обеспечении <span class="req">*</span></span>'
            .'<textarea class="field-textarea" name="reason" rows="2" maxlength="600" required></textarea></label>';
        $form='<form class="registry-ui-form-section" method="post" action="/rental/action">'
            .self::fields($contract,LegalHandoff::TERMS).$fields
            .'<div class="demo-row"><button class="btn btn-success-solid" type="submit">Подтвердить первый расчёт</button></div></form>';
        return self::panel('legal-initial-terms','Подтвердить условия первого начисления','Закрыть условия первого начисления',$form);
    }

    private static function documents(array $contract,array $files,bool $ready): string
    {
        $id=(int)$contract['id'];
        if ($ready) {
            $body='<form class="registry-ui-form-section" method="post" action="/rental/action" enctype="multipart/form-data">'
                .self::fields($contract,LegalHandoff::UPLOAD)
                .'<label class="file-dropzone registry-ui-review-upload" data-file-dropzone>'
                .'<span class="dz-title">Перетащите сюда итоговые файлы</span>'
                .'<span class="btn btn-secondary">Выбрать файлы</span>'
                .'<span class="dz-note" id="legal-files-help">Word, PDF или ZIP, до 20 МБ на файл. Загрузка начнётся сразу.</span>'
                .'<input type="file" name="legal_files[]" accept=".doc,.docx,.pdf,.zip" aria-label="Выбрать итоговые файлы"'
                .' aria-describedby="legal-files-help" multiple required data-rental-upload-on-select></label></form>'
                .($files?self::fileList($files,$contract):'');
            $body.='<div class="demo-row"><form method="post" action="/rental/word-zip">'
                .PremisesActsCard::hidden('_csrf',\App\Http::csrf())
                .PremisesActsCard::hidden('id',$id)
                .PremisesActsCard::hidden('version',(int)$contract['version'])
                .PremisesActsCard::hidden('request_key',bin2hex(random_bytes(16)))
                .'<button type="submit" class="btn btn-secondary">Скачать заполненные шаблоны</button></form></div>';
        } else {
            $body=RentalUI::alert('Подтвердите контрагента и условия',
                'После двух решений откроются загрузка итоговых файлов и скачивание шаблонов.');
        }
        return self::section('Договор',$body,$files?'Комплект готов':($ready?'Подготовка комплекта':'Ожидает двух решений'),(bool)$files);
    }

    public static function accountantDocuments(int $id,array $files,bool $handoff=true): string
    {
        $body=$files?self::fileList($files):'<p class="registry-ui-modal-help">Текущий комплект не подтверждён.</p>';
        return self::section('Документы от юриста',$body.self::history($id,!$handoff),$files?'Текущий комплект':'',(bool)$files);
    }

    private static function section(string $title,string $body,string $state,bool $done): string
    {
        return RentalUI::reviewSection($title,$body,$state,$done);
    }

    private static function disclosure(string $label,string $body): string
    {
        return '<details class="registry-ui-form-disclosure"><summary class="btn btn-secondary">'.View::e($label)
            .'</summary><div class="registry-ui-form-section">'.$body.'</div></details>';
    }

    private static function fields(array $contract,string $action): string
    {
        return PremisesActsCard::hidden('_csrf',\App\Http::csrf())
            .PremisesActsCard::hidden('id',(int)$contract['id'])
            .PremisesActsCard::hidden('version',(int)$contract['version'])
            .PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)));
    }

    private static function confirm(array $contract,string $action,string $label,bool $disabled,string $extra=''): string
    {
        return '<form method="post" action="/rental/action">'
            .self::fields($contract,$action).$extra
            .'<button class="btn btn-success-solid" type="submit"'.($disabled?' disabled':'').'>'.View::e($label).'</button></form>';
    }

    private static function toggle(string $id,string $label,bool $danger=false,bool $neutralClose=false): string
    {
        return '<button type="button" class="btn '.($danger?'btn-danger':'btn-secondary').'" hidden data-form-disclosure-control'
            .($neutralClose?' data-disclosure-neutral-close':'')
            .' aria-expanded="false" aria-controls="'.View::e($id).'">'.View::e($label).'</button>';
    }

    private static function panel(string $id,string $label,string $opened,string $body,bool $danger=false): string
    {
        return '<details id="'.View::e($id).'" class="registry-ui-form-disclosure" data-form-disclosure>'
            .'<summary class="btn '.($danger?'btn-danger':'btn-secondary').'"><span data-disclosure-collapsed>'.View::e($label)
            .'</span><span data-disclosure-expanded>'.View::e($opened).'</span></summary>'
            .'<div class="registry-ui-form-section">'.$body.'</div></details>';
    }

    private static function reason(array $contract,string $action,string $id,string $label,string $opened,
        string $help,string $submit,bool $danger=true,string $reasonLabel='Причина'): string
    {
        if ($contract['status']==='returned' && $action==='contract.return') $action='contract.forward';
        $form='<form class="registry-ui-form-section'.($danger?' registry-ui-review-reason':'').'" method="post" action="/rental/action">'
            .self::fields($contract,$action).'<p class="registry-ui-modal-help">'.View::e($help).'</p>'
            .'<label class="field"><span class="field-label">'.View::e($reasonLabel).'</span>'
            .'<textarea class="field-input" name="reason" rows="2" maxlength="1000" required></textarea></label>'
            .'<div class="demo-row"><button class="btn '.($danger?'btn-danger':'btn-primary').'" type="submit">'.View::e($submit).'</button></div></form>';
        return self::panel($id,$label,$opened,$form,true);
    }

    private static function fileList(array $files,?array $draft=null): string
    {
        $list='<ul class="registry-ui-form-section registry-ui-review-files">';
        foreach ($files as $file) {
            $list.='<li><a href="/rental/download?id='.View::e($file['id']).'">'.View::e($file['filename']).'</a>';
            if ($draft) $list.='<form method="post" action="/rental/action">'.self::fields($draft,LegalHandoff::REMOVE)
                .PremisesActsCard::hidden('file_id',$file['id'])
                .'<button type="submit" class="btn btn-secondary" aria-label="Убрать '.View::e($file['filename']).'">Убрать</button></form>';
            $list.='</li>';
        }
        return $list.'</ul>';
    }

    private static function history(int $id,bool $includeLatest): string
    {
        $sets=LegalHandoff::history(DB::pdo(),$id);
        if (!$includeLatest) array_shift($sets);
        $body='';
        foreach ($sets as $set) {
            $body.='<section class="registry-ui-form-section"><h4 class="registry-ui-modal-summary">'
                .View::e($set['actor'].' · '.(new \DateTimeImmutable($set['sent_at']))->format('d.m.Y H:i'))
                .'</h4>'.self::fileList($set['files']).'</section>';
        }
        foreach (Controller::query("SELECT created_at,description FROM rental_events WHERE contract_id=? AND action='contract.return'
            AND actor_role='accountant' ORDER BY id DESC",[$id]) as $event) {
            $note=explode(' · ',$event['description'],2)[1]??$event['description'];
            $body.=RentalUI::alert('Замечание бухгалтера · '.(new \DateTimeImmutable($event['created_at']))->format('d.m.Y H:i'),$note);
        }
        return $body?self::disclosure('История передачи бухгалтеру',$body):'';
    }
}
