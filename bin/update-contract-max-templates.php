<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI_ONLY');
$options=getopt('',['database:','apply','install-handover-question','install-staff-handoff','install-reservation-notices']);$p=App\DB::pdo();$database=$p->query('SELECT DATABASE()')->fetchColumn();
if(($options['database']??null)!==$database||($database!=='ipphp_ui'&&!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$database)))throw new RuntimeException('EXPLICIT_DATABASE_REQUIRED');
$entries=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/contract-max-templates.json'),true,32,JSON_THROW_ON_ERROR);
if((int)$p->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1)throw new RuntimeException('MAX_BUSY');
try{
 $catalog=new Modules\Integrations\TemplateCatalog($p,Modules\Integrations\Config::store());$out=[];
 $setters=array_intersect(['install-staff-handoff','install-handover-question','install-reservation-notices'],array_keys($options));
 if (count($setters)>1) throw new RuntimeException('SELECT_ONE_TEMPLATE_SOURCE');
 if (array_key_exists('install-reservation-notices',$options)) {
     $apply=array_key_exists('apply',$options);
     echo json_encode(['applied'=>$apply,'templates'=>$catalog->installReservationNotices($apply)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
     return;
 }
 if (array_key_exists('install-staff-handoff',$options)) {
     $apply=array_key_exists('apply',$options);
     if ($apply) $catalog->installStaffHandoff();
     echo json_encode(['applied'=>$apply,'template'=>'staffHandoff'],JSON_THROW_ON_ERROR),"\n";
     return;
 }
 if (array_key_exists('install-handover-question',$options)) {
     $apply=array_key_exists('apply',$options);
     if ($apply) $catalog->installHandoverQuestion();
     echo json_encode(['applied'=>$apply,'template'=>Modules\Rental\HandoverQuestion::EVENT],JSON_THROW_ON_ERROR),"\n";
     return;
 }
 foreach($entries as $key=>$entry){
  $q=$p->prepare("SELECT version,format,body_text FROM integration_template_catalog WHERE channel='max' AND source_key=?");$q->execute([$key]);$old=$q->fetch();if(!$old)throw new RuntimeException('TEMPLATE_MISSING_'.$key);
  $changed=$old['format']!==$entry['format']||$old['body_text']!==$entry['body'];
  $version=(int)$old['version'];
  if($changed&&array_key_exists('apply',$options))$version=$catalog->save('max',$key,$version,$entry,'По поручению владельца: шаблоны без призывов к действию')['version'];
  $out[$key]=['changed'=>$changed,'version'=>$version];
 }
 echo json_encode(['applied'=>array_key_exists('apply',$options),'templates'=>$out],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
}finally{$p->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
