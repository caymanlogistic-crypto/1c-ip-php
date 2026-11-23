<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\{Auth, PagePolicy};
use Modules\Rental\{BookingNotices, Importer, Service, WorkflowEvents};
use PDO;
use RuntimeException;

/** One approved MAX profile and group, with the regular committed messaging journal. */
final class BookingMax
{
    private ?array $delivery=null;
    private bool $prepared=false;
    private bool $sent=false;
    private readonly bool $fixture;

    public function __construct(private PDO $db,private ?PrivateStore $store=null,private mixed $httpFixture=null)
    {
        $this->fixture=is_callable($httpFixture);
        if ($httpFixture!==null && !$this->fixture) throw new RuntimeException('RESERVATION_MAX_FIXTURE_INVALID');
    }

    private function settings(): ?array
    {
        if (PHP_SAPI!=='cli' || Config::liveAllowed() || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) return null;
        $db=$this->db->query('SELECT DATABASE()')->fetchColumn();
        $stand=getenv('APP_STAND');
        if ($this->fixture) {
            if ($stand || getenv('APP_RESERVATION_MAX_TEST')!=='1' || !is_string($db) || !preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',$db))
                return null;
        } elseif (PHP_OS_FAMILY!=='Linux' || $stand!=='ui' || $db!=='ipphp_ui') return null;
        $path=Config::root().'/.local/'.($this->fixture?'reservation-max-local.json':'reservation-max-admission.json');
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path)>4096)
            throw new RuntimeException('RESERVATION_MAX_ADMISSION_INVALID');
        $m=Support::decode(file_get_contents($path));
        if (($m['enabled']??null)===false) return null;
        $keys=['schema','enabled','project_id','scope','database','epoch','event_floor','valid_from','expires_at',
            'group_id','group_name','chat_id','revision','vault_sha256','catalog_versions'];
        if (count($m)!==count($keys) || array_diff($keys,array_keys($m)) || ($m['schema']??null)!==1 || ($m['enabled']??null)!==true
            || $m['project_id']!==WorkflowEvents::PROJECT || $m['scope']!=='reservation-notices' || $m['database']!==$db
            || !self::uuid($m['epoch']) || !self::uuid($m['group_id']) || !is_int($m['event_floor']) || $m['event_floor']<0
            || !is_int($m['valid_from']) || !is_int($m['expires_at']) || $m['expires_at']<=$m['valid_from']
            || !is_int($m['revision']) || $m['revision']<1 || !is_string($m['group_name']) || trim($m['group_name'])===''
            || !is_string($m['chat_id']) || !is_string($m['vault_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D',$m['vault_sha256'])
            || !is_array($m['catalog_versions']) || !$m['catalog_versions']
            || array_diff(array_keys($m['catalog_versions']),array_column(BookingNotices::TEMPLATES,0)))
            throw new RuntimeException('RESERVATION_MAX_ADMISSION_INVALID');
        Support::chatId($m['chat_id']);
        foreach ($m['catalog_versions'] as $version) if (!is_int($version) || $version<1)
            throw new RuntimeException('RESERVATION_MAX_ADMISSION_INVALID');
        if ($m['valid_from']>time() || $m['expires_at']<=time()
            || $m['epoch']!==$this->db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn()) return null;
        return $m;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$value)===1;
    }

    public function run(string $date): array
    {
        Service::date($date);
        $m=$this->settings();
        $summary=['state'=>$m?'complete':'disabled','accepted'=>0,'failed'=>0,'uncertain'=>0,'skipped'=>0];
        if (!$m) return $summary;
        if ((!$this->fixture && $date!==date('Y-m-d')) || $this->db->inTransaction()) throw new RuntimeException('RESERVATION_MAX_RUNTIME_INVALID');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1)
            return array_replace($summary,['state'=>'busy']);
        try {
            $this->checkContext($m);
            if (!$this->fixture) $this->caFile();
            $q=$this->db->prepare("SELECT f.id,f.source_key FROM rental_facts f WHERE f.namespace=? AND f.operative=1 AND f.id>?
                AND JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.epoch'))=?
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.recorded_at')) AS UNSIGNED)>=?
                AND NOT EXISTS(SELECT 1 FROM messaging_attempts a WHERE a.provider='max' AND a.request_key=CONCAT('reservation-max-',f.source_key))
                ORDER BY f.id");
            $q->execute([BookingNotices::NS,$m['event_floor'],$m['epoch'],$m['valid_from']]);
            $processed=0;
            foreach ($q->fetchAll() as $row) {
                $plan=$this->plan($m,$row,$date);
                if (!$plan) { $summary['skipped']++; continue; }
                if ($processed>=20) return array_replace($summary,['state'=>'more']);
                if ($this->pending($m['chat_id'])) return array_replace($summary,['state'=>'reconciliation']);
                $this->waitForTarget($m['chat_id']);
                $this->checkContext($m);
                if ($this->plan($m,$row,$date)!==$plan) { $summary['skipped']++; continue; }
                $this->delivery=['scope'=>$m,'row'=>$row,'date'=>$date,'plan'=>$plan];
                $this->prepared=false;$this->sent=false;
                $fixture=$this->fixture ? function($method,$path,$headers,$body,$timeout):array {
                    $this->assertHttpRequest($method,'https://platform-api2.max.ru'.$path,$body);
                    return ($this->httpFixture)($method,$path,$headers,$body,$timeout);
                } : null;
                $native=new MessageTransport($fixture,null,null,$this);
                $transport=static function($provider,$operation,$config,$secret,$target,$raw,$id,$groups) use($native): array {
                    if (!in_array($target,$groups,true)) throw new RuntimeException('RESERVATION_MAX_GROUP_CHANGED');
                    return $native->execute($provider,$operation,$config,$secret,$target,$raw,$id,[$target]);
                };
                $attempt=(new Messaging($this->db,$this->store ?? Config::store(),$transport))->dispatch(
                    'max',$plan['actor_id'],$plan['request_key'],'send',[$m['group_id']],$plan['text']);
                $status=$attempt[0]['status'] ?? '';
                if (!in_array($status,['accepted','failed','uncertain'],true)) throw new RuntimeException('RESERVATION_MAX_RESULT_INVALID');
                $summary[$status]++;
                $processed++;
                if ($status==='uncertain') return array_replace($summary,['state'=>'reconciliation']);
            }
            return $summary;
        } finally {
            $this->delivery=null;
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
        }
    }

    private function checkContext(array $scope): void
    {
        if ($this->settings()!==$scope) throw new RuntimeException('RESERVATION_MAX_SCOPE_CHANGED');
        ServerWorkflowMax::assertProfile($this->db,$scope);
    }

    private function pending(string $target): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
            AND (status='pending' OR (status='uncertain' AND (target=? OR request_key LIKE 'reservation-max-%'
                OR request_key LIKE 'contract-workflow-%'))) LIMIT 1");
        $q->execute([$target]);
        return (bool)$q->fetchColumn();
    }

    private function waitForTarget(string $target): void
    {
        $q=$this->db->prepare("SELECT TIMESTAMPDIFF(MICROSECOND,NOW(6),MAX(created_at)+INTERVAL 1 SECOND)
            FROM messaging_attempts WHERE provider='max' AND target=? AND operation='send'
            AND created_at>DATE_SUB(NOW(6),INTERVAL 1 SECOND)");
        $q->execute([$target]);
        $delay=(int)$q->fetchColumn();
        if ($delay>0) usleep($delay);
    }

    private function read(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);$q->execute($params);
        return $q->fetch() ?: null;
    }

    private function plan(array $scope,array $row,string $date): ?array
    {
        $fact=$this->read('SELECT fact_json FROM rental_facts WHERE id=? AND namespace=? AND source_key=? AND operative=1',
            [$row['id'],BookingNotices::NS,$row['source_key']]);
        if (!$fact) return null;
        $notice=Importer::decodeExact($fact['fact_json']);
        $sha=$notice['sha256']??null;
        unset($notice['sha256']);
        if (($notice['schema']??null)!==1 || !is_string($sha) || !hash_equals($sha,hash('sha256',Importer::canonicalJson($notice)))
            || (int)$row['id']<=$scope['event_floor'] || $notice['epoch']!==$scope['epoch'] || $notice['recorded_at']<$scope['valid_from']
            || $notice['recorded_at']>time() || !BookingNotices::current($this->db,$notice,$date)) return null;
        $id=$notice['reservation_id'];
        $action=$notice['action'];
        if ($action==='reservation.create' && (!is_string($notice['start_date']??null) || !array_key_exists('comment',$notice))
            || $action==='reservation.extend' && !is_string($notice['before_until']??null)) return null;
        // A first run may close overdue pre-admission bookings without sending their backlog.
        if ($action==='reservation.expire'
            && (new \DateTimeImmutable($notice['until']))->modify('+1 day')->format('Y-m-d')<date('Y-m-d',$scope['valid_from'])) return null;
        if ($row['source_key']!==hash('sha256',implode(':',[$notice['epoch'],$action,$id,$notice['version']]))) return null;
        $template=BookingNotices::TEMPLATES[$action][0] ?? '';
        if (!isset($scope['catalog_versions'][$template]) || !$this->provenance($notice)) return null;
        $actor=$notice['actor_id'] ?? $notice['responsible_id'];
        if (!$this->authorizedActor($actor,$notice['auth_version'])) return null;
        $render=TemplateCatalog::renderEvent($this->db,'max',$template,BookingNotices::values($notice));
        if ($render['binding']['version']!==$scope['catalog_versions'][$template] || $render['format']!=='plain')
            throw new RuntimeException('RESERVATION_MAX_TEMPLATE_CHANGED');
        return ['actor_id'=>$actor,'request_key'=>'reservation-max-'.$row['source_key'],'text'=>$render['body'],
            'source_sha256'=>$sha,'template'=>$render['binding']];
    }

    private function provenance(array $notice): bool
    {
        $id=$notice['reservation_id'];
        $action=$notice['action'];
        $audit=$this->read("SELECT user_id,after_json FROM audit_log
            WHERE id=? AND entity_type='reservation' AND entity_id=? AND action=?",[$notice['audit_id'],$id,$action]);
        if (!$audit || ($audit['user_id']===null?null:(int)$audit['user_id'])!==$notice['actor_id']) return false;
        $evidence=Importer::decodeExact($audit['after_json']);
        if (Importer::canonicalJson($evidence)!==Importer::canonicalJson(array_intersect_key($notice,$evidence))) return false;
        if ($notice['actor_id']!==null) {
            $command=$this->read('SELECT result_json FROM rental_commands WHERE command_key=? AND action=?',[$notice['origin'],$action]);
            return $command && (int)(Importer::decodeExact($command['result_json'])['id']??0)===$id;
        }
        if (!in_array($action,['reservation.reminder','reservation.expire'],true)) return false;
        $run=$this->read('SELECT details_json FROM rental_schedule_runs WHERE run_key=? AND action=?',[$notice['origin'],$action]);
        if (!$run) return false;
        $details=Importer::decodeExact($run['details_json']);
        return ($details['id']??null)===$id && ($details['until']??null)===$notice['until']
            && ($details['version']??null)===($action==='reservation.expire'?$notice['version']-1:$notice['version']);
    }

    private function authorizedActor(int $id,?int $version): bool
    {
        $actor=$this->read("SELECT u.id,u.role role_id,u.auth_version,r.grants_configured,rp.kind workflow_role,
            CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END role
            FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=? AND u.active=1",[$id]);
        return $actor && ($version===null || (int)$actor['auth_version']===$version)
            && in_array($actor['role'],['manager','admin'],true)
            && Auth::canFor($actor,'rental.read') && Auth::canFor($actor,'rental.write') && PagePolicy::level('rental.contracts',$actor)>=2;
    }

    public function assertOperation(string $provider,string $operation,string $target,string $raw,array $groups): void
    {
        $delivery=$this->delivery;
        if (!$delivery || $provider!=='max' || $operation!=='send' || $target!==$delivery['scope']['chat_id'] || $groups!==[$target]
            || $raw!==Support::json(['text'=>$delivery['plan']['text']])) throw new RuntimeException('RESERVATION_MAX_REQUEST_REJECTED');
        if (!$this->fixture && $delivery['date']!==date('Y-m-d')) throw new RuntimeException('RESERVATION_MAX_EVENT_CHANGED');
        $this->checkContext($delivery['scope']);
        if ($this->plan($delivery['scope'],$delivery['row'],$delivery['date'])!==$delivery['plan'])
            throw new RuntimeException('RESERVATION_MAX_EVENT_CHANGED');
        $p=$delivery['plan'];
        $claim=$this->read("SELECT COUNT(*) n FROM messaging_attempts WHERE provider='max'
            AND actor_id=? AND request_key=? AND target=? AND revision=? AND status='pending' AND body_text=?",
            [$p['actor_id'],$p['request_key'],$target,$delivery['scope']['revision'],$p['text']]);
        if ((int)($claim['n']??0)!==1) throw new RuntimeException('RESERVATION_MAX_CLAIM_REQUIRED');
    }

    public function beforeRequest(string $method,string $path,?string $body): void
    {
        $this->assertRequest($method,'https://platform-api2.max.ru'.$path,$body);
        $this->prepared=true;
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if (!$this->prepared) throw new RuntimeException('RESERVATION_MAX_HTTP_REJECTED');
        $this->assertRequest($method,$url,$body);
        $this->prepared=false;
        $this->sent=true;
    }

    private function assertRequest(string $method,string $url,?string $body): void
    {
        if (!$this->delivery) throw new RuntimeException('RESERVATION_MAX_HTTP_REJECTED');
        $chat=$this->delivery['scope']['chat_id'];
        ServerWorkflowMax::assertPath((int)$this->sent,$method,$url,$body,$chat,$this->delivery['plan']['text']);
        $this->assertOperation('max','send',$chat,(string)$body,[$chat]);
    }

    public function caFile(): string
    {
        return (new ServerWorkflowMax($this->db))->caFile();
    }
}
