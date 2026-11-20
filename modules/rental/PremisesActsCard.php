<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,DB,Http,PagePolicy,View};
use PDO;
use RuntimeException;

final class PremisesActsCard
{
    private static function route(int $id): string
    {
        return '/rental/acts?id='.$id;
    }

    public static function summary(array $contract): string
    {
        $db=DB::pdo();$id=(int)$contract['id'];
        $subjects=PremisesActs::subjects($db,$id);
        $history=PremisesActs::history($db,$id);
        if (!$subjects && !$history && !$contract['return_confirmed']) return '';
        $dates=[];
        foreach ($history as $record) {
            if ($record['kind']!=='return') $dates[]=[PremisesActs::TITLES[$record['kind']].' · '.$record['object_name'],Documents::dateText($record['date'])];
        }
        if ($contract['return_confirmed']) $dates[]=['Фактический возврат',Documents::dateText($contract['return_confirmed'])];
        return ($dates?RentalUI::pairs($dates):'').'<div class="demo-row">'.RentalUI::button('Акты и опись',self::route($id)).'</div>';
    }

    public static function card(): string
    {
        $id=(int)($_GET['id']??0);
        $contract=Access::contract($id);
        $db=DB::pdo();
        $objectId=(int)($_GET['object_id']??0);
        $kind=(string)($_GET['kind']??'');
        if ($kind==='return') {
            foreach (PremisesActs::history($db,$id) as $record) {
                if ($record['kind']==='return' && $record['object_id']===$objectId && $record['file_id']===($_GET['file']??null)) return self::snapshot($contract,$record);
            }
            throw new RuntimeException('Опись акта возврата не найдена.',404);
        }
        if ($objectId) {
            if (isset(PremisesActs::FILE_KINDS[$kind]) && ($record=PremisesActs::recorded($db,$id,$objectId,$kind))) return self::snapshot($contract,$record);
            $object=PremisesActs::requireSubject($db,$contract,$objectId,$kind);
            if (!PremisesActs::canEdit($contract,$kind)) throw new RuntimeException('Действие с актом недоступно.',403);
            Access::contract($id,2);
            return $kind==='count'?self::countForm($db,$contract,$object):self::actForm($db,$contract,$object,$kind);
        }
        return self::overview($db,$contract);
    }

    private static function accessoryLabel(array $object): string
    {
        return PremisesActs::parking($object)?'Брелоки':'Пропуска';
    }

    private static function overview(PDO $db,array $contract): string
    {
        $id=(int)$contract['id'];$route=self::route($id);
        $body=RentalUI::notice().RentalUI::alert('Формы актов в Word','Образцы находятся в проекте договора. Фактические даты и подписанные акты хранятся ниже.');
        $subjects=PremisesActs::subjects($db,$id);
        $history=PremisesActs::history($db,$id);
        if (LegalReview::assigned($contract) && Auth::user()['role']==='lawyer' && Auth::can('rental.approve.legal')) {
            $body.='<div class="demo-row">'.WordPack::action($contract).'</div>';
        }
        foreach ($subjects as $objectId=>$object) {
            $body.='<section class="registry-ui-form-section"><h3 class="registry-ui-modal-summary">'.View::e($object['name']).'</h3>';
            $count=PremisesActs::count($db,$id,$objectId);
            $body.=RentalUI::pairs([[self::accessoryLabel($object),$count===null?'Согласованное количество не внесено':$count['count']]]);
            $actions='';
            if (PremisesActs::canEdit($contract,'count')) $actions.=RentalUI::button('Внести количество',$route.'&object_id='.$objectId.'&kind=count');
            foreach (array_keys(PremisesActs::FILE_KINDS) as $kind) {
                if (!PremisesActs::permits($object,$kind)) continue;
                $record=PremisesActs::recorded($db,$id,$objectId,$kind);
                if ($record || PremisesActs::canEdit($contract,$kind)) {
                    $label=PremisesActs::TITLES[$kind].($record?' · '.Documents::dateText($record['date']):' · оформить акт');
                    $actions.=RentalUI::button($label,$route.'&object_id='.$objectId.'&kind='.$kind);
                }
            }
            if (!PremisesActs::parking($object) && PagePolicy::level('rental.objects',Auth::user())>=1) {
                $actions.=RentalUI::button('Текущая опись','/rental/object-inventory?id='.$objectId);
            }
            $body.=($actions?'<div class="demo-row">'.$actions.'</div>':'').'</section>';
        }
        foreach ($history as $record) {
            $kind=$record['kind'];
            if ($kind!=='return' && isset($subjects[$record['object_id']])) continue;
            $url=$route.'&object_id='.$record['object_id'].'&kind='.$kind;
            if ($kind==='return') $url.='&file='.$record['file_id'];
            $body.='<div class="demo-row">'.RentalUI::button(PremisesActs::TITLES[$kind].' · '.$record['object_name'].' · '.Documents::dateText($record['date']),$url).'</div>';
        }
        $return=ReturnAct::state($db,$contract);
        if ($return['file'] && $return['confirmed']) $body.=ReturnAct::summary($return);
        if ($contract['status']==='reconciling' && !$return['confirmed'] && in_array(Auth::user()['role'],['manager','admin'],true)
            && Auth::can('rental.write') && PagePolicy::level('rental.reconciliation',Auth::user())>=2) {
            $body.='<div class="demo-row">'.RentalUI::button('Загрузить и подтвердить акт возврата',
                '/rental/action-form?id='.$id.'&action=contract.return-act').'</div>';
        }
        return RentalUI::modal('Акты и опись · договор № '.$contract['number'],$body,
            RentalUI::button('К договору','/rental/contract?id='.$id),'contracts-active');
    }

    private static function countForm(PDO $db,array $contract,array $object): string
    {
        $count=PremisesActs::count($db,(int)$contract['id'],(int)$object['id']);
        $fields=self::hidden('object_id',$object['id']).self::hidden('count_version',$count['version']??0)
            .Controller::input('accessories','Согласованное количество',$count['count']??'','number',true);
        $body=Controller::form(PremisesActs::COUNT,(int)$contract['id'],(int)$contract['version'],$fields,'Сохранить');
        return RentalUI::modal(self::accessoryLabel($object).' · '.$object['name'],$body,'','contracts-active');
    }

    private static function actForm(PDO $db,array $contract,array $object,string $kind): string
    {
        $id=(int)$contract['id'];$objectId=(int)$object['id'];
        $pending=PremisesActs::candidate($db,$contract,$objectId,$kind);
        $items=$kind==='transfer' && !PremisesActs::parking($object)?PremisesInventory::items($db,$objectId):[];
        $body=RentalUI::pairs([['Договор',$contract['number']],['Объект',$object['name']]]);
        $body.='<form id="premises-act-file" class="registry-ui-modal-grid" method="post" action="/rental/upload" enctype="multipart/form-data">'
            .self::hidden('_csrf',Http::csrf()).self::hidden('id',$id).self::hidden('version',$contract['version'])
            .self::hidden('kind',PremisesActs::FILE_KINDS[$kind]).self::hidden('entity_type','contract').self::hidden('object_id',$objectId)
            .'<label class="field"><span class="field-label">Подписанный акт</span><input type="file" name="file" accept=".pdf,.doc,.docx" required></label></form>';
        $actions='<button class="btn btn-secondary" type="submit" form="premises-act-file">Загрузить файл</button>';
        if ($pending) {
            $body.='<div class="demo-row">'.self::fileLink($pending['id'],'Скачать загруженный акт').'</div>';
            if ($kind==='transfer' && !PremisesActs::parking($object)) {
                $body.='<h3 class="registry-ui-modal-summary">Опись на передачу</h3>'.PremisesInventory::table($items)
                    .'<p class="registry-ui-secondary">Сверьте опись с подписанным актом перед подтверждением.</p>';
            }
            $body.='<form id="premises-act-confirm" class="registry-ui-modal-grid" method="post" action="/rental/action">'
                .self::hidden('_csrf',Http::csrf()).self::hidden('id',$id).self::hidden('version',$contract['version'])
                .self::hidden('action',PremisesActs::CONFIRM).self::hidden('command_key',bin2hex(random_bytes(16)))
                .self::hidden('act_kind',$kind).self::hidden('object_id',$objectId).self::hidden('file_id',$pending['id'])
                .self::hidden('inventory_hash',PremisesActs::inventoryDigest($items))
                .Controller::input('act_date','Фактическая дата','','date');
            if ($kind==='access') {
                $body.=Controller::input('period_from','Начало допуска','','date').Controller::input('period_until','Окончание допуска','','date')
                    .Controller::input('keys','Переданные ключи');
            }
            $body.='<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required>'
                .'<span>Акт подписан обеими сторонами</span></label></form>';
            $actions.='<button class="btn btn-primary" type="submit" form="premises-act-confirm">Подтвердить акт</button>';
        }
        return RentalUI::modal(PremisesActs::TITLES[$kind].' · '.$object['name'],$body,$actions,'contracts-active');
    }

    private static function snapshot(array $contract,array $act): string
    {
        $kind=$act['kind'];$details=$act['details'];
        $body=RentalUI::pairs([
            ['Договор',$contract['number']],['Объект на дату акта',$act['object_name']],['Фактическая дата',Documents::dateText($act['date'])],
            ['Редакция договора',$act['version']],
        ]).'<div class="demo-row">'.self::fileLink($act['file_id'],'Скачать подписанный акт').'</div>';
        if ($kind==='access') $body.=RentalUI::pairs([
            ['Период допуска',Documents::dateText($details['period_from']).' — '.Documents::dateText($details['period_until'])],['Переданные ключи',$details['keys']],
        ]);
        if ($act['accessories']!==null) $body.=RentalUI::pairs([[self::accessoryLabel(['type'=>$act['object_type']]),$act['accessories']]]);
        if ($kind==='return' && $details['defects']!=='') $body.=RentalUI::pairs([
            ['Дефекты',$details['defects']],['Срок устранения',Documents::dateText($details['remedy_by'])],
        ]);
        if ($kind!=='access' && !PremisesActs::parking(['type'=>$act['object_type']])) {
            $body.='<h3 class="registry-ui-modal-summary">Опись на дату акта</h3>'.PremisesInventory::table($act['inventory'],(int)$contract['id']);
        }
        return RentalUI::modal('Подписанный акт · '.PremisesActs::TITLES[$kind],$body,
            RentalUI::button('К актам договора',self::route((int)$contract['id'])),'contracts-active');
    }

    public static function returnFields(PDO $db,array $contract): string
    {
        $objects=PremisesActs::subjects($db,(int)$contract['id']);
        $items=PremisesActs::returnInventory($db,$objects);
        $body=self::hidden('inventory_hash',PremisesActs::inventoryDigest($items));
        foreach ($items as $objectId=>$inventory) {
            $body.='<section class="registry-ui-form-section"><h3 class="registry-ui-modal-summary">'.View::e($objects[$objectId]['name']).'</h3>'
                .PremisesInventory::table($inventory)
                .'<label class="field"><span class="field-label">Дефекты при возврате</span><textarea class="field-input" name="return_details['.$objectId.'][defects]" maxlength="2000"></textarea></label>'
                .Controller::input('return_details['.$objectId.'][remedy_by]','Согласованный срок устранения','','date',false).'</section>';
        }
        return $body;
    }

    public static function hidden(string $key,mixed $value): string
    {
        return '<input type="hidden" name="'.View::e($key).'" value="'.View::e($value).'">';
    }

    private static function fileLink(string $fileId,string $label): string
    {
        return '<a class="btn btn-secondary" href="/rental/download?id='.View::e($fileId).'">'.View::e($label).'</a>';
    }
}
