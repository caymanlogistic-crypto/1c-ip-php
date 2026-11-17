<?php
declare(strict_types=1);
namespace Modules\Rental;

/** Public attempt DTO projection only. It authorizes no retry or delivery. */
final class OperationLineage
{
    private static function compatible(array $parent,array $child): bool
    {
        if (!preg_match('/^email:[a-f0-9]{32}$/D',$parent['id'])
            || !preg_match('/^email:[a-f0-9]{32}$/D',$child['id'])
            || !in_array($parent['operation'] ?? '',['contract-packet','addendum-packet'],true)
            || ($parent['operation'] ?? null)!==($child['operation'] ?? null)
            || ($parent['row_key'] ?? null)!==($child['row_key'] ?? null)
            || empty($parent['row_key'])
            || (string)($parent['entity_version'] ?? '')!==(string)($child['entity_version'] ?? '')
            || empty($parent['entity_version'])
            || !preg_match('/^[a-f0-9]{64}$/D',$parent['wire_sha256'] ?? '')
            || ($parent['wire_sha256'] ?? null)!==($child['wire_sha256'] ?? null)) return false;
        foreach ([$parent,$child] as $attempt) {
            if (!preg_match('/^[1-9][0-9]{0,18}$/D',(string)($attempt['delivery_order'] ?? ''))
                || count($attempt['steps'] ?? [])!==1) return false;
        }
        return ($parent['steps'][0]['native_status'] ?? '')==='failed'
            && ($parent['steps'][0]['status'] ?? '')==='error'
            && empty($parent['steps'][0]['uncertain']) && empty($parent['steps'][0]['accepted'])
            && bccomp((string)$child['delivery_order'],(string)$parent['delivery_order'],0)>0;
    }

    /**
     * retry_parent_id is a normalized immediate-parent ID, not an arbitrary root.
     * Only a linear, same-packet, same-wire chain suppresses old live errors.
     */
    public static function collapse(array $groups): array
    {
        $index=[];$duplicates=[];
        foreach ($groups as $position=>$group) {
            $id=$group['id'];
            if (isset($index[$id])) $duplicates[$id]=true;
            $index[$id]=$position;
        }
        $parents=[];$children=[];
        foreach ($groups as $position=>$group) {
            $parentId=$group['retry_parent_id'] ?? null;
            if (!is_string($parentId) || !isset($index[$parentId])
                || isset($duplicates[$parentId]) || isset($duplicates[$group['id']])) continue;
            $parent=$index[$parentId];
            if (!self::compatible($groups[$parent],$group)) continue;
            $parents[$position]=$parent;
            $children[$parent][]=$position;
        }
        // A fork is not resolved using creation timestamps, UUIDs or status preference.
        foreach ($children as $parent=>$next) {
            if (count($next)<2) continue;
            foreach ($next as $child) unset($parents[$child]);
            unset($children[$parent]);
        }
        $result=[];$consumed=[];
        foreach ($groups as $root=>$group) {
            if (isset($consumed[$root]) || isset($parents[$root])) continue;
            $chain=[$root];$leaf=$root;
            while (isset($children[$leaf][0])) {
                $leaf=$children[$leaf][0];$chain[]=$leaf;
            }
            if (count($chain)===1) {$result[]=$group;continue;}
            $current=$groups[$leaf];$history=[];$attempts=0;$fingerprints=[];
            foreach ($chain as $position) {
                $member=$groups[$position];$step=$member['steps'][0];
                $attempts+=(int)$step['attempts'];$fingerprints[]=$member['fingerprint'];
                $history[]=['id'=>$member['id'],'created_at'=>$member['created_at'] ?? null,
                    'status'=>$step['native_status'],'label'=>$step['label'],'attempts'=>(int)$step['attempts']];
                $consumed[$position]=true;
            }
            $current['id']=$group['id'];
            $current['latest_attempt_id']=$groups[$leaf]['id'];
            $current['created_at']=$group['created_at'] ?? null;
            $current['steps'][0]['attempts']=$attempts;
            $current['attempt_history']=$history;
            $current['fingerprint']=hash('sha256',Importer::canonicalJson($fingerprints));
            $result[]=$current;
        }
        return $result;
    }
}
