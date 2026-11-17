<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{OnecOperations,OnecNormalConfirmationRequired,Config};
$db=App\DB::pdo();
if(!str_starts_with($db->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_'))throw new RuntimeException('ISOLATED_TASK_DB_REQUIRED');
if(Config::liveAllowed()||getenv('IPPHP_ALLOW_ONEC_WRITE'))throw new RuntimeException('NO_EXTERNAL_ACCESS_IN_TEST');
function verify(bool $condition,string $name):void{if(!$condition)throw new RuntimeException($name);}
function denied(callable $call,int $code):void{try{$call();throw new LogicException('UNEXPECTED_SUCCESS');}catch(RuntimeException $e){verify($e->getCode()===$code,'EXPECTED_DENIAL_'.$code.'_'.$e->getMessage());}}
$service=new OnecOperations($db,Config::store());
$admin=(int)$db->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
$viewer=(int)$db->query("SELECT u.id FROM users u LEFT JOIN role_profiles p ON p.role_id=u.role WHERE u.active=1 AND u.role<>'admin' AND COALESCE(p.kind,'viewer') NOT IN ('accountant','admin') LIMIT 1")->fetchColumn();
$contract=$db->query("SELECT c.id,c.party_id FROM contracts c LEFT JOIN rental_packet_confirmations p ON p.record_kind='contract' AND p.record_id=c.id WHERE p.id IS NULL AND c.status='approved' ORDER BY c.id LIMIT 1")->fetch();
verify($admin>0&&$viewer>0&&$contract!==false,'TEST_SOURCE_REQUIRED');
$identity=['record_kind'=>'contract','record_id'=>(string)$contract['id'],'subject_id'=>'party:'.$contract['party_id']];
$before=[];foreach(['integration_native_prepared','integration_native_intents','integration_native_receipts']as$t)$before[$t]=$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();
$result=$service->execute('onec-counterparty','preview',$admin,$identity);
verify($result['state']==='awaiting_confirmation'&&$result['written']===false,'UNCONFIRMED_PREVIEW');
for($i=0;$i<2;$i++){
    try{$service->execute('onec-counterparty','prepare',$admin,$identity);throw new LogicException('UNCONFIRMED_PREPARE_ACCEPTED');}
    catch(OnecNormalConfirmationRequired $e){verify(in_array('confirmation.normal_accountant_packet',$e->fields,true),'CONFIRMATION_GUARD');}
}
denied(fn()=>$service->execute('onec-invoice','preview',$admin,$identity),400);
denied(fn()=>$service->execute('onec-counterparty','preview',$admin,$identity+['native_body'=>'{}']),400);
denied(fn()=>$service->execute('onec-counterparty','prepare',$viewer,$identity),403);
denied(fn()=>$service->execute('onec-invoice','dispatch',$admin,['intent_id'=>'11111111-1111-4111-8111-111111111111']),403);
foreach(['check','generate']as$action){$r=$service->execute('onec-print',$action,$admin,[]);verify($r['state']==='awaiting_template'&&!$r['written'],'PRINT_REQUIRES_REAL_SAMPLE');}
// Configured page read access must never authorize preparation, even with finance.write.
$accountant=$db->query("SELECT u.id,u.role FROM users u JOIN role_profiles p ON p.role_id=u.role WHERE p.kind='accountant' AND u.active=1 LIMIT 1")->fetch();
verify((bool)$accountant,'ACCOUNTANT_REQUIRED');
$db->beginTransaction();
try{
    $db->prepare('UPDATE roles SET grants_configured=1 WHERE id=?')->execute([$accountant['role']]);
    $db->prepare("INSERT IGNORE INTO role_permissions(role_id,permission_key) VALUES(?,'integrations.read')")->execute([$accountant['role']]);
    $db->prepare("INSERT INTO role_page_grants(role_id,page_key,level) VALUES(?,'connections.onec-invoice',1) ON DUPLICATE KEY UPDATE level=1")->execute([$accountant['role']]);
    $service->authorize('onec-invoice',(int)$accountant['id'],'read');
    denied(fn()=>$service->authorize('onec-invoice',(int)$accountant['id'],'prepare'),403);
}finally{$db->rollBack();}
foreach($before as$t=>$count)verify($db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn()===$count,'NO_JOURNAL_SIDE_EFFECT_'.$t);
echo "OneC operations: real preview, confirmation/repeat guards, kinds, input, roles/page grants, print template and no journal side effects PASS\n";
