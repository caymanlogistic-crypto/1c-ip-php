<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB, Http, PagePolicy, View};
use RuntimeException;

final class AccountantReviewCard
{
    public const CHANGED_MESSAGE='Пакет изменился после открытия. Проверьте актуальные данные и подтвердите пакет заново.';

    public static function available(array $contract): bool
    {
        return RegistryState::managed($contract) && in_array($contract['status'],['accounting','approved'],true);
    }

    public static function partyDetails(): string
    {
        if ((Auth::user()['role']??null)!=='accountant') throw new RuntimeException('Действие доступно бухгалтерии.',403);
        Access::pages(['parties']);
        $contract=Access::contract((int)($_GET['contract_id']??0));
        if (!self::available($contract)) throw new RuntimeException('Договор не на проверке бухгалтерии.',409);
        $party=Service::get(DB::pdo(),'parties',(int)$contract['party_id']);
        return PartyDirectory::reviewModal($party);
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
        $packetHash='';
        $failure='';
        if ($canWrite) {
            try {
                $needsConfirmation=$needsConfirmation || PacketForm::requiresConfirmation('contract',$id);
                if ($needsConfirmation) {
                    $preview=NativePacket::preview($db,'contract',$id,$actor);
                    $view=$preview['view'];
                    LegalHandoff::assertCounterparty($db,$contract);
                    $confirmation=PacketForm::confirmation($preview,$previous,self::CHANGED_MESSAGE);
                    $packetHash=$preview['expected_packet_hash'];
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
        $notice=RentalUI::notice().($confirmation['notice']??($previous===null?'':RentalUI::alert(self::CHANGED_MESSAGE,'','warning')));
        if ($delivery && $delivery['message']!=='') {
            $notice.=RentalUI::alert('Документы и доставка',$delivery['message'],$delivery['error']?'error':'info');
        }
        if ($failure!=='') $notice.=RentalUI::alert('Проверка пакета недоступна',$failure,'warning');
        $body=$notice.'<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .self::party($id,$party,$actor).self::terms($contract,$context)
            .LegalHandoffCard::accountantDocuments($id,$files,$handoff!==null)
            .self::invoice($contract,$view['invoices']??null,$context['organization']['vat']??null,$needsConfirmation)
            .'</div>';
        $footer='';
        if ($canWrite && $contract['status']==='accounting') {
            $footer=RentalUI::button('Вернуть на доработку',self::decisionUrl($id,'return'),danger:true);
        }
        if ($canWrite && $needsConfirmation) {
            $footer.=$confirmation
                ?RentalUI::button('Подтвердить пакет',self::decisionUrl($id,'confirm').'&packet='.$packetHash,primary:true)
                :'<button type="button" class="btn btn-primary" disabled>Подтвердить пакет</button>';
        } elseif ($canOpenDelivery) {
            $footer=RentalUI::button('Пакет и отправка','/rental/action-form?id='.$id.'&action=contract.send',true);
        }
        return RentalUI::modal('Договор №'.$contract['number'].' · проверка бухгалтерией',$body,$footer,
            'approvals',wide:true,extractForms:false,tall:true);
    }

    private static function decisionUrl(int $contractId,string $decision): string
    {
        return '/rental/accountant-decision?id='.$contractId.'&decision='.$decision;
    }

    public static function decision(?int $contractId=null,?string $selectedDecision=null): string
    {
        if ((Auth::user()['role']??null)!=='accountant') throw new RuntimeException('Действие доступно бухгалтерии.',403);
        Auth::requirePermission('finance.write');
        $id=$contractId??(int)($_GET['id']??0);
        $contract=Access::contract($id,2);
        $decision=$selectedDecision??(string)($_GET['decision']??'');
        $action=match ($decision) {
            'return'=>'contract.return',
            'confirm'=>'contract.packet',
            default=>throw new RuntimeException('Действие не найдено.',404),
        };
        PagePolicy::requireCommand($action);
        if (!self::available($contract) || ($decision==='return' && $contract['status']!=='accounting'))
            throw new RuntimeException('Стадия договора изменилась. Откройте карточку заново.',409);
        if ($decision==='return') return ApprovalDecision::returnForRevision($contract,$action);
        $formId='accountant-decision';
        $form='<form id="'.$formId.'" method="post" action="/rental/action" hidden>'
            .self::fields($contract,$action);
        $db=DB::pdo();
        $preview=NativePacket::preview($db,'contract',$id,Auth::user());
        if (!hash_equals($preview['expected_packet_hash'],(string)($_GET['packet']??''))) {
            throw new RuntimeException('Пакет изменился. Откройте карточку и сверьте актуальные данные.',409);
        }
        LegalHandoff::assertCounterparty($db,$contract);
        $body=self::confirmationSummary($contract,$preview['view']['context']['party'])
            .$form.PacketForm::confirmation($preview)['fields'];
        foreach (['counterparty_confirmed','financial_confirmed','document_confirmed'] as $check) {
            $body.=PremisesActsCard::hidden($check,'1');
        }
        $body.='</form>';
        $submit='<button type="submit" form="'.$formId.'" class="btn btn-primary">Подтвердить</button>';
        return RentalUI::modal('Подтвердить пакет',$body,$submit,'approvals',closeLabel:'Вернуться');
    }

    private static function confirmationSummary(array $contract,array $party): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $issued=InitialInvoice::issuedFor($db,$id);
        $planned=$issued?[]:InitialInvoice::preview($db,$contract,InitialInvoiceCard::defaultIssueDate($contract))['rows'];
        $bills='';
        foreach (InitialInvoice::securityFirst($issued ?: $planned) as $line) {
            $label=self::invoiceLabel($line);
            if ($issued) $label='№'.InvoiceDocument::number($line['number']).' · '.$label;
            $bills.='<li>'.View::e($label).' · '.Controller::money($line['amount']).'</li>';
        }
        $delivery=trim((string)($party['edo']??''))!=='' && trim((string)($party['edo_id']??''))!==''
            ?'Клиент получит приглашение и комплект документов через ЭДО.'
            :'Клиент получит комплект документов по электронной почте.';
        $billing=match (true) {
            (bool)$issued=>'В пакет войдут ранее выставленные счета.',
            $bills!==''=>'Будут выставлены первые счета.',
            default=>'Состав первых счетов пока не рассчитан.',
        };
        return '<h3 class="registry-ui-modal-summary">После подтверждения пакета</h3>'
            .'<ol class="registry-ui-modal-list"><li>Если контрагент новый, система заведёт его в 1С.</li>'
            .'<li>'.View::e($billing).($bills?'<ul class="registry-ui-modal-list">'.$bills.'</ul>':'').'</li>'
            .'<li>'.View::e($delivery).'</li>'
            .'<li>Договор будет принят на обслуживание с контролем счетов и оплаты.</li></ol>';
    }

    private static function party(int $contractId,array $party,array $actor): string
    {
        $body=RentalUI::pairs([['Наименование',$party['name']],['ИНН / КПП',($party['inn']?:'—').' / '.($party['kpp']?:'—')]]);
        if (PagePolicy::level('rental.parties',$actor)>=1) {
            $body.=RentalUI::button('Полные реквизиты','/rental/accountant-party-details?contract_id='.$contractId);
        }
        return RentalUI::reviewSection('Контрагент',$body);
    }

    private static function terms(array $contract,?array $context): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $facts=[
            ['Дата договора',Documents::dateText($contract['document_date'])],
            ['Период аренды',Documents::dateText($contract['start_date']).' — '.Documents::dateText($contract['end_date'])],
            ...RenewalIndexation::facts($db,$contract,'contract'),
        ];
        $vat=$context['organization']['vat']??null;
        if ($vat!==null) $facts[]=['НДС',(string)(float)$vat.' %'];
        $body=RentalUI::pairs($facts).RentalUI::reviewTermsTable(BillableTerms::forContract($db,$id),$contract['amount']);
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
        $db=DB::pdo();
        $issued=InitialInvoice::issuedFor($db,$id);
        $previewUrl='';
        $body='';
        $rows=[];
        if ($issued) {
            $files=InitialInvoiceCard::pdfFiles($id);
            $attached=array_map('intval',array_column($packetInvoices??[],'id'));
            $held=$needsConfirmation && $packetInvoices!==null
                ?array_diff(InitialInvoice::separateIds(DB::pdo(),$id),$attached):[];
            if ($held) {
                $body.=RentalUI::alert('Отдельные счета ждут проверки перед включением в пакет',
                    'Сверьте срок оплаты и актуальность условий этих счетов.','warning');
            }
            foreach (InitialInvoice::securityFirst($issued) as $invoice) {
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
                $number=View::e('№'.InvoiceDocument::number($invoice['number'])).'<div class="doc-meta">'.View::e(self::invoiceLabel($invoice)).'</div>';
                if ($fileId) $number.='<a href="/rental/download?id='.View::e($fileId).'">Скачать PDF</a>';
                if ($membership) $number.='<div class="doc-meta">'.$membership.'</div>';
                $rows[]=[$number,View::e($invoice['period']),
                    View::e($invoice['due']?Documents::dateText($invoice['due']):'Требует подтверждения'),$amount];
            }
        } else {
            $plan=InitialInvoice::preview($db,$contract,InitialInvoiceCard::defaultIssueDate($contract));
            if ($plan['rows']) $previewUrl=InitialInvoicePreview::route($id);
            foreach (InitialInvoice::securityFirst($plan['rows']) as $line) {
                $period=$line['kind']==='Обеспечительный платёж'?'—'
                    :Documents::dateText($line['from']).' — '.Documents::dateText($line['to']);
                $rows[]=[View::e(self::invoiceLabel($line)),View::e($period),
                    View::e($line['due']?Documents::dateText($line['due']):'Требует подтверждения'),Controller::money($line['amount'])];
            }
            foreach (array_unique($plan['questions']) as $question)
                $body.='<p class="registry-ui-modal-help">'.View::e($question).'</p>';
            if (!$rows && $body==='') $body='<p class="registry-ui-modal-help">Нет данных для предварительного расчёта.</p>';
        }
        if ($rows) $body.='<div class="registry-ui-review-terms">'
            .Controller::table(['Начисление / счёт','Период','Срок оплаты','Сумма с НДС'],$rows,['text','text','short','money']).'</div>';
        if ($issued) {
            $body.='<div class="demo-row">'.RentalUI::button('Открыть первые счета',InitialInvoiceCard::route($id)).'</div>';
        }
        $state=$issued?'Счета зарегистрированы':'Требует уточнения';
        if ($previewUrl!=='') $state=InitialInvoicePreview::LABEL;
        return RentalUI::reviewSection('Первый расчёт',$body,$state,(bool)$issued,$previewUrl);
    }

    private static function invoiceLabel(array $line): string
    {
        return match ($line['kind']) {
            'Первый период'=>InitialInvoice::DUE_PARTS['rent'],
            'Аренда'=>InitialInvoice::DUE_PARTS['next'],
            default=>$line['kind'],
        };
    }

    private static function fields(array $contract,string $action): string
    {
        return PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('id',(int)$contract['id'])
            .PremisesActsCard::hidden('version',(int)$contract['version']).PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)));
    }
}
