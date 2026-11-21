<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{DB, View};
use RuntimeException;

final class OccupiedBillingCard
{
    public static function fields(?string $object,array $row=[]): string
    {
        $name=static fn(string $key): string=>$object===null?$key:'billing_exception['.$object.']['.$key.']';
        $checked=($row['enabled'] ?? ($row ? '1' : '0'))==='1';
        return '<div data-occupied-billing><label class="registry-ui-checkbox"><input type="checkbox" data-occupied-billing-check name="'
            .View::e($name('enabled')).'" value="1"'.($checked?' checked':'').'><span>'.OccupiedBilling::LABEL.'</span></label>'
            .'<div data-occupied-billing-fields'.($checked?'':' hidden').'><div class="registry-ui-modal-grid">'
            .Controller::input($name('reason'),'Согласованное основание',(string)($row['reason'] ?? ''),'text',false)
            .Controller::input($name('until'),'Окончание по условию объекта',(string)($row['until'] ?? ''),'date',false)
            .'</div></div></div>';
    }

    public static function summary(array $contract,array $objects): string
    {
        $draft=ContractDraft::input($contract);
        $exceptions=$draft===null?OccupiedBilling::exceptions($contract):OccupiedBilling::draftRows($draft);
        $description=[];
        foreach ($objects as $object) {
            $row=$exceptions[$object['id']] ?? null;
            if (!$row) continue;
            try {
                $until=Documents::dateText(Service::date($row['until'] ?? ''));
            } catch (RuntimeException) {
                $until='уточнения срока';
            }
            $description[]=$object['name'].' · до '.$until
                .(!empty($row['reason'])?' · '.$row['reason']:'');
        }
        return $description?RentalUI::alert('Есть исключение по объекту',implode(' / ',$description),'info'):'';
    }

    public static function editor(): string
    {
        $id=(int)($_GET['id'] ?? 0);
        $contract=Access::contract($id,2);
        if (!OccupiedBilling::canEdit($contract)) throw new RuntimeException('Действие доступно бухгалтеру действующего договора.',403);
        $objects=Occupancy::objects(DB::pdo(),$id);
        $exceptions=OccupiedBilling::exceptions($contract);
        $oid=(int)($_GET['object_id'] ?? 0);
        if (!$oid) {
            $rows=[];
            foreach ($objects as $obj) {
                $rows[]=[View::e($obj['name']),View::e(isset($exceptions[$obj['id']])?'Есть исключение':'Обычная аренда'),
                    RentalUI::button('Открыть','/rental/occupied-billing?id='.$id.'&object_id='.$obj['id'])];
            }
            return RentalUI::modal('Исключение по объекту · №'.$contract['number'],Controller::table(
                ['Объект','Условие','Действие'],$rows),'','contracts-active');
        }
        if (!isset($objects[$oid])) throw new RuntimeException('Объект не входит в договор.',404);
        $term=OccupiedBilling::term(DB::pdo(),$id,$oid);
        $body=RentalUI::pairs([['Объект',$objects[$oid]['name']],['Срок по условию',
            Documents::dateText($term['start_date']).' — '.Documents::dateText($term['end_date'])]]);
        [$form,$actions]=DirectoryUi::form(OccupiedBilling::SAVE,$contract,
            '<input type="hidden" name="object_id" value="'.$oid.'">'
            .self::fields(null,$exceptions[$oid] ?? ['enabled'=>'0','until'=>$term['end_date']]),'Сохранить исключение');
        return RentalUI::modal('Исключение по объекту · №'.$contract['number'],$body.$form,$actions,'contracts-active');
    }
}
