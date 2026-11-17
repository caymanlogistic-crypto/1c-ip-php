<?php
declare(strict_types=1);

namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** The owner's two explicit test destinations. No web override or arbitrary recipient input. */
final class OwnerMessaging
{
    public const TEXT = 'Проверка отправки из новой локальной PHP-системы';
    private const ADMISSION = 'owner-messaging-test-20261116-v1';

    public function __construct(private PDO $db,private PrivateStore $store) {}

    private static function authorized(): void
    {
        if (PHP_SAPI !== 'cli' || getenv('IPPHP_OWNER_MESSAGING_ADMISSION') !== self::ADMISSION) throw new RuntimeException('OWNER_MESSAGE_NOT_AUTHORIZED');
    }

    public static function shape(array $owner): array
    {
        $smtp=$owner['smtp']??[]; $max=$owner['max']??[]; $destinations=$owner['testDestinations']??[];
        if (!array_key_exists('imap',$owner) || !array_key_exists('replyTo',$smtp)
            || $owner['imap']!==null || $smtp['replyTo']!==null
            || ($smtp['port']??null)!==465 || strtolower((string)($smtp['security']??''))!=='ssl') throw new RuntimeException('OWNER_MESSAGE_SHAPE_REJECTED');
        foreach (['host','login','password','fromAddress'] as $key) {
            if (!is_string($smtp[$key]??null) || $smtp[$key]==='' || strlen($smtp[$key])>8192
                || preg_match('/[\x00-\x1f\x7f]/',$smtp[$key])) throw new RuntimeException('OWNER_MESSAGE_SHAPE_REJECTED');
        }
        if (!is_string($smtp['fromName']??null)||strlen($smtp['fromName'])>120||preg_match('/[\x00-\x1f\x7f]/',$smtp['fromName'])) throw new RuntimeException('OWNER_MESSAGE_SHAPE_REJECTED');
        if (!preg_match('/^[a-z0-9.-]{1,253}$/iD',$smtp['host']) || !filter_var($smtp['fromAddress'],FILTER_VALIDATE_EMAIL)
            || !filter_var($destinations['senderAddress']??'',FILTER_VALIDATE_EMAIL)) throw new RuntimeException('OWNER_MESSAGE_SHAPE_REJECTED');
        foreach (['token','internalGroupId','groupName'] as $key) {
            if (!is_string($max[$key]??null) || $max[$key]==='' || strlen($max[$key])>8192
                || preg_match('/[\x00-\x1f\x7f]/',$max[$key])) throw new RuntimeException('OWNER_MESSAGE_SHAPE_REJECTED');
        }
        $chat=Support::chatId($max['chatId']??null);
        if ($chat!==Support::chatId($destinations['maxChatId']??null)) throw new RuntimeException('OWNER_MAX_GROUP_BINDING_CONFLICT');
        return ['smtp'=>['host'=>$smtp['host'],'port'=>465,'security'=>'ssl','username'=>$smtp['login'],
            'from_email'=>$smtp['fromAddress'],'from_name'=>$smtp['fromName']], 'chat'=>$chat];
    }

    private static function load(): array
    {
        $path=Config::root().'/.local/integration-source/owner-messaging-20261116.credentials.json';
        if (!is_file($path)||is_link($path)||filesize($path)>65536) throw new RuntimeException('OWNER_MESSAGE_HANDOFF_REQUIRED');
        $owner=Support::decode(file_get_contents($path));
        self::shape($owner);
        return $owner;
    }

    /** Pure candidate only: remove a matching explicit Markdown wrapper, never token content. */
    public static function canonicalMaxCandidate(array $owner): array
    {
        self::shape($owner);
        if (!preg_match('/^(`{1,3})([^`\s]+)\1$/D',$owner['max']['token'],$match)) {
            throw new RuntimeException('OWNER_MAX_FENCE_NOT_EXPLICIT');
        }
        $candidate=$owner;
        $candidate['max']['token']=$match[2];
        return $candidate;
    }

    private function context(array $owner): array
    {
        $shape=self::shape($owner);
        $email=Config::get($this->db,'email'); $max=Config::get($this->db,'max');
        if (!$email['enabled']||!$max['enabled']) throw new RuntimeException('OWNER_CONNECTION_DISABLED');
        $es=Config::secrets('email'); $ms=Config::secrets('max');
        Config::assertSecretRevision($es,(int)$email['revision']); Config::assertSecretRevision($ms,(int)$max['revision']);
        $emailPublic=Support::decode($email['config_json']);
        $emailPublic['port']=(int)($emailPublic['port']??0);
        $emailPublic['security']=strtolower((string)($emailPublic['security']??''));
        $emailPublic['from_name']=$emailPublic['from_name']??'';
        $emailSame=Support::canonical($emailPublic)===Support::canonical($shape['smtp'])
            && hash_equals((string)($es['secret']??''),$owner['smtp']['password']);
        $maxSame=hash_equals((string)($ms['secret']??''),$owner['max']['token']);
        $query=$this->db->prepare("SELECT DISTINCT g.id,g.name FROM messaging_groups g JOIN integration_group_bindings b ON b.local_group_id=g.id AND b.provider='max' WHERE g.provider='max' AND g.enabled=1 AND g.chat_id=? AND b.source_group_id=?");
        $query->execute([$shape['chat'],$owner['max']['internalGroupId']]);
        $groups=$query->fetchAll(PDO::FETCH_ASSOC);
        if (count($groups)!==1 || $groups[0]['name']!==$owner['max']['groupName']) throw new RuntimeException('OWNER_MAX_GROUP_BINDING_CONFLICT');
        unset($es,$ms);
        return ['email'=>$email,'max'=>$max,'email_same'=>$emailSame,'max_same'=>$maxSame,'group_id'=>$groups[0]['id'],'shape'=>$shape];
    }

    public static function publish(PDO $db,string $provider,int $expected,array $public,string $receiptSha,array $proof): int
    {
        self::authorized();
        if (!$db->inTransaction()||!in_array($provider,['email','max'],true)) throw new RuntimeException('OWNER_MESSAGE_TRANSACTION_REQUIRED');
        $query=$db->prepare('SELECT revision,enabled FROM integration_connections WHERE provider=? FOR UPDATE'); $query->execute([$provider]);
        $current=$query->fetch(PDO::FETCH_ASSOC);
        $query=$db->prepare('SELECT * FROM integration_configuration_sources WHERE provider=? FOR UPDATE'); $query->execute([$provider]);
        $source=$query->fetch(PDO::FETCH_ASSOC);
        if (!$current||!$source||!$current['enabled']||(int)$current['revision']!==$expected) throw new RuntimeException('OWNER_MESSAGE_CAS_CONFLICT');
        Config::save($db,$provider,$public,$expected,true);
        $proof['predecessor']=$source; $proof['source_revision_kind']='new-PHP-owner-configuration'; $proof['upstream_revision']=null;
        $db->prepare("UPDATE integration_configuration_sources SET variant='owner-current-msg-v1',source_receipt_sha256=?,source_revision=?,metadata_json=?,activated_at=NOW(6) WHERE provider=?")
            ->execute([$receiptSha,$expected+1,Support::json($proof),$provider]);
        $db->prepare('UPDATE integration_connections SET last_checked_at=NULL WHERE provider=? AND revision=?')->execute([$provider,$expected+1]);
        return $expected+1;
    }

    public static function consumed(PDO $db,string $provider): ?array
    {
        self::authorized();
        if (!in_array($provider,['email','max'],true)) throw new RuntimeException('OWNER_MESSAGE_NOT_AUTHORIZED');
        $query=$db->prepare('SELECT id,status,result_code FROM messaging_attempts WHERE provider=? AND actor_id=0 AND request_key=?');
        $query->execute([$provider,'owner-smoke-20261116-'.$provider]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function activate(): array
    {
        self::authorized();
        if ($this->db->inTransaction()) throw new RuntimeException('OWNER_MESSAGE_TRANSACTION_REQUIRED');
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-owner-messaging',0)")->fetchColumn()!==1) throw new RuntimeException('OWNER_MESSAGE_BUSY');
        try {
            $owner=self::load(); $context=$this->context($owner); $changed=[];
            foreach (['email','max'] as $provider) {
                if ($context[$provider.'_same']) continue;
                $expected=(int)$context[$provider]['revision'];
                $proof=['project_id'=>ConfigActivation::PROJECT,'owner_handoff_basename'=>'owner-messaging-20261116.credentials.json',
                    'provider'=>$provider,'old_revision'=>$expected,'new_revision'=>$expected+1,'whole_profile'=>true,
                    'credentials_hashed'=>false,'imap_replaced'=>false,'explicit_destination_binding'=>true];
                $proofPath=$this->store->put('owner-config-provenance',Support::json($proof));
                $history=Config::root().'/private/integrations/config-history';
                if (!is_dir($history)&&!mkdir($history,0700,true)) throw new RuntimeException('OWNER_HISTORY_FAILED');
                $cipher=file_get_contents(Config::root().'/private/integrations/'.$provider.'.enc');
                $historyPath=$history.'/'.$provider.'-revision-'.$expected.'.enc';
                if (is_link($historyPath)||file_exists($historyPath)) throw new RuntimeException('OWNER_HISTORY_ALREADY_EXISTS');
                $file=fopen($historyPath,'xb');
                if (!$file) throw new RuntimeException('OWNER_HISTORY_FAILED');
                try { if (fwrite($file,$cipher)!==strlen($cipher)) throw new RuntimeException('OWNER_HISTORY_FAILED'); fflush($file); if(function_exists('fsync'))fsync($file); }
                finally { fclose($file); } chmod($historyPath,0600); unset($cipher);
                $secret=['secret'=>$provider==='email'?$owner['smtp']['password']:$owner['max']['token'],
                    'approved_recipients'=>$provider==='email'?[$owner['testDestinations']['senderAddress']]:[],
                    'approved_chat_ids'=>$provider==='max'?[$context['shape']['chat']]:[],
                    '_owner_bound_revision'=>$expected+1,'_source_receipt_sha256'=>basename($proofPath)];
                $public=$provider==='email'?$context['shape']['smtp']:[];
                $this->db->beginTransaction();
                try {
                    self::publish($this->db,$provider,$expected,$public,basename($proofPath),$proof+['proof_path'=>$proofPath,'encrypted_history_preserved'=>true]);
                    Config::saveSecrets($provider,$secret);
                    $this->db->commit();
                } catch (\Throwable $error) { if($this->db->inTransaction())$this->db->rollBack(); throw $error; }
                unset($secret); $changed[$provider]=$expected+1;
            }
            return ['changed_revisions'=>$changed,'imap_replaced'=>false,'actual_sends'=>0];
        } finally { $this->db->query("SELECT RELEASE_LOCK('ipphp-owner-messaging')"); }
    }

    public function sendOnce(string $provider): array
    {
        self::authorized();
        if (!in_array($provider,['email','max'],true)||!Config::liveAllowed()) throw new RuntimeException('OWNER_MESSAGE_NOT_AUTHORIZED');
        $lock='ipphp-owner-test-'.$provider;
        $query=$this->db->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]);
        if ((int)$query->fetchColumn()!==1) throw new RuntimeException('OWNER_MESSAGE_BUSY');
        try {
            $owner=self::load(); $context=$this->context($owner);
            if (!$context[$provider.'_same']) throw new RuntimeException('OWNER_MESSAGE_PROFILE_NOT_SELECTED');
            $revision=(int)$context[$provider]['revision']; $requestKey='owner-smoke-20261116-'.$provider;
            if ($old=self::consumed($this->db,$provider)) return ['provider'=>$provider,'revision'=>$revision,'attempt_id'=>$old['id'],'status'=>$old['status'],'code'=>$old['result_code'],'already_consumed'=>true];
            $target=$provider==='email'?$owner['testDestinations']['senderAddress']:$context['group_id'];
            $transport=function($p,$operation,$config,$unusedSecret,$recipient,$raw,$remoteId,$groups) use ($owner,$context,$provider,$revision): array {
                $current=Config::get($this->db,$provider);
                if (!$current['enabled']||(int)$current['revision']!==$revision||$current['config_json']!==$context[$provider]['config_json']) throw new RuntimeException('OWNER_MESSAGE_CAS_CONFLICT');
                $selected=Config::secrets($provider); Config::assertSecretRevision($selected,$revision);
                $provided=$provider==='email'?$owner['smtp']['password']:$owner['max']['token'];
                if (!hash_equals((string)($selected['secret']??''),$provided)) throw new RuntimeException('OWNER_MESSAGE_CAS_CONFLICT');
                $expected=$provider==='email'?$owner['testDestinations']['senderAddress']:$context['shape']['chat'];
                if ($recipient!==$expected) throw new RuntimeException('OWNER_DESTINATION_MISMATCH');
                $secret=['secret'=>$provided,'approved_recipients'=>[$owner['testDestinations']['senderAddress']], 'approved_chat_ids'=>[$context['shape']['chat']]];
                return (new MessageTransport())->execute($p,$operation,$config,$secret,$recipient,$raw,$remoteId,$groups);
            };
            $attempts=(new Messaging($this->db,$this->store,$transport))->dispatch($provider,0,$requestKey,'send',[$target],self::TEXT,$provider==='email'?self::TEXT:'',[]);
            $attempt=$attempts[0]; $receipt=$this->store->receiptFor($attempt['id']);
            return ['provider'=>$provider,'revision'=>$revision,'attempt_id'=>$attempt['id'],'status'=>$attempt['status'],
                'code'=>$attempt['result_code'],'receipt_path'=>$receipt['receipt_path']??null,'automatic_retry_allowed'=>false,
                'provider_accepted'=> $attempt['status']==='accepted','inbox_delivery_proven'=>false,'attachments'=>0];
        } finally { $query=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]); }
    }
}
