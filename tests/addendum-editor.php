<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\DB;
use Modules\Rental\{AddendumController,Service};
$p=DB::pdo();
if(PHP_SAPI!=='cli'||getenv('APP_CONTRACT_DRAFT_TEST')!=='1'||!preg_match('/^ipphp_task_[a-z0-9_]+$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Explicit isolated task DB required');
function verify(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}
$p->beginTransaction();
try {
 $u=$p->query("SELECT id,auth_version FROM users WHERE role='manager' AND active=1 ORDER BY id LIMIT 1")->fetch();$_SESSION=['user_id'=>$u['id'],'auth_version'=>$u['auth_version']];
 $from=date('Y-m-d');$until=date('Y-m-d',strtotime('+1 month'));
 $rooms=array_values(array_filter(Modules\Rental\Availability::items($from,$until),fn($room)=>$room['available']));verify((bool)$rooms,'No isolated fixture room');$oid=$rooms[0]['id'];
 $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();$cid=(int)$p->query('SELECT COALESCE(MAX(id),900000)+1 FROM contracts')->fetchColumn();$party=(int)$p->query('SELECT id FROM parties WHERE blocked=0 ORDER BY id LIMIT 1')->fetchColumn();
 $p->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,signed,source_json) VALUES(?,?,?,CURRENT_DATE,?,?,'123.45','active',1,'{}')")->execute([$cid,'LOCAL-BASE-'.bin2hex(random_bytes(6)),$party,$from,$until]);
 Modules\Rental\Occupancy::insert($p,'contract_objects',['contract_id'=>$cid,'object_id'=>$oid]);
 $p->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,source_json) VALUES(?,?,?,?,'123.45','{}')")->execute([$cid,$oid,$from,$until]);
 $before=Service::get($p,'contracts',$cid);$_GET=['contract_id'=>$cid];$html=AddendumController::editor();
 verify(str_contains($html,'К договору №'.$before['number']),'Missing base contract context');
 $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);$pairs=[];
 foreach($xpath->query('//form[@id="rental-addendum-form"]//input[@name][not(ancestor::template)]') as $input)$pairs[]=urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
 parse_str(implode('&',$pairs),$in);unset($in['_csrf']);$in['number']='LOCAL-'.bin2hex(random_bytes(6));$in['intent']='draft';
 $expected=[];foreach($in['objects'] as $oid)$expected[$oid]=[$in['term_start'][$oid],$in['term_end'][$oid],$in['term_amount'][$oid]];
 $result=Service::command('document.create',$in);$id=(int)$result['id'];
 foreach($p->query('SELECT object_id,start_date,end_date,amount FROM document_terms WHERE document_id='.$id) as $row)verify($expected[$row['object_id']]===[$row['start_date'],$row['end_date'],$row['amount']],'Changed original per-room terms');
 verify(Service::get($p,'contracts',$cid)===$before,'Draft changed base contract');
 $_GET=['id'=>$id];$reopened=AddendumController::editor();verify(str_contains($reopened,$in['number']),'Saved DS did not reopen');
 verify(str_contains($reopened,'data-rental-picker="room"'),'DS lost shared picker');
 echo "PASS: rendered DS payload saves/reopens with exact dates/money and unchanged base contract; transaction rolled back.\n";
} catch(Throwable $failure){fwrite(STDERR,$failure->getMessage().' at '.$failure->getLine().PHP_EOL);$failed=true;} finally {if($p->inTransaction())$p->rollBack();}
if(!empty($failed))exit(1);
