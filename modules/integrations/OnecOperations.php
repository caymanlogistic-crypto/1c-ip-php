<?php
declare(strict_types=1);
namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** Shared application entry point. Callers supply business identities, never OData bodies. */
final class OnecOperations
{
    public const CONNECTIONS = [
        'onec-counterparty'=>['title'=>'1С: Внесение контрагента','kind'=>'counterparty','description'=>'Передача контрагента из согласованного документа в 1С'],
        'onec-invoice'=>['title'=>'1С: Внесение счёта','kind'=>'customer_invoice','description'=>'Создание счёта на оплату покупателю в 1С'],
        'onec-print'=>['title'=>'1С: Формирование счёта','kind'=>'print','description'=>'Печатный файл счёта по согласованному образцу'],
    ];

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public static function definition(string $connection): array
    {
        return self::CONNECTIONS[$connection] ?? throw new RuntimeException('ONEC_OPERATION_NOT_FOUND',404);
    }

    public function authorize(string $connection, int $actor, string $action): void
    {
        self::definition($connection);
        // Business callers use the existing packet/finance authority, independently
        // of permission to open or configure technical connection pages.
        if (!in_array($action,['read','check','settings'],true)) {
            try{(new OnecOwnerFacade($this->db,$this->store))->actor($actor,match($action){'preview','prepare'=>'prepare','dispatch'=>'create','reconcile'=>'reconcile',default=>'read_existing'});}
            catch(RuntimeException $e){if($e->getMessage()==='ONEC_ACTOR_DENIED')throw new RuntimeException('ONEC_ACTOR_DENIED',403,$e);throw $e;}
            return;
        }
        $q=$this->db->prepare("SELECT u.id,u.role AS role_id,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE COALESCE(rp.kind,'viewer') END AS role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=? AND u.active=1");$q->execute([$actor]);
        $user=$q->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new RuntimeException('ONEC_ACTOR_DENIED',403);
        $permission=in_array($action,['check','settings'],true)?'integrations.write':'integrations.read';
        $q=$this->db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*')");$q->execute([$user['role_id'],$permission]);
        if (!$q->fetchColumn()) throw new RuntimeException('ONEC_ACTOR_DENIED',403);
        $level=\App\PagePolicy::level('connections.'.$connection,$user);
        if ($level < (in_array($action,['read','preview','state'],true)?1:2)) throw new RuntimeException('ONEC_ACTOR_DENIED',403);
    }

    public static function identity(string $connection, array $input): array
    {
        $kind=self::definition($connection)['kind'];
        $id=$input['record_id']??null;$subject=$input['subject_id']??null;
        $prefix=$kind==='counterparty'?'party':'invoice';
        if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||bccomp($id,(string)PHP_INT_MAX)>0
            ||!in_array($input['record_kind']??null,['contract','document'],true)
            ||!is_string($subject)||!preg_match('/^'.$prefix.':[1-9][0-9]{0,18}$/D',$subject)) throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
        return ['record_kind'=>$input['record_kind'],'record_id'=>(int)$id,'subject_id'=>$subject];
    }

    public function execute(string $connection, string $action, int $actor, array $input): array
    {
        if (!in_array($action,['check','preview','prepare','dispatch','reconcile','state','generate'],true)) throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
        $this->authorize($connection,$actor,$action);
        $definition=self::definition($connection);
        $allowed=match($action){'check'=>['request_key'],'preview','prepare'=>['record_kind','record_id','subject_id'],'generate'=>['record_kind','record_id','subject_id'],default=>['intent_id']};
        if (array_diff(array_keys($input),$allowed)) throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
        if ($definition['kind']==='print') return ['state'=>'awaiting_template','code'=>'ONEC_PRINT_TEMPLATE_REQUIRED','written'=>false,'title'=>'Для формирования печатного файла нужен образец счёта.'];
        if ($action==='generate') throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
        if ($action==='check') {
            $key=$input['request_key']??'';
            if (!is_string($key)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)) throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
            $run=(new ProviderService($this->db,$this->store))->execute('onec','check',$actor,$key,[]);
            return ['state'=>$run['status'],'code'=>$run['result_code'],'written'=>false,'title'=>html_entity_decode(Controller::resultCode($run['result_code']??''),ENT_QUOTES,'UTF-8'),
                'scope'=>'connection_read_only','write_enabled'=>Config::liveAllowed()&&getenv('IPPHP_ALLOW_ONEC_WRITE')==='owner-authorized-prepared-create'];
        }
        $service=OnecNativeService::project($this->db,$this->store);
        if (in_array($action,['preview','prepare'],true)) {
            $identity=self::identity($connection,$input);
            if ($action==='preview') return (new OnecOwnerFacade($this->db,$this->store))->preview($actor,$definition['kind'],$identity)+['title'=>'Проверка данных для передачи в 1С.'];
            return self::result($service->prepare($actor,$definition['kind'],$identity));
        }
        $id=$input['intent_id']??null;
        if (!is_string($id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) throw new RuntimeException('NATIVE_INPUT_REJECTED',400);
        $q=$this->db->prepare('SELECT kind,actor_id FROM integration_native_intents WHERE id=?');$q->execute([$id]);$intent=$q->fetch(PDO::FETCH_ASSOC);
        if (!$intent||$intent['kind']!==$definition['kind']||(int)$intent['actor_id']!==$actor) throw new RuntimeException('ONEC_ACTOR_DENIED',403);
        if ($action==='dispatch'&&getenv('IPPHP_ALLOW_ONEC_WRITE')!=='owner-authorized-prepared-create') return ['state'=>'write_disabled','code'=>'NATIVE_WRITES_DISABLED','written'=>false,'title'=>'Запись в 1С пока не настроена. Данные не отправлялись.'];
        return self::result(match($action){'dispatch'=>$service->dispatch($id,$actor),'reconcile'=>$service->reconcile($id,$actor),default=>$service->readExisting($id,$actor)});
    }

    public static function result(array $intent): array
    {
        return ['intent_id'=>$intent['id'],'state'=>$intent['state'],'revision'=>(int)$intent['revision'],'native_number'=>$intent['native_number'],
            'written'=>in_array($intent['state'],['saved','existing_verified'],true),'title'=>match($intent['state']){
                'saved'=>'Запись сохранена в 1С и проверена.','existing_verified'=>'Подтверждена существующая запись 1С.',
                'prepared'=>'Данные подготовлены. Запись в 1С ещё не выполнялась.',
                'unknown','unconfirmed','posting','ack_received'=>'Результат требует сверки. Повторная запись не выполняется.',default=>'Операция требует проверки.'}];
    }

    public static function missingMessages(array $fields): array
    {
        return array_values(array_unique(array_map(static fn($field)=>match(true){
            str_starts_with($field,'confirmation.')=>'Бухгалтер должен подтвердить актуальный документ.',
            str_starts_with($field,'connection_binding.')=>'Нужно настроить привязку к действующему подключению 1С.',
            str_starts_with($field,'native_refs.counterparty.'),str_starts_with($field,'native_party_identity.')=>'Нужно подтвердить реквизиты контрагента в 1С.',
            str_starts_with($field,'native_refs.'),str_starts_with($field,'native_policy.')=>'Нужно подтвердить справочники и условия счёта в 1С.',
            default=>'Нужно подтвердить актуальные условия и состав документа.',
        },$fields)));
    }
}
