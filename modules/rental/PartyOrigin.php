<?php
declare(strict_types=1);
namespace Modules\Rental;

final class PartyOrigin
{
    /** A local PK is never presented as an external GUID or a 1C catalogue code. */
    public static function read(array $party): array
    {
        $source=json_decode($party['source_json'] ?? '{}',true) ?: [];
        $guid=$source['source_party_guid'] ?? null;$code=$source['source_business_fields']['Code'] ?? null;
        if (!$guid) {
            $binding=Controller::query("SELECT source_id FROM rental_source_bindings WHERE entity_type='party' AND entity_id=? AND relation_kind IN ('identity','counterparty') ORDER BY namespace,source_id",[$party['id']]);
            $ids=array_values(array_unique(array_column($binding,'source_id')));
            if (count($ids)===1 && preg_match('/^[a-f0-9-]{36}$/Di',$ids[0])) $guid=$ids[0];
        }
        return ['kind'=>$guid?'onec':'local','label'=>$guid?'Из 1С':'Создан в базе','guid'=>$guid,'code'=>$code,'local_id'=>(int)$party['id']];
    }
}
