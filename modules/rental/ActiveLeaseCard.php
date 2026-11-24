<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB, PagePolicy};

final class ActiveLeaseCard
{
    public const TERMINATE_LABEL = 'Расторжение договора';

    public static function render(array $contract): string
    {
        $id=(int)$contract['id'];
        $user=Auth::user();
        $managed=empty(json_decode($contract['source_json'],true)['imported']);
        $canManage=in_array($user['role'],['admin','manager'],true) && Auth::can('rental.write');
        $body=ContractRevision::presentation($contract).RentalUI::notice();
        $body.='<div class="registry-ui-form-sections registry-ui-form-sections--compact">'
            .RentalUI::reviewSection('Контрагент',RentalUI::pairs([['Наименование',$contract['party_name']]]))
            .RentalUI::reviewSection('Условия договора',self::terms($contract,$managed),'Действует',true)
            .SecurityDepositCard::section($contract,reviewStyle:true)
            .RentalUI::reviewSection('Оплата',LeasePaymentTermsCard::summary($contract)
                .InitialInvoiceCard::entry($contract).InitialInvoiceCard::attachments($contract));

        $documents=CustomerBookings::contractOrigin($contract);
        $links=Documents::signedContractLinks($id);
        if ($links) $documents.='<div class="demo-row">'.implode(' ',$links).'</div>';
        $delivery=Delivery::presentation(DB::pdo(),'contract',$id);
        if ($delivery && $delivery['message']!=='') {
            $documents.=RentalUI::alert('Документы и доставка',$delivery['message'],$delivery['error']?'error':'info');
        }
        $documents.=HandoverQuestionCard::section($contract).ContractReviewFiles::presentation($contract);
        if ($canManage && $managed && !$contract['signed'] && PagePolicy::level(PagePolicy::commandPage('contract.sign'),$user)>=2) {
            $documents.=RentalUI::button('Загрузить подписанный договор','/rental/action-form?id='.$id.'&action=contract.sign');
        }
        if ($documents!=='') $body.=RentalUI::reviewSection('Документы',$documents);
        $body.='</div>';

        $addendum=$canManage && PagePolicy::level(PagePolicy::commandPage('document.create'),$user)>=2
            ?RentalUI::button('Допсоглашение','/rental/addendum-form?contract_id='.$id,true):'';
        $terminate=$canManage && PagePolicy::level(PagePolicy::commandPage('contract.reconcile'),$user)>=2
            ?RentalUI::button(self::TERMINATE_LABEL,'/rental/action-form?id='.$id.'&action=contract.reconcile',danger:true):'';

        return RentalUI::modal('Договор №'.$contract['number'],$body,$addendum,'contracts-active',wide:true,
            leadingActions:$terminate,extractForms:false);
    }

    private static function terms(array $contract,bool $managed): string
    {
        $id=(int)$contract['id'];
        $today=date('Y-m-d');
        $current=[];
        $parts=[];
        foreach (BillableTerms::forContract(DB::pdo(),$id) as $term) {
            if (($term['effective_date']!==null && $term['effective_date']>$today)
                || ($term['superseded_on']!==null && $term['superseded_on']<$today)) continue;
            if ($term['object_id']===null) {
                $term['type']='Юридический адрес';
                $parts[$term['target_key']]=$term;
            } else {
                $current[$term['object_id']]=$term;
            }
        }
        $objects=Controller::query('SELECT o.id,o.name,o.type,o.area FROM contract_objects co
            JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=? ORDER BY o.id',[$id]);
        $rows=[];
        $editable=[];
        foreach ($objects as $object) {
            $term=$current[$object['id']] ?? null;
            $rows[]=$object+['amount'=>$term['amount'] ?? null];
            if (isset($term['start_date'],$term['end_date'])) $editable[]=$object+['end_date'=>$term['end_date']];
        }
        $rows=[...$rows,...array_values($parts)];
        $total='0.00';
        foreach ($rows as $row) {
            if ($row['amount']===null) {
                $total=null;
                break;
            }
            $total=bcadd($total,$row['amount'],2);
        }
        $body=RentalUI::pairs([
            ['Дата договора',Documents::dateText($contract['document_date'])],
            ['Период аренды',RegistryState::date($contract['start_date']).' — '.RegistryState::date($contract['end_date'])],
            ['Ответственный',$managed?'Менеджер':'Не назначен'],
            ['Подписание',$contract['signed']?'Подписи обеих сторон подтверждены':($managed?'Ожидается подписанный экземпляр':'По исходному реестру')],
            ...(!$rows || $total===null ? [['Аренда за месяц с НДС',Controller::money($contract['amount'])]] : []),
        ]);
        return $body.RentalUI::reviewTermsTable($rows,$total).OccupiedBillingCard::summary($contract,$objects)
            .OccupiedBillingCard::inlineEditor($contract,$editable).ExpiryNoticeCard::entry($contract);
    }
}
