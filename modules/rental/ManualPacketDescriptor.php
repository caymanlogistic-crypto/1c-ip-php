<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

/** A physical handover proof, never an Email payload or dispatch authorization. */
final class ManualPacketDescriptor
{
    private const FIELDS=['schema','packet_record','b_binding','original_actor_context','legal_file',
        'invoice_ids','invoice_versions','invoice_scope','attachments'];

    public static function freeze(array $packet,array $attachments):array
    {
        if(($packet['state']??null)!=='confirmed')throw new RuntimeException('MANUAL_PACKET_B_REQUIRED',409);
        $descriptor=['schema'=>'rental-manual-packet-v1','packet_record'=>$packet['record'],
            'b_binding'=>$packet['b_packet'],'original_actor_context'=>$packet['original_actor_context'],
            'legal_file'=>$packet['file'],'invoice_ids'=>$packet['invoice_ids'],
            'invoice_versions'=>array_map(static fn(array $invoice):array=>['id'=>(int)$invoice['id'],'version'=>(int)$invoice['version']],$packet['invoices']),
            'invoice_scope'=>self::scope($packet['invoices']),'attachments'=>$attachments];
        $descriptor['sha256']=hash('sha256',Importer::canonicalJson($descriptor));
        return self::verify($descriptor);
    }

    public static function scope(array $invoices):array
    {
        foreach($invoices as$invoice)if(!array_key_exists('scope_admission',$invoice)||!is_array($invoice['scope_state']??null))throw new RuntimeException('MANUAL_PACKET_SCOPE_MISSING',409);
        return array_map(static fn(array $invoice):array=>['invoice_id'=>(int)$invoice['id'],
            'scope_admission'=>$invoice['scope_admission'],'scope_state'=>$invoice['scope_state']],$invoices);
    }

    /** Schema/digest verification only; current B/files must be checked by the SQL reader. */
    public static function verify(array $descriptor):array
    {
        $keys=array_keys($descriptor);sort($keys,SORT_STRING);
        $allowed=[...self::FIELDS,'sha256'];sort($allowed,SORT_STRING);
        if($keys!==$allowed||($descriptor['schema']??null)!=='rental-manual-packet-v1')throw new RuntimeException('MANUAL_PACKET_SCHEMA_INVALID',409);
        $hash=$descriptor['sha256'];unset($descriptor['sha256']);
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)
            ||!hash_equals($hash,hash('sha256',Importer::canonicalJson($descriptor))))throw new RuntimeException('MANUAL_PACKET_DIGEST_INVALID',409);
        $record=$descriptor['packet_record'];$actor=$descriptor['original_actor_context'];
        if(!is_array($record)||!in_array($record['kind']??null,['contract','document'],true)
            ||!is_int($record['id']??null)||$record['id']<1||!is_int($record['version']??null)||$record['version']<1
            ||!is_array($actor)||!is_int($actor['id']??null)||$actor['id']<1
            ||!is_string($actor['role_id']??null)||$actor['role_id']===''
            ||!is_int($actor['auth_version']??null)||$actor['auth_version']<1)throw new RuntimeException('MANUAL_PACKET_IDENTITY_INVALID',409);
        foreach(['b_binding','legal_file']as$field)if(!is_array($descriptor[$field])||!$descriptor[$field])throw new RuntimeException('MANUAL_PACKET_APPROVAL_INVALID',409);
        foreach(['invoice_ids','invoice_versions','invoice_scope','attachments']as$field)if(!is_array($descriptor[$field])||!array_is_list($descriptor[$field]))throw new RuntimeException('MANUAL_PACKET_MEMBERSHIP_INVALID',409);
        $ids=$descriptor['invoice_ids'];
        if(count(array_unique($ids,SORT_REGULAR))!==count($ids)||count($descriptor['invoice_versions'])!==count($ids)
            ||count($descriptor['invoice_scope'])!==count($ids)||count($descriptor['attachments'])!==count($ids)+1)throw new RuntimeException('MANUAL_PACKET_MEMBERSHIP_INVALID',409);
        foreach($ids as$index=>$id){
            if(!is_int($id)||$id<1||($descriptor['invoice_versions'][$index]['id']??null)!==$id
                ||!is_int($descriptor['invoice_versions'][$index]['version']??null)||$descriptor['invoice_versions'][$index]['version']<1
                ||($descriptor['invoice_scope'][$index]['invoice_id']??null)!==$id
                ||!array_key_exists('scope_admission',$descriptor['invoice_scope'][$index])
                ||!is_array($descriptor['invoice_scope'][$index]['scope_state']??null))throw new RuntimeException('MANUAL_PACKET_MEMBERSHIP_INVALID',409);
        }
        foreach($descriptor['attachments']as$file)if(!is_array($file)||!is_string($file['file_id']??null)
            ||!is_string($file['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']))throw new RuntimeException('MANUAL_PACKET_FILE_INVALID',409);
        $descriptor['sha256']=$hash;
        return $descriptor;
    }
}
