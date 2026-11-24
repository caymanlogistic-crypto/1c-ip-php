<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\Auth;
use PDO;
use RuntimeException;

/** Operator opt-in: one committed object transition, one TEST destination, one POST. */
final class ServerObjectMax
{
    private ?array $event=null;
    private ?array $scope=null;
    private ?array $route=null;
    private ?string $pending=null;
    private int $sent=0;
    public function __construct(private PDO $db) {}

    public function caFile(): string
    {
        $path=Config::root().'/.local/certs/windows-trusted-roots.pem';
        if (is_link($path)||!is_file($path)||!is_readable($path)
            ||!hash_equals('b846c5f49517b6de22fcfaa239a9c0561185f22cb9ffc7e2b39ef8fb806c59e0',hash_file('sha256',$path)))
            throw new RuntimeException('OBJECT_MAX_CA_REJECTED');
        return $path;
    }

    public static function validate(mixed $m): void
    {
        $keys=['schema','enabled','project_id','scope','stand','database','revision','group_id','group_name','chat_id','vault_sha256','event_floor'];
        if (!is_array($m)||count($m)!==count($keys)||array_diff($keys,array_keys($m))
            ||$m['schema']!==1||$m['enabled']!==true||$m['project_id']!==\Modules\Rental\WorkflowEvents::PROJECT
            ||$m['scope']!=='object-activity'||$m['stand']!=='ui'||$m['database']!=='ipphp_ui'||$m['group_name']!=='ТЕСТОВАЯ'
            ||!is_int($m['revision'])||$m['revision']<1||!is_int($m['event_floor'])||$m['event_floor']<0
            ||!is_string($m['group_id'])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$m['group_id'])
            ||!is_string($m['vault_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$m['vault_sha256'])||!is_string($m['chat_id']))
            throw new RuntimeException('OBJECT_MAX_ADMISSION_INVALID');
        Support::chatId($m['chat_id']);
    }

    public static function settings(): ?array
    {
        if (PHP_OS_FAMILY!=='Linux'||getenv('APP_STAND')!=='ui') return null;
        $path=Config::root().'/.local/object-max-admission.json';
        if (!file_exists($path)) return null;
        if (is_link($path)||!is_readable($path)||filesize($path)>4096) throw new RuntimeException('OBJECT_MAX_ADMISSION_INVALID');
        $m=Support::decode(file_get_contents($path));self::validate($m);return $m;
    }

    private function context(): array
    {
        $m=self::settings();
        if ($m===null||$this->db->inTransaction()||$this->db->query('SELECT DATABASE()')->fetchColumn()!==$m['database']) throw new RuntimeException('OBJECT_MAX_SCOPE_REJECTED');
        $c=Config::get($this->db,'max');$secret=Config::secrets('max');Config::assertSecretRevision($secret,$m['revision']);
        $path=Config::root().'/private/integrations/max.enc';
        if (!$c['enabled']||(int)$c['revision']!==$m['revision']||($secret['_owner_bound_revision'] ?? null)!==$m['revision']
            ||($secret['approved_chat_ids'] ?? null)!==[$m['chat_id']]||empty($secret['secret'])||is_link($path)
            ||!hash_equals($m['vault_sha256'],hash_file('sha256',$path))) throw new RuntimeException('OBJECT_MAX_PROFILE_CHANGED');
        $q=$this->db->prepare("SELECT name,chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 AND id=?");$q->execute([$m['group_id']]);$group=$q->fetch();
        if (!$group||$group['name']!==$m['group_name']||(string)$group['chat_id']!==$m['chat_id']) throw new RuntimeException('OBJECT_MAX_GROUP_CHANGED');
        return $m;
    }

    public function dispatch(array $event): array
    {
        $scope=$this->context();
        $proof=(new ObjectActivityMax($this->db))->plan($event['event_id'],$event['command_key'],Auth::user() ?? []);
        if ($proof!==$event||$event['event_id']<=$scope['event_floor']) throw new RuntimeException('OBJECT_MAX_EVENT_REJECTED');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) throw new RuntimeException('OBJECT_MAX_BUSY');
        try {
            $key=ObjectActivityMax::requestKey($event);
            // Never replay legacy claims. Their unknown outcome cannot duplicate a distinct,
            // freshly committed object event; unresolved deliveries of this capability do block it.
            $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND (status='pending' OR (status='uncertain' AND request_key LIKE 'object-activity-%')) AND NOT(actor_id=? AND request_key=?)");$q->execute([$event['actor_id'],$key]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('OBJECT_MAX_RECONCILIATION_REQUIRED');
            $this->scope=$this->context();$this->event=$event;$this->sent=0;$this->pending=null;
            if ($this->scope!==$scope) throw new RuntimeException('OBJECT_MAX_SCOPE_REJECTED');
            $route=$this->route=MaxWorkingDestination::route($this->db,$scope,$event['event_id']);
            $transport=fn($p,$o,$c,$s,$t,$r,$id,$g)=>(new MessageTransport(null,null,null,$this))->execute(
                $p,$o,$c,MaxWorkingDestination::recipientSecret($s,$t,$route,$g),$t,$r,$id,[$t]);
            return (new Messaging($this->db,Config::store(),$transport))->dispatch('max',$event['actor_id'],$key,'send',[$route['group_id']],$event['text']);
        } finally {$this->event=null;$this->scope=null;$this->route=null;$this->pending=null;$this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        if (!$this->event||!$this->scope||$this->context()!==$this->scope||$provider!=='max'||$operation!=='send'
            ||!$this->route||$target!==$this->route['chat_id']||$groups!==[$target]||$raw!==Support::json(['text'=>$this->event['text']])
            ||MaxWorkingDestination::route($this->db,$this->scope,$this->event['event_id'])!==$this->route)
            throw new RuntimeException('OBJECT_MAX_REQUEST_REJECTED');
        $proof=(new ObjectActivityMax($this->db))->plan($this->event['event_id'],$this->event['command_key'],Auth::user() ?? []);
        if ($proof!==$this->event) throw new RuntimeException('OBJECT_MAX_EVENT_REJECTED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? AND target=? AND revision=? AND status='pending' AND body_text=?");
        $q->execute([$this->event['actor_id'],ObjectActivityMax::requestKey($this->event),$target,$this->scope['revision'],$this->event['text']]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('OBJECT_MAX_CLAIM_REQUIRED');
    }

    public static function assertPath(int $sent,string $method,string $url,?string $body,string $chat,string $text): void
    {
        if ($sent!==0||$method!=='POST'||$url!=='https://platform-api2.max.ru/messages?chat_id='.rawurlencode($chat)
            ||$body!==Support::json(['text'=>$text])) throw new RuntimeException('OBJECT_MAX_HTTP_REJECTED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertHttpScope($method,'https://platform-api2.max.ru'.$path,$body);$this->pending=$body;
    }
    private function assertHttpScope(string $method,string $url,?string $body): void
    {
        if (!$this->event||!$this->route) throw new RuntimeException('OBJECT_MAX_SCOPE_REJECTED');
        $chat=$this->route['chat_id'];
        self::assertPath($this->sent,$method,$url,$body,$chat,$this->event['text']);
        $this->assertOperation('max','send',$chat,(string)$body,[$chat]);
    }
    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        $this->assertHttpScope($method,$url,$body);
        if ($body!==$this->pending) throw new RuntimeException('OBJECT_MAX_HTTP_REJECTED');
        $this->sent++;$this->pending=null;
    }
}
