<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\LeaseRegistry;

$check=static function(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);};
foreach ([1=>'1 день',2=>'2 дня',5=>'5 дней',11=>'11 дней',12=>'12 дней',21=>'21 день',22=>'22 дня'] as $days=>$label) {
    $end=(new DateTimeImmutable('2026-11-18'))->modify('+'.$days.' days')->format('Y-m-d');
    $check(LeaseRegistry::expiryComment($end,'2026-11-18')===$label.' до окончания аренды','Remaining days: '.$days);
}
$check(LeaseRegistry::expiryComment('2026-11-18','2026-11-18')==='Аренда заканчивается сегодня','Today');
$check(LeaseRegistry::expiryComment('2026-11-06','2026-11-18')==='Аренда закончилась 12 дней назад','Past date');
$check(LeaseRegistry::expiryComment(null,'2026-11-18')==='','No end date');
$contract=['id'=>1,'status'=>'active','source_json'=>'{}','finish_date'=>null,'end_date'=>date('Y-m-d')];
$task=['entity'=>'contract','id'=>1,'role'=>'manager','severity'=>'yellow','text'=>'Сохранённый <комментарий>'];
foreach (['manager','admin','lawyer','accountant','director','viewer'] as $role) {
    $view=LeaseRegistry::projection($contract,$role,[$task],false,true);
    $html=LeaseRegistry::commentHtml($view);
    $check(str_contains($html,'<strong class="registry-ui-error">Аренда заканчивается сегодня</strong>'),'System notice for '.$role);
    if ($role==='manager') $check(str_contains($html,'Сохранённый &lt;комментарий&gt;'),'Existing comment preserved and escaped');
    $plain=LeaseRegistry::projection($contract,$role,[],false,false);
    $check(LeaseRegistry::commentHtml($plain)==='—','No notice outside ending selection');
}
$items=[];
for ($id=1;$id<=11;$id++) {
    $record=$contract;$record['id']=$id;
    $signal=$task;$signal['id']=$id;$signal['severity']=$id<=6?'red':'yellow';
    $duplicate=$signal;$duplicate['text']='Второе действие того же договора';
    $view=LeaseRegistry::projection($record,'admin',[$signal,$duplicate],false,true);
    $check($view['severity']===$signal['severity'],'Admin sees assigned signal');
    $manager=LeaseRegistry::projection($record,'manager',[$signal],false,true);
    $check($view['severity']===$manager['severity'],'Admin and assigned manager row color');
    $viewer=LeaseRegistry::projection($record,'viewer',[$signal],false,true);
    $check($viewer['severity']==='','No other-role signal for viewer');
    $items[]=['contract'=>$record,'view'=>$view];
}
$tabs=LeaseRegistry::tabs($items,true);
$check($tabs['all']===['Все',11,[6,5,0,0]],'All flag colors and deduplication');
$check($tabs['ending']===['Заканчиваются',11,[6,5,0,0]],'Ending flag colors');
$check($tabs['review']===['На завершении',0,[0,0,0,0]],'Review flag excludes active rows');
$items[0]['contract']['status']='reconciling';$items[0]['view']['ending']=false;
$tabs=LeaseRegistry::tabs($items,true);
$check($tabs['ending'][2]===[5,5,0,0] && $tabs['review'][2]===[1,0,0,0],'Each flag counts its selection');
echo "PASS: rental expiry labels, six roles, escaping, admin/manager colors, other-role isolation, flag counts/deduplication\n";
