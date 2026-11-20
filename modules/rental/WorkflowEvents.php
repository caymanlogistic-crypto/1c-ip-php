<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

/** Commit provenance on the existing event row, not a second workflow or inbox. */
final class WorkflowEvents
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';
    private const ACTIONS=['create','edit','submit','legal','return','forward','packet','handed','sign','apply'];

    public static function before(PDO $db,string $action,array $input): ?array
    {
        $parts=explode('.',$action);
        $party=count($parts)===2&&$parts[0]==='party'&&in_array($parts[1],['block','unblock'],true);
        $cancel=$action==='contract.cancel';
        $question=in_array($action,[HandoverQuestion::OPEN,HandoverQuestion::RETRY],true);
        if(!$party&&!$cancel&&!$question&&(count($parts)!==2||!in_array($parts[0],['contract','document'],true)||!in_array($parts[1],self::ACTIONS,true)))return null;
        $row=null;
        if($parts[1]!=='create')$row=Service::get($db,$party?'parties':($parts[0]==='contract'?'contracts':'rental_documents'),(int)($input['id']??0));
        return ['kind'=>$parts[0],'before'=>$row,'event_floor'=>(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn()];
    }

    /** Pure selector: an attempted/failed command, draft save or replay is not an event. */
    public static function transition(string $action,array $input,?array $before,array $after): ?array
    {
        $op=explode('.',$action)[1]??'';$from=$before['status']??null;$to=$after['status']??null;
        $kind=null;$target=null;
        if(in_array($action,['party.block','party.unblock'],true)){
            if((bool)($before['blocked']??false)===(bool)$after['blocked'])return null;
            $kind=$after['blocked']?'block':'unblock';
        }elseif($action==='contract.cancel'&&$from!=='cancelled'&&$to==='cancelled'){$kind='cancelled';}
        elseif($to==='legal'&&($op==='submit'||in_array($op,['create','edit'],true)&&($input['intent']??'')==='submit')){
            $kind=$from==='returned'&&($before['return_to']??null)==='manager'?'resubmit':'submit';$target='lawyer';
        }elseif($op==='legal'&&$to==='accounting'){$kind='legalApprove';$target='accountant';}
        elseif($op==='return'&&$to==='returned'){$kind=$from==='accounting'?'accountantReturn':'lawyerReturn';$target=$after['return_to']??null;}
        elseif($op==='forward'&&$to==='returned'&&($after['return_to']??null)==='manager'){$kind='lawyerReturn';$target='manager';}
        elseif($op==='packet'&&$to==='approved'&&!empty($input['packet_confirmed'])){$kind='package';$target='manager';}
        elseif($op==='handed'&&$from==='approved'&&$to==='sent'){$kind='handed';$target='manager';}
        elseif($op==='sign'&&$from==='sent'&&in_array($to,['active','signed'],true)){$kind='signed';$target='manager';}
        elseif($op==='apply'&&empty($before['applied'])&&!empty($after['applied'])){$kind='activated';$target='manager';}
        return $kind===null?null:['event_kind'=>$kind,'target_role'=>$target,'resubmitted'=>$kind==='resubmit'];
    }

    public static function record(PDO $db,array $actor,string $action,array $input,array $result,string $commandKey,?array $context): ?int
    {
        if($context===null)return null;
        if(!$db->inTransaction())throw new RuntimeException('Workflow event must share its command transaction.');
        $kind=$context['kind'];$party=$kind==='party';$id=(int)($result['id']??$context['before']['id']??0);
        $after=Service::get($db,$party?'parties':($kind==='contract'?'contracts':'rental_documents'),$id);
        $transition=self::transition($action,$input,$context['before'],$after);
        if (!empty($result['question_notification'])) {
            $transition=['event_kind'=>HandoverQuestion::EVENT,'target_role'=>'accountant','resubmitted'=>false];
        }
        if($transition===null)return null;
        $contractId=$party?null:($kind==='contract'?$id:(int)$after['contract_id']);
        $q=$db->prepare($party?'SELECT id,description,source_json FROM rental_events WHERE id>? AND actor_id=? AND action=? AND contract_id IS NULL ORDER BY id':'SELECT id,description FROM rental_events WHERE id>? AND actor_id=? AND action=? AND contract_id=? ORDER BY id');
        $q->execute($party?[$context['event_floor'],$actor['id'],$action]:[$context['event_floor'],$actor['id'],$action,$contractId]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($events)!==1)throw new RuntimeException('Workflow event must bind one actual command event.');
        $q=$db->prepare('SELECT fingerprint,result_json FROM rental_commands WHERE command_key=?');$q->execute([$commandKey]);$command=$q->fetch(PDO::FETCH_ASSOC);
        if(!$command)throw new RuntimeException('Workflow command receipt missing.');
        if($party){$contract=['number'=>'','party_name'=>$after['name']];}
        else{$q=$db->prepare('SELECT c.number,p.name party_name FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);}
        $epoch=$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        $body=['schema'=>1,'kind'=>'committed-rental-workflow-event','project_id'=>self::PROJECT,
            'event_id'=>(int)$events[0]['id'],'command_key'=>$commandKey,'action'=>$action,
            'command_fingerprint'=>$command['fingerprint'],'command_result_sha256'=>hash('sha256',$command['result_json']),
            'entity_kind'=>$kind,'entity_id'=>$id,'entity_version'=>(int)$after['version'],'contract_id'=>$contractId,
            'record_number'=>$party?'':(string)$after['number'],'contract_number'=>(string)$contract['number'],'party_name'=>(string)$contract['party_name'],
            'from_status'=>$party?(!empty($context['before']['blocked'])?'blocked':'unblocked'):($context['before']['status']??null),'to_status'=>$party?($after['blocked']?'blocked':'unblocked'):$after['status'],'return_to'=>$after['return_to']??null,
            'actor_id'=>(int)$actor['id'],'role_id'=>$actor['role_id']??$actor['role'],'actor_role'=>$actor['role'],
            'auth_version'=>(int)$actor['auth_version'],'actor_name'=>$actor['name'],'epoch'=>(string)$epoch,
            'text'=>(string)$events[0]['description'],'reason'=>(string)($party?($after['block_reason']??''):($after['reason']??''))]+$transition;
        if($party){
            $original=json_decode($events[0]['source_json']??'',true,32,JSON_THROW_ON_ERROR);
            if(($original['kind']??null)!=='party-block-projects'||($original['command_key']??null)!==$commandKey)throw new RuntimeException('Party event provenance missing.');
            $body['party_id']=$id;$body['extra']=$original['extra'];$body['source_commit']=$original['source_commit'];
        }
        $body['sha256']=hash('sha256',Importer::canonicalJson($body));
        $q=$db->prepare($party?'UPDATE rental_events SET source_json=? WHERE id=? AND source_json=CAST(? AS JSON)':'UPDATE rental_events SET source_json=? WHERE id=? AND source_json IS NULL');
        $q->execute($party?[Importer::json($body),$body['event_id'],$events[0]['source_json']]:[Importer::json($body),$body['event_id']]);
        if($q->rowCount()!==1)throw new RuntimeException('Workflow event provenance already differs.');
        return $body['event_id'];
    }

    public static function decode(array $row): ?array
    {
        if(!is_string($row['source_json']??null))return null;
        $body=json_decode($row['source_json'],true,32,JSON_THROW_ON_ERROR);
        if(($body['kind']??null)!=='committed-rental-workflow-event')return null;
        $sha=$body['sha256']??null;unset($body['sha256']);
        if(($body['schema']??null)!==1||($body['project_id']??null)!==self::PROJECT||($body['event_id']??null)!==(int)$row['id']
            ||!is_string($sha)||!hash_equals($sha,hash('sha256',Importer::canonicalJson($body))))throw new RuntimeException('Workflow event receipt changed.');
        return $body+['sha256'=>$sha,'created_at'=>$row['created_at']];
    }

    public static function latest(PDO $db,string $kind,int $id,bool $handoffOnly=false): ?array
    {
        if(!in_array($kind,['contract','document'],true))throw new RuntimeException('Invalid workflow entity.');
        $sql="SELECT id,source_json,created_at FROM rental_events WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.kind'))='committed-rental-workflow-event' AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.epoch'))=(SELECT epoch FROM rental_runtime_context WHERE id=1) AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.entity_kind'))=? AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.entity_id'))=?";
        if($handoffOnly)$sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.event_kind')) IN ('submit','resubmit')";
        else $sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.event_kind'))<>'handoverQuestion'";
        $q=$db->prepare($sql.' ORDER BY id DESC LIMIT 1');$q->execute([$kind,(string)$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?self::decode($row):null;
    }
}
