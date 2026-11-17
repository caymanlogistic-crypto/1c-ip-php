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
echo "PASS: remaining days, Russian plurals, today/past/missing date, six roles, existing comment and escaping\n";
