<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,BillableTerms,PagePolicy};
use PDO;
use RuntimeException;

final class WordGenerationData
{
    public static function dateWords(string $date): string
    {
        Service::date($date);[$year,$month,$day]=explode('-',$date);
        return '«'.$day.'» '.['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня',
            '07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'][$month].' '.$year.' года';
    }

    public static function load(PDO $db,string $entity,int $id,int $version): array
    {
        Auth::requirePermission('rental.read');Auth::requirePermission('rental.approve.legal');Service::roles(['lawyer']);
        $record=match($entity){'contract'=>Access::contract($id,2),'document'=>Access::document($id,2),default=>throw new RuntimeException('Неизвестный документ.',403)};
        PagePolicy::requireCommand($entity==='contract'?'contract.legal':'document.legal');Service::version($record,['version'=>$version]);
        if (!($record['status']==='legal'||$record['status']==='returned'&&$record['return_to']==='lawyer'))throw new RuntimeException('Формирование не назначено юристу.',409);
        $contract=$entity==='contract'?$record:Service::get($db,'contracts',(int)$record['contract_id']);
        if ($entity==='document'&&!hash_equals($record['base_hash'],Evidence::documentBase($db,(int)$contract['id'])))throw new RuntimeException('Исходные условия договора изменились.',409);
        $q=$db->prepare('SELECT * FROM parties WHERE id=?');$q->execute([$contract['party_id']]);$party=$q->fetch()?:throw new RuntimeException('Контрагент не найден.');
        $org=$db->query('SELECT * FROM organization WHERE id=1')->fetch();
        if ($org['vat']===null)throw new RuntimeException('Укажите НДС нашей организации в реквизитах перед формированием Word.',422);
        if ($record['amount']===null||bccomp($record['amount'],'0',2)<=0)throw new RuntimeException('Нужна подтверждённая сумма договора.',422);
        $terms=$entity==='contract'?BillableTerms::forContract($db,$id):BillableTerms::forDocument($db,$id);
        if (!$terms)throw new RuntimeException('Нужен подтверждённый состав помещений.',422);
        if (array_filter($terms,static fn($t)=>$t['target_kind']!=='physical-object'))throw new RuntimeException('Эти исходники предназначены для физических помещений и машино-мест. Для иного предмета нужен соответствующий шаблон.',422);
        $q=$db->prepare('SELECT * FROM party_bank_accounts WHERE party_id=? AND operational=1 ORDER BY id LIMIT 1');$q->execute([$party['id']]);$bank=$q->fetch()?:[];
        $area='0.0000';$areaKnown=true;$numbers=[];$floors=[];$cadastres=[];
        foreach ($terms as $term) {
            if ($term['amount']===null||bccomp($term['amount'],'0',2)<=0)throw new RuntimeException('Нужна подтверждённая цена каждого помещения.',422);
            $numbers[]=$term['name'];if ($term['area']===null)$areaKnown=false;else $area=bcadd($area,$term['area'],4);
            if ($term['floor']!==null&&trim($term['floor'])!=='')$floors[]=$term['floor'];
            $q=$db->prepare('SELECT c.number FROM rental_objects o JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id=?');$q->execute([$term['object_id']]);$number=$q->fetchColumn();if($number)$cadastres[]=$number;
        }
        $values=['number'=>(string)$contract['number'],'date'=>$contract['document_date']?Documents::dateText($contract['document_date']):'',
            'date_words'=>$contract['document_date']?self::dateWords($contract['document_date']):'',
            'end_date_words'=>$record['end_date']?self::dateWords($record['end_date']):'',
            'party_full_name'=>$party['name'],'party_short_name'=>$party['name'],'party_address'=>$party['address']??'',
            'party_postal_address'=>$party['postal_address']??'', 'party_email'=>$party['email']??'',
            'party_inn'=>$party['inn']??'','party_kpp'=>$party['kpp']??'','party_ogrn'=>$party['ogrn']??'',
            'party_bank_line'=>!empty($bank['account'])&&!empty($bank['bank'])?$bank['account'].' в '.$bank['bank']:'',
            'party_correspondent'=>$bank['correspondent_account']??'','party_bik'=>$bank['bik']??'',
            'area'=>$areaKnown?rtrim(rtrim($area,'0'),'.'):'','room_numbers'=>implode(', ',array_unique($numbers)),
            'floor'=>implode(', ',array_unique($floors)),'cadastre_numbers'=>implode(', ',array_unique($cadastres)),
            'vat'=>rtrim(rtrim((string)$org['vat'],'0'),'.'),
            'service_start_words'=>$record['start_date']?self::dateWords($record['start_date']):'',
            'service_end_words'=>$record['end_date']?self::dateWords($record['end_date']):'',
            'addendum_date_words'=>$entity==='document'&&$record['document_date']?self::dateWords($record['document_date']):''];
        if (!empty($party['postal_same_as_legal'])&&$values['party_address']!=='')$values['party_postal_address']=$values['party_address'];
        $values+=self::moneyFields($record['amount'],(string)$org['vat'],'rent');
        // These are the supplied landlord's templates; never silently put a different landlord behind their fixed clauses.
        if (preg_replace('/\D/','',(string)$org['inn'])!=='390613939405')throw new RuntimeException('Исходники относятся к ИП Яковлеву. Сначала согласуйте реквизиты Арендодателя в шаблонах.',422);
        return compact('entity','record','contract','party','org','terms','values');
    }

    public static function moneyFields(string $gross,string $vat,string $prefix): array
    {
        $gross=Service::money($gross);if(bccomp($gross,'0',2)<=0)throw new RuntimeException('Сумма должна быть положительной.',422);
        $denominator=bcadd('1',bcdiv($vat,'100',8),8);
        $net=Billing::round(bcdiv($gross,$denominator,8));$tax=bcsub($gross,$net,2);[$rub,$kop]=explode('.',$net);
        $formatter=new \NumberFormatter('ru_RU',\NumberFormatter::SPELLOUT);$words=$formatter->format((int)$rub);
        if ($words===false)throw new RuntimeException('Не удалось представить сумму прописью.');
        return [$prefix.'_net'=>$rub,$prefix.'_net_kopecks'=>$kop,$prefix.'_net_words'=>$words,$prefix.'_vat'=>Documents::amountText($tax)];
    }

    public static function complete(array $values,array $input): array
    {
        $allowed=[];foreach(OriginalDocuments::catalog() as $definition)foreach($definition['fields'] as $field)$allowed[$field]=true;
        foreach(['act_date','handover_deadline','ownership_date','service_date','service_gross'] as $field)$allowed[$field]=true;
        foreach ($input as $key=>$value) {
            if (!isset($allowed[$key])||!is_string($value)||mb_strlen($value)>4000||str_contains($value,"\0"))throw new RuntimeException('Некорректные данные генерации.',422);
            // Current recorded facts and computed money are server-owned.
            if (!isset($values[$key])||trim($values[$key])==='')$values[$key]=trim($value);
        }
        foreach(['act_date'=>'act_date_words','handover_deadline'=>'handover_deadline_words','ownership_date'=>'ownership_date_words','service_date'=>'service_date_words'] as $key=>$target)
            if (!empty($values[$key]))$values[$target]=self::dateWords($values[$key]);
        foreach(['passes_count','parking_passes_count'] as $key)if(isset($values[$key])&&!preg_match('/^[0-9]{1,6}$/D',$values[$key]))throw new RuntimeException('Количество пропусков должно быть целым неотрицательным числом.',422);
        if (!empty($values['service_gross'])) {
            $values+=self::moneyFields($values['service_gross'],$values['vat'],'service');
            $gross=Service::money($values['service_gross']);[$rub,$kop]=explode('.',$gross);
            $words=(new \NumberFormatter('ru_RU',\NumberFormatter::SPELLOUT))->format((int)$rub);
            $values['service_gross_words']=Documents::amountText($gross).' ('.$words.' рублей '.$kop.' копеек)';
        }
        if (empty($values['party_requisites'])) {
            $parts=[];
            foreach(['party_address'=>'Юридический адрес','party_postal_address'=>'Почтовый адрес','party_inn'=>'ИНН','party_kpp'=>'КПП','party_ogrn'=>'ОГРН / ОГРНИП','party_bank_line'=>'Р/с','party_correspondent'=>'К/с','party_bik'=>'БИК'] as $key=>$label)
                if(!empty($values[$key]))$parts[]=$label.': '.$values[$key];
            if(!empty($values['party_inn'])&&!empty($values['party_bank_line'])&&!empty($values['party_bik']))$values['party_requisites']=implode("\n",$parts);
        }
        return $values;
    }
}
