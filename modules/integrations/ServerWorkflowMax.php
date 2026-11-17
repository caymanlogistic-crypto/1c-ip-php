<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\Auth;
use PDO;
use RuntimeException;

/** Operator opt-in: one committed contract workflow transition, one TEST destination, one POST. */
final class ServerWorkflowMax
{
    private ?array $event=null;
    private ?array $scope=null;
    private ?string $pending=null;
    private int $sent=0;
    public function __construct(private PDO $db) {}

    public function caFile(): string
    {
        $path=Config::root().'/.local/certs/windows-trusted-roots.pem';
        if (is_link($path)||!is_file($path)||!is_readable($path)
            ||!hash_equals('b846c5f49517b6de22fcfaa239a9c0561185f22cb9ffc7e2b39ef8fb806c59e0',hash_file('sha256',$path)))
            throw new RuntimeException('WORKFLOW_MAX_CA_REJECTED');
        return $path;
    }

    public static function validate(mixed $m): void
    {
        if(!is_array($m)||($m['scope']??null)!=='contract-workflow'||!is_array($m['catalog_versions']??null)
            ||array_diff(array_keys($m['catalog_versions']),self::KINDS))throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        foreach(self::KINDS as $key)if(!is_int($m['catalog_versions'][$key]??null)||$m['catalog_versions'][$key]<1)throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        $base=$m;unset($base['catalog_versions']);$base['scope']='object-activity';ServerObjectMax::validate($base);
    }

    private const KINDS=['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','package','signed','cancelled'];

    public static function requestKey(array $event):string
    {
        return 'contract-workflow-'.hash('sha256',$event['command_key'].':'.$event['event_id']);
    }

    public function plan(int $eventId,string $commandKey,array $actor):?array
    {
        $scope=$this->context();
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('WORKFLOW_MAX_POST_REQUIRED');
        $q=$this->db->prepare('SELECT id,source_json,created_at FROM rental_events WHERE id=?');$q->execute([$eventId]);$row=$q->fetch();
        $event=$row?\Modules\Rental\WorkflowEvents::decode($row):null;
        if(!$event||$event['entity_kind']!=='contract'||$eventId<=$scope['event_floor']||!in_array($event['event_kind'],self::KINDS,true))return null;
        $current=Auth::user();
        if(!$current||(int)$current['id']!==(int)($actor['id']??0)||$event['actor_id']!==(int)$current['id']
            ||$event['auth_version']!==(int)$current['auth_version']||$event['command_key']!==$commandKey)throw new RuntimeException('WORKFLOW_MAX_ACTOR_CHANGED');
        $key=CurrentContractMaxProof::select($event);
        $proof=(new CurrentContractMaxProof($this->db))->plan($eventId,$scope['group_id'],$scope['revision'],$scope['catalog_versions'][$key]);
        return ['event_id'=>$eventId,'command_key'=>$commandKey,'actor_id'=>$event['actor_id'],'text'=>$proof['catalog']['body'],'proof_sha256'=>$proof['sha256']];
    }

    public function afterCommittedCommand(int $eventId,string $commandKey,array $actor):array
    {
        $event=$this->plan($eventId,$commandKey,$actor);
        if($event===null)return ['status'=>'skipped'];
        $attempts=$this->dispatch($event);$a=$attempts[0]??[];
        return ['status'=>$a['status']??'uncertain','code'=>$a['result_code']??'NO_RECEIPT','attempt_id'=>$a['id']??null];
    }

    public static function settings(): ?array
    {
        if (PHP_OS_FAMILY!=='Linux'||getenv('APP_STAND')!=='ui') return null;
        $path=Config::root().'/.local/workflow-max-admission.json';
        if (!file_exists($path)) return null;
        if (is_link($path)||!is_readable($path)||filesize($path)>4096) throw new RuntimeException('WORKFLOW_MAX_ADMISSION_INVALID');
        $m=Support::decode(file_get_contents($path));self::validate($m);return $m;
    }

    private function context(): array
    {
        $m=self::settings();
        if ($m===null||$this->db->inTransaction()||$this->db->query('SELECT DATABASE()')->fetchColumn()!==$m['database']) throw new RuntimeException('WORKFLOW_MAX_SCOPE_REJECTED');
        $c=Config::get($this->db,'max');$secret=Config::secrets('max');Config::assertSecretRevision($secret,$m['revision']);
        $path=Config::root().'/private/integrations/max.enc';
        if (!$c['enabled']||(int)$c['revision']!==$m['revision']||($secret['_owner_bound_revision'] ?? null)!==$m['revision']
            ||($secret['approved_chat_ids'] ?? null)!==[$m['chat_id']]||empty($secret['secret'])||is_link($path)
            ||!hash_equals($m['vault_sha256'],hash_file('sha256',$path))) throw new RuntimeException('WORKFLOW_MAX_PROFILE_CHANGED');
        $q=$this->db->prepare("SELECT name,chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 AND id=?");$q->execute([$m['group_id']]);$group=$q->fetch();
        if (!$group||$group['name']!==$m['group_name']||(string)$group['chat_id']!==$m['chat_id']) throw new RuntimeException('WORKFLOW_MAX_GROUP_CHANGED');
        return $m;
    }

    public function dispatch(array $event): array
    {
        $scope=$this->context();
        $proof=$this->plan($event['event_id'],$event['command_key'],Auth::user() ?? []);
        if ($proof!==$event||$event['event_id']<=$scope['event_floor']) throw new RuntimeException('WORKFLOW_MAX_EVENT_REJECTED');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) throw new RuntimeException('WORKFLOW_MAX_BUSY');
        try {
            $key=self::requestKey($event);
            // Never replay legacy claims. Their unknown outcome cannot duplicate a distinct,
            // freshly committed contract event; unresolved deliveries of this capability do block it.
            $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND (status='pending' OR (status='uncertain' AND request_key LIKE 'contract-workflow-%')) AND NOT(actor_id=? AND request_key=?)");$q->execute([$event['actor_id'],$key]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('WORKFLOW_MAX_RECONCILIATION_REQUIRED');
            $this->scope=$this->context();$this->event=$event;$this->sent=0;$this->pending=null;
            $transport=fn($p,$o,$c,$s,$t,$r,$id,$g)=>(new MessageTransport(null,null,null,$this))->execute($p,$o,$c,$s,$t,$r,$id,$g);
            return (new Messaging($this->db,Config::store(),$transport))->dispatch('max',$event['actor_id'],$key,'send',[$scope['group_id']],$event['text']);
        } finally {$this->event=null;$this->scope=null;$this->pending=null;$this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        if (!$this->event||!$this->scope||$this->context()!==$this->scope||$provider!=='max'||$operation!=='send'
            ||$target!==$this->scope['chat_id']||$groups!==[$target]||$raw!==Support::json(['text'=>$this->event['text']])) throw new RuntimeException('WORKFLOW_MAX_REQUEST_REJECTED');
        $proof=$this->plan($this->event['event_id'],$this->event['command_key'],Auth::user() ?? []);
        if ($proof!==$this->event) throw new RuntimeException('WORKFLOW_MAX_EVENT_REJECTED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? AND target=? AND revision=? AND status='pending' AND body_text=?");
        $q->execute([$this->event['actor_id'],self::requestKey($this->event),$target,$this->scope['revision'],$this->event['text']]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('WORKFLOW_MAX_CLAIM_REQUIRED');
    }

    public static function assertPath(int $sent,string $method,string $url,?string $body,string $chat,string $text): void
    {
        if ($sent!==0||$method!=='POST'||$url!=='https://platform-api2.max.ru/messages?chat_id='.rawurlencode($chat)
            ||$body!==Support::json(['text'=>$text])) throw new RuntimeException('WORKFLOW_MAX_HTTP_REJECTED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertHttpScope($method,'https://platform-api2.max.ru'.$path,$body);$this->pending=$body;
    }
    private function assertHttpScope(string $method,string $url,?string $body): void
    {
        if (!$this->event||!$this->scope) throw new RuntimeException('WORKFLOW_MAX_SCOPE_REJECTED');
        self::assertPath($this->sent,$method,$url,$body,$this->scope['chat_id'],$this->event['text']);
        $this->assertOperation('max','send',$this->scope['chat_id'],(string)$body,[$this->scope['chat_id']]);
    }
    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        $this->assertHttpScope($method,$url,$body);
        if ($body!==$this->pending) throw new RuntimeException('WORKFLOW_MAX_HTTP_REJECTED');
        $this->sent++;$this->pending=null;
    }
}
