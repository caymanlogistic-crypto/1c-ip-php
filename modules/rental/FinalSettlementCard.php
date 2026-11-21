<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,DB,PagePolicy,View};
use RuntimeException;

final class FinalSettlementCard
{
    public const TITLES=[
        FinalReview::RETURN_FINANCE=>'Вернуть на доработку',FinalReview::RETURN_LEGAL=>'Вернуть на доработку',
        FinalReview::RESUBMIT=>'Передать на повторную проверку',FinalSettlement::TERMS=>'Причина и сроки завершения',
        FinalSettlement::FINANCE=>'Итоговые расчёты',FinalSettlement::AGREEMENT=>'Юридическое основание завершения',
        ReturnAct::RECORD_ONE=>'Односторонний акт возврата',
    ];

    public static function section(array $contract,array $act): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $data=FinalSettlement::read($db,$id);
        $timeline=$data['timeline'] ?? [];
        $finance=$data['finance'] ?? [];
        $notice=$timeline['notice_date'] ?? null;
        $due=$timeline['notice_due'] ?? null;
        $fields=[
            ['Причина завершения',$contract['finish_reason'] ?? 'Нет сведений'],
            ['Дата прекращения',Documents::dateText($contract['finish_date'])],
            ['Дата предупреждения',Documents::dateText($notice)],
            ['Последний день для предупреждения',Documents::dateText($due)],
            ['Согласованное условие предупреждения',($timeline['notice_rule'] ?? '') ?: 'Не подтверждено'],
            ['Соблюдение срока',$notice && $due ? ($notice<=$due?'Срок соблюдён':'Срок не соблюдён') : 'Не подтверждено'],
            ['Последний оплачиваемый день',Documents::dateText($timeline['last_payable_day'] ?? null)],
        ];
        $body='<h3 class="registry-ui-modal-summary">Финальная сверка</h3>'.RentalUI::pairs($fields);
        $reviews=FinalReview::approvals($db,$id);
        $signature=Evidence::signature($db,$id);
        $rows=[];
        foreach (FinalReview::LABELS as $kind=>$label) {
            $marker=FinalReview::marker($db,$id,$kind);
            $review=$reviews[$kind] ?? null;
            $confirmed=FinalReview::current($db,$contract,$kind,$review,$signature) || ($contract['status']==='closed' && $review);
            $view=match($marker['state'] ?? '') {
                'returned'=>'Менеджеру на доработку',
                'reassigned'=>'Повторная проверка · '.($marker['reviewer'] ?? $label),
                default=>$confirmed?'Подтверждено':'Ожидается подтверждение',
            };
            $rows[]=[View::e($label),View::e($view),View::e($confirmed?($review['actor_name'] ?? '—'):($marker['reviewer'] ?? '—'))];
            if (($marker['state'] ?? '')==='returned') {
                $task=($marker['comment'] ?? '') ?: ($kind==='finance'?'Сверьте финансовые основания и итоговые счета.':'Сверьте итоговое соглашение и условия завершения.');
                $body.=RentalUI::alert('На доработке у менеджера',($marker['reviewer'] ?? $label).' · '.$task,'warning');
            }
        }
        $rows[]=['Менеджер',View::e(!empty($act['not_applicable'])?$act['message']:
            ($act['confirmed']?'Фактический возврат подтверждён':'Ожидается двусторонний акт')),'—'];
        $body.=Controller::table(['Участник','Состояние','Сотрудник'],$rows)
            .ReturnAct::summary($act).ReturnAct::history($db,$id);
        $agreement=$data['agreement'] ?? null;
        $mode=$agreement['state'] ?? 'unresolved';
        $files=$mode==='signed'?FinalSettlement::files($db,$id):FinalSettlement::legalBasisFiles($db,$id);
        $file=$files[$agreement['id'] ?? ''] ?? null;
        $body.='<h3 class="registry-ui-modal-summary">Юридическое основание завершения</h3>'
            .RentalUI::pairs([['Итоговое соглашение',FinalSettlement::AGREEMENT_STATES[$mode]],
                ['Основание решения',($agreement['reason'] ?? '') ?: '—']]);
        if ($file) {
            if ($mode==='signed') $body.=RentalUI::pairs([['Подписано',Documents::dateText($agreement['signed_on'])]]);
            $body.='<div class="demo-row">'.Controller::link('/rental/download?id='.$file['id'],$file['filename']).'</div>';
        }
        $body.=self::financeSummary($db,$id,$finance);
        $history=FinalReview::history($db,$id);
        if ($history) {
            $rows=[];
            foreach ($history as $event) {
                $state=$event['state'];
                $time=$state==='reassigned'?$event['resubmitted_at']:$event['when'];
                $rows[]=[View::e(FinalReview::LABELS[$event['kind']]),
                    View::e(match($state){'returned'=>'Возврат','reassigned'=>'Передано на повторную проверку',default=>'Подтверждение'}),
                    View::e($state==='reassigned'?$event['resubmitted_by']:$event['reviewer']),
                    View::e((new \DateTimeImmutable($time))->format('d.m.Y H:i')),
                    View::e($event['previous_state'] ?? '—'),View::e(($state==='reassigned'?$event['response']:($event['comment'] ?? '')) ?: '—')];
            }
            $body.='<h3 class="registry-ui-modal-summary">История проверок</h3>'
                .Controller::table(['Проверка','Действие','Кто','Дата','Прежнее решение','Комментарий'],$rows);
        }
        return $body;
    }

    private static function financeSummary(\PDO $db,int $id,array $data): string
    {
        $items=[['Долг по подтверждённому основанию',Controller::money($data['debt'] ?? null)],
            ['Неустойка',Controller::money($data['penalty'] ?? null)],
            ['Переменные расходы',FinalSettlement::VARIABLES[$data['variable'] ?? 'open']],
            ['Источник переменных расходов',($data['variable_source'] ?? '') ?: 'Данные поставщика не подтверждены'],
            ['Сумма переменных расходов',Controller::money($data['variable_amount'] ?? null)],
            ['Основание итоговых сумм',($data['finance_source'] ?? '') ?: '—']];
        $balance=FinalSettlement::balance($db,$id,$data);
        if (isset($balance['remaining'])) {
            $items[]=['Оплачено по окончательным счетам',Controller::money($balance['paid'])];
            $items[]=['Остаток по окончательным счетам',Controller::money($balance['remaining'])];
        }
        $body='<h3 class="registry-ui-modal-summary">Итоговые расчёты</h3>'.RentalUI::pairs($items);
        if (isset($balance['question'])) $body.=RentalUI::alert('Требуется финансовая сверка',$balance['question'],'warning');
        elseif (FinalSettlement::scoped($db,$id) && ($omitted=FinalSettlement::omittedInvoiceQuestion($db,$id,$data)))
            $body.=RentalUI::alert('Проверьте действующие счета',$omitted,'warning');
        $all=FinalSettlement::invoices($db,$id);
        foreach ($data['invoice_ids'] ?? [] as $invoice) {
            if (isset($all[$invoice])) $body.='<div class="demo-row">'.RentalUI::button('Окончательный счёт № '.$all[$invoice]['number'],'/rental/invoice?id='.$invoice).'</div>';
            else $body.='<p class="registry-ui-secondary">Ранее выбранный окончательный счёт № '.(int)$invoice.' требует сверки.</p>';
        }
        return $body;
    }

    public static function actions(array $contract): string
    {
        if (!FinalReview::ready($contract)) return '';
        $user=Auth::user();
        if (PagePolicy::level('rental.reconciliation',$user)<2) return '';
        $id=(int)$contract['id'];
        $buttons=[];
        $manager=in_array($user['role'],['manager','admin'],true) && Auth::can('rental.write');
        $legal=in_array($user['role'],['lawyer','admin'],true) && Auth::can('rental.approve.legal');
        $accounting=in_array($user['role'],['accountant','admin'],true) && Auth::can('finance.write');
        if ($manager) {
            $buttons[]=self::link($id,FinalSettlement::TERMS);
            if ($contract['status']==='reconciling' && !OccupiedBilling::noPhysicalReturn(DB::pdo(),$contract)) {
                $buttons[]=self::link($id,ReturnAct::RECORD_ONE);
            }
        }
        if ($legal) $buttons[]=self::link($id,FinalSettlement::AGREEMENT);
        if ($accounting) $buttons[]=self::link($id,FinalSettlement::FINANCE);
        foreach (FinalReview::LABELS as $kind=>$label) {
            $state=FinalReview::marker(DB::pdo(),$id,$kind)['state'] ?? '';
            if ($manager && $state==='returned') {
                $buttons[]=self::link($id,FinalReview::RESUBMIT,$kind,'Передать на повторную проверку · '.$label);
            }
            if ($state!=='returned' && ($kind==='finance'?$accounting:$legal)) {
                $action=$kind==='finance'?FinalReview::RETURN_FINANCE:FinalReview::RETURN_LEGAL;
                $buttons[]=self::link($id,$action);
            }
        }
        return $buttons?'<div class="demo-row">'.implode('',$buttons).'</div>':'';
    }

    private static function link(int $id,string $action,string $kind='',?string $label=null): string
    {
        return RentalUI::button($label ?? self::TITLES[$action],'/rental/action-form?'.http_build_query(array_filter([
            'id'=>$id,'action'=>$action,'review_kind'=>$kind,
        ])));
    }

    public static function timelineFields(array $v=[]): string
    {
        return Controller::input('notice_date','Фактическая дата предупреждения',$v['notice_date'] ?? '','date',false)
            .Controller::input('notice_due','Последний день для предупреждения',$v['notice_due'] ?? '','date',false)
            .Controller::input('notice_rule','Согласованное условие предупреждения',$v['notice_rule'] ?? '','text',false)
            .Controller::input('last_payable_day','Последний оплачиваемый день',$v['last_payable_day'] ?? '','date',false);
    }

    public static function action(array $contract,string $action): string
    {
        $id=(int)$contract['id'];
        Access::contract($id,2);
        PagePolicy::requireCommand($action);
        $role=match($action) {
            FinalReview::RETURN_FINANCE,FinalSettlement::FINANCE=>'accountant',
            FinalReview::RETURN_LEGAL,FinalSettlement::AGREEMENT=>'lawyer',default=>'manager',
        };
        Service::roles([$role]);
        Auth::requirePermission(match($role){'accountant'=>'finance.write','lawyer'=>'rental.approve.legal',default=>'rental.write'});
        FinalSettlement::stage($contract);
        $data=FinalSettlement::read(DB::pdo(),$id);
        $extra='';
        $label=match($action){FinalReview::RETURN_FINANCE,FinalReview::RETURN_LEGAL=>'Вернуть на доработку',
            FinalReview::RESUBMIT=>'Передать на проверку',default=>'Сохранить'};
        if (in_array($action,FinalReview::ACTIONS,true)) {
            $fields='<label class="field"><span class="field-label">Комментарий необязателен</span>'
                .'<textarea class="field-input" name="comment" maxlength="1500"></textarea></label>';
            if ($action===FinalReview::RESUBMIT) {
                $kind=(string)($_GET['review_kind'] ?? '');
                FinalReview::reviewer($kind);
                $marker=FinalReview::marker(DB::pdo(),$id,$kind);
                if (($marker['state'] ?? '')!=='returned') throw new RuntimeException('Нет задачи на доработке.',409);
                $fields=RentalUI::pairs([['Кому будет передано',$marker['reviewer']]])
                    .PremisesActsCard::hidden('review_kind',$kind).$fields.self::confirm('Исправления внесены');
            }
        } elseif ($action===FinalSettlement::TERMS) {
            $fields=Controller::input('reason','Причина завершения',$contract['finish_reason'] ?? '')
                .self::timelineFields($data['timeline'] ?? []);
        } elseif ($action===FinalSettlement::FINANCE) {
            $fields=self::financeFields($id,$data['finance'] ?? []);
        } elseif ($action===FinalSettlement::AGREEMENT) {
            $extra=AuxController::uploadForm($contract,FinalSettlement::AGREEMENT_FILE,'Файл подписанного итогового соглашения');
            $decision=$data['agreement'] ?? [];
            $mode=$decision['state'] ?? 'unresolved';
            $agreements=array_column(FinalSettlement::files(DB::pdo(),$id),'filename','id');
            $bases=array_column(FinalSettlement::legalBasisFiles(DB::pdo(),$id),'filename','id');
            $fields=DirectoryUi::select('agreement_state','Применимость отдельного соглашения',FinalSettlement::AGREEMENT_STATES,$mode,true)
                .DirectoryUi::select('file_id','Подписанное итоговое соглашение',[''=>'Выберите при наличии']+$agreements,$mode==='signed'?($decision['id'] ?? ''):'')
                .Controller::input('signed_on','Дата подписания итогового соглашения',$decision['signed_on'] ?? '','date',false)
                .DirectoryUi::select('basis_file_id','Подписанный договор или ДС для решения без соглашения',[''=>'Выберите основание']+$bases,$mode==='not_required'?($decision['id'] ?? ''):'')
                .Controller::input('legal_basis','Пункт или условие подписанных документов',$decision['reason'] ?? '','text',false)
                .self::confirm('Сверил юридическое основание с подписанным документом',false);
        } else {
            if ($contract['status']!=='reconciling') throw new RuntimeException('Договор не на сверке.',409);
            $files=array_filter(ReturnAct::files(DB::pdo(),$id,ReturnAct::ONE_FILE),
                static fn(array $f)=>(int)$f['version']===(int)$contract['version'] && !ReturnAct::receipt(DB::pdo(),$f['id']));
            $extra=AuxController::uploadForm($contract,ReturnAct::ONE_FILE,'Файл одностороннего акта');
            $choices=array_column($files,'filename','id');
            $fields=$choices ? DirectoryUi::select('file_id','Сохранённый файл',[''=>'Выберите файл']+$choices,'',true)
                .Controller::input('signed_on','Дата подписания','','date',true)
                .Controller::input('signer','Кем подписан односторонний акт') : '';
            $extra.=ReturnAct::history(DB::pdo(),$id);
        }
        $body=RentalUI::notice().$extra.($fields!==''?Controller::form($action,$id,(int)$contract['version'],$fields,$label):'');
        return RentalUI::modal(self::TITLES[$action],$body,'','reconciliation');
    }

    private static function financeFields(int $id,array $data): string
    {
        $body=Controller::input('debt','Подтверждённый долг, включая переменные расходы, ₽',$data['debt'] ?? '','text',false)
            .Controller::input('penalty','Подтверждённая неустойка, ₽',$data['penalty'] ?? '','text',false)
            .Controller::input('finance_source','Основание сумм',$data['finance_source'] ?? '','text',false)
            .self::confirm('Суммы сверены с основанием',false)
            .DirectoryUi::select('variable','Переменные расходы',FinalSettlement::VARIABLES,$data['variable'] ?? 'open',true)
            .Controller::input('variable_amount','Переменные расходы, ₽',$data['variable_amount'] ?? '','text',false)
            .Controller::input('variable_source','Исходные данные или причина ожидания',$data['variable_source'] ?? '','text',false);
        $rows=[];
        foreach (FinalSettlement::invoices(DB::pdo(),$id) as $invoice) {
            $i=(int)$invoice['id'];
            $rows[]=['<label class="registry-ui-checkbox"><input type="checkbox" name="invoice_ids[]" value="'.$i.'"'
                .(in_array($i,$data['invoice_ids'] ?? [],true)?' checked':'').'><span>Окончательный</span></label>',
                View::e($invoice['number']),View::e(Controller::money($invoice['amount'])),View::e(Controller::money($invoice['paid']))];
        }
        if ($rows) $body.=Controller::table(['Связь','Счёт','Сумма','Оплачено'],$rows);
        return $body;
    }

    private static function confirm(string $text,bool $required=true): string
    {
        return '<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1"'
            .($required?' required':'').'><span>'.View::e($text).'</span></label>';
    }
}
