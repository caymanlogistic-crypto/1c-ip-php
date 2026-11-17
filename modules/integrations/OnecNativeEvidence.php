<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Historical receipt verification has no dependency on the current business database slice. */
final class OnecNativeEvidence
{
    public static function readSaved(PrivateStore $store,array $intent): array
    {
        if(!in_array($intent['state']??null,['saved','existing_verified'],true)||!isset(OnecMapping::COLLECTIONS[$intent['kind']??'']))throw new \RuntimeException('ONEC_NATIVE_SAVED_EVIDENCE_REQUIRED');
        $read=$store->verifySpool($intent['readback_path']);$c=$read['context']??[];
        foreach(['attempt_id'=>'id','source_instance'=>'source_instance','native_key'=>'native_key','request_key'=>'request_key','body_sha256'=>'body_sha256','post_fence'=>'post_fence']as$key=>$field)
            if(($c[$key]??null)!==$intent[$field])throw new \RuntimeException('ONEC_NATIVE_EVIDENCE_SCOPE_CHANGED');
        if(($c['kind']??null)!=='native-read-receipt'||($c['http_status']??null)!==200||!str_starts_with($c['stage']??'','readback-'))throw new \RuntimeException('ONEC_NATIVE_READBACK_EVIDENCE_REQUIRED');
        $store->read($intent['body_path'],$intent['body_sha256']);
        $raw=base64_decode($read['raw_base64'],true);$facts=Support::decode($raw);$facts=$facts['d']??$facts;
        $number=$intent['kind']==='counterparty'?'Code':'Number';
        if(($facts['Ref_Key']??null)!==$intent['native_key']||($facts['DataVersion']??null)!==$intent['native_version']||($facts[$number]??null)!==$intent['native_number']||($facts['DeletionMark']??null)!==false
            ||($intent['kind']==='customer_invoice'&&($facts['Posted']??null)!==false))throw new \RuntimeException('ONEC_NATIVE_EVIDENCE_RESULT_CHANGED');
        if($intent['state']==='saved'){
            $ack=$store->receiptFor($intent['id']);$a=$ack['receipt']['context']??[];
            foreach(['native_key','body_sha256','source_instance','post_fence','request_key']as$field)if(($a[$field]??null)!==$intent[$field])throw new \RuntimeException('ONEC_NATIVE_ACK_SCOPE_CHANGED');
            if(($a['http_status']??null)!==201)throw new \RuntimeException('ONEC_NATIVE_ACK_EVIDENCE_REQUIRED');
            $created=Support::decode(base64_decode($ack['receipt']['raw_base64'],true));$created=$created['d']??$created;
            if(($created['Ref_Key']??null)!==$intent['native_key'])throw new \RuntimeException('ONEC_NATIVE_ACK_KEY_CHANGED');
        }
        return ['raw'=>$raw,'facts'=>$facts,'readback'=>$read];
    }
}
