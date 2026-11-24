<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB, PagePolicy, People, View};
use RuntimeException;

final class ContractApprovalPreview
{
    public const PARTY_URL='/rental/approval-party-details';
    private const TRANSITIONS=['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','handed','cancelled'];

    public static function available(array $contract,string $role): bool
    {
        return $role!=='admin' && RegistryState::managed($contract)
            && in_array($contract['status'],['legal','accounting','returned','approved','sent','cancelled'],true)
            && RegistryState::owner($contract)!==$role;
    }

    public static function partyDetails(): string
    {
        Access::pages(['parties']);
        $contract=Access::contract((int)($_GET['contract_id']??0));
        if (!self::available($contract,Auth::user()['role'])) {
            throw new RuntimeException('Договор уже на другом этапе.',409);
        }
        return PartyDirectory::reviewModal(Service::get(DB::pdo(),'parties',(int)$contract['party_id']));
    }

    public static function render(array $contract): string
    {
        $id=(int)$contract['id'];
        $actor=Auth::user();
        $party=Service::get(DB::pdo(),'parties',(int)$contract['party_id']);
        $event=$contract['status']==='legal'?self::latestEvent($id):[];
        $partyBody=RentalUI::pairs([
            ['Наименование',$party['name']],
            ['ИНН / КПП',($party['inn']?:'—').' / '.($party['kpp']?:'—')],
        ]);
        if (PagePolicy::level('rental.parties',$actor)>=1) {
            $partyBody.=RentalUI::button('Полные данные',self::PARTY_URL.'?contract_id='.$id);
        }

        $files=self::documents($contract);
        $body=self::stage($contract,$event)
            .RentalUI::reviewSection('Контрагент',$partyBody)
            .RentalUI::reviewSection('Условия',self::terms($contract))
            .RentalUI::reviewSection('Документы',$files?:'<p class="registry-ui-modal-help">Документы ещё не переданы.</p>');
        return RentalUI::modal('Договор №'.$contract['number'],$body,active:'approvals',
            leadingActions:self::cancelAction($contract,$actor),extractForms:false);
    }

    private static function cancelAction(array $contract,array $actor): string
    {
        if (!in_array($actor['role'],['manager','lawyer','accountant'],true)) return '';

        $label='Аннулировать договор';
        $action='contract.cancel';
        $cancelled=$contract['status']==='cancelled';
        if (!$cancelled && $actor['role']==='manager' && Auth::can('rental.write')
            && PagePolicy::level(PagePolicy::commandPage($action),$actor)>=2) {
            return RentalUI::button($label,'/rental/action-form?id='.(int)$contract['id'].'&action='.$action,danger:true);
        }

        $reason=$cancelled?'Договор уже аннулирован.':'Аннулирование доступно менеджеру по аренде с правом изменения договора.';
        return '<span title="'.View::e($reason).'"><button type="button" class="btn btn-danger" disabled aria-description="'
            .View::e($reason).'">'.View::e($label).'</button></span>';
    }

    private static function stage(array $contract,array $event): string
    {
        $status=$contract['status'];
        $owner=RegistryState::owner($contract);
        $responsible=$owner?People::workflowName($owner):'';
        $title=match ($status) {
            'legal'=>($event['event_kind']??null)==='resubmit'?'Повторная проверка у юриста':'На проверке у юриста',
            'accounting'=>'На проверке у бухгалтерии',
            'approved'=>'Пакет согласован · подготовка отправки',
            'sent'=>'Ожидается подписанный экземпляр',
            'returned'=>$owner==='lawyer'?'Бухгалтерия: отказ в согласовании':'На доработке у менеджера',
            'cancelled'=>'Договор аннулирован',
        };
        $description=match ($status) {
            'legal'=>'Проверяются контрагент и условия договора.',
            'accounting'=>'Проверяются реквизиты, условия и документы.',
            'approved'=>Delivery::presentation(DB::pdo(),'contract',(int)$contract['id'])['message']??'Пакет готовится к отправке клиенту.',
            'sent'=>'Документы переданы клиенту.',
            'returned'=>($owner==='lawyer'?'Причина отказа':'Замечание юриста').($contract['reason']?' · '.$contract['reason']:''),
            'cancelled'=>$contract['reason']??'',
        };
        if ($responsible!=='') $description='Ответственный · '.$responsible.'. '.$description;
        return RentalUI::alert($title,$description,$status==='cancelled'?'error':'info',filled:true);
    }

    private static function terms(array $contract): string
    {
        $db=DB::pdo();
        $terms=BillableTerms::forContract($db,(int)$contract['id']);
        $body=RentalUI::pairs([
            ['Дата договора',RegistryState::date($contract['document_date'])],
            ['Период аренды',RegistryState::date($contract['start_date']).' — '.RegistryState::date($contract['end_date'])],
            ...RenewalIndexation::facts($db,$contract,'contract'),
        ]);
        return $body.RentalUI::reviewTermsTable($terms,$contract['amount']);
    }

    private static function documents(array $contract): string
    {
        $id=(int)$contract['id'];
        $db=DB::pdo();
        $materials=ContractReviewFiles::submittedPresentation($contract);
        if (!in_array($contract['status'],['accounting','approved','sent','returned'],true)) return $materials;
        $history=LegalHandoff::history($db,$id);

        $query=$db->prepare("SELECT f.*,a.confirmed_at FROM rental_approvals a JOIN rental_files f ON f.id=a.file_id
            AND f.entity_type='contract' AND f.entity_id=a.entity_id
            WHERE a.entity_type='contract' AND a.entity_id=? AND a.role='lawyer'");
        $query->execute([$id]);
        $primary=$query->fetch();
        $current=null;
        if ($primary) {
            foreach ($history as $bundle) {
                if (($bundle['files'][0]['id']??null)!==$primary['id']) continue;
                $current=$bundle;
                break;
            }
            if (!$current) {
                $pack=WordPack::evidence($db,$primary['id']);
                $current=['files'=>$pack['files']??[$primary],'sent_at'=>$primary['confirmed_at']];
            }
        }
        if (!$current && $contract['status']==='returned') $current=$history[0]??null;
        if (!$current) return $materials;

        $label=$contract['status']==='returned'?'Последний переданный комплект':'Комплект юриста';
        return self::fileList($current,$label);
    }

    private static function fileList(array $bundle,string $label): string
    {
        $html='<ul class="registry-ui-form-section registry-ui-review-files registry-ui-review-files--inline">';
        $date=!empty($bundle['sent_at'])?(new \DateTimeImmutable($bundle['sent_at']))->format('d.m.Y H:i'):'';
        foreach ($bundle['files'] as $file) {
            $html.='<li><div><span class="registry-ui-modal-text">'.View::e($label).'</span>'
                .($date!==''?'<div class="doc-meta">'.View::e($date).'</div>':'').'</div>'
                .'<a href="/rental/download?id='.View::e($file['id']).'">'.View::e($file['filename']).'</a></li>';
        }
        return $html.'</ul>';
    }

    private static function latestEvent(int $id): array
    {
        $db=DB::pdo();
        $marks=implode(',',array_fill(0,count(self::TRANSITIONS),'?'));
        $query=$db->prepare("SELECT id,created_at,source_json FROM rental_events WHERE id>? AND contract_id=? AND
            JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.entity_kind'))='contract' AND
            JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.event_kind')) IN ($marks) ORDER BY id DESC");
        $query->execute([ZeroPoint::eventFloor($db),$id,...self::TRANSITIONS]);
        while ($row=$query->fetch()) {
            if ($event=WorkflowEvents::decode($row)) return $event;
        }
        return [];
    }
}
