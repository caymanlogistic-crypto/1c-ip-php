<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\PagePolicy;
use PDO;
use RuntimeException;

/** Separate one-shot readiness permission. Never authorizes a message or workflow event. */
final class MaxReadCheck
{
    public const ADMISSION='owner-authorized-max-readiness-two-gets';
    private ?array $armed=null;
    private ?array $secret=null;
    private int $next=0;
    private ?string $pendingPath=null;
    public function __construct(private PDO $db){}

    public static function assertPath(int $next,string $method,string $path,?string $body,string $chat): void
    {
        if(!preg_match('/^-?[0-9]{1,24}$/D',$chat)||$method!=='GET'||$body!==null
            ||!in_array($next,[0,1],true)||$path!==($next===0?'/me':'/chats/'.$chat.'/members/me'))
            throw new RuntimeException('MAX_READ_PATH_NOT_AUTHORIZED');
    }

    public static function assertActor(array $actor,int $id,int $authVersion,bool $read,bool $send,int $page): void
    {
        if(empty($actor['active'])||(int)$actor['id']!==$id||(int)$actor['auth_version']!==$authVersion||!$read||!$send||$page<2)
            throw new RuntimeException('MAX_READ_ACTOR_NOT_AUTHORIZED');
    }

    public function plan(int $actorId,int $authVersion,string $groupId,int $revision,string $requestKey): array
    {
        if($this->db->inTransaction()||$actorId<1||$authVersion<1||$revision!==6
            ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$groupId)
            ||!preg_match('/^max-read-check-[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$requestKey))
            throw new RuntimeException('MAX_READ_SELECTOR_INVALID');
        if((int)$this->db->query('SELECT @@port')->fetchColumn()!==43061
            ||$this->db->query('SELECT DATABASE()')->fetchColumn()!=='ip_ya_php_scratch_core_f6e21e3c_d6dc6bc7_r2')
            throw new RuntimeException('MAX_READ_LOCAL_R2_ONLY');
        $epoch=$this->db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        if($epoch!==\Modules\Rental\WorkflowNotices::EPOCH)throw new RuntimeException('MAX_READ_PROJECT_EPOCH_CHANGED');
        $q=$this->db->prepare("SELECT u.id,u.active,u.role role_id,u.auth_version,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE COALESCE(rp.kind,'viewer') END role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?");
        $q->execute([$actorId]);$actor=$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('MAX_READ_ACTOR_NOT_AUTHORIZED');
        $grant=function(string $key)use($actor):bool{
            $q=$this->db->prepare('SELECT 1 FROM role_permissions WHERE role_id=? AND (permission_key=? OR permission_key=?) LIMIT 1');
            $q->execute([$actor['role_id'],$key,'*']);return (bool)$q->fetchColumn();
        };
        $level=PagePolicy::levelForRole((string)$actor['role_id'],$actor['role'],!empty($actor['grants_configured']),'connections.max');
        self::assertActor($actor,$actorId,$authVersion,$grant('messaging.read'),$grant('messaging.send'),$level);
        $connection=Config::get($this->db,'max');
        if(!$connection['enabled']||(int)$connection['revision']!==$revision)throw new RuntimeException('MAX_READ_REVISION_CHANGED');
        $q=$this->db->prepare("SELECT g.id,g.name,g.chat_id,b.source_variant,b.source_group_id FROM messaging_groups g JOIN integration_group_bindings b ON b.provider='max' AND b.local_group_id=g.id WHERE g.id=? AND g.provider='max' AND g.enabled=1");
        $q->execute([$groupId]);$groups=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($groups)!==1||$groups[0]['name']!=='ТЕСТОВАЯ')throw new RuntimeException('MAX_READ_APPROVED_GROUP_REQUIRED');
        Support::chatId($groups[0]['chat_id']);
        $source=$this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='max'")->fetch(PDO::FETCH_ASSOC);
        if(!$source||(int)$source['source_revision']!==$revision)throw new RuntimeException('MAX_READ_SOURCE_CHANGED');
        $plan=['schema'=>1,'project_id'=>CaptureReader::PROJECT,'epoch'=>$epoch,'actor'=>$actor,'group'=>$groups[0],
            'connection'=>$connection,'source'=>$source,'revision'=>$revision,'request_key'=>$requestKey,'operation'=>'check',
            'paths'=>['/me','/chats/'.$groups[0]['chat_id'].'/members/me'],'provider_posts'=>0];
        // Public status may be updated by this check; it is not its admission authority.
        $plan['connection']=array_intersect_key($connection,array_flip(['provider','enabled','revision','config_json']));
        $plan['sha256']=hash('sha256',Support::canonical($plan));return $plan;
    }

    private function current(): array
    {
        if(PHP_SAPI!=='cli'||getenv('IPPHP_MAX_READ_CHECK')!==self::ADMISSION||!$this->armed||$this->db->inTransaction())
            throw new RuntimeException('MAX_READ_SCOPE_NOT_AUTHORIZED');
        $p=$this->armed;
        $now=$this->plan((int)$p['actor']['id'],(int)$p['actor']['auth_version'],$p['group']['id'],$p['revision'],$p['request_key']);
        if(!hash_equals($now['sha256'],$p['sha256'])||Config::secrets('max')!==$this->secret)throw new RuntimeException('MAX_READ_CONTEXT_CHANGED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND actor_id=? AND request_key=? AND operation='check' AND target='@connection' AND revision=? AND status='pending'");
        $q->execute([$p['actor']['id'],$p['request_key'],$p['revision']]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('MAX_READ_COMMITTED_CLAIM_REQUIRED');
        return $p;
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        $p=$this->current();
        if($provider!=='max'||$operation!=='check'||$target!=='@connection'||$raw!==''||$groups!==[$p['group']['chat_id']])
            throw new RuntimeException('MAX_READ_OPERATION_NOT_AUTHORIZED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $p=$this->current();self::assertPath($this->next,$method,$path,$body,$p['group']['chat_id']);$this->pendingPath=$path;
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        $prefix='https://platform-api2.max.ru';
        if(!str_starts_with($url,$prefix.'/'))throw new RuntimeException('MAX_READ_PATH_NOT_AUTHORIZED');
        $path=substr($url,strlen($prefix));$p=$this->current();
        self::assertPath($this->next,$method,$path,$body,$p['group']['chat_id']);
        if($this->pendingPath!==$path)throw new RuntimeException('MAX_READ_UPPER_GUARD_REQUIRED');
        $this->pendingPath=null;$this->next++;
    }

    public function dispatch(array $plan,string $expectedSha): array
    {
        if(PHP_SAPI!=='cli'||getenv('IPPHP_MAX_READ_CHECK')!==self::ADMISSION)throw new RuntimeException('MAX_READ_SCOPE_NOT_AUTHORIZED');
        $fresh=$this->plan((int)$plan['actor']['id'],(int)$plan['actor']['auth_version'],$plan['group']['id'],$plan['revision'],$plan['request_key']);
        if($plan!==$fresh||!hash_equals($expectedSha,$fresh['sha256']))throw new RuntimeException('MAX_READ_PLAN_CHANGED');
        if((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1)throw new RuntimeException('MAX_READ_BUSY');
        try{
            foreach(['key','max.enc'] as $file){$path=Config::root().'/private/integrations/'.$file;if(is_link($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('MAX_READ_EXISTING_VAULT_REQUIRED');}
            if(filesize(Config::root().'/private/integrations/key')!==32)throw new RuntimeException('MAX_READ_EXISTING_VAULT_REQUIRED');
            $secret=Config::secrets('max');Config::assertSecretRevision($secret,$fresh['revision']);
            if(($secret['_owner_bound_revision']??null)!==$fresh['revision']||($secret['approved_chat_ids']??null)!==[$fresh['group']['chat_id']])
                throw new RuntimeException('MAX_READ_PRIVATE_BINDING_CHANGED');
            $this->armed=$fresh;$this->secret=$secret;$this->next=0;$this->pendingPath=null;
            $transport=function($provider,$operation,$config,$secret,$target,$raw,$remoteId,$groups)use($fresh):array{
                if($secret!==$this->secret)throw new RuntimeException('MAX_READ_PRIVATE_BINDING_CHANGED');
                return (new MessageTransport(null,null,null,$this))->execute($provider,$operation,$config,$secret,$target,$raw,$remoteId,[$fresh['group']['chat_id']]);
            };
            return(new Messaging($this->db,Config::store(),$transport))->dispatch('max',(int)$fresh['actor']['id'],$fresh['request_key'],'check',[]);
        }finally{$this->armed=null;$this->secret=null;$this->pendingPath=null;$this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");}
    }
}
