<?php
declare(strict_types=1);
namespace Modules\Rental;

/** Presentation values for the missing fields only; no persistence or command execution. */
final class WordGenerationDemo
{
    public static function values(array $inputs,array $plan): array
    {
        $today=date('Y-m-d');$start=$plan['record']['start_date']??$today;
        $ip=preg_match('/(?:^|[^\p{L}])ИП(?:$|[^\p{L}])|Индивидуальный предприниматель/ui',$plan['party']['name']??'')===1;
        $floor=trim((string)($plan['values']['floor']??''))?:'2';
        $samples=[
            'contract_document_date'=>$today,'act_date'=>$start,'handover_deadline'=>$start,
            'ownership_date'=>'2020-01-15','service_date'=>$today,'service_gross'=>'1050.00',
            'floor'=>'2','floor_words'=>['-3'=>'третьем подземном','-2'=>'втором подземном','-1'=>'первом подземном',
                '1'=>'первом','2'=>'втором','3'=>'третьем','4'=>'четвёртом','5'=>'пятом','6'=>'шестом','7'=>'седьмом'][$floor]??'указанном',
            'party_signer_title'=>$ip?'Индивидуальный предприниматель':'Генеральный директор',
            'party_signer_title_genitive'=>$ip?'индивидуального предпринимателя':'Генерального директора',
            'party_signer_genitive'=>$ip?'Соколова Алексея Андреевича (ДЕМО)':'Иванова Ивана Ивановича (ДЕМО)',
            'party_signer_short'=>$ip?'Соколов А.А. (ДЕМО)':'Иванов И.И. (ДЕМО)',
            'party_authority'=>$ip?'свидетельства о государственной регистрации (ДЕМО)':'Устава (ДЕМО)',
            'party_kpp'=>$ip?'не применяется':'000000000','party_ogrn'=>$ip?'000000000000000':'0000000000000',
            'party_inn'=>$ip?'000000000000':'0000000000','party_email'=>'demo@example.test',
            'party_address'=>'ДЕМО: г. Примерск, ул. Учебная, д. 1','party_postal_address'=>'ДЕМО: г. Примерск, ул. Учебная, д. 1',
            'party_bank_line'=>'00000000000000000000 в ДЕМО-БАНКЕ','party_correspondent'=>'00000000000000000000','party_bik'=>'000000000',
            'building_room_numbers'=>'ДЕМО: помещение I, комната 10','ownership_record'=>'ДЕМО-77-2020-001',
            'organization_registration_date'=>'15.01.2020','organization_signer_genitive'=>'Иванова Ивана Ивановича (ДЕМО)',
            'meter_readings'=>'ДЕМО: счётчик №0001, 125,50 кВт·ч','passes_count'=>'2','parking_passes_count'=>'1',
            'service_number'=>'ДЕМО-У-001','clause_reference'=>'2.1','addendum_date_words'=>WordGenerationData::dateWords($today),
        ];
        $result=[];
        foreach($inputs as $key=>$field)$result[$key]=$samples[$key]??match($field['type']) {
            'date'=>$today,'number'=>'1',default=>'ДЕМО: '.$field['label'],
        };
        return $result;
    }
}
