<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/modules/integrations/native/php/onec/ReadOnly.php';
use App\DB;
use Modules\Integrations\OnecPartySupplement;
use Modules\Rental\{PartySupplementMapping,Service,ZeroPoint,ZeroPointPartyEnrichment};
$p=DB::pdo();if($p->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_task_party_enrich'||!in_array('--apply-isolated',$argv,true))throw new RuntimeException('Own isolated database required');
$checks=0;$check=static function(bool $b,string $why)use(&$checks){if(!$b)throw new RuntimeException($why);$checks++;};
$deny=static function(callable $f,int $code)use($check){try{$f();}catch(RuntimeException $e){$check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected denial');};
$login=static function(string $role)use($p){$q=$p->prepare('SELECT id,auth_version FROM users WHERE role=? AND active=1 ORDER BY id LIMIT 1');$q->execute([$role]);$u=$q->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];};
$base=ZeroPoint::load();$oldPin=json_decode(file_get_contents(ZeroPoint::directory().'/installed.json'),true);$data=ZeroPoint::capture($p);
$source=$p->query('SELECT * FROM integration_native_sources WHERE enabled=1')->fetch();$conn=$p->query("SELECT * FROM integration_connections WHERE provider='onec'")->fetch();
$capture=['schema'=>1,'kind'=>'ONEC_PARTY_SUPPLEMENT','captured_at'=>date(DATE_ATOM),'project_id'=>ZeroPoint::PROJECT,'source_instance'=>$source['source_instance'],'source_revision'=>(int)$source['revision'],'profile_reference'=>$source['profile_ref'],'connection_revision'=>(int)$conn['revision'],'scope'=>[],
    'data'=>['parties'=>[],'contacts'=>[],'bank_accounts'=>[],'banks'=>[],'currencies'=>[],'kinds'=>[],'roles'=>[],'edo'=>'NOT_EXPOSED_IN_REVIEWED_COUNTERPARTY_METADATA'],'requests'=>[['method'=>'GET']]];
$d=&$capture['data'];$banks=$data['tables']['party_bank_accounts'];
foreach($data['tables']['parties'] as $party){
    $s=json_decode($party['source_json'],true);$guid=$s['source_party_guid'];$capture['scope'][(string)$party['id']]=$guid;
    $d['parties'][$guid]=['Ref_Key'=>$guid,'DataVersion'=>'fixture-v1','DeletionMark'=>false,'IsFolder'=>false,'Code'=>$s['source_business_fields']['Code'],'Description'=>$party['name'],'ИНН'=>$party['inn'],'КПП'=>$party['kpp'],'ОсновноеКонтактноеЛицо_Key'=>$s['source_primary_contact_guid']??'','ОсновнойБанковскийСчет_Key'=>$s['source_primary_bank_account_guid']??'','КонтактнаяИнформация'=>[]];
    foreach($banks as $b){$bs=json_decode($b['source_json'],true);if($bs['source_bank_account_guid']!==($s['source_primary_bank_account_guid']??''))continue;
        $d['bank_accounts'][$bs['source_bank_account_guid']]=['Ref_Key'=>$bs['source_bank_account_guid'],'DataVersion'=>'fixture-bank-v1','Owner'=>$guid,'Owner_Type'=>'StandardODATA.Catalog_Контрагенты','DeletionMark'=>false,'НомерСчета'=>$b['account'],'Банк_Key'=>$bs['bank_guid'],'Валютный'=>false,'ВалютаДенежныхСредств_Key'=>$bs['currency_guid'],'ДатаЗакрытия'=>'0001-01-01T00:00:00'];
        $d['banks'][$bs['bank_guid']]=['Ref_Key'=>$bs['bank_guid'],'DataVersion'=>'fixture-v1','DeletionMark'=>false,'Code'=>$b['bik'],'Description'=>$b['bank'],'КоррСчет'=>$b['correspondent_account']];$d['currencies'][$bs['currency_guid']]=['Ref_Key'=>$bs['currency_guid'],'DataVersion'=>'fixture-v1','DeletionMark'=>false,'Code'=>'643'];
    }
}
$first=array_values(array_filter($data['tables']['parties'],static function($r)use($capture){$s=json_decode($r['source_json'],true);return empty($r['email']) && !empty($s['source_primary_contact_guid']) && isset($capture['data']['bank_accounts'][$s['source_primary_bank_account_guid']??'']);}))[0];$raw=json_decode($first['source_json'],true);$guid=$raw['source_party_guid'];$contact=$raw['source_primary_contact_guid'];
$ci=[['Тип'=>'Телефон','НомерТелефона'=>'+79990000001','Представление'=>'+79990000001'],['Тип'=>'АдресЭлектроннойПочты','АдресЭП'=>'primary@example.invalid','Представление'=>'primary@example.invalid']];
$d['contacts'][$contact]=['Ref_Key'=>$contact,'DataVersion'=>'fixture-v1','DeletionMark'=>false,'ОбъектВладелец'=>$guid,'ОбъектВладелец_Type'=>'StandardODATA.Catalog_Контрагенты','Description'=>'Fixture primary','Должность'=>'Director','КонтактнаяИнформация'=>$ci];
$extra='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$d['contacts'][$extra]=['Ref_Key'=>$extra,'DataVersion'=>'fixture-v1','DeletionMark'=>false,'ОбъектВладелец'=>$guid,'ОбъектВладелец_Type'=>'StandardODATA.Catalog_Контрагенты','Description'=>'Fixture additional','Должность'=>'','КонтактнаяИнформация'=>[['Тип'=>'АдресЭлектроннойПочты','АдресЭП'=>'additional@example.invalid']]];
unset($d);
$mapped=PartySupplementMapping::correct($data,$capture,10000);$byId=array_column($mapped['data']['tables']['parties'],null,'id');
$check($byId[$first['id']]['email']==='primary@example.invalid','Primary email comes from linked primary contact');$check($byId[$first['id']]['account_email']==='additional@example.invalid','Additional email belongs to sole additional contact');
$check($byId[$first['id']]['contact']===$first['contact'],'Existing contact preserved');$check($byId[$first['id']]['document_recipient']===$first['document_recipient'] && $byId[$first['id']]['additional_document_recipient']===$first['additional_document_recipient'],'No recipient/send authority inferred');
$check($mapped['report']['banks_selected']>30,'Explicit current primary banks can be selected');$check($byId[$first['id']]['edo_id']==='','No fabricated EDO identity');
$dupe=$capture;$dupe['data']['contacts'][$extra]['Description']='Fixture additional, Director';$dupe['data']['contacts'][$extra]['Должность']='Director';
$formatted=PartySupplementMapping::correct($data,$dupe,10000);$formattedRows=array_column($formatted['data']['tables']['parties'],null,'id');$check($formattedRows[$first['id']]['additional_contact']==='Fixture additional, Director','Source description containing the role is not duplicated');
$owned=$data;$index=array_search($first['id'],array_column($owned['tables']['parties'],'id'),true);$owned['tables']['parties'][$index]['additional_contact']='Fixture additional, Director, Director';$os=json_decode($owned['tables']['parties'][$index]['source_json'],true);$os['one_c_enrichment']=['capture_sha256'=>ZeroPoint::digest($dupe),'filled_fields'=>['additional_contact']];$owned['tables']['parties'][$index]['source_json']=ZeroPoint::json($os);
$fixedRows=array_column(PartySupplementMapping::correct($owned,$dupe,10000)['data']['tables']['parties'],null,'id');$check($fixedRows[$first['id']]['additional_contact']==='Fixture additional, Director','Only source-owned legacy duplicate is corrected');
$owned['tables']['parties'][$index]['additional_contact']='Manual contact choice';$fixedRows=array_column(PartySupplementMapping::correct($owned,$dupe,10000)['data']['tables']['parties'],null,'id');$check($fixedRows[$first['id']]['additional_contact']==='Manual contact choice','Manual additional contact is preserved even with old provenance');
$wrong=$capture;$wrong['data']['contacts'][$contact]['ОбъектВладелец_Type']='StandardODATA.Catalog_Организации';$deny(static fn()=>PartySupplementMapping::correct($data,$wrong,10000),409);
$closed=$capture;foreach($closed['data']['bank_accounts'] as &$b)$b['ДатаЗакрытия']='2020-01-01T00:00:00';unset($b);$check(PartySupplementMapping::correct($data,$closed,10000)['report']['banks_selected']===0,'Closed accounts are not activated');
$preserve=$data;$preserve['tables']['parties'][0]['email']='manual@example.invalid';$check(PartySupplementMapping::correct($preserve,$capture,10000)['data']['tables']['parties'][0]['email']==='manual@example.invalid','Manual populated field preserved');
// Exercise real Reader query builder and transport guards using no-network fixtures.
$small=$capture['data'];$small['parties']=[$guid=>$small['parties'][$guid]];$bankKey=$small['parties'][$guid]['ОсновнойБанковскийСчет_Key'];$small['bank_accounts']=[$bankKey=>$small['bank_accounts'][$bankKey]];
$fixture=static function($method,$resource,$query,$headers,$seconds,$cap)use($small,$check){
    $check($method==='GET','Supplement is GET only');$check(!str_contains($query['$select']??'','ДатаРождения'),'Birthdays excluded');
    $key=match($resource){'Catalog_Контрагенты'=>'parties','Catalog_КонтактныеЛица'=>'contacts','Catalog_БанковскиеСчета'=>'bank_accounts','Catalog_Банки'=>'banks','Catalog_Валюты'=>'currencies','Catalog_ВидыКонтактнойИнформации'=>'kinds','Catalog_РолиКонтактныхЛиц'=>'roles',default=>throw new RuntimeException('Unexpected route')};
    $rows=array_values($small[$key]);if(in_array($key,['banks','currencies','contacts'],true))$rows=array_values(array_filter($rows,static fn($r)=>str_contains($query['$filter'],$r['Ref_Key'])));
    return ['status'=>200,'body'=>json_encode(['value'=>$rows])];
};
$reader=new NativeOnec\Reader('fixture','not-a-secret',null,$fixture);$actual=$reader->partySupplement([$guid],[$extra]);$check(count($actual['parties'])===1,'Bounded party fetched');$check(count($actual['contacts'])===2,'Primary and observed additional contact fetched by exact ID');$check(count($reader->requests)<=12,'Request budget retained');
try{(new NativeOnec\Reader('fixture','not-a-secret',null,$fixture))->partySupplement(["x' or true"]);throw new LogicException('Injection accepted');}catch(RuntimeException $e){$check($e->getMessage()==='READ_KEY_INVALID','Query injection denied before network');}
$sha=ZeroPoint::digest($capture);ZeroPoint::write(OnecPartySupplement::directory().'/'.$sha.'.json',$capture);
$login('manager');$deny(static fn()=>ZeroPointPartyEnrichment::prepare(['capture_sha256'=>$sha]),403);$login('admin');
$plan=ZeroPointPartyEnrichment::prepare(['capture_sha256'=>$sha]);$tamper=$plan;$tamper['document']['data']['tables']['payments'][0]['amount']='1.00';$tamper['next_sha256']=ZeroPoint::digest($tamper['document']);$deny(static fn()=>ZeroPointPartyEnrichment::apply($tamper),409);
$hash=static function(bool $versions=true)use($p){$out=[];foreach(['contracts','contract_objects','contract_terms','invoices','invoice_objects','invoice_coverage','payments','payment_allocations','reservations','rental_objects','users','roles','integration_connections','integration_native_intents','messaging_attempts','messaging_receipts','rental_delivery_intents','rental_files'] as $t){$rows=$p->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);if(!$versions)foreach($rows as &$r)unset($r['version']);unset($r);$h=array_map(ZeroPoint::digest(...),$rows);sort($h);$out[$t]=ZeroPoint::digest($h);}return $out;};
$before=$hash();$beforeRestore=$hash(false);$result=ZeroPointPartyEnrichment::apply($plan);$check($before===$hash(),'Business money, occupancy, files and outgoing receipts unchanged');$check(ZeroPoint::json($result)===ZeroPoint::json(ZeroPointPartyEnrichment::apply($plan)),'Replay returns exact receipt');
$restore=ZeroPoint::preview();ZeroPoint::restore($restore+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
$r=$p->prepare('SELECT email FROM parties WHERE id=?');$r->execute([$first['id']]);$check($r->fetchColumn()==='primary@example.invalid','Enrichment survives actual point restore');
$check($p->query('SELECT COUNT(*) FROM party_bank_accounts WHERE operational=1')->fetchColumn()>30,'Selected bank accounts survive restore');
// A full restore intentionally removes later work. Compare only unchanged financial/system facts here.
$afterRestore=$hash(false);foreach(['users','roles','integration_connections','integration_native_intents','messaging_attempts','messaging_receipts','rental_delivery_intents','rental_files','invoices','invoice_objects','invoice_coverage','payments','payment_allocations'] as $t)$check($beforeRestore[$t]===$afterRestore[$t],'Restore protected '.$t);
ZeroPoint::assertMoney($p);
// Restore the original baseline for review and any later real-source test, never leaving fixture contacts in use.
$tmp=ZeroPoint::directory().'/test-original-pin.json';ZeroPoint::write($tmp,$oldPin);rename($tmp,ZeroPoint::directory().'/installed.json');
$reset=ZeroPoint::preview();ZeroPoint::restore($reset+['command_key'=>bin2hex(random_bytes(16)),'confirmation'=>'ВОССТАНОВИТЬ']);
echo json_encode(['passed'=>$checks,'fixture_contacts_removed'=>true,'external_calls'=>0]).PHP_EOL;
