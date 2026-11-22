<?php
declare(strict_types=1);
namespace Modules\Integrations;

use Modules\Rental\{BookingNotices, Importer, Service};
use PDO;
use RuntimeException;

/** Uses the existing message journal, sealed spools and refusal/uncertainty handling. */
final class BookingMax
{
    public function __construct(private PDO $db,private PrivateStore $store) {}

    public function preview(string $key,string $date): ?array
    {
        Service::date($date);
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) throw new RuntimeException('BOOKING_NOTICE_KEY_INVALID');
        $q=$this->db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([BookingNotices::NS,$key]);$json=$q->fetchColumn();
        if (!$json) return null;
        $notice=Importer::decodeExact($json);
        if (!BookingNotices::current($this->db,$notice,$date)) return null;
        $template=BookingNotices::TEMPLATES[$notice['action']][0] ?? null;
        if (!$template) return null;
        return TemplateCatalog::renderEvent($this->db,'max',$template,BookingNotices::values($notice))
            + ['responsible_id'=>(int)$notice['responsible_id']];
    }

    /**
     * The current MAX admissions authorize other types of events. Only a supplied fixture
     * reaches the transport in this candidate, and only from an isolated CLI schema.
     */
    public function dispatchLocal(string $key,string $date,int $actor,string $group,int $profileVersion,int $templateVersion,callable $fixture): array
    {
        if (PHP_SAPI!=='cli' || getenv('APP_RESERVATION_MAX_TEST')!=='1' || getenv('APP_STAND') || Config::liveAllowed()
            || !preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',(string)$this->db->query('SELECT DATABASE()')->fetchColumn())
            || $this->db->inTransaction()) throw new RuntimeException('RESERVATION_MAX_LOCAL_FIXTURE_REQUIRED');
        if (!(int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn())
            throw new RuntimeException('MAX_TARGET_BUSY');
        try {
            $request='reservation-max-'.$key;
            $q=$this->db->prepare("SELECT * FROM messaging_attempts WHERE provider='max' AND request_key=?");
            $q->execute([$request]);
            if ($rows=$q->fetchAll()) {
                if ((int)$rows[0]['actor_id']!==$actor) throw new RuntimeException('RESERVATION_MAX_SCOPE_CHANGED');
                return $rows;
            }
            $render=$this->preview($key,$date);
            if (!$render) return [];
            $c=Config::get($this->db,'max');
            if ($actor<=0 || $render['responsible_id']!==$actor || !$c['enabled'] || (int)$c['revision']!==$profileVersion || $render['binding']['version']!==$templateVersion
                || $render['format']!=='plain') throw new RuntimeException('RESERVATION_MAX_SCOPE_CHANGED');
            $q=$this->db->prepare("SELECT chat_id FROM messaging_groups WHERE id=? AND provider='max' AND enabled=1");
            $q->execute([$group]);$target=$q->fetchColumn();
            if ($target===false) throw new RuntimeException('GROUP_DISABLED_OR_UNKNOWN');
            $q=$this->db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
                AND target=? AND status IN ('pending','uncertain') LIMIT 1");
            $q->execute([$target]);
            if ($q->fetchColumn()) throw new RuntimeException('MAX_PENDING_OR_UNCERTAIN_REQUIRES_RECONCILIATION');
            $transport=static function(string $provider,string $operation,array $config,array $secret,string $chat,string $raw) use($target,$fixture,$render): array {
                if ($provider!=='max' || $operation!=='send' || $chat!==(string)$target || (Support::decode($raw)['text'] ?? null)!==$render['body'])
                    throw new RuntimeException('RESERVATION_MAX_SCOPE_CHANGED');
                return $fixture($raw);
            };
            return (new Messaging($this->db,$this->store,$transport))->dispatch('max',$actor,$request,'send',[$group],$render['body']);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
        }
    }
}
