<?php
declare(strict_types=1);
namespace Modules\Integrations;

use Modules\Rental\WorkflowEvents;
use PDO;
use RuntimeException;

/** Official history observations are separate from the historical transport receipts. */
final class StaffHandoffObservation
{
    public const KIND='max-staff-handoff-history-observation';
    private const RESULT='history-empty-original-outcomes-unknown';
    private const MAX_AGE=1800;
    private const CONTROL_RADIUS_MS=5000;

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public function verify(array $scope,string $sha,int $now): array
    {
        $sealed=$this->store->verifySpool('spool/'.$sha);
        $context=$sealed['context'] ?? [];
        if ($context!==$this->context($scope)) throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_SCOPE_CHANGED');
        $record=Support::decode((string)base64_decode($sealed['raw_base64'],true));
        $observed=$record['observed_at'] ?? null;
        $control=$record['control'] ?? [];
        $controlStamp=$control['timestamp_ms'] ?? null;
        if (($record['result'] ?? null)!==self::RESULT || ($record['membership_http'] ?? null)!==200
            || ($record['bot_is_admin'] ?? null)!==true || ($record['history_allowed'] ?? null)!==true
            || ($control['latest_http'] ?? null)!==200 || ($control['interval_http'] ?? null)!==200
            || ($control['same_message'] ?? null)!==true || !is_int($controlStamp) || $controlStamp<1
            || !preg_match('/\A[a-f0-9]{64}\z/',(string)($control['message_id_sha256'] ?? ''))
            || !is_int($observed) || $observed>$now || $observed<$now-self::MAX_AGE
            || intdiv($controlStamp,1000)>$observed) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_INVALID');
        }
        $rows=$record['attempts'] ?? [];
        if (!array_is_list($rows) || count($rows)!==2 || ($rows[0]['attempt_id'] ?? null)!==$scope['isolated_probe']['attempt_id']
            || ($rows[0]['attempt_id'] ?? null)===($rows[1]['attempt_id'] ?? null)) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_INVALID');
        }
        foreach (['server','cli'] as $i=>$lane) {
            $row=$rows[$i];
            if ($row!==$this->snapshot($scope,(string)($row['attempt_id'] ?? ''),$lane)
                    +['history_http'=>200,'messages_returned'=>0]
                || $row['contract_id']===$scope['contract_id'] || $row['event_id']>$scope['event_floor']) {
                throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_HISTORY_CHANGED');
            }
        }
        return array_column($rows,'attempt_id');
    }

    /** Runs under the operator's provider lock. The caller binds GET to the configured chat. */
    public function observe(array $scope,string $serverId,string $cliId,callable $get): string
    {
        if ($serverId===$cliId || $this->db->inTransaction()
            || (int)$this->db->query("SELECT IS_USED_LOCK('ipphp-provider:max')=CONNECTION_ID()")->fetchColumn()!==1) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_LOCK_REQUIRED');
        }
        $attempts=[$this->snapshot($scope,$serverId,'server'),$this->snapshot($scope,$cliId,'cli')];
        $membership=$get('/chats/'.rawurlencode($scope['chat_id']).'/members/me');
        if ($membership['http']!==200 || ($membership['data']['is_bot'] ?? null)!==true
            || ($membership['data']['is_admin'] ?? null)!==true
            || !in_array('read_all_messages',$membership['data']['permissions'] ?? [],true)) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_MEMBERSHIP_REQUIRED');
        }
        $history='/messages?chat_id='.rawurlencode($scope['chat_id']);
        $control=$this->checkBounds($scope['chat_id'],$history,$get);
        foreach ($attempts as $i=>$attempt) {
            $response=$get(self::window($history,$attempt['lower_ms'],$attempt['upper_ms']));
            if ($response['http']!==200 || ($response['data']['messages'] ?? null)!==[]) {
                throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_EMPTY_HISTORY_REQUIRED');
            }
            if ($attempt!==$this->snapshot($scope,$attempt['attempt_id'],$attempt['lane'])) {
                throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_HISTORY_CHANGED');
            }
            $attempts[$i]+=['history_http'=>200,'messages_returned'=>0];
        }
        foreach ($attempts as $attempt) {
            if ($attempt!==$this->snapshot($scope,$attempt['attempt_id'],$attempt['lane'])
                    +['history_http'=>200,'messages_returned'=>0]) {
                throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_HISTORY_CHANGED');
            }
        }
        $record=['result'=>self::RESULT,'observed_at'=>time(),'membership_http'=>200,
            'bot_is_admin'=>true,'history_allowed'=>true,'control'=>$control,'attempts'=>$attempts];
        return basename($this->store->spool($this->context($scope),Support::json($record)));
    }

    private function checkBounds(string $chat,string $history,callable $get): array
    {
        $latest=$get($history.'&count=1');
        $messages=$latest['data']['messages'] ?? [];
        $message=$messages[0] ?? null;
        $stamp=$message['timestamp'] ?? null;
        $id=$message['body']['mid'] ?? null;
        if ($latest['http']!==200 || count($messages)!==1 || !is_int($stamp) || $stamp<=self::CONTROL_RADIUS_MS
            || intdiv($stamp,1000)>time() || !is_string($id) || $id===''
            || (string)($message['recipient']['chat_id'] ?? '')!==$chat) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_CONTROL_REQUIRED');
        }
        $control=$get(self::window($history,$stamp-self::CONTROL_RADIUS_MS,$stamp+self::CONTROL_RADIUS_MS));
        $matches=array_filter($control['data']['messages'] ?? [],static fn(array $row): bool=>
            ($row['timestamp'] ?? null)===$stamp && ($row['body']['mid'] ?? null)===$id
            && (string)($row['recipient']['chat_id'] ?? '')===$chat);
        if ($control['http']!==200 || count($matches)!==1) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_CONTROL_REQUIRED');
        }
        return ['latest_http'=>200,'interval_http'=>200,'same_message'=>true,
            'timestamp_ms'=>$stamp,'message_id_sha256'=>hash('sha256',$id)];
    }

    private static function window(string $history,int $lower,int $upper): string
    {
        // MAX defines from as the upper bound and to as the lower bound.
        return $history.'&count=30&from='.$upper.'&to='.$lower;
    }

    private function context(array $scope): array
    {
        return ['kind'=>self::KIND,'schema'=>1,'profile_revision'=>$scope['revision'],
            'vault_sha256'=>$scope['vault_sha256'],'group_id'=>$scope['group_id'],'target'=>$scope['chat_id']];
    }

    private function snapshot(array $scope,string $id,string $lane): array
    {
        $q=$this->db->prepare("SELECT a.*,r.receipt_path FROM messaging_attempts a
            LEFT JOIN messaging_receipts r ON r.attempt_id=a.id WHERE a.id=? AND a.provider='max'
            AND a.operation='send' AND a.target=? AND a.revision=? AND a.status='uncertain'
            AND a.result_code='MAX_ACCEPTANCE_UNKNOWN' AND a.finished_at IS NOT NULL AND a.remote_id IS NULL");
        $q->execute([$id,$scope['chat_id'],$scope['revision']]);
        $attempt=$q->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || $attempt['id']!==$id || trim($attempt['body_text'])==='') {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_ATTEMPT_CHANGED');
        }
        $keyExpression=$lane==='server'
            ? "CONCAT('contract-workflow-',SHA2(CONCAT(JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.command_key')),':',id),256))"
            : "CONCAT('rental-max-',SHA2(CONCAT(JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.epoch')),':',id),256))";
        $q=$this->db->prepare("SELECT id,actor_id,action,contract_id,source_json,created_at FROM rental_events
            WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.kind'))='committed-rental-workflow-event'
            AND actor_id=? AND created_at<=? AND $keyExpression=? LIMIT 2");
        $q->execute([$attempt['actor_id'],$attempt['created_at'],$attempt['request_key']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $row=$rows[0] ?? [];
        $event=count($rows)===1?WorkflowEvents::decode($row):null;
        $expected=$lane==='server'
            ? ['contract.return','accountantReturn','accounting','returned','lawyer']
            : ['contract.legal','legalApprove','legal','accounting',null];
        if (!$event || $event['entity_kind']!=='contract' || $event['contract_id']!==(int)$row['contract_id']
            || $event['actor_id']!==(int)$row['actor_id'] || $event['action']!==$row['action']
            || [$event['action'],$event['event_kind'],$event['from_status'],$event['to_status'],$event['return_to'] ?? null]!==$expected
            || ($lane==='server'?ServerWorkflowMax::requestKey($event):WorkflowMaxEvent::requestKey($event))!==$attempt['request_key']) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_EVENT_CHANGED');
        }
        $q=$this->db->prepare('SELECT action,fingerprint,result_json FROM rental_commands WHERE command_key=?');
        $q->execute([$event['command_key']]);
        $command=$q->fetch(PDO::FETCH_ASSOC);
        if (!$command || $command['action']!==$event['action'] || $command['fingerprint']!==$event['command_fingerprint']
            || !hash_equals($event['command_result_sha256'],hash('sha256',$command['result_json']))) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_COMMAND_CHANGED');
        }
        ksort($attempt);
        $created=new \DateTimeImmutable($attempt['created_at']);
        $finished=new \DateTimeImmutable($attempt['finished_at']);
        if ($finished<$created || $finished->getTimestamp()-$created->getTimestamp()>35
            || $finished->getTimestamp()+35>=time()) {
            throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_TIME_INVALID');
        }
        return ['attempt_id'=>$id,'lane'=>$lane,'row_sha256'=>hash('sha256',Support::json($attempt)),
            'contract_id'=>$event['contract_id'],'event_id'=>$event['event_id'],'event_sha256'=>$event['sha256'],'event_at'=>$row['created_at'],
            'lower_ms'=>($created->getTimestamp()-20)*1000,'upper_ms'=>($created->getTimestamp()+35)*1000];
    }
}
