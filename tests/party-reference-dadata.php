<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\DadataLookup;
use Modules\Rental\PartyReference;

function verify(bool $condition,string $failure): void
{
    if (!$condition) throw new RuntimeException($failure);
}

$company=['data'=>['type'=>'LEGAL','inn'=>'7719402047','kpp'=>'772301001','ogrn'=>'1157746078984',
    'name'=>['full_with_opf'=>'ООО «Моторика»'],'management'=>['name'=>'Руководитель']]];
$branch=$company;
$branch['data']['kpp']='540602001';
$branch['data']['name']['full_with_opf']='Учебный филиал';
$companies=DadataLookup::suggestions(['suggestions'=>[$company,$branch]]);
$ambiguous=PartyReference::project('inn','7719402047','',$companies);
verify($ambiguous['values']===[] && $ambiguous['field']==='kpp' && $ambiguous['partyType']==='company',
    'Ambiguous branches confirm the organization type and require KPP without proposing details');
verify(str_contains($ambiguous['error'],'несколько подразделений организации.'),'Same OGRN and different KPP identify subdivisions');
$different=$branch;
$different['data']['ogrn']='1157746078985';
$ambiguous=PartyReference::project('inn','7719402047','',[$company,$different]);
verify(!str_contains($ambiguous['error'],'подразделений'),'Different OGRNs must not be described as subdivisions of one organization');
$selected=PartyReference::project('inn','7719402047','540602001',$companies);
verify($selected['partyType']==='company' && $selected['values']===
    ['name'=>'Учебный филиал','kpp'=>'540602001','ogrn'=>'1157746078984'],'Confirmed branch, party type and signer allowlist');
verify(PartyReference::project('inn','7719402047','540602002',$companies)['values']===[],'Other branch must not substitute');

$ip=['data'=>['type'=>'INDIVIDUAL','inn'=>'784806113663','ogrn'=>'304780000000001',
    'name'=>['full'=>'Учебный предприниматель'],'address'=>['value'=>'г Санкт-Петербург']]];
$selected=PartyReference::project('inn','784806113663','',[$ip]);
$values=$selected['values'];
verify($selected['partyType']==='entrepreneur' && ($values['ogrnip'] ?? null)==='304780000000001'
    && !isset($values['address']),'Confirmed IP type and OGRNIP without a city-only address');
$previous=$ip;
$previous['data']['ogrn']='304780000000002';
$ambiguous=PartyReference::project('inn','784806113663','',[$ip,$previous]);
verify($ambiguous['values']===[] && $ambiguous['field']===null && !isset($ambiguous['partyType'])
    && str_contains($ambiguous['error'],'ОГРНИП'),'Ambiguous IP registrations must request OGRNIP without flagging KPP');

$bank=['data'=>['bic'=>'044525225','name'=>['payment'=>'ПАО СБЕРБАНК','short'=>'Короткое имя'],
    'correspondent_account'=>'30101810400000000225','account'=>'40702810900000000001']];
verify(PartyReference::project('bik','044525225','',[$bank])['values']===
    ['bank'=>'ПАО СБЕРБАНК','correspondent_account'=>'30101810400000000225'],'Payment name and correspondent account only');
verify(PartyReference::project('bik','044525224','',[$bank])['values']===[],'Other BIK must not substitute');
try {
    DadataLookup::suggestions(['suggestions'=>['not_a_list'=>[]]]);
    throw new LogicException('Malformed provider JSON must be rejected');
} catch (RuntimeException $failure) {
    verify($failure->getMessage()==='INVALID_DADATA_RESPONSE','Unexpected provider error');
}
echo "DaData party/bank response checks passed\n";
