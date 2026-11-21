<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\Audit;
use PDO;
use RuntimeException;

/** Consumes one separately approved training send while leaving the older outcome unknown. */
final class StaffHandoffProbe
{
    private const CLAIM = 'max.staff-handoff-probe';
    private const ENTITY = 'max-workflow-attempt';
    private const MAX_SECONDS = 900;

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public static function validate(mixed $probe): void
    {
        $keys=['attempt_id','receipt_sha256','decision_ref','owner_id','contract_number','recipient_id','epoch','valid_from','expires_at'];
        $uuid='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/';
        if (is_array($probe) && array_key_exists('observation_sha256',$probe)) {
            $keys[]='observation_sha256';
            if (!is_string($probe['observation_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/',$probe['observation_sha256']))
                throw new RuntimeException('STAFF_HANDOFF_OBSERVATION_INVALID');
        }
        if (!is_array($probe) || count($probe)!==count($keys) || array_diff($keys,array_keys($probe))
            || !is_string($probe['attempt_id']) || !preg_match($uuid,$probe['attempt_id'])
            || !is_string($probe['receipt_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/',$probe['receipt_sha256'])
            || !is_string($probe['decision_ref']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,95}\z/',$probe['decision_ref'])
            || !is_int($probe['owner_id']) || $probe['owner_id']<1
            || !is_string($probe['contract_number']) || !preg_match('/\AУЧ-MAX-37(?:-[A-Z0-9]{1,20})?\z/u',$probe['contract_number'])
            || !is_int($probe['recipient_id']) || $probe['recipient_id']<1
            || !is_string($probe['epoch']) || !preg_match($uuid,$probe['epoch'])
            || !is_int($probe['valid_from']) || $probe['valid_from']<1
            || !is_int($probe['expires_at']) || $probe['expires_at']<=$probe['valid_from']
            || $probe['expires_at']-$probe['valid_from']>self::MAX_SECONDS) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_ADMISSION_INVALID');
        }
    }

    public static function admits(array $probe,array $event,int $now): bool
    {
        $targets=$event['staff_handoff']['targets'] ?? [];
        return $now>=$probe['valid_from'] && $now<$probe['expires_at']
            && $event['epoch']===$probe['epoch'] && $event['contract_number']===$probe['contract_number']
            && ($event['entity_kind'] ?? null)==='contract' && ($event['from_status'] ?? null)==='draft'
            && ($event['to_status'] ?? null)==='legal' && ($event['event_kind'] ?? null)==='submit'
            && ($event['actor_role'] ?? null)==='manager'
            && count($targets)===1 && ($targets[0]['role'] ?? null)==='lawyer'
            && ($targets[0]['id'] ?? null)===$probe['recipient_id'];
    }

    public function verifyReady(array $scope): array
    {
        $this->verifyBasis($scope);
        $probe=$scope['isolated_probe'];
        $observation=$probe['observation_sha256'] ?? null;
        $ids=$observation===null?[$probe['attempt_id']]
            :(new StaffHandoffObservation($this->db,$this->store))->verify($scope,$observation,time());
        $slots=implode(',',array_fill(0,count($ids),'?'));
        $unknown=$observation===null?" AND (operation='send' OR request_key LIKE 'contract-workflow-%')":'';
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max'
            AND (status='pending' OR (status='uncertain' AND id NOT IN ($slots)$unknown)) LIMIT 1");
        $q->execute($ids);
        if ($q->fetchColumn()) throw new RuntimeException(ServerWorkflowMax::RECONCILIATION_REQUIRED);
        $q=$this->db->prepare("SELECT 1 FROM audit_log WHERE entity_type=? AND action=? AND entity_id IN ($slots) LIMIT 1");
        $q->execute([self::ENTITY,self::CLAIM,...$ids]);
        if ($q->fetchColumn()) throw new RuntimeException('STAFF_HANDOFF_PROBE_ALREADY_CLAIMED');
        return $ids;
    }

    private function verifyBasis(array $scope): void
    {
        $probe=$scope['isolated_probe'];
        $targets=$this->db->query("SELECT id,chat_id,name FROM messaging_groups WHERE provider='max' AND enabled=1")->fetchAll(PDO::FETCH_ASSOC);
        if (count($targets)!==1 || $targets[0]['id']!==$scope['group_id']
            || $targets[0]['name']!==$scope['group_name'] || $targets[0]['chat_id']!==$scope['chat_id']) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_GROUP_CHANGED');
        }
        $q=$this->db->prepare("SELECT 1 FROM users WHERE id=? AND role='admin' AND active=1");
        $q->execute([$probe['owner_id']]);
        if (!$q->fetchColumn()) throw new RuntimeException('STAFF_HANDOFF_PROBE_OWNER_CHANGED');

        $q=$this->db->prepare("SELECT a.*,r.receipt_path FROM messaging_attempts a
            JOIN messaging_receipts r ON r.attempt_id=a.id
            WHERE a.id=? AND a.provider='max' AND a.operation='send' AND a.target=?
              AND a.status='uncertain' AND a.result_code='MAX_ACCEPTANCE_UNKNOWN' AND a.finished_at IS NOT NULL");
        $q->execute([$probe['attempt_id'],$scope['chat_id']]);
        $attempt=$q->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || !empty($attempt['remote_id']) || (int)$attempt['revision']!==$scope['revision']
            || !preg_match('/\Acontract-workflow-[a-f0-9]{64}\z/',$attempt['request_key'])
            || !hash_equals('spool/'.$probe['receipt_sha256'],$attempt['receipt_path'])) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_RECEIPT_CHANGED');
        }
        $request=$this->store->verifySpool($attempt['spool_path']);
        $context=$request['context'] ?? [];
        $receipt=$this->store->receiptFor($attempt['id']);
        if (!$receipt || $receipt['receipt_path']!==$attempt['receipt_path']
            || ($receipt['receipt']['context']['kind'] ?? null)!=='transport_receipt'
            || ($receipt['receipt']['context']['request_key'] ?? null)!==$attempt['request_key']
            || ($context['attempt_id'] ?? null)!==$attempt['id'] || ($context['provider'] ?? null)!=='max'
            || ($context['operation'] ?? null)!=='send' || ($context['target'] ?? null)!==$scope['chat_id']
            || ($context['request_key'] ?? null)!==$attempt['request_key']
            || ($context['revision'] ?? null)!==$scope['revision']
            || ($context['fingerprint'] ?? null)!==$attempt['fingerprint']
            || base64_decode($request['raw_base64'],true)!==Support::json(['text'=>$attempt['body_text']])) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_RECEIPT_CHANGED');
        }
        $outcome=Support::decode((string)base64_decode($receipt['receipt']['raw_base64'],true));
        if (($outcome['status'] ?? null)!=='uncertain' || ($outcome['code'] ?? null)!=='MAX_ACCEPTANCE_UNKNOWN'
            || !empty($outcome['remote_id'])) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_RECEIPT_CHANGED');
        }
    }

    /** Owns the same provider lock from the committed claim through the single transport call. */
    public function claim(array $scope,array $event): void
    {
        ServerWorkflowMax::validateStaff($scope);
        $probe=$scope['isolated_probe'];
        if ($this->db->inTransaction() || (int)$this->db->query("SELECT IS_USED_LOCK('ipphp-provider:max')=CONNECTION_ID()")->fetchColumn()!==1) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_LOCK_REQUIRED');
        }
        if (!ServerWorkflowMax::staffAdmits($scope,$event)
            || ($event['request_key'] ?? null)!==ServerWorkflowMax::requestKey($event,true)) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_EVENT_CHANGED');
        }
        $ids=$this->verifyReady($scope);
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? LIMIT 1");
        $q->execute([$event['actor_id'],$event['request_key']]);
        if ($q->fetchColumn()) throw new RuntimeException('STAFF_HANDOFF_PROBE_ALREADY_CLAIMED');

        // Write once before transport. A crash after the claim consumes this approval.
        $observation=$probe['observation_sha256'] ?? null;
        $decision=['owner_id'=>$probe['owner_id'],'decision_ref'=>$probe['decision_ref'],'contract_id'=>$scope['contract_id'],
            'recipient_id'=>$probe['recipient_id'],'event_id'=>$event['event_id'],'new_request_key'=>$event['request_key'],
            'profile_revision'=>$scope['revision'],'template_version'=>$scope['catalog_version'],'old_outcome'=>'uncertain',
            'historical_attempts'=>$ids];
        if ($observation!==null) $decision['primary_attempt_id']=$probe['attempt_id'];
        $this->db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $evidence=['outcome'=>'uncertain'];
                if ($observation!==null) $evidence['observation_sha256']=$observation;
                if ($id===$probe['attempt_id']) $evidence['receipt_sha256']=$probe['receipt_sha256'];
                Audit::record(self::CLAIM,self::ENTITY,$id,$evidence,$decision);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
