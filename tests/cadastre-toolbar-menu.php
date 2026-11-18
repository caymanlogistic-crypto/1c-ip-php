<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{DB,Menu};
use Modules\Rental\CadastreDirectory;

$p=DB::pdo();
if (!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('OWN_ISOLATED_DATABASE_REQUIRED');
$check=static function(bool $ok,string $why):void {if (!$ok) throw new RuntimeException($why);};
$session=$_SESSION??[];$get=$_GET;
$p->beginTransaction();
try {
    foreach (['admin','director','manager','lawyer','accountant','viewer'] as $role) {
        foreach (Menu::GROUPS['Контроль'] as $key=>$label) {
            $check(Menu::visible($key,['role'=>$role])===($role==='admin'),'Control visibility '.$role.'/'.$key);
        }
    }
    $actors=$p->query("SELECT id,role,auth_version FROM users WHERE id IN (SELECT MIN(id) FROM users WHERE active=1 GROUP BY role) ORDER BY id")->fetchAll();
    $roles=[];
    foreach ($actors as $actor) {
        if (!in_array($actor['role'],['admin','director','manager','lawyer','accountant'],true)) continue;
        $_SESSION=['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];$_GET=[];
        $html=App\View::page('Проверка меню','');
        $check(str_contains($html,'id="menu-folder-4"')===($actor['role']==='admin'),'Control folder '.$actor['role']);
        $check(str_contains($html,'id="menu-administration"')===($actor['role']==='admin'),'Administration folder '.$actor['role']);
        $roles[]=$actor['role'];
    }
    $admin=$p->query("SELECT id,auth_version FROM users WHERE active=1 AND role='admin' LIMIT 1")->fetch();
    $_SESSION=['user_id'=>$admin['id'],'auth_version'=>$admin['auth_version']];
    foreach ([[],['type'=>'Офис','q'=>'-3'],['type'=>'Офис','q'=>'old','reset'=>'1']] as $params) {
        $_GET=$params;$html=CadastreDirectory::page();
        $doc=new DOMDocument();$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$x=new DOMXPath($doc);
        $form='//form[@data-rental-registry-filter]';
        $check($x->query($form)->length===1,'One filter form');
        $check($x->query($form.'//form')->length===0,'No nested form');
        $check($x->query($form.'//select[@name="type"]')->length===1,'Type in toolbar form');
        $search=$x->query($form.'//div[@class="registry-ui-search-group"]/input[contains(@class,"toolbar-search")]');
        $check($search->length===1,'Shared showcase search');
        $expected=isset($params['reset'])?'':($params['q']??'');
        $check($search->item(0)->getAttribute('value')===$expected,'Search/reset value');
        $check($x->query('//form[contains(@class,"registry-ui-tabs")]/input[@name="q"]')->item(0)->getAttribute('value')===$expected,'Tabs preserve effective search after reset');
        if (($params['type']??'')==='Офис'&&!isset($params['reset'])) {
            foreach ($x->query('//tr[@data-rental-url]') as $row) {
                $cells=$x->query('./td',$row);$check($cells->item(1)->textContent==='Офис','Type filter');
                $check(str_contains($row->textContent,'-3'),'Search filter');
            }
        }
    }
    // Navigation visibility does not replace the existing route authorization.
    $check(Menu::allowed('errors',['role'=>'director']),'Existing director route policy is preserved');
    $_SESSION=['user_id'=>$admin['id'],'auth_version'=>$admin['auth_version']];
    $p->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute(['admin']);
    try {CadastreDirectory::page();throw new RuntimeException('Expected permission rejection');}
    catch (RuntimeException $e) {$check($e->getCode()===403,'Cadastre read permission remains enforced');}
    echo 'PASS: admin/non-admin menu, toolbar/search/type/reset/tabs, denied read; roles='.implode(',',$roles).PHP_EOL;
} finally {$p->rollBack();$_SESSION=$session;$_GET=$get;}
