<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\Auth;
use Closure;
use PDO;
use RuntimeException;

/** Authorizes one form submission to the configured working group. */
final class ServerManualMax
{
    private ?array $claim=null;
    private bool $armed=false;
    private int $sent=0;

    public function __construct(private PDO $db,private ?Closure $httpFixture=null) {}

    public static function configured(): bool
    {
        return PHP_OS_FAMILY==='Linux' && getenv('APP_STAND')==='ui' && !Config::liveAllowed();
    }

    private function scope(): array
    {
        $database=$this->db->query('SELECT DATABASE()')->fetchColumn();
        $fixture=PHP_SAPI==='cli' && $this->httpFixture!==null && !getenv('APP_STAND')
            && !Config::liveAllowed() && getenv('APP_MAX_WORKING_DESTINATION_FIXTURE')==='max-destination-fixture'
            && $database==='ipphp_task_max_destination';
        if (!$fixture && (!self::configured() || $this->httpFixture!==null || $database!=='ipphp_ui')) {
            throw new RuntimeException('MAX_MANUAL_ADMISSION_REQUIRED');
        }
        $base=MaxWorkingDestination::settings($this->db);
        $scope=$base?MaxWorkingDestination::admission($this->db,$base):null;
        if (!$scope || !$scope['group_enabled']) throw new RuntimeException('MAX_MANUAL_ADMISSION_REQUIRED');
        return $scope;
    }

    private function assertActor(int $actor): void
    {
        if ((int)(Auth::user()['id']??0)!==$actor || !Auth::can('messaging.read')
            || !PageView::canEdit('max','messaging.send')) {
            throw new RuntimeException('MAX_MANUAL_FORBIDDEN',403);
        }
    }

    public function dispatch(int $actor,string $key,array $targets,string $text): array
    {
        $this->assertActor($actor);
        if (!ContractWorkflowRelay::isUuid($key)) throw new \InvalidArgumentException('INVALID_MESSAGE_COMMAND');
        if ($this->db->inTransaction() || $this->claim!==null
            || (int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) {
            throw new RuntimeException('MAX_MANUAL_BUSY');
        }
        try {
            $scope=$this->scope();
            if (array_values($targets)!==[$scope['group_id']]) throw new RuntimeException('MAX_MANUAL_WORK_ONLY');
            $unresolved=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
                AND (status='pending' OR (status='uncertain' AND target=? AND CAST(body_text AS BINARY)=CAST(? AS BINARY)))
                AND NOT (actor_id=? AND request_key=?) LIMIT 1");
            $unresolved->execute([$scope['chat_id'],$text,$actor,$key]);
            if ($unresolved->fetchColumn()) throw new RuntimeException('MAX_MANUAL_RECONCILIATION_REQUIRED');

            $this->claim=['actor'=>$actor,'key'=>$key,'text'=>$text,'scope'=>$scope];
            $this->armed=false;
            $this->sent=0;
            $transport=function($provider,$operation,$config,$secret,$target,$raw,$remoteId,$groups) use($scope): array {
                $secret=MaxWorkingDestination::recipientSecret($secret,$target,$scope,$groups);
                return (new MessageTransport($this->httpFixture,null,null,$this))
                    ->execute($provider,$operation,$config,$secret,$target,$raw,$remoteId,[$target]);
            };
            return (new Messaging($this->db,Config::store(),$transport))
                ->dispatch('max',$actor,$key,'send',$targets,$text);
        } finally {
            $this->claim=null;
            $this->armed=false;
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
        }
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        $claim=$this->claim;
        if (!$claim || $this->db->inTransaction() || $provider!=='max' || $operation!=='send' || $groups!==[$target]
            || $target!==$claim['scope']['chat_id'] || $raw!==MessageTransport::maxPayload($claim['text'])
            || $this->scope()!==$claim['scope']) {
            throw new RuntimeException('MAX_MANUAL_REQUEST_REJECTED');
        }
        $this->assertActor($claim['actor']);
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
            AND actor_id=? AND request_key=? AND target=? AND revision=? AND status='pending' AND CAST(body_text AS BINARY)=CAST(? AS BINARY)");
        $q->execute([$claim['actor'],$claim['key'],$target,$claim['scope']['revision'],$claim['text']]);
        if (!$q->fetchColumn()) throw new RuntimeException('MAX_MANUAL_CLAIM_REQUIRED');
    }

    private function assertPath(string $method,string $url,?string $body): void
    {
        $claim=$this->claim;
        if (!$claim) throw new RuntimeException('MAX_MANUAL_REQUEST_REJECTED');
        $chat=$claim['scope']['chat_id'];
        ServerObjectMax::assertPath($this->sent,$method,$url,$body,$chat,$claim['text']);
        $this->assertOperation('max','send',$chat,(string)$body,[$chat]);
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertPath($method,'https://platform-api2.max.ru'.$path,$body);
        $this->armed=true;
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if (!$this->armed) throw new RuntimeException('MAX_MANUAL_REQUEST_REJECTED');
        $this->assertPath($method,$url,$body);
        $this->armed=false;
        $this->sent++;
    }

    public function caFile(): string
    {
        return (new ServerWorkflowMax($this->db))->caFile();
    }
}
