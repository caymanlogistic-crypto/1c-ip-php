<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{DB,Http,View};
use PDO;
use RuntimeException;

final class RentalServicesCard
{
    private static function route(string $key): string
    {
        return '/rental/service?id='.$key;
    }

    private static function formRoute(int $lease,string $kind,string $key=''): string
    {
        return '/rental/service-form?contract_id='.$lease.'&kind='.$kind.($key?'&service_id='.$key:'');
    }

    private static function leaseLink(array $lease): string
    {
        return RentalUI::button('Договор аренды № '.$lease['number'],'/rental/contract?id='.(int)$lease['id']);
    }

    public static function listing(): string
    {
        $id=(int)($_GET['contract_id']??0);
        $lease=$id?Access::contract($id):null;
        if (!$id) Access::pages(['contracts-active','contracts-completed','reconciliation']);
        $db=DB::pdo();
        $query=trim((string)($_GET['q']??''));
        $body=RentalUI::notice();
        if ($lease) $body.='<div class="demo-row">'.self::leaseLink($lease).'</div>';
        else $body.='<form class="registry-ui-modal-grid" method="get" action="/rental/services">'
            .Controller::input('q','Номер договора или ДС',$query,'text',false).'<div class="demo-row"><button class="btn btn-secondary">Найти</button></div></form>';
        $rows=[];
        foreach (RentalServices::agreements($db,$id?:null) as $key=>$agreement) {
            try {$base=$lease??Access::contract($agreement['lease_id']);} catch (RuntimeException $e) {
                if (in_array($e->getCode(),[403,404],true)) continue;
                throw $e;
            }
            $amendments=RentalServices::amendments($db,$key);
            $numbers=[$agreement['number'],$base['number'],...array_column($amendments,'number')];
            if ($query!=='' && !WorkRegistry::matches($query,$numbers)) continue;
            $now=$agreement['status']==='active'?CleaningTerms::resolve($agreement,$amendments,date('Y-m-d')):null;
            $docs=[];
            foreach ($amendments as $docKey=>$doc) {
                $docs[]=RentalUI::button($doc['number'].' · '.RentalServices::AMENDMENTS[$doc['kind']],self::route($docKey));
            }
            $documentLinks=RentalUI::button($agreement['number'],self::route($key));
            foreach ($docs as $link) $documentLinks.='<div class="demo-row">'.$link.'</div>';
            $rows[]=[$documentLinks,View::e($base['number']),
                $now?View::e(self::termDates($now)):'—',$now?View::e(self::money($now['monthly'])):'—',
                match($agreement['status']){
                    'draft'=>$agreement['terms']['rate']===null?'Тариф ждёт решения бухгалтера':'Проект',
                    'cancelled'=>'Отменённый проект',default=>$now?'Действует':'Вне срока',
                }];
        }
        $body.=$rows?Controller::table(['Договор клининга и ДС','Аренда','Действующий срок','В месяц','Состояние'],
            $rows,['text','code','date','money','text']):'<p class="registry-ui-secondary">Договоры клининга не найдены.</p>';
        if ($lease) {
            $subject=TemplateAssets::objectKind($db,$id);
            if ($lease['status']==='active' && RentalServices::canEdit($lease)) {
                $body.='<div class="demo-row">';
                if ($subject==='office') $body.=RentalUI::button('Создать договор клининга',self::formRoute($id,'cleaning'),true)
                    .RentalUI::button('Оформить пропуск',self::formRoute($id,'pass'));
                if ($subject==='parking') $body.=RentalUI::button('Оформить брелок',self::formRoute($id,'fob'));
                $body.=RentalUI::button('Оформить юридический адрес',self::formRoute($id,'address')).'</div>';
            }
            $charges=array_filter(RentalServices::charges($db,$id),static fn($item)=>$item['agreement_id']==='');
            if ($charges) $body.='<h3 class="registry-ui-modal-summary">Дополнительные услуги</h3>'.self::chargeTable($charges,$lease);
        }
        return RentalUI::modal('Клининг и допуслуги',$body,
            $lease?RentalUI::button('К договору аренды','/rental/contract?id='.$id):'','contracts-active',true);
    }

    private static function money(?string $value): string
    {
        return $value===null?'Требуется решение по тарифу':Documents::amountText($value).' ₽';
    }

    private static function termDates(array $terms): string
    {
        return Documents::dateText($terms['from']).' — '.Documents::dateText($terms['until']);
    }

    private static function terms(array $terms): string
    {
        return RentalUI::pairs([
            ['Срок',self::termDates($terms)],['Площадь',str_replace('.',',',rtrim(rtrim($terms['area'],'0'),'.')).' м²'],
            ['Ставка за м²',self::money($terms['rate'])],['В месяц с НДС',self::money($terms['monthly'])],
            ['Основание тарифа',$terms['rate_basis']!==''?$terms['rate_basis']:($terms['rate']===null?'Требуется решение сотрудника':'Приложение № 1 к договору клининга, форма D23')],
        ]);
    }

    public static function card(): string
    {
        $key=(string)($_GET['id']??'');
        $db=DB::pdo();
        $document=RentalServices::document($db,$key);
        $lease=Access::contract($document['lease_id']);
        $id=(int)$lease['id'];
        $root=$document['agreement_id']??$key;
        $body=RentalUI::notice().RentalUI::pairs([['Номер',$document['number']],['Дата документа',Documents::dateText($document['document_date'])],
            ['Состояние',match($document['status']){
                'draft'=>'Ожидает подтверждения','cancelled'=>'Проект отменён',default=>'Подписанные условия подтверждены',
            }]])
            .'<div class="demo-row">'.self::leaseLink($lease).'</div>';
        if ($root!==$key) {
            $base=RentalServices::agreement($db,$root);
            $body.=RentalUI::pairs([['Вид ДС',RentalServices::AMENDMENTS[$document['kind']]],['Дата действия',Documents::dateText($document['effective'])]])
                .'<div class="demo-row">'.RentalUI::button('К договору клининга № '.$base['number'],self::route($root)).'</div>';
        }
        if ($document['terms']) {
            if ($document['status']==='draft' && $document['terms']['rate']===null) {
                $body.=RentalUI::alert('Тариф ждёт решения бухгалтера',
                    'Площадь не входит в диапазоны D23. Укажите согласованную ставку и основание для этого договора после загрузки подписанного файла.','warning');
            }
            if ($root===$key && $document['status']==='active') $body.='<h3 class="registry-ui-modal-summary">Условия при подписании договора</h3>';
            $body.=self::terms($document['terms']);
        }
        if ($document['status']==='draft') $body.=self::draftActions($db,$lease,$key,$document);
        elseif ($document['status']==='active') $body.='<div class="demo-row">'.self::fileLink($document['file_id'],'Скачать подписанный документ').'</div>';
        else {
            $body.=RentalUI::pairs([['Причина отмены',$document['reason']]]);
            if ($file=RentalServices::pendingFile($db,$id,$key)) $body.='<div class="demo-row">'.self::fileLink($file['id'],'Скачать приложенный файл').'</div>';
        }
        if ($root===$key && $document['status']==='active') $body.=self::operations($db,$lease,$key,$document);
        return RentalUI::modal(($root===$key?'Договор клининга № ':'ДС по клинингу № ').$document['number'],$body,
            RentalUI::button('К реестру услуг','/rental/services?contract_id='.$id),'contracts-active',true);
    }

    private static function draftActions(PDO $db,array $lease,string $key,array $document): string
    {
        $id=(int)$lease['id'];$revision=(int)$lease['version'];
        $file=RentalServices::pendingFile($db,$id,$key);
        $hidden=PremisesActsCard::hidden('service_id',$key).PremisesActsCard::hidden('service_version',$document['version']);
        $body=$file?'<div class="demo-row">'.self::fileLink($file['id'],'Скачать проверяемый файл').'</div>':'';
        $maySign=RentalServices::mayAmend($lease,$document['kind']);
        if (RentalServices::canEdit($lease)) {
            if ($maySign) {
                $body.='<form class="registry-ui-modal-grid" method="post" action="/rental/upload" enctype="multipart/form-data">'
                    .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('id',$id).PremisesActsCard::hidden('version',$revision)
                    .PremisesActsCard::hidden('entity_type','contract').PremisesActsCard::hidden('kind',RentalServices::FILE).$hidden
                    .'<label class="field"><span class="field-label">Подписанный документ</span><input type="file" name="file" accept=".pdf,.doc,.docx" required></label>'
                    .'<div class="demo-row"><button class="btn btn-secondary">Загрузить файл</button></div></form>';
            }
            $body.=Controller::form(RentalServices::CANCEL,$id,$revision,$hidden.Controller::input('reason','Причина отмены проекта'),'Отменить проект');
        }
        if ($file && $maySign && RentalServices::canEdit($lease,true)) {
            $fields=$hidden.PremisesActsCard::hidden('file_id',$file['id']);
            if ($document['terms'] && $document['terms']['rate']===null) $fields.=self::manualRate(true);
            $fields.='<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required>'
                .'<span>Документ подписан обеими сторонами, условия сверены</span></label>';
            $body.=Controller::form(RentalServices::CONFIRM,$id,$revision,$fields,'Подтвердить подписанные условия');
        }
        return $body;
    }

    private static function operations(PDO $db,array $lease,string $key,array $agreement): string
    {
        $id=(int)$lease['id'];$history=RentalServices::amendments($db,$key);
        $body='';
        $current=CleaningTerms::resolve($agreement,$history,date('Y-m-d'));
        if ($current!==$agreement['terms']) {
            $body.='<h3 class="registry-ui-modal-summary">Условия на сегодня</h3>'
                .($current?self::terms($current):'<p class="registry-ui-secondary">Клининг вне действующего срока.</p>');
        }
        $body.='<h3 class="registry-ui-modal-summary">Дополнительные соглашения</h3>';
        foreach ($history as $docId=>$entry) {
            $body.='<div class="demo-row">'.RentalUI::button($entry['number'].' · '.RentalServices::AMENDMENTS[$entry['kind']].' · '.Documents::dateText($entry['effective']),self::route($docId)).'</div>';
        }
        if (RentalServices::canEdit($lease)) {
            $body.='<div class="demo-row">';
            if (!array_intersect(['draft'],array_column($history,'status'))) {
                $terminated=array_filter($history,static fn($entry)=>$entry['status']==='active' && $entry['kind']==='terminate');
                foreach (RentalServices::AMENDMENTS as $kind=>$label) {
                    if (!$terminated && RentalServices::mayAmend($lease,$kind)) $body.=RentalUI::button($label,self::formRoute($id,$kind,$key));
                }
            }
            if ($lease['status']==='active') $body.=RentalUI::button('Дополнительная уборка',self::formRoute($id,'extra',$key));
            $body.='</div>';
        }
        $charges=array_filter(RentalServices::charges($db,$id),static fn($item)=>$item['agreement_id']===$key);
        $body.='<h3 class="registry-ui-modal-summary">Регулярные и дополнительные услуги</h3>';
        if ($charges) $body.=self::chargeTable($charges);
        $period=(string)($_GET['period']??date('Y-m'));
        [$first,$last]=CleaningTerms::month($period);
        $closed=$charges[$key.':'.$period]??null;
        $planned=CleaningTerms::monthTerms($agreement,$history,$period);
        $ended=false;
        foreach ($history as $entry) {
            if ($entry['status']==='active' && $entry['kind']==='terminate' && $entry['effective']<=$first) $ended=true;
        }
        $body.='<form class="registry-ui-modal-grid" method="get" action="/rental/service">'.PremisesActsCard::hidden('id',$key)
            .Controller::input('period','Месяц расчёта',$period,'month').'<div class="demo-row"><button class="btn btn-secondary">Показать расчёт</button></div></form>';
        $body.=RentalUI::pairs([['Месяц',$period],
            ['Регулярная сумма с НДС',$ended && !$closed?'—':self::money($closed['amount']??$planned['monthly']??null)],
            ['Статус расчёта',$closed?'Месяц закрыт':($ended?'Клининг прекращён':($planned?'По подписанным условиям':'Требуется согласованный расчёт'))]]);
        if (!$closed && $planned && $last<date('Y-m-d') && RentalServices::canEdit($lease,true)) {
            $fields=PremisesActsCard::hidden('service_id',$key).PremisesActsCard::hidden('service_version',$agreement['version'])
                .PremisesActsCard::hidden('period',$period);
            $body.=Controller::form(RentalServices::BOOK,$id,(int)$lease['version'],$fields,'Закрыть месяц клининга');
        }
        return $body;
    }

    public static function form(): string
    {
        $id=(int)($_GET['contract_id']??0);
        $lease=Access::contract($id,2);
        if (!RentalServices::canEdit($lease)) throw new RuntimeException('Действие доступно менеджеру аренды.',403);
        $kind=(string)($_GET['kind']??'');
        $key=(string)($_GET['service_id']??'');
        $parent=$key!==''?RentalServices::agreement(DB::pdo(),$key):null;
        if ($parent && $parent['lease_id']!==$id) throw new RuntimeException('Договор клининга относится к другой аренде.',404);
        $body=self::leaseLink($lease);
        $common=$parent?PremisesActsCard::hidden('service_id',$key).PremisesActsCard::hidden('service_version',$parent['version']):'';
        if ($kind==='cleaning') {
            $title='Новый договор клининга';
            $action=RentalServices::CREATE;
            $fields=self::contractFields().Controller::input('from','Начало услуг','','date').Controller::input('until','Окончание услуг','','date')
                .Controller::input('area','Площадь по договору, м²').self::manualRate(false);
            $body.=self::bands();
        } elseif (isset(RentalServices::AMENDMENTS[$kind]) && $parent) {
            $title=RentalServices::AMENDMENTS[$kind].' клининга';
            $action=RentalServices::AMEND;
            $fields=$common.PremisesActsCard::hidden('amend_kind',$kind).self::contractFields()
                .Controller::input('effective',$kind==='terminate'?'Дата расторжения':'Дата начала новых условий','','date');
            if ($kind==='renew') $fields.=Controller::input('until','Новая дата окончания','','date');
            if ($kind==='tariff') $fields.=self::manualRate(false);
        } elseif (isset(RentalServices::ORDERS[$kind]) && ($kind!=='extra' || $parent)) {
            $title=RentalServices::ORDERS[$kind];
            $action=RentalServices::ORDER;
            $fields=$common.PremisesActsCard::hidden('order_kind',$kind)
                .Controller::input('order_date','Дата услуги',date('Y-m-d'),'date')
                .($kind==='extra'?Controller::input('quantity','Площадь дополнительной уборки, м²'):Controller::input('quantity','Количество','1','number'))
                .Controller::input('unit_price','Согласованная цена за единицу, ₽')
                .Controller::input('details','Что согласовано и на каком основании');
        } else throw new RuntimeException('Действие с услугой не найдено.',404);
        $body.=Controller::form($action,$id,(int)$lease['version'],$fields,'Сохранить');
        return RentalUI::modal($title,$body,'','contracts-active');
    }

    private static function contractFields(): string
    {
        return Controller::input('number','Номер','', 'text',false).Controller::input('document_date','Дата документа',date('Y-m-d'),'date');
    }

    private static function manualRate(bool $required): string
    {
        return Controller::input('manual_rate','Индивидуальная ставка за м² с НДС, ₽','','text',$required)
            .Controller::input('rate_basis','Основание согласования ставки','','text',$required);
    }

    private static function bands(): string
    {
        $rows=array_map(static fn($band)=>[$band[0].'–'.$band[1],View::e(self::money($band[2]))],CleaningTerms::BANDS);
        return Controller::table(['Площадь, м²','Ставка D23 за м²'],$rows,['text','money']);
    }

    private static function chargeTable(array $charges,?array $lease=null): string
    {
        $rows=[];
        foreach ($charges as $item) {
            $regular=$item['kind']==='regular';
            $volume=$regular?$item['area']:($item['quantity']??'');
            $square=$regular || $item['kind']==='extra';
            if ($square && str_contains((string)$volume,'.')) $volume=rtrim(rtrim((string)$volume,'0'),'.');
            $rows[]=[View::e($item['period']),View::e($regular?'Регулярный клининг':RentalServices::ORDERS[$item['kind']]),
                $regular?View::e(self::termDates($item)):View::e(Documents::dateText($item['date'])),
                View::e($volume.($square?' м²':'')),View::e(self::money($item['rate'])),
                View::e(self::money($item['amount'])),View::e($regular?'Закрытый месяц':$item['details']),
                ...($lease?[self::leaseLink($lease)]:[])];
        }
        return Controller::table(['Месяц','Услуга','Дата / период','Объём','Ставка','Сумма','Основание',...($lease?['Договор-основание']:[])],$rows,
            ['code','text','date','short','money','money','text',...($lease?['text']:[])]);
    }

    private static function fileLink(string $key,string $label): string
    {
        return '<a class="btn btn-secondary" href="/rental/download?id='.View::e($key).'">'.View::e($label).'</a>';
    }
}
