<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,PagePolicy,View};
use DateTimeImmutable;

final class SecurityDepositCard
{
    public static function section(array $contract): string
    {
        $db=DB::pdo();
        $id=(int)$contract['id'];
        $closing=in_array($contract['status'],['reconciling','ended','closed'],true);
        if (!$closing && !in_array($contract['status'],['active','sent'],true)) return '';
        $ledger=SecurityDeposit::read($db,$contract);
        if (!$closing && !$ledger['invoice'] && !$ledger['moves']) return '';
        $invoice=$ledger['invoice'];
        $basis=$ledger['basis'];
        $money=Controller::money(...);
        $body='<h3 class="registry-ui-modal-summary">Обеспечение</h3>'.RentalUI::pairs([
            ['Обязательство',$money($invoice['amount'] ?? null)],
            ['Подтверждённые поступления',$money($ledger['received'])],
            ['Пришло зачётом с прежнего договора',$money($ledger['credited'])],
            ['К доплате по обязательству',$money($ledger['due']===null?null:(bccomp($ledger['due'],'0',2)<0?'0.00':$ledger['due']))],
            ['Временно оставлено до сверки',$money($ledger['held'])],
            ['Удержано по согласованному основанию',$money($ledger['retained'])],
            ['Перенесено на новый договор',$money($ledger['out'])],
            ['Фактически возвращено',$money($ledger['returned'])],
            ['Доступный остаток',$money($ledger['question']?null:$ledger['free'])],
        ]);
        if ($invoice && PagePolicy::level('rental.invoice-list',Auth::user())>=1) {
            $body.='<div class="demo-row">'.RentalUI::button('Счёт обеспечения № '.$invoice['number'],'/rental/invoice?id='.$invoice['id']).'</div>';
        }
        if ($ledger['question']) $body.=RentalUI::alert('Нужна сверка бухгалтера',$ledger['question'],'warning');
        if ($basis) {
            $body.=RentalUI::pairs([['Согласованное основание',SecurityDeposit::MODES[$basis['mode']]],
                ['Основание решения',$basis['reason']],['Согласовал',self::reviewer($basis)],
                ['Дата подписания соглашения',Documents::dateText($basis['document']['date'])]])
                .'<div class="demo-row">'.self::file($basis['document']).'</div>';
        } elseif ($closing) {
            $body.=RentalUI::alert('Нужно решение юриста','Сверьте подписанное соглашение и применимый порядок по обеспечению.');
        }
        $preview=SecurityDeposit::proposal($db,$contract,$ledger);
        if (isset($preview['amount'])) {
            $deadline=$preview['deadline'];
            $body.=RentalUI::pairs([['Предложено к возврату',$money($preview['amount'])],
                ['Дата подписи двустороннего акта',Documents::dateText($preview['evidence']['act_signed_on'])],
                ['Срок возврата',Documents::dateText($deadline['date'] ?? null)],['Рабочий календарь',$deadline['source'] ?? 'Не подтверждён'],
                ['Состояние расчёта',$preview['saved']?'Расчёт сохранён':'Предпросмотр']]);
        }
        if ($ledger['plan']) {
            $plan=$ledger['plan'];
            $body.=RentalUI::pairs([['Сохранённая сумма расчёта',$money($plan['amount'])],
                ['Основание сохранённого расчёта',$plan['reason']],['Расчёт сохранил',$plan['actor']],
                ['Источник срока',$plan['deadline']['source'].' · '.Documents::dateText($plan['deadline']['valid_from'])
                    .' — '.Documents::dateText($plan['deadline']['valid_to'])]]);
        }
        if ($question=$preview['question'] ?? $preview['deadline']['question'] ?? null)
            $body.=RentalUI::alert('Нужна сверка по возврату',$question,'warning');
        if ($ledger['rows']) {
            $rows=[];
            foreach ($ledger['rows'] as $row) {
                $proof=$row['proof'];
                $order=$row['order'];
                $label=$proof?'Сохранённое поступление из выписки':'Проведённое поступление не подтверждено';
                $rows[]=[View::e('№ '.$row['id']),View::e(Documents::dateText($row['date'])),View::e($money($row['amount'])),
                    View::e($label).($order?' · '.Controller::link('/rental/download?id='.$order['id'],'Платёжное поручение'):'')];
            }
            $body.=Controller::table(['Приход','Дата','Распределено','Подтверждение'],$rows);
        }
        if ($ledger['moves']) $body.=self::history($id,$ledger['moves']);
        return $body.($closing?self::actions($id,$ledger,$preview):'');
    }

    private static function file(array $doc): string
    {
        return Controller::link('/rental/download?id='.$doc['id'],$doc['name']);
    }

    private static function history(int $id,array $moves): string
    {
        $rows=[];
        foreach (array_reverse($moves) as $move) {
            $kind=$move['kind'];
            $related=($move['target'] ?? null)===$id?$move['contract']:($move['target'] ?? null);
            $source=isset($move['bank_document']) ? Controller::link('/connections/onec-yakovlev?tab=bank&document='.$move['bank_document']['id'],$move['bank_document']['name']) :
                ($related===$move['contract']?View::e($move['document']['name']):self::file($move['document']));
            $label=match($kind) {
                SecurityDeposit::HOLD=>'Остаток до сверки',
                SecurityDeposit::RETAIN=>'Удержано',
                SecurityDeposit::RETURNED=>'Фактически возвращено',
                default=>$related===$move['contract']?'Зачтено с прежнего договора':'Перенесено на новый договор',
            };
            if ($related) {
                $source.=' · '.Controller::link('/rental/contract?id='.$related,
                    $related===$move['contract']?'Прежний договор':'Новый договор');
                if ($related===$move['contract']) $source.=' · '.self::file($move['document']);
            }
            $reason=$move['reason'];
            if (isset($move['bank_document']['purpose'])) $reason.=' · '.$move['bank_document']['purpose'];
            $rows[]=[View::e($label),View::e(Documents::dateText($move['date'])),View::e(Controller::money($move['amount'])),
                View::e((new DateTimeImmutable($move['recorded_at']))->format('d.m.Y H:i')),
                View::e(self::reviewer($move)),$source,View::e($reason)];
        }
        return '<h3 class="registry-ui-modal-summary">История операций с обеспечением</h3>'
            .Controller::table(['Операция','Дата основания','Сумма','Учтено','Сотрудник','Документ','Основание'],$rows);
    }

    private static function reviewer(array $entry): string
    {
        $role=match($entry['role']) {'lawyer'=>'Юрист','accountant'=>'Бухгалтер',default=>'Администратор'};
        return $entry['actor'].' · '.$role;
    }

    private static function actions(int $id,array $ledger,array $preview): string
    {
        $user=Auth::user();
        if (PagePolicy::level('rental.reconciliation',$user)<2) return '';
        $actions=in_array($user['role'],['admin','lawyer'],true) && Auth::can('rental.approve.legal')?[SecurityDeposit::BASIS]:[];
        if (!in_array($user['role'],['admin','accountant'],true) || !Auth::can('finance.write') || $ledger['question'])
            return $actions?'<div class="demo-row">'.self::button($id,SecurityDeposit::BASIS).'</div>':'';
        $finance=match($ledger['basis']['mode'] ?? '') {
            'full'=>array_filter([empty($preview['deadline']['date'])?null:SecurityDeposit::PLAN,
                isset($preview['amount'])?SecurityDeposit::RETURNED:null]),
            'claims'=>[SecurityDeposit::HOLD,SecurityDeposit::RETAIN,SecurityDeposit::RETURNED],
            'transfer'=>[SecurityDeposit::TRANSFER],
            default=>[],
        };
        $actions=array_merge($actions,$finance);
        if (!$actions) return '';
        return '<div class="demo-row">'.implode('',array_map(static fn(string $action)=>self::button($id,$action),$actions)).'</div>';
    }

    private static function button(int $id,string $action): string
    {
        return RentalUI::button(SecurityDeposit::TITLES[$action],'/rental/action-form?'.http_build_query(['id'=>$id,'action'=>$action]));
    }

    public static function action(int $id,string $action): string
    {
        $contract=SecurityDeposit::access($id,$action);
        $db=DB::pdo();
        $basis=SecurityDeposit::basis($db,$id);
        $proof=$action===SecurityDeposit::BASIS?(FinalSettlement::read($db,$id)['agreement'] ?? []):[];
        $file=($proof['state'] ?? '')==='signed' ? FinalSettlement::files($db,$id)[$proof['id'] ?? ''] ?? null : null;
        $body=match($action) {
            SecurityDeposit::BASIS=>($file?'<div class="demo-row">'.self::file(['id'=>$file['id'],'name'=>$file['filename']]).'</div>':'')
                .DirectoryUi::select('mode','Применимый порядок',SecurityDeposit::MODES,$basis['mode'] ?? 'full',true)
                .Controller::input('amount','Сумма переноса или согласованный предел удержания, ₽',$basis['amount'] ?? '','text',false)
                .DirectoryUi::select('target','Связанный новый договор для D20',self::successors($contract),(string)($basis['target'] ?? '0'))
                .Controller::input('source_number','Номер прежнего договора в подписанном D20',$basis['reference']['source_number'] ?? '','text',false)
                .Controller::input('target_number','Номер нового договора в подписанном D20',$basis['reference']['target_number'] ?? '','text',false),
            SecurityDeposit::HOLD=>Controller::input('amount','Всего временно оставить до сверки, ₽'),
            SecurityDeposit::RETAIN=>Controller::input('amount','Сумма удержания, ₽')
                .'<label class="registry-ui-checkbox"><input type="checkbox" name="from_hold" value="1"><span>Взять из суммы, оставленной до сверки</span></label>',
            SecurityDeposit::RETURNED=>Controller::input('bank_document','Номер или ID проведённого списания из сохранённой выписки')
                .'<div class="demo-row">'.Controller::link('/connections/onec-yakovlev?tab=bank','Сохранённая выписка 1С').'</div>',
            SecurityDeposit::TRANSFER=>RentalUI::pairs([['К переносу по соглашению',Controller::money($basis['amount'] ?? null)]]),
            default=>'',
        };
        $confirm=$action===SecurityDeposit::BASIS?'Подтверждаю условие по подписанному соглашению. Для D20 сверил оба договора и сумму.':
            'Подтверждаю по документам';
        $reason=Controller::input('reason','Пункт документа или пояснение к операции')
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>'.View::e($confirm).'</span></label>';
        return RentalUI::modal(SecurityDeposit::TITLES[$action],
            RentalUI::notice().Controller::form($action,$id,(int)$contract['version'],$body.$reason,'Сохранить'),
            '','reconciliation');
    }

    private static function successors(array $old): array
    {
        $rows=Controller::query("SELECT id,number FROM contracts
            WHERE party_id=? AND start_date>? AND signed=1
                AND status IN ('sent','active') ORDER BY id",[$old['party_id'],$old['start_date']]);
        $options=['0'=>'Не выбран'];
        foreach ($rows as $row) $options[$row['id']]='№ '.$row['number'];
        return $options;
    }
}
