<?php
declare(strict_types=1);

namespace Modules\Integrations;

use Modules\Rental\WorkflowEvents;
use PDO;
use RuntimeException;

/** Selects a destination for native ERP events. Relayed and training events keep their own admissions. */
final class MaxWorkingDestination
{
    public const TEST = 'test';
    public const WORK = 'work';
    private const ACTION = 'integration.max.working_destination';
    private const MARKER = 'max-working-destination.json';
    private const LOCAL_MARKER = 'max-working-destination-local.json';

    private static function runtime(PDO $db): bool
    {
        $name = $db->query('SELECT DATABASE()')->fetchColumn();
        return PHP_OS_FAMILY==='Linux' && getenv('APP_STAND')==='ui' && $name==='ipphp_ui'
            || !getenv('APP_STAND') && $name==='ipphp_task_max_destination'
                && getenv('APP_MAX_WORKING_DESTINATION_FIXTURE')==='max-destination-fixture';
    }

    private static function history(PDO $db,?string $floor=null,?int $id=null): ?array
    {
        if ($floor!==null && !in_array($floor,['event_floor','fact_floor'],true)) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_INVALID');
        }
        $where=$floor===null?'':" AND CAST(JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.".$floor."')) AS UNSIGNED)<?";
        $q=$db->prepare("SELECT after_json FROM audit_log WHERE action=? AND entity_type='max' AND entity_id='working-destination'".$where
            .' ORDER BY id DESC LIMIT 1');
        $q->execute($floor===null?[self::ACTION]:[self::ACTION,$id]);
        $json=$q->fetchColumn();
        return $json===false?null:Support::decode($json);
    }

    public static function selection(PDO $db): array
    {
        return self::history($db)??['destination'=>self::TEST,'serial'=>0];
    }

    /** Bind the alternative to the unchanged TEST bot and one independently admitted group. */
    public static function admission(PDO $db,array $base): ?array
    {
        if (!self::runtime($db)) return null;
        $path=APP_ROOT.'/.local/'.self::MARKER;
        if (!is_file($path)) return null;
        if (is_link($path) || filesize($path)>2048) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        $m=Support::decode(file_get_contents($path));
        $keys=['schema','scope','project_id','database','revision','test_group_id','test_chat_id',
            'group_id','group_name','chat_id','vault_sha256','membership_verified_at','relay_sources'];
        if (count($m)!==count($keys) || array_diff($keys,array_keys($m))
            || ($m['schema']??null)!==1 || $m['scope']!=='native-max-working-destination'
            || $m['project_id']!==WorkflowEvents::PROJECT || $m['database']!==$base['database']
            || $m['revision']!==$base['revision'] || $m['vault_sha256']!==$base['vault_sha256']
            || $m['test_group_id']!==$base['group_id'] || $m['test_chat_id']!==$base['chat_id']
            || !ContractWorkflowRelay::isUuid($m['group_id']) || $m['group_id']===$base['group_id']
            || !is_string($m['chat_id']) || $m['chat_id']===$base['chat_id']
            || !is_string($m['group_name']) || trim($m['group_name'])==='' || $m['group_name']===$base['group_name']
            || !is_array($m['relay_sources']) || array_diff(array_keys($m['relay_sources']),['contract','reservation'])
            || !is_int($m['membership_verified_at']) || $m['membership_verified_at']<1 || $m['membership_verified_at']>time()) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        }
        foreach ($m['relay_sources'] as $source) {
            if (!ContractWorkflowRelay::isUuid($source)) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        }
        Support::chatId($m['chat_id']);
        ServerWorkflowMax::assertProfile($db,$base);
        $q=$db->prepare("SELECT name,chat_id,enabled FROM messaging_groups WHERE provider='max' AND id=?");
        $q->execute([$m['group_id']]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['name']!==$m['group_name'] || $row['chat_id']!==$m['chat_id']) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_GROUP_CHANGED');
        }
        return $m+['group_enabled'=>(bool)$row['enabled']];
    }

    public static function route(PDO $db,array $base,int $id,bool $booking=false): array
    {
        // Do not look up or apply server choices in a copied local database.
        if (!self::runtime($db)) return self::original($base);
        $choice=self::history($db,$booking?'fact_floor':'event_floor',$id);
        if (($choice['destination']??self::TEST)===self::TEST) return self::original($base);
        $admission=self::admission($db,$base);
        if (!$admission || !$admission['group_enabled']
            || ($choice['group_id']??null)!==$admission['group_id'] || ($choice['chat_id']??null)!==$admission['chat_id']) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        }
        return self::original($admission);
    }

    public static function relayed(PDO $db,array $base,string $kind,string $source,bool $work): array
    {
        if (!$work) return self::original($base);
        $admission=self::admission($db,$base);
        if (!$admission || !$admission['group_enabled'] || ($admission['relay_sources'][$kind]??null)!==$source) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_RELAY_NOT_ADMITTED',403);
        }
        return self::original($admission);
    }

    public static function localChoice(PDO $db,string $kind,string $source,int $id): bool
    {
        if (PHP_OS_FAMILY!=='Windows' || getenv('APP_STAND')) return false;
        $choice=self::history($db,$kind==='reservation'?'fact_floor':'event_floor',$id);
        if (($choice['destination']??self::TEST)!==self::WORK || ($choice['relay_sources'][$kind]??null)!==$source) return false;
        $m=self::localSettings($db);
        if (!$m || ($m['relay_sources'][$kind]??null)!==$source || ($choice['chat_id']??null)!==$m['chat_id']) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        }
        return true;
    }

    private static function localSettings(PDO $db): ?array
    {
        if (PHP_OS_FAMILY!=='Windows' || getenv('APP_STAND')) return null;
        $path=APP_ROOT.'/.local/'.self::LOCAL_MARKER;
        if (!is_file($path)) return null;
        if (is_link($path) || filesize($path)>2048) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        $m=Support::decode(file_get_contents($path));
        $name=$db->query('SELECT DATABASE()')->fetchColumn();
        $keys=['schema','scope','root','database','group_name','chat_id','relay_sources'];
        if (count($m)!==count($keys) || array_diff($keys,array_keys($m)) || $m['schema']!==1
            || $m['scope']!=='local-relay-max-working-destination' || $m['database']!==$name
            || !is_string($name) || !preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/D',$name)
            || !is_string($m['root']) || strcasecmp(str_replace('\\','/',(string)realpath($m['root'])),str_replace('\\','/',APP_ROOT))!==0
            || !is_string($m['group_name']) || trim($m['group_name'])===''
            || !is_string($m['chat_id']) || !is_array($m['relay_sources']) || !$m['relay_sources']
            || array_diff(array_keys($m['relay_sources']),['contract','reservation'])) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        }
        Support::chatId($m['chat_id']);
        foreach ($m['relay_sources'] as $kind=>$source) {
            $file=$kind==='contract'?'contract-workflow-relay-local.json':'reservation-max-relay-local.json';
            $binding=APP_ROOT.'/.local/'.$file;
            if (!ContractWorkflowRelay::isUuid($source) || !is_file($binding) || is_link($binding)
                || (Support::decode(file_get_contents($binding))['source']??null)!==$source) {
                throw new RuntimeException('MAX_WORKING_DESTINATION_RELAY_NOT_ADMITTED');
            }
        }
        return $m+['via_relay'=>true];
    }

    public static function original(array $scope): array
    {
        return array_intersect_key($scope,['group_id'=>true,'group_name'=>true,'chat_id'=>true]);
    }

    /** Each existing native send still receives a singleton allowlist before the HTTP guard. */
    public static function recipientSecret(array $secret,string $target,array $route,array $groups): array
    {
        if ($target!==$route['chat_id'] || !in_array($target,$groups,true)) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_GROUP_CHANGED');
        }
        $secret['approved_chat_ids']=[$target];
        return $secret;
    }

    public static function change(PDO $db,array $base,string $destination,int $expected,int $actor): void
    {
        $local=self::localSettings($db);
        if (!in_array($destination,[self::TEST,self::WORK],true) || (!$local && !self::runtime($db)) || $db->inTransaction()) {
            throw new RuntimeException('Выбор группы недоступен в этой копии ERP.',403);
        }
        if ((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) {
            throw new RuntimeException('MAX сейчас обрабатывает отправку. Повторите переключение.',409);
        }
        try {
            $db->beginTransaction();
            $db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $before=self::selection($db);
            if ($before['serial']!==$expected) throw new RuntimeException('Выбор группы изменился. Откройте настройки заново.',409);
            if ($before['destination']===$destination) {$db->commit(); return;}
            if (!$local) ServerWorkflowMax::assertProfile($db,$base);
            if ($db->query("SELECT 1 FROM messaging_attempts WHERE provider='max' AND operation='send'
                AND status IN ('pending','uncertain') LIMIT 1")->fetchColumn()) {
                throw new RuntimeException('Сверьте отправки MAX с неподтверждённым результатом перед переключением.',409);
            }
            $group=$local?($destination===self::WORK?$local:['group_name'=>'ТЕСТОВАЯ'])
                :($destination===self::WORK?self::admission($db,$base):$base);
            if (!$group) throw new RuntimeException('Сначала подтвердите допуск рабочего чата MAX.',409);
            if ($destination===self::WORK && !$local) {
                $db->prepare("UPDATE messaging_groups SET enabled=1 WHERE provider='max' AND id=?")->execute([$group['group_id']]);
            }
            $after=['destination'=>$destination,'serial'=>$expected+1] + self::original($group);
            if ($local) $after['relay_sources']=$local['relay_sources'];
            $after['event_floor']=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();
            $after['fact_floor']=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM rental_facts')->fetchColumn();
            $db->prepare("INSERT INTO audit_log(user_id,action,entity_type,entity_id,before_json,after_json)
                VALUES(?,?,'max','working-destination',?,?)")->execute([$actor,self::ACTION,Support::json($before),Support::json($after)]);
            $db->commit();
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
        }
    }

    public static function settings(PDO $db): ?array
    {
        if (!self::runtime($db)) return self::localSettings($db);
        if (getenv('APP_MAX_WORKING_DESTINATION_FIXTURE')==='max-destination-fixture' && !getenv('APP_STAND')) {
            $path=APP_ROOT.'/.local/workflow-max-destination-fixture.json';
            return is_file($path)?Support::decode(file_get_contents($path)):null;
        }
        return ServerWorkflowMax::settings();
    }

    /** Operator preparation admits the second registry row without selecting it or sending. */
    public static function prepareGroup(PDO $db,array $base,bool $apply): array
    {
        if (!self::runtime($db) || $db->inTransaction()) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
        if ((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) {
            throw new RuntimeException('MAX_WORKING_DESTINATION_BUSY');
        }
        try {
            $group=self::admission($db,$base);
            if (!$group) throw new RuntimeException('MAX_WORKING_DESTINATION_ADMISSION');
            if ($apply && !$group['group_enabled']) {
                $q=$db->prepare("UPDATE messaging_groups SET enabled=1 WHERE provider='max' AND id=? AND chat_id=? AND name=?");
                $q->execute([$group['group_id'],$group['chat_id'],$group['group_name']]);
                if ($q->rowCount()!==1) throw new RuntimeException('MAX_WORKING_DESTINATION_GROUP_CHANGED');
            }
            return ['group_name'=>$group['group_name'],'available'=>$group['group_enabled']||$apply,
                'destination'=>self::selection($db)['destination'],'applied'=>$apply];
        } finally {
            $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')")->fetchColumn();
        }
    }
}
