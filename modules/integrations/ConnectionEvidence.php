<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;

/** Read-only projection of matching saved evidence. Never refreshes credentials or calls a provider. */
final class ConnectionEvidence
{
    public function __construct(private PDO $db, private PrivateStore $store) {}

    public static function matches(string $provider, array $connection, array $source, array $run, array $metadata): bool
    {
        if (($run['provider'] ?? null) !== $provider || (int)($run['revision'] ?? 0) !== (int)$connection['revision'] || (int)$connection['revision'] < 1) return false;
        if($provider==='onec'){
            try{$transport=OnecDevSshTransport::identity();}catch(\Throwable){return false;}
            $observed=$metadata['transport']??$metadata['native_diagnostic']['dev_transport']??['mode'=>'direct'];
            if(($transport['mode']??null)==='dev_ssh'){
                foreach(['mode','transport_id','config_sha256']as$key)if(($observed[$key]??null)!==$transport[$key])return false;
            }elseif(($observed['mode']??'direct')!=='direct')return false;
        }
        $pending = in_array($run['status'] ?? null,['pending','uncertain'],true);
        if ($run['operation'] === 'check') return $pending || ($metadata['revision_current'] ?? null) === true;
        if (!in_array($run['operation'], ['auth-metadata','fresh-mail-auth','capability-probe'], true)
            || !is_string($source['source_receipt_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$source['source_receipt_sha256'])
            || ($metadata['source_receipt_sha256'] ?? null) !== ($source['source_receipt_sha256'] ?? null)
            || ($metadata['operative_scope_current'] ?? true) !== true) return false;
        if ($run['operation']==='capability-probe') return $provider==='onec'
            && ($metadata['mode'] ?? null)==='once_source_equivalent_organization_read'
            && (int)($metadata['operative_revision'] ?? 0)===(int)$connection['revision']
            && ($metadata['profile_reference'] ?? null)===($source['selected_profile'] ?? null);
        if ($run['operation'] === 'fresh-mail-auth') return (int)($metadata['operative_revision'] ?? 0) === (int)$connection['revision'];
        if (in_array($provider, ['email','max'], true)) return true;
        return (int)($metadata['operative_revision_at_check'] ?? ($pending?($metadata['operative_revision_at_admission'] ?? 0):0)) === (int)$connection['revision'];
    }

    private function verifiedRun(array $run, array $metadata): bool
    {
        try {
            $receipt = $this->store->verifySpool($metadata['receipt_path'] ?? '');
            if (($receipt['context']['provider'] ?? null) !== $run['provider']
                || ($receipt['context']['run_id'] ?? null) !== $run['id']
                || ($receipt['context']['operation'] ?? null) !== $run['operation']) return false;
            $body = Support::decode(base64_decode($receipt['raw_base64'], true));
            if (($body['status'] ?? null) !== $run['status'] || ($body['code'] ?? null) !== $run['result_code']) return false;
            $covered = $body['metadata'] ?? $body['coverage'] ?? null;
            unset($metadata['receipt_path']);
            if ($run['operation'] === 'check') unset($metadata['command_context'], $metadata['bank_auth_identity']);
            return is_array($covered) && Support::canonical($covered) === Support::canonical($metadata);
        } catch (\Throwable) { return false; }
    }

    private function message(string $provider, int $revision): ?array
    {
        $query = $this->db->prepare("SELECT * FROM messaging_attempts WHERE provider=? AND revision=? AND operation IN('send','check') ORDER BY created_at DESC,id DESC LIMIT 1");
        $query->execute([$provider,$revision]);
        $attempt = $query->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) return null;
        $verified = false;
        try {
            $spool = $this->store->verifySpool($attempt['spool_path']);
            $context = $spool['context'];
            $receipt = $this->store->receiptFor($attempt['id']);
            $body = $receipt ? Support::decode(base64_decode($receipt['receipt']['raw_base64'],true)) : [];
            $verified = ($context['attempt_id'] ?? null) === $attempt['id'] && ($context['provider'] ?? null) === $provider
                && (int)($context['revision'] ?? 0) === $revision && ($context['target'] ?? null) === $attempt['target']
                && ($context['operation'] ?? null) === $attempt['operation']
                && ($body['status'] ?? null) === $attempt['status'] && ($body['code'] ?? null) === $attempt['result_code'];
        } catch (\Throwable) {}
        return ['id'=>$attempt['id'],'provider'=>$provider,'revision'=>$revision,'operation'=>$attempt['operation']==='send'?'message-send':'message-check',
            'status'=>$verified?$attempt['status']:'unverified','result_code'=>$verified?$attempt['result_code']:'EVIDENCE_UNCONFIRMED',
            'created_at'=>$attempt['created_at'],'finished_at'=>$attempt['finished_at'],
            'metadata'=>['transport_diagnostic'=>array_intersect_key($body ?? [],array_flip(['http_status','phase','safe_error_code']))],
            'proof_verified'=>$verified];
    }

    public function read(string $provider): array
    {
        Support::provider($provider);
        $connection = Config::get($this->db,$provider);
        $base = ['provider'=>$provider,'revision'=>(int)$connection['revision'],'stored_status'=>$connection['last_status'],
            'status'=>$connection['enabled']?'unverified':'disabled','scope'=>'not_checked','proof_verified'=>false,'checked_at'=>null,'run_id'=>null];
        if (!$connection['enabled']) return $base;
        if ($provider === 'bank') {
            $parent = $this->read('onec');
            return array_replace($base,['status'=>$parent['status']==='failed'?'blocked':'unverified','scope'=>'bank_statement_not_checked',
                'effective_onec_revision'=>$parent['revision'],'dependency_run_id'=>$parent['run_id'],'proof_verified'=>$parent['proof_verified']]);
        }
        $query = $this->db->prepare('SELECT * FROM integration_configuration_sources WHERE provider=?');
        $query->execute([$provider]); $source = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        $query = $this->db->prepare("SELECT * FROM integration_runs WHERE provider=? AND operation IN('auth-metadata','fresh-mail-auth','capability-probe','check') ORDER BY created_at DESC,id DESC LIMIT 100");
        $query->execute([$provider]); $selected = null;
        foreach ($query as $run) {
            try { $metadata = Support::decode($run['coverage_json'] ?? '{}'); }
            catch (\Throwable) { continue; }
            if (!self::matches($provider,$connection,$source,$run,$metadata)) continue;
            $verified = $this->verifiedRun($run,$metadata);
            $selected = $run + ['metadata'=>$metadata,'proof_verified'=>$verified];
            if (!$verified) { $selected['status']='unverified'; $selected['result_code']='EVIDENCE_UNCONFIRMED'; }
            break; // A newer unconfirmed observation must not fall back to an older green one.
        }
        if (in_array($provider,['email','max'],true)) {
            $message = $this->message($provider,(int)$connection['revision']);
            if ($message && (!$selected || $message['created_at']>$selected['created_at'])) $selected=$message;
        }
        if (!$selected) return $base;
        $metadata = $selected['metadata'];
        $accounts = $metadata['accounts_count'] ?? $metadata['accounts_returned'] ?? null;
        if (!is_int($accounts) || $accounts < 0 || $accounts > 100000) $accounts = null;
        $requests = $metadata['native_diagnostic']['requests'] ?? $metadata['requests'] ?? [];
        $lastRequest = $requests ? $requests[array_key_last($requests)] : [];
        $http = $metadata['transport_diagnostic']['http_status'] ?? $lastRequest['http_code'] ?? null;
        if (!is_int($http) || $http < 100 || $http > 599) $http = null;
        $fileBodies=$metadata['file_coverage']['full_bodies_read']??null;
        if(!is_int($fileBodies)||$fileBodies<0||$fileBodies>2)$fileBodies=null;
        return array_replace($base,['status'=>$selected['status'],'scope'=>$selected['operation']==='capability-probe'||($metadata['scope']??null)==='organization_read_capability'?'organization-capability':$selected['operation'],'proof_verified'=>$selected['proof_verified'],
            'checked_at'=>$selected['finished_at'] ?? $selected['created_at'],'created_at'=>$selected['created_at'],'run_id'=>$selected['id'],'code'=>$selected['result_code'],
            'accounts_count'=>$accounts,
            'http_status'=>$http??($metadata['http']??null),'file_bodies_read'=>$fileBodies,
            'transport_mode'=>$metadata['transport']['mode']??$metadata['native_diagnostic']['dev_transport']['mode']??'direct']);
    }

    public static function pushWindow(array $evidence,?int $now=null):?string
    {
        if(($evidence['provider']??null)!=='diadoc'||($evidence['code']??null)!=='PUSH_REQUIRED'||empty($evidence['proof_verified']))return null;
        try{$created=new \DateTimeImmutable((string)($evidence['created_at']??''),new \DateTimeZone('Europe/Moscow'));}
        catch(\Throwable){return 'local_window_unknown';}
        if(empty($evidence['created_at'])||($now??time())<$created->getTimestamp())return 'local_window_unknown';
        return ($now??time())-$created->getTimestamp()<=600?'awaiting_confirmation':'local_window_expired';
    }

    public static function text(array $evidence,?int $now=null): string
    {
        if ($evidence['status']==='disabled') return 'Подключение выключено.';
        if ($evidence['scope']==='bank_statement_not_checked') return $evidence['status']==='blocked'
            ? 'Выписка не проверялась: доступ к 1С не подтверждён.' : 'Проверка банковской выписки ещё не выполнена.';
        if (!$evidence['proof_verified']) return 'Для текущих настроек нет подтверждённого результата проверки.';
        $push=self::pushWindow($evidence,$now);
        if($push==='awaiting_confirmation')return 'Подтвердите вход в уведомлении Контур. Повторный запрос не отправляется.';
        if($push==='local_window_expired')return 'Время локального ожидания подтверждения Контур истекло. Срок действия у провайдера неизвестен; новый запрос не отправляется.';
        if($push==='local_window_unknown')return 'Срок локального ожидания подтверждения Контур не установлен. Автоматического повтора нет.';
        if ($evidence['scope']==='organization-capability') {
            if ($evidence['status']==='success') return (($evidence['transport_mode']??null)==='dev_ssh'?'Через сервер разработки доступен справочник организаций 1С. ':'Справочник организаций 1С доступен. ').'Другие операции 1С не проверялись.';
            return 'Чтение справочника организаций 1С не подтверждено.'.(!empty($evidence['http_status'])?' HTTP '.$evidence['http_status'].'.':'');
        }
        if ($evidence['status']==='accepted') return 'Последняя отправка принята провайдером. Это не подтверждение прочтения.';
        if ($evidence['status']==='success') {
            if (in_array($evidence['provider'],['email','max'],true)) return 'Доступ подтверждён без отправки сообщения.';
            if ($evidence['scope']==='check') return 'Ограниченная проверка сведений выполнена.'
                .(!empty($evidence['file_bodies_read'])?' В памяти прочитано файлов: '.$evidence['file_bodies_read'].'. Целостность по эталону поставщика не подтверждена.':' Исходные файлы не подтверждены.')
                .' Полнота истории не подтверждена.';
            return 'Вход и сведения источника подтверждены.'.(isset($evidence['accounts_count'])?' Лицевых счетов: '.$evidence['accounts_count'].'.':'')
                .' Документы и файлы не проверялись.';
        }
        if ($evidence['status']==='failed' && $evidence['scope']==='message-send') return 'Последняя отправка не принята провайдером.';
        if ($evidence['status']==='failed') return $evidence['provider']==='onec'
            ? '1С отказала в доступе'.(!empty($evidence['http_status'])?' (HTTP '.$evidence['http_status'].')':'').'. Причина отказа не подтверждена.'
            : 'Последняя проверка не завершена успешно. Требуется уточнить доступ.';
        return 'Результат ожидается или требует сверки. Автоматического повтора нет.';
    }
}
