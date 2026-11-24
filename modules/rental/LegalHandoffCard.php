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
        $multiple=InitialInvoice::legalSecurityMultiple($db,$contract);
        $body=RentalUI::notice();
        if ($contract['status']==='returned') {
            $body.=RentalUI::alert('Бухгалтерия: отказ в согласовании',(string)$contract['reason'],'warning');
        }
        $body.='<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .self::party($contract,$party,$details,$review['party'])
            .self::terms($contract,$review['terms'])
            .self::securityMultiple($contract,$multiple)
            .self::documents($contract,$files,$ready).'</div>';
        $formId='legal-handoff';
        $canTransfer=$ready && $files && $multiple!==null;
        $body.='<form id="'.$formId.'" method="post" action="/rental/action">'
            .self::fields($contract,'contract.legal')
            .PremisesActsCard::hidden('security_multiple',$multiple??'')
            .PremisesActsCard::hidden('packet_fingerprint',LegalHandoff::fingerprint($files)).'</form>';
        $footer='<button class="btn btn-primary" type="submit" form="'.$formId.'"'
            .($canTransfer?' data-legal-transfer-ready':' disabled').'>Согласовать и передать бухгалтеру</button>';
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
        $actions=(!$party['blocked']?ApprovalDecision::lawyerButton($contract,'block'):'')
            .ApprovalDecision::lawyerButton($contract,'documents')
            .self::confirm($contract,LegalHandoff::PARTY,'Разрешить работу',$checked || $blocked,
                extra:PremisesActsCard::hidden('party_basis',LegalHandoff::partyBasis(DB::pdo(),$contract)));
        return self::section('Согласование контрагента',$facts
            .'<div class="registry-ui-review-actions"><div>'.$links.'</div><div class="demo-row">'.$actions.'</div></div>'
            .ContractReviewFiles::legalPresentation($contract,$party),
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
        $facts.='<div class="registry-ui-review-actions"><div></div><div class="demo-row">'
            .ApprovalDecision::lawyerButton($contract,'terms')
            .self::confirm($contract,LegalHandoff::TERMS,'Условия верны',$checked)
            .'</div></div>';
        return self::section('Проверка условий',$facts,$checked?'Условия подтверждены':'Ожидает решения',$checked);
    }

    private static function securityMultiple(array $contract,?int $multiple): string
    {
        $issued=InitialInvoice::issuedSecurityMultiple(DB::pdo(),(int)$contract['id']);
        $form='<form id="legal-security-multiple" class="registry-ui-form-section" method="post" action="/rental/action">'
            .self::fields($contract,LegalHandoff::SECURITY_MULTIPLE)
            .'<div class="registry-ui-form-row"><div class="registry-ui-modal-address-row registry-ui-modal-address-row--code">'
            .'<input class="field-input" type="number" name="multiple" min="0" step="1" value="'.($multiple??$issued??1)
            .'" aria-label="Кратность обеспечения" aria-describedby="legal-security-multiple-help" required'.($issued!==null?' readonly':'').'>'
            .'<button class="btn btn-success-solid" type="submit"'.($multiple!==null?' disabled':'')
            .'>Согласовать кратность</button></div></div>'
            .'<p id="legal-security-multiple-help" class="registry-ui-modal-help">0 — без обеспечения.</p></form>';
        return RentalUI::reviewSection('Кратность обеспечения',$form,$multiple===null?'Ожидает согласования':'Согласована',$multiple!==null);
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

    public static function accountantDocuments(array $files): string
    {
        $body=$files?self::fileList($files):'<p class="registry-ui-modal-help">Текущий комплект не подтверждён.</p>';
        return self::section('Документы от юриста',$body,$files?'Текущий комплект':'',(bool)$files);
    }

    private static function section(string $title,string $body,string $state,bool $done): string
    {
        return RentalUI::reviewSection($title,$body,$state,$done);
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
}
