<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class Messaging
{
    public function __construct(private \PDO $db,private PrivateStore $store,private mixed $transport=null) {}
    public static function exceptionResult(string $operation, string $stage, \Throwable $error): array {
        // Before entering the transport no provider request can have happened.
        if (in_array($stage, ['claim_spool_verify','secret_revision'], true)) {
            return ['status'=>'failed','code'=>$error->getMessage()==='CREDENTIAL_REVISION_CONFLICT'?'CREDENTIAL_REVISION_CONFLICT':'MESSAGE_PREFLIGHT_FAILED'];
        }
        return ['status'=>$operation==='send'?'uncertain':'failed','code'=>'TRANSPORT_RESULT_UNKNOWN'];
    }
    public function dispatch(string $provider,int $actor,string $requestKey,string $operation,array $targets,string $text='',string $subject='',array $attachments=[]): array {
        if(!in_array($provider,['email','max'],true)||!in_array($operation,['check','send'],true)||!preg_match('/^[A-Za-z0-9-]{16,100}$/D',$requestKey))throw new \InvalidArgumentException('INVALID_MESSAGE_COMMAND');
        if(mb_strlen($text)>4000||mb_strlen($subject)>200||preg_match('/[\r\n\x00]/',$subject)||$provider==='max'&&$subject!=='')throw new \InvalidArgumentException('MESSAGE_LIMIT');
        if($provider==='max'&&$attachments||count($attachments)>10)throw new \InvalidArgumentException('ATTACHMENT_LIMIT');$total=0;$normalized=[];
        foreach($attachments as $a){if(!is_array($a)||!is_string($a['name']??null)||!is_string($a['bytes']??null))throw new \InvalidArgumentException('INVALID_ATTACHMENT');$name=basename(str_replace('\\','/',$a['name']));if($name===''||strlen($name)>180||preg_match('/[\x00-\x1f\x7f]/',$name))throw new \InvalidArgumentException('INVALID_ATTACHMENT_NAME');$total+=strlen($a['bytes']);$normalized[]=['name'=>$name,'bytes'=>$a['bytes'],'sha256'=>hash('sha256',$a['bytes']),'mime'=>(new \finfo(FILEINFO_MIME_TYPE))->buffer($a['bytes'])];}if($total>10*1024*1024)throw new \InvalidArgumentException('ATTACHMENT_LIMIT');
        if($operation==='check'){$targets=['@connection'];$text='';$subject='';if($attachments)throw new \InvalidArgumentException('CHECK_NO_ATTACHMENTS');}
        else {if(trim($text)===''||!$targets||count($targets)>($provider==='email'?20:100))throw new \InvalidArgumentException('MESSAGE_TARGETS_REQUIRED');$targets=array_values(array_unique(array_map('strval',$targets)));foreach($targets as $target){if($provider==='email'&&!filter_var($target,FILTER_VALIDATE_EMAIL)||$provider==='max'&&!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$target))throw new \InvalidArgumentException('INVALID_TARGET');}}
        sort($targets,SORT_STRING);$manifest=array_map(static fn($a)=>['name'=>$a['name'],'sha256'=>$a['sha256'],'mime'=>$a['mime'],'bytes'=>strlen($a['bytes'])],$normalized);$fp=hash('sha256',Support::json([$provider,$operation,$targets,$subject,$text,$manifest]));
        $this->db->beginTransaction();try{
            $q=$this->db->prepare('SELECT * FROM messaging_attempts WHERE provider=? AND actor_id=? AND request_key=? ORDER BY target FOR UPDATE');$q->execute([$provider,$actor,$requestKey]);$existing=$q->fetchAll(\PDO::FETCH_ASSOC);if($existing){foreach($existing as $old)if(!hash_equals($old['fingerprint'],$fp))throw new \RuntimeException('IDEMPOTENCY_CONFLICT');$this->db->commit();return $existing;}
            $q=$this->db->prepare('SELECT * FROM integration_connections WHERE provider=? FOR UPDATE');$q->execute([$provider]);$connection=$q->fetch(\PDO::FETCH_ASSOC);if(!$connection||!$connection['enabled'])throw new \RuntimeException('CONNECTION_DISABLED');$config=json_decode($connection['config_json'],true,32,JSON_THROW_ON_ERROR);$revision=(int)$connection['revision'];
            $groups=[];if($provider==='max'){$rows=$this->db->query("SELECT id,chat_id FROM messaging_groups WHERE provider='max' AND enabled=1")->fetchAll(\PDO::FETCH_KEY_PAIR);$groups=array_values($rows);if($operation==='send'){if(array_diff($targets,array_keys($rows)))throw new \RuntimeException('GROUP_DISABLED_OR_UNKNOWN');$targets=array_map(static fn($id)=>Support::chatId($rows[$id]),$targets);foreach($targets as $target){$q=$this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND target=? AND operation='send' AND (status='pending' OR created_at>DATE_SUB(NOW(6),INTERVAL 1 SECOND))");$q->execute([$target]);if($q->fetchColumn()>0)throw new \RuntimeException('MAX_TARGET_BUSY');}}}
            $attempts=[];foreach($targets as $target){$id=Support::uuid();$remoteId=$id.'@ipphp.local';$raw=$operation==='check'?'':($provider==='email'?MessageTransport::mime($config,$target,$subject,$text,$normalized,$remoteId):Support::json(['text'=>$text]));$context=['attempt_id'=>$id,'provider'=>$provider,'actor_id'=>$actor,'request_key'=>$requestKey,'operation'=>$operation,'target'=>$target,'revision'=>$revision,'fingerprint'=>$fp,'attachments'=>$manifest,'message_id'=>$remoteId];$path=$this->store->spool($context,$raw);$this->db->prepare('INSERT INTO messaging_attempts(id,provider,actor_id,request_key,operation,target,fingerprint,subject,body_text,status,spool_path,revision) VALUES(?,?,?,?,?,?,?,?,?,\'pending\',?,?)')->execute([$id,$provider,$actor,$requestKey,$operation,$target,$fp,$subject,$text,$path,$revision]);$attempts[]=['id'=>$id,'target'=>$target,'spool_path'=>$path,'raw'=>$raw,'remote_id'=>$remoteId];}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Claim is committed before any side effect. A crash leaves pending, which is never auto-replayed.
        foreach($attempts as $a){$stage='claim_spool_verify';try{$this->store->verifySpool($a['spool_path']);$stage='secret_revision';$secret=Config::secrets($provider);Config::assertSecretRevision($secret,$revision);$stage='transport_entry';$result=is_callable($this->transport)?($this->transport)($provider,$operation,$config,$secret,$a['target'],$a['raw'],$a['remote_id'],$groups):(new MessageTransport())->execute($provider,$operation,$config,$secret,$a['target'],$a['raw'],$a['remote_id'],$groups);if(!in_array($result['status']??'', $operation==='check'?['success','failed']:['accepted','failed','uncertain'],true))throw new \RuntimeException('INVALID_TRANSPORT_RESULT');}catch(\Throwable $e){$result=self::exceptionResult($operation,$stage,$e);if($provider==='max')$result+=['transport_stage'=>$stage]+MessageTransport::safeException($e);}
            // Immutable HMAC receipt remains separate from SQL's public delivery status.
            $receipt=$this->store->spool(['attempt_id'=>$a['id'],'kind'=>'transport_receipt','request_key'=>$requestKey],Support::json($result));
            $this->store->linkReceipt($a['id'],$receipt);
            $this->db->prepare('INSERT INTO messaging_receipts(attempt_id,receipt_path) VALUES(?,?)')->execute([$a['id'],$receipt]);
            $q=$this->db->prepare("UPDATE messaging_attempts SET status=?,result_code=?,remote_id=?,finished_at=NOW(6) WHERE id=? AND status='pending'");$q->execute([$result['status'],$result['code']??'NO_CODE',isset($result['remote_id'])?substr((string)$result['remote_id'],0,200):null,$a['id']]);
            if($operation==='check')$this->db->prepare('UPDATE integration_connections SET last_checked_at=NOW(6),last_status=? WHERE provider=? AND revision=?')->execute([$result['status'],$provider,$revision]);
        }
        $q=$this->db->prepare('SELECT * FROM messaging_attempts WHERE provider=? AND actor_id=? AND request_key=? ORDER BY target');$q->execute([$provider,$actor,$requestKey]);return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function recoverPending(string $id): void {
        // Explicit reconciliation records uncertainty only; it does not call a provider.
        $q=$this->db->prepare("SELECT * FROM messaging_attempts WHERE id=? AND status='pending'");$q->execute([$id]);$a=$q->fetch(\PDO::FETCH_ASSOC);if(!$a)return;$this->store->verifySpool($a['spool_path']);$this->store->spool(['attempt_id'=>$id,'kind'=>'reconciliation'],Support::json(['status'=>'uncertain','code'=>'PROCESS_INTERRUPTED_NO_REPLAY']));$this->db->prepare("UPDATE messaging_attempts SET status='uncertain',result_code='PROCESS_INTERRUPTED_NO_REPLAY',finished_at=NOW(6) WHERE id=? AND status='pending'")->execute([$id]);
    }
}
