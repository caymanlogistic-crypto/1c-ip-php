<?php
declare(strict_types=1);
namespace Modules\Integrations;

use App\{Auth,PagePolicy};
use Modules\Rental\ObjectActivity;
use PDO;
use RuntimeException;

/** A fresh object-save POST, one committed transition, one configured TEST group. No scan/replay. */
final class ObjectActivityMax
{
    public function __construct(private PDO $db) {}

    public static function requestKey(array $event): string
    {
        return 'object-activity-'.hash('sha256',$event['command_key'].':'.$event['event_id']);
    }

    public function plan(int $eventId,string $commandKey,array $actor): ?array
    {
        $q=$this->db->prepare('SELECT * FROM rental_events WHERE id=?');$q->execute([$eventId]);$row=$q->fetch();
        $event=$row?ObjectActivity::decode($row):null;
        if ($event===null) return null;
        if ($this->db->inTransaction()) throw new RuntimeException('Object MAX requires commit.');
        $current=Auth::user();
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !$current || (int)$current['id']!==(int)($actor['id'] ?? 0)
            || $event['command_key']!==$commandKey || $event['actor_id']!==(int)$current['id']
            || $event['auth_version']!==(int)$current['auth_version'] || !in_array($current['role'],['manager','admin'],true)
            || !Auth::can('rental.read') || !Auth::can('rental.write') || PagePolicy::level('rental.objects',$current)<2)
            throw new RuntimeException('Object MAX actor not authorized.');
        $q=$this->db->prepare('SELECT * FROM rental_commands WHERE command_key=?');$q->execute([$commandKey]);$command=$q->fetch();
        if (!$command || $command['action']!=='object.save' || !hash_equals($event['command_fingerprint'],$command['fingerprint'])
            || !hash_equals($event['command_result_sha256'],hash('sha256',$command['result_json'])))
            throw new RuntimeException('Object MAX command proof changed.');
        $q=$this->db->prepare('SELECT version,active FROM rental_objects WHERE id=?');$q->execute([$event['object_id']]);$object=$q->fetch();
        if (!$object || (int)$object['version']!==$event['object_version'] || (int)$object['active']!==$event['to_active'])
            throw new RuntimeException('Object MAX state changed.');
        return $event;
    }

    public function afterCommittedCommand(int $eventId,string $commandKey,array $actor): ?array
    {
        $event=$this->plan($eventId,$commandKey,$actor);
        if ($event===null) return null;
        $groups=$this->db->query("SELECT id FROM messaging_groups WHERE provider='max' AND enabled=1 AND name='ТЕСТОВАЯ'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($groups)!==1) throw new RuntimeException('Object MAX needs one TEST group.');
        $key=self::requestKey($event);
        if (ServerObjectMax::settings()!==null) {
            $attempts=(new ServerObjectMax($this->db))->dispatch($event);
        } elseif (LocalMaxReadCheck::configured()) {
            $attempts=(new LocalMaxReadCheck($this->db))->dispatchObjectActivity($event,$groups);
        } else {
            if (!Config::liveAllowed()) return ['status'=>'unavailable'];
            $attempts=(new Messaging($this->db,Config::store()))->dispatch('max',$event['actor_id'],$key,'send',$groups,$event['text']);
        }
        $attempt=$attempts[0] ?? [];
        return ['status'=>$attempt['status'] ?? 'uncertain','code'=>$attempt['result_code'] ?? 'NO_RECEIPT'];
    }
}
