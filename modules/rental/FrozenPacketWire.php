<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

/** Identity of the original rendered packet, independent of its retry envelope. */
final class FrozenPacketWire
{
    private const FROZEN=['policy','subject','text','recipients','attachments','catalog_binding','catalog_values',
        'recipient_context','packet_record','actor_context','b_binding','legal_file','invoice_ids','invoice_versions','contract_number'];
    private const ENVELOPE=['delivery_order','retry_of','retry_root','sender_context','frozen_wire_sha256'];

    public static function projection(array $payload):array
    {
        if(array_diff(self::FROZEN,array_keys($payload))||array_diff(array_keys($payload),array_merge(self::FROZEN,self::ENVELOPE)))throw new RuntimeException('PACKET_FROZEN_WIRE_SCHEMA_INVALID');
        foreach(['policy','subject','text','contract_number']as$key)if(!is_string($payload[$key]))throw new RuntimeException('PACKET_FROZEN_WIRE_SCHEMA_INVALID');
        foreach(array_diff(self::FROZEN,['policy','subject','text','contract_number'])as$key)if(!is_array($payload[$key]))throw new RuntimeException('PACKET_FROZEN_WIRE_SCHEMA_INVALID');
        if(!in_array($payload['policy'],['rental-approved-packet-v1','rental-approved-addendum-packet-v1'],true))throw new RuntimeException('PACKET_FROZEN_WIRE_SCHEMA_INVALID');
        return array_intersect_key($payload,array_flip(self::FROZEN));
    }
    public static function digest(array $payload):string
    {
        return hash('sha256',Importer::canonicalJson(self::projection($payload)));
    }
}
