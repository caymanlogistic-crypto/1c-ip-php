<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, Http, View};
use RuntimeException;

final class ApprovalDecision
{
    private const LAWYER = [
        'block'=>['Заблокировать работу','Новые договоры с этим контрагентом будут заблокированы. Текущий договор вернётся менеджеру.','Заблокировать и вернуть','Причина блокировки'],
        'documents'=>['Дозапросить документы','Договор вернётся менеджеру с указанным списком.','Отправить запрос','Какие документы нужны'],
        'terms'=>['Условия неверны','Договор вернётся менеджеру с указанными замечаниями.','Вернуть на доработку','Какие условия нужно исправить'],
    ];

    public static function lawyerButton(array $contract,string $decision): string
    {
        return RentalUI::button(self::LAWYER[$decision][0],'/rental/action-form?id='.(int)$contract['id']
            .'&action='.self::lawyerAction($contract,$decision).'&legal_decision='.$decision,danger:true);
    }

    public static function lawyer(array $contract,string $action,string $decision): string
    {
        $options=self::LAWYER[$decision]??throw new RuntimeException('Действие не найдено.',404);
        if ((Auth::user()['role']??'')!=='lawyer') throw new RuntimeException('Действие доступно юристу.',403);
        Auth::requirePermission('rental.approve.legal');
        Access::contract((int)$contract['id'],2);
        if (!RegistryState::managed($contract) || !LegalReview::assigned($contract)
            || $action!==self::lawyerAction($contract,$decision)) {
            throw new RuntimeException('Этап договора изменился. Откройте карточку заново.',409);
        }
        [$title,$effect,$submit,$label]=$options;
        return self::form($contract,$action,$title,$effect,$submit,$label);
    }

    public static function returnForRevision(array $record,string $action): string
    {
        $document=str_starts_with($action,'document.');
        $forward=str_ends_with($action,'.forward');
        $toLawyer=!$forward && $record['status']==='accounting';
        Service::roles($toLawyer?['accountant']:['lawyer']);
        Auth::requirePermission($toLawyer?'finance.write':'rental.approve.legal');
        if ($document) Access::document((int)$record['id'],2);
        else Access::contract((int)$record['id'],2);
        if ($forward ? !LegalReview::assigned($record) || $record['status']!=='returned'
            : !in_array($record['status'],['accounting','legal'],true)) {
            throw new RuntimeException('Этап согласования изменился. Откройте карточку заново.',409);
        }
        $subject=$document?'Допсоглашение':'Договор';
        $recipient=$toLawyer?'юристу':'менеджеру';
        $title=$forward?'Передать замечания менеджеру':'Вернуть на доработку';
        $reason=$document && $forward?(string)$record['reason']:'';
        return self::form($record,$action,$title,$subject.' вернётся '.$recipient.' с указанной причиной.',
            $forward?'Передать менеджеру':'Вернуть','Причина возврата',$reason);
    }

    private static function lawyerAction(array $contract,string $decision): string
    {
        if ($decision==='block') return LegalHandoff::BLOCK;
        return $contract['status']==='returned'?'contract.forward':'contract.return';
    }

    private static function form(array $record,string $action,string $title,string $effect,string $submit,string $label,string $reason=''): string
    {
        $formId='approval-decision';
        $body='<form class="registry-ui-form-section" id="'.$formId.'" method="post" action="/rental/action">'
            .PremisesActsCard::hidden('_csrf',Http::csrf())
            .PremisesActsCard::hidden('id',(int)$record['id'])
            .PremisesActsCard::hidden('version',(int)$record['version'])
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)))
            .PremisesActsCard::hidden('action',$action)
            .'<p class="registry-ui-modal-text">'.View::e($effect).'</p>'
            .'<label class="field"><span class="field-label">'.View::e($label).'</span>'
            .'<textarea class="field-input" name="reason" rows="3" maxlength="1000" required>'.View::e($reason).'</textarea></label></form>';
        $button='<button type="submit" form="'.$formId.'" class="btn btn-danger">'.View::e($submit).'</button>';
        return RentalUI::modal($title,$body,$button,'approvals',closeLabel:'Вернуться',confirmation:true);
    }
}
