<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\{Audit,Auth,PagePolicy};
use Modules\Rental\{Service,StaffHandoff};
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
        return self::selectsSubmission($probe,$event,$now)
            && count($targets)===1 && ($targets[0]['role'] ?? null)==='lawyer'
            && ($targets[0]['id'] ?? null)===$probe['recipient_id'];
    }

    private static function selectsSubmission(array $probe,array $event,int $now): bool
    {
        return $now>=$probe['valid_from'] && $now<$probe['expires_at']
            && $event['epoch']===$probe['epoch'] && $event['contract_number']===$probe['contract_number']
            && ($event['entity_kind'] ?? null)==='contract' && ($event['from_status'] ?? null)==='draft'
            && ($event['to_status'] ?? null)==='legal' && ($event['event_kind'] ?? null)==='submit'
            && ($event['actor_role'] ?? null)==='manager';
    }

    public static function reviewer(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT u.name,u.role role_id,r.grants_configured FROM users u
            JOIN roles r ON r.id=u.role JOIN role_profiles p ON p.role_id=u.role
            WHERE u.id=? AND u.active=1 AND p.kind='lawyer'");
        $q->execute([$id]);
        $user=$q->fetch(PDO::FETCH_ASSOC);
        if (!$user || !Auth::canFor($user,'rental.read')
            || !Auth::canFor($user,Service::commandGrant('contract.legal',['role'=>'lawyer']))
            || PagePolicy::levelForRole($user['role_id'],'lawyer',(bool)$user['grants_configured'],PagePolicy::commandPage('contract.legal'))<2
            || ($name=StaffHandoff::name($user['name'],'lawyer'))!==trim($user['name'])
            || $name===\App\People::roleLabel('lawyer')) {
            throw new RuntimeException('STAFF_HANDOFF_PROBE_RECIPIENT_CHANGED');
        }
        return ['role'=>'lawyer','id'=>$id,'label'=>$name];
    }

    public static function assertReviewer(PDO $db,int $id,array $target): void
    {
        if (Support::canonical(self::reviewer($db,$id))!==Support::canonical($target))
            throw new RuntimeException('STAFF_HANDOFF_PROBE_RECIPIENT_CHANGED');
    }

    public function namedReviewer(array $scope,array $event): ?array
    {
        $probe=$scope['isolated_probe'] ?? null;
        if ($probe===null || !ServerWorkflowMax::staffSelects($scope,$event)
            || !self::selectsSubmission($probe,$event,time())
            || ($event['target_role'] ?? null)!=='lawyer'
            || ($event['staff_handoff']['targets'][0]['role'] ?? null)!=='lawyer'
            || count($event['staff_handoff']['targets'])!==1) return null;

        ServerWorkflowMax::assertProfile($this->db,$scope);
        $q=$this->db->query("SELECT version,source_sha256 FROM integration_template_catalog WHERE channel='max' AND source_key='staffHandoff'");
        $template=$q->fetch(PDO::FETCH_ASSOC);
        if (!$template || (int)$template['version']!==$scope['catalog_version']
            || $template['source_sha256']!==hash_file('sha256',__DIR__.'/defaults/staff-handoff-max.json')) {
            throw new RuntimeException('STAFF_HANDOFF_TEMPLATE_CHANGED');
        }
        $this->verifyReady($scope);
        return self::reviewer($this->db,$probe['recipient_id']);
    }

    /** Reads only the trusted server marker; unusable probes leave the regular target intact. */
    public static function assign(PDO $db,array $event): ?array
    {
        try {
            $scope=ServerWorkflowMax::staffSettings();
            if ($scope===null || !isset($scope['isolated_probe'])
                || $db->query('SELECT DATABASE()')->fetchColumn()!==$scope['database']) return null;
            return (new self($db,Config::store()))->namedReviewer($scope,$event);
        } catch (RuntimeException) {
            return null;
        }
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
        self::assertReviewer($this->db,$probe['recipient_id'],$event['staff_handoff']['targets'][0]);
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
