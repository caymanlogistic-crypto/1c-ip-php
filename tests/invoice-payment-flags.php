<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Rental\{InvoiceFacts,InvoiceRegistry};
$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$base=['status'=>'active','control_closed'=>0,'amount'=>'100.00','paid'=>'0.00','due'=>date('Y-m-d'),'source_json'=>'{}','manual_review'=>0];
$cases=[
 ['today',[],true,false,false],
 ['future',['due'=>date('Y-m-d',strtotime('+1 day'))],true,false,false],
 ['missing due',['due'=>null],true,false,false],
 ['yesterday',['due'=>date('Y-m-d',strtotime('-1 day'))],false,true,false],
 ['partial overdue',['due'=>date('Y-m-d',strtotime('-1 day')),'paid'=>'99.99'],false,true,false],
 ['paid error',['paid'=>'100.00','manual_review'=>1],false,false,true],
 ['unknown',['amount'=>null],false,false,false],
 ['frozen',['status'=>'frozen','due'=>'2000-01-01'],false,false,false],
 ['revoked',['status'=>'revoked','due'=>'2000-01-01'],false,false,false],
 ['closed',['control_closed'=>1,'due'=>'2000-01-01'],false,false,false],
 ['overdue error',['manual_review'=>1,'due'=>'2000-01-01'],false,true,false],
];
foreach($cases as [$name,$changes,$waiting,$overdue,$paid])foreach(['','red','yellow','blue'] as $severity){
 $invoice=array_replace($base,$changes);$view=InvoiceFacts::view($invoice,$severity);
 $check([$view['waiting'],$view['overdue'],$view['paid']]===[$waiting,$overdue,$paid],$name.': payment flags independent of role severity');
 foreach(['waiting'=>$waiting,'overdue'=>$overdue,'paid'=>$paid] as $flag=>$expected)$check(InvoiceRegistry::selected(['_view'=>$view],$flag)===$expected,$name.': '.$flag);
}
// Verify actual renderer markup without introducing a second UI implementation.
$controls=new ReflectionMethod(InvoiceRegistry::class,'controls');
$rows=[];foreach($cases as [$name,$changes])$rows[]=['_view'=>InvoiceFacts::view(array_replace($base,$changes),'red')];
$html=$controls->invoke(null,$rows,'query','7','overdue',9,false);$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
$buttons=$xp->query('//button');$check($buttons->length===4,'Only four payment flags');
$labels=['Все','Ожидают оплаты','Просрочка оплаты','Оплаченные'];
foreach($buttons as $n=>$button){$check(str_starts_with(trim($button->textContent),$labels[$n]),'Label '.$n);$markers=$xp->query('.//*[@data-attention]',$button);$check($markers->length===($n===2?1:0),'Only overdue has a marker');if($n===2)$check($markers->item(0)->getAttribute('data-attention')==='red'&&$markers->item(0)->textContent==='3','Exact overdue red count');}
$check(str_contains($html,'name="party" value="7"')&&str_contains($html,'name="contract_id" value="9"')&&str_contains($html,'name="q" value="query"'),'Preserve search context');
echo "PASS payment boundaries, partial/unknown amounts, frozen/revoked/closed, paid errors, role severity independence, four flags and exact red marker\n";
