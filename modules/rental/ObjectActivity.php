<?php
declare(strict_types=1);
namespace Modules\Rental;

use PDO;
use RuntimeException;

/** Provenance of an actual object state change, on the existing command/event rows. */
final class ObjectActivity
{
    public static function before(PDO $db,string $action,array $input): ?array
    {
        if ($action!=='object.save' || empty($input['id'])) return null;
        return ['object'=>Service::get($db,'rental_objects',(int)$input['id']),
            'floor'=>(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn()];
    }

    public static function record(PDO $db,array $actor,array $input,array $result,string $key,array $context): ?int
    {
        $before=$context['object'];$after=Service::get($db,'rental_objects',(int)$result['id']);
        if ((int)$before['active']===(int)$after['active']) return null;
        if (!$db->inTransaction()) throw new RuntimeException('Object event must share the command transaction.');
        $q=$db->prepare("SELECT id FROM rental_events WHERE id>? AND actor_id=? AND action='object.save' AND contract_id IS NULL ORDER BY id");
        $q->execute([$context['floor'],$actor['id']]);$events=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($events)!==1) throw new RuntimeException('Object command must bind one event.');
        $q=$db->prepare('SELECT fingerprint,result_json FROM rental_commands WHERE command_key=?');$q->execute([$key]);$command=$q->fetch();
        if (!$command) throw new RuntimeException('Object command receipt missing.');
        $date=Service::date((string)$input['active_on']);
        $text='Объект аренды «'.$after['name'].'»: '.($after['active']?'возвращён в активные':'переведён в неактивные')
            .'. Дата изменения: '.Documents::dateText($date).'. Изменил: '.$actor['name'].'.';
        $body=['schema'=>1,'kind'=>'committed-object-activity','project_id'=>WorkflowEvents::PROJECT,
            'event_id'=>(int)$events[0],'command_key'=>$key,'command_fingerprint'=>$command['fingerprint'],
            'command_result_sha256'=>hash('sha256',$command['result_json']),'object_id'=>(int)$after['id'],
            'object_name'=>(string)$after['name'],'object_version'=>(int)$after['version'],
            'from_active'=>(int)$before['active'],'to_active'=>(int)$after['active'],'changed_on'=>$date,
            'actor_id'=>(int)$actor['id'],'auth_version'=>(int)$actor['auth_version'],'text'=>$text];
        $body['sha256']=hash('sha256',Importer::canonicalJson($body));
        $q=$db->prepare('UPDATE rental_events SET description=?,source_json=? WHERE id=? AND source_json IS NULL');
        $q->execute([$text,Importer::json($body),$body['event_id']]);
        if ($q->rowCount()!==1) throw new RuntimeException('Object event already changed.');
        return $body['event_id'];
    }

    public static function decode(array $row): ?array
    {
        $body=json_decode($row['source_json'] ?? 'null',true,32,JSON_THROW_ON_ERROR);
        if (($body['kind'] ?? null)!=='committed-object-activity') return null;
        $sha=$body['sha256'] ?? '';unset($body['sha256']);
        if (($body['schema'] ?? null)!==1 || ($body['project_id'] ?? null)!==WorkflowEvents::PROJECT
            || ($body['event_id'] ?? null)!==(int)$row['id'] || !is_string($sha)
            || !hash_equals($sha,hash('sha256',Importer::canonicalJson($body)))
            || !in_array($body['from_active'] ?? null,[0,1],true) || !in_array($body['to_active'] ?? null,[0,1],true)
            || $body['from_active']===$body['to_active'] || ($row['action'] ?? '')!=='object.save'
            || (int)$row['actor_id']!==$body['actor_id'] || $row['contract_id']!==null)
            throw new RuntimeException('Object activity proof changed.');
        return $body+['sha256'=>$sha];
    }
}
