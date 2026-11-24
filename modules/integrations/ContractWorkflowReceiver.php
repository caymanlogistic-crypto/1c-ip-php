<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\{DB, Http, PagePolicy};
use Modules\Rental\Service;
use PDO;
use RuntimeException;
use Throwable;

/** Authenticated intake into the existing server MAX adapter and messaging journal. */
final class ContractWorkflowReceiver
{
    private const MARKER='contract-workflow-relay-server.json';
    private ?array $delivery=null;
    private int $sent=0;

    public function __construct(private PDO $db,private ?\Closure $fixture=null) {}

    public static function receive(): never
    {
        if (PHP_OS_FAMILY!=='Linux' || getenv('APP_STAND')!=='ui' || ($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'
            || !is_file(APP_ROOT.'/.local/'.self::MARKER)) {
            Http::json(['error'=>'unavailable'],404);
        }
        header('Cache-Control: no-store');
        $size=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $raw=file_get_contents('php://input',false,null,0,ContractWorkflowRelay::LIMIT+1);
        if ($size<1 || $size>ContractWorkflowRelay::LIMIT || !is_string($raw) || strlen($raw)!==$size) {
            Http::json(['error'=>'invalid_request'],400);
        }
        try {
            $service=new self(DB::pdo());
            Http::json($service->accept($raw,(string)($_SERVER['HTTP_X_IPPHP_RELAY_TIME'] ?? ''),
                (string)($_SERVER['HTTP_X_IPPHP_RELAY_SIGNATURE'] ?? '')));
        } catch (RuntimeException $error) {
            $status=in_array($error->getCode(),[400,403,409],true)?$error->getCode():503;
            Http::json(['error'=>$status===503?'unavailable':'rejected'],$status);
        } catch (Throwable $error) {
            \App\SafeErrors::record($error);
            Http::json(['error'=>'unavailable'],503);
        }
    }

    public function accept(string $raw,string $time,string $signature): array
    {
        $scope=$this->settings();
        $plan=ContractWorkflowRelay::verify($raw,$time,$signature,$scope,time());
        $key='contract-workflow-relay-'.hash('sha256',$plan['source'].':'.$plan['epoch'].':'.$plan['event_id']);
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',2)")->fetchColumn()!==1)
            throw new RuntimeException('CONTRACT_RELAY_BUSY',409);
        try {
            if ($this->settings()!==$scope) throw new RuntimeException('CONTRACT_RELAY_SCOPE_CHANGED',409);
            ContractWorkflowRelay::verify($raw,$time,$signature,$scope,time());
            $actor=$this->serverActor($scope,$plan);
            $text=$this->message($scope,$plan);
            ServerWorkflowMax::assertProfile($this->db,$scope);
            $route=$this->route($scope,$plan);
            $q=$this->db->prepare("SELECT actor_id,target FROM messaging_attempts WHERE provider='max' AND request_key=?");
            $q->execute([$key]);
            $existing=$q->fetchAll();
            if ($existing && (count($existing)!==1 || (int)$existing[0]['actor_id']!==$actor || $existing[0]['target']!==$route['chat_id'])) {
                throw new RuntimeException('CONTRACT_RELAY_RECEIPT_CONFLICT',409);
            }
            if (!$existing) $this->assertAvailable($route['chat_id']);
            $this->sent=0;
            $this->delivery=['scope'=>$scope,'route'=>$route,'plan'=>$plan,'actor'=>$actor,'key'=>$key,'text'=>$text];
            $fixture=$this->fixture===null?null:function(string $method,string $path,array $headers,?string $body): array {
                $this->assertHttpRequest($method,'https://platform-api2.max.ru'.$path,$body);
                return ($this->fixture)($method,$path,$headers,$body);
            };
            $adapter=new MessageTransport($fixture,null,null,$this);
            $transport=static fn($provider,$operation,$config,$secret,$target,$body,$id,$groups): array=>
                $adapter->execute($provider,$operation,$config,MaxWorkingDestination::recipientSecret($secret,$target,$route,$groups),
                    $target,$body,$id,[$target]);
            $attempts=(new Messaging($this->db,Config::store(),$transport))->dispatch(
                'max',$actor,$key,'send',[$route['group_id']],$text,maxFormat:'html');
            return ['status'=>$attempts[0]['status'],'event_id'=>$plan['event_id']];
        } finally {
            $this->delivery=null;
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
        }
    }

    private function route(array $scope,array $plan): array
    {
        return MaxWorkingDestination::relayed($this->db,$scope,'contract',$scope['source'],
            ($plan['destination']??null)===MaxWorkingDestination::WORK);
    }

    private function settings(): array
    {
        $database=$this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($this->fixture!==null) {
            $allowed=PHP_SAPI==='cli' && !getenv('APP_STAND') && getenv('APP_CONTRACT_RELAY_TEST')==='1'
                && preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D',$database);
        } else {
            $allowed=PHP_OS_FAMILY==='Linux' && getenv('APP_STAND')==='ui' && $database==='ipphp_ui';
        }
        if (!$allowed || Config::liveAllowed() || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT') || $this->db->inTransaction()) {
            throw new RuntimeException('CONTRACT_RELAY_SERVER_SCOPE');
        }
        $scope=ContractWorkflowRelay::readScope($this->fixture===null?self::MARKER:'contract-workflow-relay-test.json');
        if (($scope['database'] ?? null)!==$database || !ContractWorkflowRelay::isUuid($scope['group_id'] ?? null)
            || ($scope['group_name'] ?? null)!=='ТЕСТОВАЯ' || !is_string($scope['chat_id'] ?? null)
            || !is_int($scope['revision'] ?? null) || $scope['revision']<1
            || !is_string($scope['vault_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$scope['vault_sha256'])
            || ($scope['destination_epoch'] ?? null)!==$this->db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn()) {
            throw new RuntimeException('CONTRACT_RELAY_SERVER_SCOPE');
        }
        Support::chatId($scope['chat_id']);
        return $scope;
    }

    private function serverActor(array $scope,array $plan): int
    {
        $id=$scope['actor_map'][$plan['actor_id']];
        $q=$this->db->prepare("SELECT u.id,u.name,u.role AS role_id,u.active,r.grants_configured,
            CASE WHEN u.role='admin' THEN 'admin' ELSE p.kind END AS role
            FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles p ON p.role_id=u.role WHERE u.id=?");
        $q->execute([$id]);
        $actor=$q->fetch();
        if (!$actor || !$actor['active'] || !ContractWorkflowRelay::actorAllowed($plan['kind'],(string)$actor['role'])) {
            throw new RuntimeException('CONTRACT_RELAY_ACTOR_DENIED',403);
        }
        $q=$this->db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*')");
        foreach (['rental.read',Service::commandGrant($plan['action'],$actor)] as $permission) {
            $q->execute([$actor['role_id'],$permission]);
            if (!$q->fetchColumn()) throw new RuntimeException('CONTRACT_RELAY_ACTOR_DENIED',403);
        }
        if (PagePolicy::levelForRole($actor['role_id'],$actor['role'],(bool)$actor['grants_configured'],
            PagePolicy::commandPage($plan['action']))<2) {
            throw new RuntimeException('CONTRACT_RELAY_ACTOR_DENIED',403);
        }
        return $id;
    }

    private function message(array $scope,array $plan): string
    {
        $version=$scope['catalog_versions'][$plan['kind']];
        $extended=ContractWorkflowRelay::assertApproved($this->db,$plan['kind'],$version);
        $field=ContractWorkflowRelay::extendedField($plan['kind'],$version);
        $hasField=$field!==null && isset($plan['values'][$field]);
        if ((!$extended && $hasField) || ($plan['kind']===AccountantPackageMax::KEY && $extended && !$hasField)) {
            throw new RuntimeException('CONTRACT_RELAY_VALUES',400);
        }
        $render=TemplateCatalog::renderEvent($this->db,'max',$plan['kind'],$plan['values']);
        if ($render['binding']['version']!==$version || $render['format']!=='html')
            throw new RuntimeException('CONTRACT_RELAY_TEMPLATE_CHANGED',409);
        return $render['body'];
    }

    private function assertAvailable(string $chat): void
    {
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
            AND (status='pending' OR (status='uncertain' AND (target=? OR request_key LIKE 'contract-workflow-%'
                OR request_key LIKE 'reservation-max-%'))) LIMIT 1");
        $q->execute([$chat]);
        if ($q->fetchColumn()) throw new RuntimeException(ServerWorkflowMax::RECONCILIATION_REQUIRED,409);
        $q=$this->db->prepare("SELECT TIMESTAMPDIFF(MICROSECOND,NOW(6),MAX(created_at)+INTERVAL 1 SECOND)
            FROM messaging_attempts WHERE provider='max' AND target=? AND operation='send'
            AND created_at>DATE_SUB(NOW(6),INTERVAL 1 SECOND)");
        $q->execute([$chat]);
        $wait=(int)$q->fetchColumn();
        if ($wait>0) usleep($wait);
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        $delivery=$this->delivery;
        if (!$delivery || $provider!=='max' || $operation!=='send' || $delivery['scope']!==$this->settings()
            || $target!==$delivery['route']['chat_id'] || $groups!==[$target]
            || $this->route($delivery['scope'],$delivery['plan'])!==$delivery['route']
            || $raw!==MessageTransport::maxPayload($delivery['text'],'html')) {
            throw new RuntimeException('CONTRACT_RELAY_TRANSPORT_SCOPE');
        }
        ServerWorkflowMax::assertProfile($this->db,$delivery['scope']);
        if ($this->serverActor($delivery['scope'],$delivery['plan'])!==$delivery['actor']
            || $this->message($delivery['scope'],$delivery['plan'])!==$delivery['text']) {
            throw new RuntimeException('CONTRACT_RELAY_TRANSPORT_SCOPE');
        }
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=?
            AND target=? AND revision=? AND body_text=? AND status='pending'");
        $q->execute([$delivery['actor'],$delivery['key'],$target,$delivery['scope']['revision'],$delivery['text']]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('CONTRACT_RELAY_CLAIM_REQUIRED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertRequest($method,'https://platform-api2.max.ru'.$path,$body);
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        $this->assertRequest($method,$url,$body);
        $this->sent++;
    }

    private function assertRequest(string $method,string $url,?string $body): void
    {
        if (!$this->delivery) throw new RuntimeException('CONTRACT_RELAY_TRANSPORT_SCOPE');
        $target=$this->delivery['route']['chat_id'];
        ServerWorkflowMax::assertPath($this->sent,$method,$url,$body,$target,$this->delivery['text'],'html');
        $this->assertOperation('max','send',$target,(string)$body,[$target]);
    }

    public function caFile(): string
    {
        return (new ServerWorkflowMax($this->db))->caFile();
    }
}
