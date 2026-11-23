<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB, Http, PagePolicy, View};
use RuntimeException;

final class AccountantReviewCard
{
    public static function available(array $contract): bool
    {
        return RegistryState::managed($contract) && in_array($contract['status'],['accounting','approved'],true);
    }

    public static function render(array $contract,?array $previous=null,string $action=''): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $actor=Auth::user();
        $approved=$contract['status']==='approved';
        $canWrite=Auth::can('finance.write') && PagePolicy::level('rental.approvals',$actor)>=2;
        $needsConfirmation=!$approved || $action==='contract.packet';
        $view=null;
        $confirmation=null;
        $failure='';
        if ($canWrite) {
            try {
                $needsConfirmation=$needsConfirmation || PacketForm::requiresConfirmation('contract',$id);
                if ($needsConfirmation) {
                    $preview=NativePacket::preview($db,'contract',$id,$actor);
                    $view=$preview['view'];
                    LegalHandoff::assertCounterparty($db,$contract);
                    $confirmation=PacketForm::confirmation($preview,$previous);
                } else {
                    $view=NativePacket::current($db,'contract',$id,$actor,'prepare');
                }
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException) throw $error;
                $failure=match ($error->getMessage()) {
                    'NATIVE_PACKET_DOCUMENT_BASE_CHANGED'=>'Условия договора или документ изменились после проверки юристом.',
                    'NATIVE_PACKET_NOT_AT_ACCOUNTANT'=>'Этап согласования изменился. Откройте карточку заново.',
                    default=>preg_match('/^[A-Z_]+$/D',$error->getMessage())
                        ?'Не удалось подтвердить актуальность реквизитов, условий или файлов.'
                        :$error->getMessage(),
                };
            }
        }
        $context=$view['context']??null;
        $party=$context['party']??Service::get($db,'parties',(int)$contract['party_id']);
        $handoff=$context['legal_handoff']??($context===null?(LegalHandoff::history($db,$id)[0]['files']??null):null);
        $files=$handoff??$context['word_pack']['files']??($context?[$context['file']]:[]);
        $delivery=$approved?Delivery::presentation($db,'contract',$id):null;
        $canOpenDelivery=$canWrite && $approved && !$needsConfirmation
            && (($view['state']??null)==='confirmed' || $delivery!==null);
        $notice=RentalUI::notice().($confirmation['notice']??($previous===null?'':RentalUI::alert(PacketForm::CHANGED_MESSAGE,'','warning')));
        if ($delivery && $delivery['message']!=='') {
            $notice.=RentalUI::alert('Документы и доставка',$delivery['message'],$delivery['error']?'error':'info');
        }
        $body=$notice.'<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .self::party($party,$actor).self::terms($contract,$context)
            .LegalHandoffCard::accountantDocuments($id,$files,$handoff!==null)
            .self::invoice($contract,$view['invoices']??null,$context['organization']['vat']??null,$needsConfirmation)
            .self::review($contract,$canWrite,$needsConfirmation,$confirmation,$failure,$action==='contract.return')
            .'</div>';
        $footer='';
        if ($canWrite && $needsConfirmation) {
            $footer='<button type="submit" class="btn btn-primary" form="accountant-packet"'
                .($confirmation?'':' disabled').'>Подтвердить пакет</button>';
        } elseif ($canOpenDelivery) {
            $footer=RentalUI::button('Пакет и отправка','/rental/action-form?id='.$id.'&action=contract.send',true);
        }
        return RentalUI::modal('Договор №'.$contract['number'].' · проверка бухгалтерией',$body,$footer,
            'approvals',wide:true,extractForms:false);
    }

    private static function party(array $party,array $actor): string
    {
        $body=RentalUI::pairs([['Наименование',$party['name']],['ИНН / КПП',($party['inn']?:'—').' / '.($party['kpp']?:'—')]]);
        if (PagePolicy::level('rental.parties',$actor)>=1) {
            $body.='<details class="registry-ui-form-disclosure"><summary class="btn btn-secondary">Показать полные реквизиты</summary>'
                .'<div class="registry-ui-form-section">'.PartyDirectory::reviewDetails($party).'</div></details>';
        }
        return RentalUI::reviewSection('Контрагент',$body);
    }

    private static function terms(array $contract,?array $context): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $rows=[];
        foreach (BillableTerms::forContract($db,$id) as $term) {
            $rows[]=[View::e($term['name']),View::e($term['type']??''),View::e($term['area']===null
                ?'—':DirectoryUi::area((string)$term['area']).' м²'),Controller::money($term['amount'])];
        }
        $facts=[
            ['Дата договора',Documents::dateText($contract['document_date'])],
            ['Период аренды',Documents::dateText($contract['start_date']).' — '.Documents::dateText($contract['end_date'])],
            ['Аренда за месяц с НДС',Controller::money($contract['amount'])],
        ];
        $vat=$context['organization']['vat']??null;
        if ($vat!==null) $facts[]=['НДС',(string)(float)$vat.' %'];
        $body=RentalUI::pairs($facts);
        if ($rows) {
            $body.='<div class="registry-ui-review-terms">'
                .Controller::table(['Объект','Тип','Площадь','Аренда с НДС'],$rows,['text','text','short','money']).'</div>';
        }
        $initial=$context['initial_terms']??InitialInvoice::approvalTerms($db,$id);
        if ($initial) {
            $body.=RentalUI::pairs([
                ['Форма договора',LeasePaymentTerms::FORMS[$initial['form']??'']??'—'],
                ['Условия первого начисления',$initial['reason']],
                ['Кратность обеспечительного платежа',(string)$initial['multiple']],
            ]);
        }
        return RentalUI::reviewSection('Финансовые условия',$body);
    }

    private static function invoice(array $contract,?array $packetInvoices,?string $vat,bool $needsConfirmation): string
    {
        $id=(int)$contract['id'];
        $issued=InitialInvoice::issuedFor(DB::pdo(),$id);
        $body='';
        if ($issued) {
            $files=InitialInvoiceCard::pdfFiles($id);
            $attached=array_map('intval',array_column($packetInvoices??[],'id'));
            $held=$needsConfirmation && $packetInvoices!==null
                ?array_diff(InitialInvoice::separateIds(DB::pdo(),$id),$attached):[];
            if ($held) {
                $body.=RentalUI::alert('Отдельные счета ждут проверки перед включением в пакет',
                    'Сверьте срок оплаты и актуальность условий этих счетов.','warning');
            }
            $rows=[];
            foreach ($issued as $invoice) {
                $invoiceId=(int)$invoice['id'];
                $fileId=$files[$invoiceId]??null;
                $amount=Controller::money($invoice['amount']);
                if ($vat!==null && in_array($invoiceId,$attached,true)) $amount.='<div class="doc-meta">НДС '.View::e((string)(float)$vat).' %</div>';
                $membership=match (true) {
                    $packetInvoices===null=>'',
                    in_array($invoiceId,$attached,true)=>'Входит в пакет',
                    in_array($invoiceId,$held,true)=>'Требует проверки',
                    default=>'Отдельный счёт',
                };
                $number=View::e('№'.InvoiceDocument::number($invoice['number'])).'<div class="doc-meta">'.View::e($invoice['kind']).'</div>';
                if ($fileId) $number.='<a href="/rental/download?id='.View::e($fileId).'">Скачать PDF</a>';
                if ($membership) $number.='<div class="doc-meta">'.$membership.'</div>';
                $rows[]=[$number,View::e($invoice['period']),
                    View::e($invoice['due']?Documents::dateText($invoice['due']):'Требует подтверждения'),$amount];
            }
            $body.='<div class="registry-ui-review-terms">'
                .Controller::table(['Счёт','Период','Срок оплаты','Сумма с НДС'],$rows,['text','text','short','money']).'</div>';
        } else {
            $body='<p class="registry-ui-modal-help">Первый счёт оформляется отдельным действием.</p>';
        }
        if (InitialInvoice::can($contract,InitialInvoice::ISSUE) || $issued) {
            $body.='<div class="demo-row">'.RentalUI::button($issued?'Открыть первые счета':'Выставить первый счёт',InitialInvoiceCard::route($id)).'</div>';
        }
        return RentalUI::reviewSection('Первый счёт',$body,$issued?'Зарегистрирован':'Не выставлен',(bool)$issued);
    }

    private static function review(array $contract,bool $canWrite,bool $needsConfirmation,?array $confirmation,string $failure,bool $openReturn): string
    {
        $body='';
        if ($failure!=='') $body.=RentalUI::alert('Проверка пакета недоступна',$failure,'warning');
        if ($confirmation) {
            $body.='<form id="accountant-packet" class="registry-ui-form-section" method="post" action="/rental/action">'
                .self::fields($contract,'contract.packet').$confirmation['fields'];
            foreach (['counterparty_confirmed'=>'Контрагент и реквизиты проверены','financial_confirmed'=>'Финансовые условия проверены',
                'document_confirmed'=>'Документы и состав пакета проверены'] as $name=>$caption) {
                $body.='<label class="registry-ui-checkbox"><input type="checkbox" name="'.$name.'" value="1" required><span>'
                    .View::e($caption).'</span></label>';
            }
            $body.='</form>';
        }
        if ($canWrite && $contract['status']==='accounting') {
            $body.='<details class="registry-ui-form-disclosure"'.($openReturn?' open':'').'>'
                .'<summary class="btn btn-danger">Вернуть на доработку</summary>'
                .'<form class="registry-ui-form-section" method="post" action="/rental/action">'
                .self::fields($contract,'contract.return')
                .'<label class="field"><span class="field-label">Причина возврата</span>'
                .'<textarea class="field-input" name="reason" rows="2" maxlength="1000" required></textarea></label>'
                .'<p class="registry-ui-modal-help">Укажите, каких данных не хватает или что нужно уточнить.</p>'
                .'<div class="demo-row"><button type="submit" class="btn btn-danger">Подтвердить возврат</button></div>'
                .'</form></details>';
        }
        $done=!$needsConfirmation && $contract['status']==='approved' && $failure==='';
        if ($done) $body.='<p class="registry-ui-modal-help">Согласованный пакет готов к отдельному действию «Пакет и отправка».</p>';
        if (!$canWrite && $body==='') $body='<p class="registry-ui-modal-help">Карточка доступна для просмотра.</p>';
        return RentalUI::reviewSection('Проверка пакета',$body,$done?'Пакет согласован':'Ожидает согласования',$done);
    }

    private static function fields(array $contract,string $action): string
    {
        return PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('id',(int)$contract['id'])
            .PremisesActsCard::hidden('version',(int)$contract['version']).PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)));
    }
}
