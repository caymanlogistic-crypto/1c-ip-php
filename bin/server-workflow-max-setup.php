<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{Config,ServerObjectMax,ServerWorkflowMax};
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||$argv!==[$argv[0],'--enable']||getenv('APP_STAND')!=='ui')throw new RuntimeException('EXPLICIT_SERVER_OPT_IN_REQUIRED');
$p=App\DB::pdo();if($p->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui')throw new RuntimeException('WRONG_DATABASE');
$m=ServerObjectMax::settings();if(!$m)throw new RuntimeException('APPROVED_TEST_PROFILE_REQUIRED');
$secret=Config::secrets('max');$c=Config::get($p,'max');Config::assertSecretRevision($secret,$m['revision']);
if(!$c['enabled']||(int)$c['revision']!==$m['revision']||$secret['approved_chat_ids']!==[$m['chat_id']]
 ||!hash_equals($m['vault_sha256'],hash_file('sha256',APP_ROOT.'/private/integrations/max.enc')))throw new RuntimeException('PROFILE_CHANGED');
$entries=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/contract-max-templates.json'),true,32,JSON_THROW_ON_ERROR);$versions=[];
foreach($entries as $key=>$entry){$q=$p->prepare("SELECT version,format,body_text FROM integration_template_catalog WHERE channel='max' AND source_key=?");$q->execute([$key]);$r=$q->fetch();
 if(!$r||$r['format']!==$entry['format']||$r['body_text']!==$entry['body'])throw new RuntimeException('APPROVED_CATALOG_REQUIRED');$versions[$key]=(int)$r['version'];}
$m['scope']='contract-workflow';$m['event_floor']=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();$m['catalog_versions']=$versions;
ServerWorkflowMax::validate($m);echo json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
