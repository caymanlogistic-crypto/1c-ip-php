<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\DB;
use PDO;
use RuntimeException;

/** File publication reconciled against the existing command receipt; never reruns an unknown command. */
final class ZeroPointRevision
{
    private const COMMANDS=['zero-point.correct'=>ZeroPointCorrection::class,'zero-point.continuity'=>ZeroPointContinuity::class,'zero-point.clarify'=>ZeroPointClarification::class,'zero-point.parties'=>ZeroPointPartyEnrichment::class];
    private static function access(): void
    {
        if (PHP_SAPI!=='cli') throw new RuntimeException('Ревизия доступна только CLI.',403);
        ZeroPoint::requireAdmin();
    }

    public static function apply(string $action,array $plan): array
    {
        self::access();$class=self::COMMANDS[$action] ?? throw new RuntimeException('Unknown correction command');
        $dir=ZeroPoint::directory();$lock=fopen($dir.'/revision.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Revision lock unavailable');
        try {
            if (is_file($dir.'/revision-pending.json')) throw new RuntimeException('Сначала выполните recover.',409);
            $pin=json_decode(file_get_contents($dir.'/installed.json'),true,512,JSON_THROW_ON_ERROR);
            if (($pin['sha256'] ?? '')===$plan['next_sha256']) return Service::command($action,$plan);
            if (($pin['sha256'] ?? '')!==$plan['expected_baseline'] || ZeroPoint::digest($plan['document'])!==$plan['next_sha256'])
                throw new RuntimeException('Изменилась ревизия или план.',409);
            if (ZeroPoint::json($class::prepare($plan['evidence']))!==ZeroPoint::json($plan))
                throw new RuntimeException('План не соответствует источнику или текущим данным.',409);
            ZeroPoint::validate(DB::pdo(),$plan['document']);
            ZeroPoint::write($dir.'/'.$plan['next_sha256'].'.json',$plan['document']);
            ZeroPoint::write($dir.'/revisions/'.$plan['expected_baseline'].'.pin.json',$pin);
            $next=['sha256'=>$plan['next_sha256'],'as_of'=>$plan['document']['as_of'],'project_id'=>ZeroPoint::PROJECT,'previous_sha256'=>$plan['expected_baseline']];
            ZeroPoint::write($dir.'/revision-pending.json',['action'=>$action,'command_key'=>$plan['command_key'],'old'=>$pin,'next'=>$next]);
            $result=Service::command($action,$plan);
            self::finish();return $result;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function assertPending(string $action,array $plan): void
    {
        self::access();$file=ZeroPoint::directory().'/revision-pending.json';
        $pending=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
        if (($pending['action'] ?? 'zero-point.correct')!==$action || ($pending['command_key'] ?? '')!==$plan['command_key'])
            throw new RuntimeException('Нет подготовленной ревизии.',409);
    }

    private static function finish(): array
    {
        $dir=ZeroPoint::directory();$pending=json_decode(file_get_contents($dir.'/revision-pending.json'),true,512,JSON_THROW_ON_ERROR);
        $action=$pending['action'] ?? 'zero-point.correct';
        if (!isset(self::COMMANDS[$action])) throw new RuntimeException('Invalid revision command');
        $receipt=DB::transaction(static function(PDO $db)use($pending,$action) {
            $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $q=$db->prepare('SELECT result_json FROM rental_commands WHERE command_key=? AND action=?');$q->execute([$pending['command_key'],$action]);
            return $q->fetchColumn();
        });
        $pin=$receipt?$pending['next']:$pending['old'];
        if ($receipt && (json_decode($receipt,true)['sha256'] ?? '')!==$pin['sha256']) throw new RuntimeException('Не совпала квитанция команды.',409);
        $file=$dir.'/'.$pin['sha256'].'.json';
        if (!is_file($file) || !hash_equals($pin['sha256'],hash_file('sha256',$file))) throw new RuntimeException('Повреждена ревизия.',409);
        $tmp=$dir.'/pin-'.bin2hex(random_bytes(8)).'.json';ZeroPoint::write($tmp,$pin);
        if (!rename($tmp,$dir.'/installed.json') || !unlink($dir.'/revision-pending.json')) throw new RuntimeException('Не завершена публикация ревизии.');
        return ['committed'=>(bool)$receipt,'sha256'=>$pin['sha256']];
    }

    public static function recover(): array
    {
        self::access();$lock=fopen(ZeroPoint::directory().'/revision.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Revision lock unavailable');
        try {return self::finish();} finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
