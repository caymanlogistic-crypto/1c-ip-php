<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\Auth;
use Modules\Rental\WorkflowEvents;
use PDO;
use RuntimeException;

/** Forward only a fresh committed contract transition. MAX credentials stay on the receiving server. */
final class ContractWorkflowRelay
{
    public const PATH='/internal/contract-workflow-max';
    private const URL='https://ui.89.208.213.14.sslip.io'.self::PATH;
    public const LIMIT=16384;
    private const AGE=90;
    private const LOCAL='contract-workflow-relay-local.json';
    public const FIELDS=[
        'submit'=>['contractSubmitTitle','contractSubmitAction'],
        'resubmit'=>['text','version','lawyer'],
        'legalApprove'=>[],
        'lawyerReturn'=>['reason','actor','recipientNames'],
        'accountantReturn'=>['reason','actor','recipientNames'],
        'package'=>['invoiceSummary','actor'],
        'signed'=>['text','actor'],
        'cancelled'=>['reason','actor'],
        'firstInvoice'=>['invoiceSummary','actor','recordUrl'],
    ];
    private const COMMON=['number','party','contractStart','contractEnd','contractObjectLines'];
    private ?string $request=null;

    public function __construct(private PDO $db,private ?\Closure $fixture=null) {}

    public static function configured(): bool
    {
        return PHP_OS_FAMILY==='Windows' && !getenv('APP_STAND') && is_file(APP_ROOT.'/.local/'.self::LOCAL);
    }

    public function afterCommittedCommand(int $eventId,string $commandKey,array $actor): array
    {
        $allowed=$this->fixture===null ? PHP_SAPI==='cli-server' && self::configured()
            : PHP_SAPI==='cli' && !getenv('APP_STAND') && getenv('APP_CONTRACT_RELAY_TEST')==='1';
        if (!$allowed || Config::liveAllowed()
            || LocalWorkflowMax::configured() || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) {
            return ['status'=>'unavailable'];
        }
        $scope=self::readScope($this->fixture===null?self::LOCAL:'contract-workflow-relay-source-test.json');
        $root=str_replace('\\','/',(string)realpath($scope['app_root'] ?? ''));
        if (strcasecmp($root,str_replace('\\','/',APP_ROOT))!==0
            || ($scope['database'] ?? '')!==$this->db->query('SELECT DATABASE()')->fetchColumn()
            || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$scope['database'])) {
            throw new RuntimeException('CONTRACT_RELAY_LOCAL_SCOPE');
        }
        $plan=$this->export($eventId,$commandKey,$actor,$scope);
        if (!$plan) return ['status'=>'skipped'];
        $time=time();
        $this->request=Support::json($plan);
        $headers=['Content-Type: application/json','X-IpPhp-Relay-Time: '.$time,
            'X-IpPhp-Relay-Signature: '.self::signature($this->request,$time,$scope['key'])];
        try {
            if (strlen($this->request)>self::LIMIT) throw new RuntimeException('CONTRACT_RELAY_PAYLOAD_LIMIT',400);
            if ($this->fixture!==null) {
                $this->assertHttpRequest('POST',self::URL,$this->request);
                $reply=($this->fixture)('POST',self::URL,$headers,$this->request);
            } else {
                $reply=HttpTransport::request('POST',self::URL,$headers,$this->request,1024,30,readCheck:$this);
            }
        } finally {
            $this->request=null;
        }
        if ($reply['status']===404) return ['status'=>'unavailable'];
        if ($reply['status']!==200) throw new RuntimeException('CONTRACT_RELAY_HANDOFF_FAILED');
        $result=Support::decode($reply['raw']);
        if (($result['event_id'] ?? null)!==$eventId || !in_array($result['status'] ?? '',['accepted','failed','uncertain','pending'],true))
            throw new RuntimeException('CONTRACT_RELAY_RECEIPT_INVALID');
        return $result;
    }

    private function export(int $id,string $commandKey,array $actor,array $scope): ?array
    {
        if ($this->db->inTransaction() || ($_SERVER['REQUEST_METHOD'] ?? '')!=='POST')
            throw new RuntimeException('CONTRACT_RELAY_POSTCOMMIT_REQUIRED');
        $q=$this->db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');
        $q->execute([$id]);
        $row=$q->fetch();
        $event=$row ? WorkflowEvents::decode($row) : null;
        $version=$scope['catalog_versions'][$event['event_kind'] ?? ''] ?? null;
        if (!$event || $version===null || $event['entity_kind']!=='contract') return null;
        $plan=[
            'source'=>$scope['source'],'epoch'=>$event['epoch'],'event_id'=>$id,'recorded_at'=>strtotime($row['created_at']),
            'contract_id'=>$event['contract_id'],'actor_id'=>$event['actor_id'],'action'=>$event['action'],
            'kind'=>$event['event_kind'],'version'=>$version,'values'=>[],
        ];
        if (!self::allowed($plan,$scope,time())) return null;
        $current=Auth::user();
        if (!$current || (int)$current['id']!==(int)($actor['id'] ?? 0)
            || (int)$current['id']!==$event['actor_id'] || (int)$current['auth_version']!==$event['auth_version']
            || $event['event_id']!==$id || $event['command_key']!==$commandKey
            || !self::actorAllowed($plan['kind'],$event['actor_role'])) {
            throw new RuntimeException('CONTRACT_RELAY_ACTOR_CHANGED');
        }
        self::assertApproved($this->db,$plan['kind'],$version);
        $proof=(new CurrentContractMaxProof($this->db))->relayProof($id,$version);
        if ($proof['event']!==$event) throw new RuntimeException('CONTRACT_RELAY_EVENT_CHANGED',409);
        $plan['values']=array_intersect_key($proof['catalog']['values'],array_flip(self::fields($plan['kind'])));
        self::validatePlan($plan,$scope,time());
        return $plan;
    }

    public static function readScope(string $file): array
    {
        $path=APP_ROOT.'/.local/'.$file;
        if (!is_file($path) || is_link($path) || filesize($path)>8192) throw new RuntimeException('CONTRACT_RELAY_SCOPE');
        $scope=Support::decode(file_get_contents($path));
        foreach (['source','source_epoch'] as $key) {
            if (!self::isUuid($scope[$key] ?? null)) throw new RuntimeException('CONTRACT_RELAY_SCOPE');
        }
        foreach (['event_floor','valid_from','expires_at','future_after'] as $key) {
            if (!is_int($scope[$key] ?? null) || $scope[$key]<0) throw new RuntimeException('CONTRACT_RELAY_SCOPE');
        }
        if (($scope['enabled'] ?? null)!==true || $scope['valid_from']<1 || $scope['valid_from']>time() || $scope['expires_at']<=time()
            || !is_string($scope['key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$scope['key'])
            || !is_array($scope['contract_ids'] ?? null) || !array_is_list($scope['contract_ids'])
            || !is_array($scope['actor_map'] ?? null) || !$scope['actor_map']
            || !is_array($scope['catalog_versions'] ?? null) || array_diff_key(array_diff_key(self::FIELDS,['firstInvoice'=>true]),$scope['catalog_versions'])
            || array_diff_key($scope['catalog_versions'],self::FIELDS)) {
            throw new RuntimeException('CONTRACT_RELAY_SCOPE');
        }
        foreach (array_merge($scope['contract_ids'],array_keys($scope['actor_map']),array_values($scope['actor_map']),array_values($scope['catalog_versions'])) as $id) {
            if (!is_int($id) || $id<1) throw new RuntimeException('CONTRACT_RELAY_SCOPE');
        }
        return $scope;
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value)===1;
    }

    private static function allowed(array $plan,array $scope,int $now): bool
    {
        return ($scope['valid_from']<=$now && $scope['expires_at']>$now)
            && $plan['source']===$scope['source'] && $plan['epoch']===$scope['source_epoch']
            && $plan['event_id']>$scope['event_floor'] && $plan['recorded_at']>=$scope['valid_from']
            && $plan['recorded_at']>=$now-self::AGE && $plan['recorded_at']<=$now+10
            && isset($scope['actor_map'][$plan['actor_id']])
            && (in_array($plan['contract_id'],$scope['contract_ids'],true)
                || ($scope['future_after']>0 && $plan['contract_id']>$scope['future_after']))
            && $plan['version']===($scope['catalog_versions'][$plan['kind']] ?? null);
    }

    public static function signature(string $raw,int $time,string $key): string
    {
        return hash_hmac('sha256',"contract-workflow-max\n".$time."\n".$raw,$key);
    }

    public static function verify(string $raw,string $time,string $signature,array $scope,int $now): array
    {
        if (strlen($raw)>self::LIMIT || !preg_match('/^[0-9]{10}$/D',$time) || abs($now-(int)$time)>self::AGE
            || !hash_equals(self::signature($raw,(int)$time,$scope['key']),$signature)) {
            throw new RuntimeException('CONTRACT_RELAY_SIGNATURE',403);
        }
        try {
            $plan=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('CONTRACT_RELAY_PAYLOAD',400);
        }
        if (!is_array($plan)) throw new RuntimeException('CONTRACT_RELAY_PAYLOAD',400);
        self::validatePlan($plan,$scope,$now);
        return $plan;
    }

    private static function fields(string $kind): array
    {
        return array_merge(self::COMMON,self::FIELDS[$kind]);
    }

    public static function contractLink(int $id): string
    {
        $base='https://ui.89.208.213.14.sslip.io';
        $settings=APP_ROOT.'/.local/runtime-settings.json';
        if (!getenv('APP_STAND') && is_file($settings)) {
            $port=Support::decode(file_get_contents($settings))['port']??0;
            if (is_int($port) && $port>=1024 && $port<=65535) $base='http://127.0.0.1:'.$port;
        }
        return $base.'/rental/contract?id='.$id;
    }

    public static function actorAllowed(string $kind,string $role): bool
    {
        return $role==='admin' || in_array($role,match ($kind) {
            'legalApprove','lawyerReturn'=>['lawyer'],
            'accountantReturn','package','firstInvoice'=>['accountant'],
            'cancelled'=>['manager','director'],
            default=>['manager'],
        },true);
    }

    private static function validatePlan(array $plan,array $scope,int $now): void
    {
        $fields=['source','epoch','event_id','recorded_at','contract_id','actor_id','action','kind','version','values'];
        if (count($plan)!==count($fields) || array_diff($fields,array_keys($plan))) throw new RuntimeException('CONTRACT_RELAY_PAYLOAD',400);
        foreach (['event_id','recorded_at','contract_id','actor_id','version'] as $field) {
            if (!is_int($plan[$field]) || $plan[$field]<1) throw new RuntimeException('CONTRACT_RELAY_PAYLOAD',400);
        }
        $kind=$plan['kind'];
        $actions=is_string($kind)?match($kind) {
            'submit'=>['contract.create','contract.edit','contract.submit'],
            'lawyerReturn'=>['contract.return','contract.forward','contract.legal-block'],
            'firstInvoice'=>[\Modules\Rental\InitialInvoice::ISSUE],
            'resubmit'=>['contract.edit','contract.submit'],
            'legalApprove'=>['contract.legal'],
            'accountantReturn'=>['contract.return'],
            'package'=>['contract.packet'],
            'signed'=>['contract.sign'],
            'cancelled'=>['contract.cancel'],
            default=>[],
        }:[];
        if (!in_array($plan['action'],$actions,true) || !is_array($plan['values'])) throw new RuntimeException('CONTRACT_RELAY_PAYLOAD',400);
        $keys=array_flip(self::fields($kind));
        if (array_diff_key($keys,$plan['values']) || array_diff_key($plan['values'],$keys)) {
            throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        }
        foreach ($plan['values'] as $key=>$value) {
            $limit=$key==='contractObjectLines'?10000:2400;
            if (!is_string($value) || strlen($value)>$limit || str_contains($value,"\0"))
                throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        }
        if ($kind==='firstInvoice' && !preg_match('~^(?:https://ui[.]89[.]208[.]213[.]14[.]sslip[.]io|http://127[.]0[.]0[.]1:[0-9]{4,5})/rental/contract[?]id='
            .$plan['contract_id'].'$~D',$plan['values']['recordUrl'])) {
            throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        }
        try {
            $objects=json_decode($plan['values']['contractObjectLines'],true,8,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        }
        if (!is_array($objects) || !array_is_list($objects)) throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        foreach ($objects as $object) {
            if (!is_array($object) || count($object)!==3 || !is_string($object['name'] ?? null)
                || !is_string($object['type'] ?? null) || !is_string($object['area'] ?? null)) {
                throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
            }
        }
        if (!self::allowed($plan,$scope,$now)) throw new RuntimeException('CONTRACT_RELAY_EVENT_SCOPE',409);
    }

    public static function assertApproved(PDO $db,string $kind,int $version): void
    {
        $approved=$kind==='firstInvoice'
            ?Support::decode(file_get_contents(__DIR__.'/defaults/first-invoice-max.json'))
            :Support::decode(file_get_contents(__DIR__.'/defaults/contract-max-templates.json'))[$kind];
        $q=$db->prepare("SELECT version,format,body_text FROM integration_template_catalog WHERE channel='max' AND source_key=?");
        $q->execute([$kind]);
        $row=$q->fetch();
        if (!$row || (int)$row['version']!==$version || $row['format']!==$approved['format'] || $row['body_text']!==$approved['body']) {
            throw new RuntimeException('CONTRACT_RELAY_TEMPLATE_CHANGED',409);
        }
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if ($method!=='POST' || $url!==self::URL || $this->request===null || $this->request!==$body)
            throw new RuntimeException('CONTRACT_RELAY_HTTP_REJECTED');
    }
}
