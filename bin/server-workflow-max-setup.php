<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{Config,ServerObjectMax,ServerWorkflowMax,Support};
$question=$argv===[$argv[0],'--enable','--handover-questions'];
$staff=count($argv)===7 && $argv[1]==='--enable' && $argv[2]==='--staff-handoff';
$sequence=count($argv)===4 && $argv[1]==='--enable' && $argv[2]==='--staff-handoff-sequence';
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||(!$staff&&!$sequence&&!$question&&$argv!==[$argv[0],'--enable'])||getenv('APP_STAND')!=='ui')throw new RuntimeException('EXPLICIT_SERVER_OPT_IN_REQUIRED');
$p=App\DB::pdo();if($p->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui')throw new RuntimeException('WRONG_DATABASE');
$m=ServerObjectMax::settings();if(!$m)throw new RuntimeException('APPROVED_TEST_PROFILE_REQUIRED');
$secret=Config::secrets('max');$c=Config::get($p,'max');Config::assertSecretRevision($secret,$m['revision']);
if(!$c['enabled']||(int)$c['revision']!==$m['revision']||$secret['approved_chat_ids']!==[$m['chat_id']]
 ||!hash_equals($m['vault_sha256'],hash_file('sha256',APP_ROOT.'/private/integrations/max.enc')))throw new RuntimeException('PROFILE_CHANGED');
if ($staff || $sequence) {
    $entry=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/staff-handoff-max.json'),true,32,JSON_THROW_ON_ERROR);
    $q=$p->prepare("SELECT version,format,body_text,source_sha256 FROM integration_template_catalog WHERE channel='max' AND source_key='staffHandoff'");
    $q->execute();$row=$q->fetch();
    if (!$row || $row['format']!==$entry['format'] || $row['body_text']!==$entry['body']
        || $row['source_sha256']!==hash_file('sha256',APP_ROOT.'/modules/integrations/defaults/staff-handoff-max.json'))
        throw new RuntimeException('APPROVED_CATALOG_REQUIRED');
    $runtime=$p->query('SELECT epoch,(SELECT COALESCE(MAX(id),0) FROM rental_events) event_floor FROM rental_runtime_context WHERE id=1')->fetch();
    if (!$runtime) throw new RuntimeException('STAFF_HANDOFF_EPOCH_REQUIRED');
    $m['event_floor']=(int)$runtime['event_floor'];
    $m['catalog_version']=(int)$row['version'];
    if ($sequence) {
        $path=$argv[3];
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path)>4096)
            throw new RuntimeException('STAFF_HANDOFF_SEQUENCE_MANIFEST_REQUIRED');
        $approved=Support::decode(file_get_contents($path));
        if (!is_array($approved) || count($approved)!==3 || array_diff(['contracts','actions','expires_at'],array_keys($approved)))
            throw new RuntimeException('STAFF_HANDOFF_SEQUENCE_MANIFEST_INVALID');
        $m['scope']=ServerWorkflowMax::STAFF_SEQUENCE;
        $m+=['epoch'=>$runtime['epoch'],'valid_from'=>time()]+$approved;
        ServerWorkflowMax::validateStaff($m);
        $q=$p->prepare('SELECT number FROM contracts WHERE id=?');
        foreach ($m['contracts'] as $approvedContract) {
            $q->execute([$approvedContract['id']]);
            if ($q->fetchColumn()!==$approvedContract['number'])
                throw new RuntimeException('STAFF_HANDOFF_APPROVED_CONTRACT_CHANGED');
        }
    } else {
        [,,,$contract,$actor,$action,$command]=$argv;
        $m['scope']='staff-handoff';
        $m+=['contract_id'=>filter_var($contract,FILTER_VALIDATE_INT),'actor_id'=>filter_var($actor,FILTER_VALIDATE_INT),
            'action'=>$action,'command_key'=>$command];
        ServerWorkflowMax::validateStaff($m);
        $q=$p->prepare('SELECT 1 FROM rental_commands WHERE command_key=?');$q->execute([$command]);
        if ($q->fetchColumn()) throw new RuntimeException('STAFF_HANDOFF_COMMAND_MUST_BE_NEW');
    }
    $json=Support::json($m)."\n";
    if (strlen($json)>4096) throw new RuntimeException('STAFF_HANDOFF_ADMISSION_TOO_LARGE');
    echo $json;
    return;
}
$entries=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/contract-max-templates.json'),true,32,JSON_THROW_ON_ERROR);$versions=[];
foreach($entries as $key=>$entry){$q=$p->prepare("SELECT version,format,body_text FROM integration_template_catalog WHERE channel='max' AND source_key=?");$q->execute([$key]);$r=$q->fetch();
 if(!$r||$r['format']!==$entry['format']||$r['body_text']!==$entry['body'])throw new RuntimeException('APPROVED_CATALOG_REQUIRED');$versions[$key]=(int)$r['version'];}
$m['scope']='contract-workflow';$m['event_floor']=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();$m['catalog_versions']=$versions;
if ($question) {
    $entry=json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/handover-question-max.json'),true,32,JSON_THROW_ON_ERROR);
    $q=$p->prepare("SELECT version,format,body_text FROM integration_template_catalog WHERE channel='max' AND source_key=?");
    $q->execute([Modules\Rental\HandoverQuestion::EVENT]);$r=$q->fetch();
    if (!$r||$r['format']!==$entry['format']||$r['body_text']!==$entry['body']) throw new RuntimeException('APPROVED_CATALOG_REQUIRED');
    $m['handover_question']=['event_floor'=>$m['event_floor'],'catalog_version'=>(int)$r['version']];
}
ServerWorkflowMax::validate($m);echo json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
