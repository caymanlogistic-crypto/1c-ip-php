<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\{DB, Http};
use Modules\Rental\{BookingNotices, Importer};
use PDO;
use RuntimeException;

final class ReservationMaxRelay
{
    public const INBOX = 'rental_booking_notice_relay';
    public const HANDED_OFF = 'rental_booking_notice_handed_off';
    public const PATH = '/internal/reservation-max';
    private const URL = 'https://ui.89.208.213.14.sslip.io'.self::PATH;
    private const LIMIT = 16384;

    private ?string $request = null;

    public function __construct(private PDO $db) {}

    /** The public route has its own authentication, independent of browser sessions. */
    public static function receive(): never
    {
        if (getenv('APP_STAND') !== 'ui' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Http::json(['error'=>'unavailable'],404);
        }
        header('Cache-Control: no-store');
        $length=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length<1 || $length>self::LIMIT) Http::json(['error'=>'invalid_request'],400);
        $raw=file_get_contents('php://input',false,null,0,self::LIMIT+1);
        if (!is_string($raw) || strlen($raw)!==$length) Http::json(['error'=>'invalid_request'],400);
        try {
            $relay=new self(DB::pdo());
            $scope=$relay->serverScope();
            if (!$scope) Http::json(['error'=>'unavailable'],404);
            $event=self::verifyRequest($raw,(string)($_SERVER['HTTP_X_IPPHP_RELAY_TIME'] ?? ''),
                (string)($_SERVER['HTTP_X_IPPHP_RELAY_SIGNATURE'] ?? ''),$scope['key'],time());
            $key=$relay->enqueue($event,$scope);
            Http::json(['queued'=>$key]);
        } catch (RuntimeException $error) {
            $status=in_array($error->getCode(),[400,403,409],true)?$error->getCode():503;
            Http::json(['error'=>$status===503?'unavailable':'rejected'],$status);
        } catch (\Throwable $error) {
            \App\SafeErrors::record($error);
            Http::json(['error'=>'unavailable'],503);
        }
    }

    public static function signature(string $raw,int $time,string $key): string
    {
        return hash_hmac('sha256',$time."\n".$raw,$key);
    }

    public static function verifyRequest(string $raw,string $time,string $signature,string $key,int $now): array
    {
        if (strlen($raw)>self::LIMIT || !preg_match('/\A[0-9]{10}\z/D',$time) || abs($now-(int)$time)>90
            || !hash_equals(self::signature($raw,(int)$time,$key),$signature)) {
            throw new RuntimeException('RESERVATION_RELAY_SIGNATURE',403);
        }
        try { $event=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('RESERVATION_RELAY_PAYLOAD',400); }
        if (!is_array($event) || !self::validEvent($event)) throw new RuntimeException('RESERVATION_RELAY_PAYLOAD',400);
        return $event;
    }

    private static function validEvent(array $e): bool
    {
        if (array_diff(array_keys($e),['key','action','recorded_at','actor_id','version','values']) || count($e)!==6
            || !is_string($e['key'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$e['key'])
            || !is_string($e['action'] ?? null) || !isset(BookingNotices::TEMPLATES[$e['action']])
            || !is_int($e['recorded_at'] ?? null) || !is_int($e['actor_id'] ?? null) || $e['actor_id']<1
            || !is_int($e['version'] ?? null) || $e['version']<1 || !is_array($e['values'] ?? null)) return false;
        $fields=['number','context','party','manager','effective','reason','actor'];
        if ($e['action']==='reservation.create') array_push($fields,'reservationStart','reservationCommentLine');
        if ($e['action']==='reservation.extend') {
            $fields[]='previousDeadline';
            if (array_key_exists('reservationStart',$e['values'])) $fields[]='reservationStart';
        }
        if (array_diff($fields,array_keys($e['values'])) || count($fields)!==count($e['values'])) return false;
        foreach ($e['values'] as $value) if (!is_string($value) || strlen($value)>1800) return false;
        return preg_match('/\A[1-9][0-9]{0,15}\z/D',$e['values']['number'])===1;
    }

    private function enqueue(array $event,array $scope,bool $example=false): string
    {
        $now=time();
        if ($event['recorded_at']<$scope['started_at'] || $event['recorded_at']>$now+10) {
            throw new RuntimeException('RESERVATION_RELAY_EVENT_TOO_OLD',409);
        }
        $key=hash('sha256',$scope['source'].':'.$event['key']);
        $digest=hash('sha256',Importer::canonicalJson($event));
        $fact=['source'=>$scope['source'],'group_id'=>$scope['group_id'],'event'=>$event,'sha256'=>$digest,
            'epoch'=>$this->db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn(),
            'recorded_at'=>$now,'example'=>$example];
        $this->db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
             ON DUPLICATE KEY UPDATE source_key=rental_facts.source_key')
            ->execute([self::INBOX,$key,Support::json($fact)]);
        $q=$this->db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::INBOX,$key]);
        $saved=Support::decode((string)$q->fetchColumn());
        if (($saved['sha256'] ?? null)!==$digest || ($saved['group_id'] ?? null)!==$scope['group_id']
            || ($saved['example'] ?? false)!==$example) {
            throw new RuntimeException('RESERVATION_RELAY_KEY_CONFLICT',409);
        }
        return $event['key'];
    }

    /** One operator-requested batch from a previously delivered local training reservation. */
    public function queueExamples(string $booking,string $batch): array
    {
        if (PHP_SAPI!=='cli' || !preg_match('/\A[1-9][0-9]{0,15}\z/D',$booking)
            || !preg_match('/\A[a-zA-Z0-9-]{16,80}\z/D',$batch)) throw new RuntimeException('RESERVATION_EXAMPLES_SELECTOR');
        $scope=$this->serverScope();
        if (!$scope) throw new RuntimeException('RESERVATION_EXAMPLES_SCOPE');
        $q=$this->db->prepare("SELECT JSON_EXTRACT(f.fact_json,'$.event') event
            FROM rental_facts f JOIN messaging_attempts a ON a.provider='max'
             AND a.request_key=CONCAT('reservation-max-',f.source_key) AND a.status='accepted'
            WHERE f.namespace=? AND f.operative=1 AND JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.source'))=?
              AND JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.event.values.number'))=?
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.example')),'false')<>'true'");
        $q->execute([self::INBOX,$scope['source'],$booking]);
        $original=[];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            $event=Support::decode($raw);
            if (!self::validEvent($event) || !str_starts_with($event['values']['party'],'Учебная проверка')
                || !str_starts_with($event['values']['context'],'Учебное')) throw new RuntimeException('RESERVATION_EXAMPLES_NOT_TRAINING');
            if (isset($original[$event['action']])) throw new RuntimeException('RESERVATION_EXAMPLES_MULTIPLE_EVENTS');
            $original[$event['action']]=$event;
        }
        if (count($original)!==3 || !isset($original['reservation.create'],$original['reservation.extend'],$original['reservation.cancel']))
            throw new RuntimeException('RESERVATION_EXAMPLES_SOURCE_MISSING');
        $original['reservation.extend']['values']['reservationStart']=$original['reservation.create']['values']['reservationStart'];
        $last=$original['reservation.extend'];
        unset($last['values']['previousDeadline'],$last['values']['reservationStart']);
        $results=[];
        $now=time();
        $this->db->beginTransaction();
        try {
            foreach (array_keys(BookingNotices::TEMPLATES) as $action) {
                $event=$original[$action] ?? $last;
                $event['action']=$action;
                $event['key']=hash('sha256','reservation-example:'.$booking.':'.$batch.':'.$action);
                $event['recorded_at']=$now;
                if (!self::validEvent($event)) throw new RuntimeException('RESERVATION_EXAMPLES_FIELDS');
                $key=hash('sha256',$scope['source'].':'.$event['key']);
                $receipt=$this->db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=?');
                $receipt->execute([self::INBOX,$key]);
                $previous=$receipt->fetchColumn();
                if (is_string($previous)) $event['recorded_at']=Support::decode($previous)['event']['recorded_at'];
                $results[$action]=$this->enqueue($event,$scope,true);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $results;
    }

    public function plan(array $fact,array $max): ?array
    {
        $binding=$this->serverScope();
        $e=$fact['event'] ?? null;
        if (!$binding || $max['group_id']!==$binding['group_id'] || $max['group_name']!=='ТЕСТОВАЯ'
            || ($fact['source'] ?? null)!==$binding['source'] || ($fact['group_id'] ?? null)!==$binding['group_id']
            || ($fact['epoch'] ?? null)!==$max['epoch'] || ($fact['recorded_at'] ?? 0)<$max['valid_from']
            || !is_array($e) || !self::validEvent($e) || $e['recorded_at']<$binding['started_at']
            || ($fact['sha256'] ?? null)!==hash('sha256',Importer::canonicalJson($e))) return null;
        if ($e['action']==='reservation.extend' && !isset($e['values']['reservationStart'])) {
            $q=$this->db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.event.values.reservationStart'))
                FROM rental_facts WHERE namespace=? AND operative=1
                AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.source'))=?
                AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.event.values.number'))=?
                AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.event.action'))='reservation.create'
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.example')),'false')<>'true'
                ORDER BY id DESC LIMIT 1");
            $q->execute([self::INBOX,$fact['source'],$e['values']['number']]);
            if (is_string($start=$q->fetchColumn())) $e['values']['reservationStart']=$start;
        }
        if ($e['action']==='reservation.reminder' && ($fact['example'] ?? false)!==true) {
            if ($e['values']['effective']!==date('d.m.Y',strtotime('+1 day'))) return null;
            $q=$this->db->prepare("SELECT 1 FROM rental_facts WHERE namespace=? AND operative=1
                AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.source'))=?
                AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.event.values.number'))=?
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.event.version')) AS UNSIGNED)>? LIMIT 1");
            $q->execute([self::INBOX,$fact['source'],$e['values']['number'],$e['version']]);
            if ($q->fetchColumn()) return null;
        }
        return $e;
    }

    private function serverScope(): ?array
    {
        if (getenv('APP_STAND')!=='ui' || $this->db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') return null;
        $scope=self::scope('reservation-max-relay-server.json');
        if (!$scope) return null;
        $path=APP_ROOT.'/.local/reservation-max-admission.json';
        if (!is_file($path) || is_link($path)) return null;
        $max=Support::decode((string)file_get_contents($path));
        if (($max['group_name'] ?? null)!=='ТЕСТОВАЯ' || ($max['group_id'] ?? null)!==$scope['group_id']
            || ($max['enabled'] ?? null)!==true || ($max['expires_at'] ?? 0)<=time()) return null;
        return $scope;
    }

    private static function scope(string $file): ?array
    {
        $path=APP_ROOT.'/.local/'.$file;
        if (!is_file($path)) return null;
        if (is_link($path) || filesize($path)>2048) throw new RuntimeException('RESERVATION_RELAY_SCOPE');
        $s=Support::decode((string)file_get_contents($path));
        foreach (['source','group_id'] as $key) {
            if (!is_string($s[$key] ?? null) || !preg_match('/\A[a-f0-9-]{36}\z/D',$s[$key])) throw new RuntimeException('RESERVATION_RELAY_SCOPE');
        }
        if (!is_int($s['started_at'] ?? null) || $s['started_at']<1 || $s['started_at']>time()
            || !is_string($s['key'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$s['key'])) throw new RuntimeException('RESERVATION_RELAY_SCOPE');
        return $s;
    }

    public function forward(string $date): array
    {
        if (PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Windows' || getenv('APP_STAND') || Config::liveAllowed()
            || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) return ['queued'=>0];
        $scope=self::scope('reservation-max-relay-local.json');
        if (!$scope) return ['queued'=>0];
        $name=$scope['database'] ?? '';
        $actual=$this->db->query('SELECT DATABASE(),CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        $root=realpath((string)($scope['root'] ?? ''));
        if (!$root || strcasecmp(str_replace('\\','/',$root),str_replace('\\','/',APP_ROOT))!==0
            || !preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',(string)$name) || $actual!==[$name,$name.'@127.0.0.1']
            || !is_int($scope['event_floor'] ?? null) || $scope['event_floor']<0) throw new RuntimeException('RESERVATION_RELAY_LOCAL_BINDING');
        $actorMap=$scope['actor_map'] ?? null;
        if (!is_array($actorMap) || !$actorMap) throw new RuntimeException('RESERVATION_RELAY_ACTOR_BINDING');
        foreach ($actorMap as $localActor=>$serverActor) {
            if (!is_int($localActor) || $localActor<1 || !is_int($serverActor) || $serverActor<1)
                throw new RuntimeException('RESERVATION_RELAY_ACTOR_BINDING');
        }
        $count=0;
        foreach ((new BookingMax($this->db))->exports($scope['event_floor'],$scope['started_at'],$date,array_keys($actorMap)) as $event) {
            $event['actor_id']=$actorMap[$event['actor_id']];
            if (!self::validEvent($event)) throw new RuntimeException('RESERVATION_RELAY_LOCAL_EVENT');
            $json=Support::json($event);
            $time=time();
            $this->request=$json;
            try {
                $reply=HttpTransport::request('POST',self::URL,['Content-Type: application/json',
                    'X-IpPhp-Relay-Time: '.$time,'X-IpPhp-Relay-Signature: '.self::signature($json,$time,$scope['key'])],
                    $json,1024,15,null,null,$this);
            } finally { $this->request=null; }
            if ($reply['status']!==200 || (Support::decode($reply['raw'])['queued'] ?? null)!==$event['key'])
                throw new RuntimeException('RESERVATION_RELAY_HANDOFF_FAILED');
            $this->db->prepare('INSERT IGNORE INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
                ->execute([self::HANDED_OFF,$event['key'],Support::json(['confirmed_at'=>time()])]);
            $count++;
        }
        return ['queued'=>$count];
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if ($method!=='POST' || $url!==self::URL || $this->request===null || $this->request!==$body)
            throw new RuntimeException('RESERVATION_RELAY_HTTP_REJECTED');
    }
}
