<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Rental\{Indicators,InvoiceFacts,InvoiceRegistry,InvoiceSignals,SourceCalendar};

$p=DB::pdo();
if(PHP_SAPI!=='cli'||!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('ISOLATED_TASK_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$base=['amount'=>'100.00','paid'=>'0.00','status'=>'active','control_closed'=>0,'due'=>'2026-11-13'];
foreach([0=>'',1=>'yellow',5=>'yellow',6=>'red',20=>'red'] as $days=>$severity){
    $row=array_replace($base,['due'=>(new DateTimeImmutable('2026-11-18'))->modify('-'.$days.' days')->format('Y-m-d')]);
    $check(InvoiceFacts::overdueDays($row,'2026-11-18')===$days,'Calendar day boundary '.$days);
    $check(InvoiceFacts::overdueSeverity($row,'2026-11-18')===$severity,'Severity boundary '.$days);
}
foreach([['paid'=>'100.00'],['paid'=>'101.00'],['amount'=>null],['paid'=>null],['due'=>null],['due'=>'2026-11-19'],['status'=>'frozen'],['status'=>'revoked'],['control_closed'=>1]] as $override){
    $check(InvoiceFacts::overdueSeverity(array_replace($base,$override),'2026-11-18')==='','Excluded debt: '.json_encode($override));
}
$check(InvoiceFacts::overdueSeverity(array_replace($base,['paid'=>'99.99']),'2026-11-18')==='yellow','One kopeck remains overdue');
$check(InvoiceFacts::overdueSeverity(array_replace($base,['due'=>'2026-10-30']),'2026-11-04')==='yellow','Weekend/holiday are calendar days');

$p->beginTransaction();
try{
    $actor=$p->query("SELECT id,auth_version FROM users WHERE role='accountant' AND active=1 ORDER BY id LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    $today=date('Y-m-d');$calendar=SourceCalendar::localTest();
    $cid=(int)$p->query('SELECT MAX(id)+1 FROM contracts')->fetchColumn();
    $party=(int)$p->query('SELECT id FROM parties ORDER BY id LIMIT 1')->fetchColumn();
    $p->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,signed,source_json) VALUES(?,?,?,CURRENT_DATE,CURRENT_DATE,?,'100.00','active',1,'{}')")
        ->execute([$cid,'LOCAL-OVERDUE-'.bin2hex(random_bytes(4)),$party,date('Y-m-d',strtotime('+1 year'))]);
    $next=(int)$p->query('SELECT MAX(id)+1 FROM invoices')->fetchColumn();$ids=[];
    foreach(['day1'=>[1,'0.00',0,0],'day5'=>[5,'0.00',1,0],'day6'=>[6,'0.00',0,0],'paidError'=>[6,'100.00',0,1]] as $key=>$fixture){
        [$days,$paid,$initial,$review]=$fixture;$id=$next++;$ids[$key]=$id;
        $due=(new DateTimeImmutable($today))->modify('-'.$days.' days')->format('Y-m-d');
        $p->prepare("INSERT INTO invoices(id,number,contract_id,kind,amount,paid,issued,due,status,initial_invoice,manual_review,source_json) VALUES(?,?,?,'Аренда','100.00',?,CURRENT_DATE,?,'active',?,?,'{}')")
            ->execute([$id,'LOCAL-OVERDUE-'.$key.'-'.$id,$cid,$paid,$due,$initial,$review]);
    }
    $before=InvoiceSignals::refresh($p,$today,'12:59',$calendar);
    $check($before['beforeControlTime']&&$before['plan']===[],'No calculation before 13:00');
    $result=InvoiceSignals::refresh($p,$today,'13:00',$calendar,true);
    $planned=array_column($result['plan'],'severity','invoice_id');
    foreach(['day1'=>'yellow','day5'=>'yellow','day6'=>'red'] as $key=>$severity)$check(($planned[$ids[$key]]??'')===$severity,'Planner '.$key);
    $check(!isset($planned[$ids['paidError']]),'Paid error must not create a debt signal');
    $again=InvoiceSignals::refresh($p,$today,'13:00',$calendar,true);
    $check($again['applied']===[],'No duplicate control events');
    foreach(['12:59','13:00'] as $time){
        $tasks=Indicators::tasks($today,$time,['invoices']);$tones=[];
        foreach($tasks as $task)if($task['entity']==='invoice'&&$task['role']==='accountant')$tones[$task['id']]=$task['severity'];
        foreach(['day1'=>'yellow','day5'=>'yellow','day6'=>'red','paidError'=>'red'] as $key=>$severity)$check(($tones[$ids[$key]]??'')===$severity,'Shared menu severity '.$time.' '.$key);
    }
    $_GET=['contract_id'=>$cid,'flag'=>'overdue'];$html=InvoiceRegistry::render(true);
    $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
    $check($xp->query('//form[contains(@class,"registry-ui-tabs")]//button')->length===2,'Only two grouped tabs');
    $check($xp->query('//button[@name="flag" and @value="all"]//span[@data-attention]')->length===0,'No error badge on All');
    $check($xp->query('//button[@name="flag" and @value="overdue"]//span[@data-attention="red" and text()="1"]')->length===1,'Only day6 contributes red, paid review excluded');
    $check($xp->query('//button[@name="flag" and @value="overdue"]//span[@data-attention="yellow" and text()="2"]')->length===1,'Day1 and day5 contribute yellow');
    $check($xp->query('//tr[@data-rental-url]')->length===3,'Only unpaid overdue invoices');
    $check($xp->query('//tr[@data-row-key="invoice:'.$ids['paidError'].'"]')->length===0,'Paid error excluded from Overdue');
    $_GET=['contract_id'=>$cid,'flag'=>'overdue','q'=>'LOCAL-OVERDUE-paidError'];
    $paidHtml=InvoiceRegistry::render(true);
    $check(str_contains($paidHtml,'По заданным условиям счета не найдены.'),'Search cannot add paid error to Overdue');
    $check(!str_contains($paidHtml,'data-attention='),'Paid error alone has no overdue markers');
    $invoices=$p->query('SELECT * FROM invoices')->fetchAll();
    $check(InvoiceFacts::menuCounts($p,true)===InvoiceFacts::overdueCounts($invoices),'Grouped menu uses the same graded debt projection');
    $_GET=['contract_id'=>$cid,'flag'=>'paid'];
    $check(str_contains(InvoiceRegistry::render(false),'LOCAL-OVERDUE-paidError'),'Flat register retains Paid filter');
    // Reuse only this isolated transactional actor; preserve the real role grants.
    $roles=$p->query('SELECT id FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $baseline=null;$tested=[];$denied=0;
    foreach ($roles as $role) {
        $p->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$actor['id']]);
        $user=App\Auth::user();
        foreach (['all','overdue'] as $flag) {
            $_GET=['contract_id'=>$cid,'flag'=>$flag];
            try { $rendered=InvoiceRegistry::render(true); }
            catch (RuntimeException $e) { if($e->getCode()!==403)throw $e;$denied++;continue; }
            $baseline[$flag]??=$rendered;
            $check($rendered===$baseline[$flag],'Identical grouped markup for role '.$role.' flag '.$flag);
            $tested[$user['role']]=true;
        }
        if(App\Menu::allowed('invoices',$user)&&App\Auth::can('rental.read')) {
            $menu=App\Menu::counts($user);
            $check($menu['pages']['invoices']===InvoiceFacts::menuCounts($p,true),'Same overdue menu for role '.$role);
        }
    }
    foreach(['admin','manager','accountant','lawyer','director'] as $role)$check(isset($tested[$role]),'Covered workflow role '.$role);
    $p->prepare('UPDATE users SET role=? WHERE id=?')->execute(['accountant',$actor['id']]);
    $p->exec("DELETE FROM role_permissions WHERE role_id='accountant' AND permission_key IN('rental.read','*')");
    try { InvoiceRegistry::render(true); throw new RuntimeException('Missing read permission must deny finance'); }
    catch(RuntimeException $e) { $check($e->getCode()===403,'Denied without rental.read'); $denied++; }
    echo 'PASS identical grouped register and overdue menu across '.implode(',',array_keys($tested)).'; denied cases: '.$denied."\n";
    echo "PASS: 0/1/5/6 days, calendar days, partial/unknown/paid/frozen/revoked/closed cases, 13:00 gate/cache, planner/menu parity, repeat events, two tabs, badge placement, paid error exclusion and search; fixtures rolled back.\n";
}finally{$p->rollBack();}
