<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,BusinessIds,DB};
use PDO;
use RuntimeException;

/** Explicit source correction: live rows and a new immutable baseline revision. No external effects. */
final class ZeroPointCorrection
{
    private static function access(): void
    {
        if (PHP_SAPI!=='cli') throw new RuntimeException('Исправление источника выполняется только CLI.',403);
        ZeroPoint::requireAdmin();
    }

    public static function prepare(array $evidence): array
    {
        self::access();$point=ZeroPoint::load();$db=DB::pdo();
        if (!hash_equals($point['sha256'],(string)($evidence['expected_baseline'] ?? ''))
            || count($evidence['dates'] ?? [])!==16
            || !preg_match('/^[a-f0-9]{64}$/D',$evidence['workbook_sha256'] ?? ''))
            throw new RuntimeException('Не совпал источник исправления.',409);
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $live=ZeroPoint::capture($db);
            foreach (['from','to'] as $side) {
                $id=(int)$evidence['replacement'][$side];
                $old=array_column($point['data']['tables']['rental_objects'],null,'id')[$id] ?? [];
                $now=array_column($live['tables']['rental_objects'],null,'id')[$id] ?? [];
                foreach (['name','floor','area','cadastre_id'] as $field)
                    if ((string)($old[$field] ?? '')!==(string)($now[$field] ?? '')) throw new RuntimeException('Изменилась идентичность места замены.',409);
            }
            $id=(int)$evidence['replacement']['contract_id'];
            if ((array_column($point['data']['tables']['contracts'],null,'id')[$id]['party_id'] ?? null)
                !==(array_column($live['tables']['contracts'],null,'id')[$id]['party_id'] ?? null))
                throw new RuntimeException('Изменился контрагент договора замены.',409);
            $termId=max(BusinessIds::next($db,'contract_terms'),max(array_column($point['data']['tables']['contract_terms'],'id'))+1);
            $db->rollBack();
        } catch (\Throwable $e) {if ($db->inTransaction()) $db->rollBack();throw $e;}
        $next=$point;unset($next['sha256'],$next['target']);
        $next['data']=self::correct($point['data'],$evidence,$termId);
        self::correct($live,$evidence,$termId); // Exact identities and source conditions must also match live data.
        $next['source_pins']['date_correction']=$evidence;
        $next['revision']=['previous_sha256'=>$point['sha256'],'workbook_sha256'=>$evidence['workbook_sha256'],'date_count'=>16];
        $next['summary']['counts']=array_map('count',$next['data']['tables']);
        $plan=['schema'=>1,'kind'=>'ZERO_POINT_SOURCE_CORRECTION','expected_baseline'=>$point['sha256'],
            'current_sha256'=>ZeroPoint::digest($live),'evidence'=>$evidence,'term_id'=>$termId,
            'document'=>$next,'next_sha256'=>ZeroPoint::digest($next)];
        $plan['command_key']=substr(ZeroPoint::digest($plan),0,32);
        return $plan;
    }

    public static function correct(array $data,array $evidence,int $termId): array
    {
        $objects=[];foreach($data['tables']['rental_objects'] as $i=>$o)$objects[(int)$o['id']]=$i;
        $seen=[];
        foreach($evidence['dates'] as $date) {
            $id=(int)$date['id'];$i=$objects[$id] ?? throw new RuntimeException('Нет объекта источника.',409);
            $o=$data['tables']['rental_objects'][$i];
            if (isset($seen[$id]) || $o['name']!==$date['name'] || (string)$o['floor']!==(string)$date['floor']
                || bccomp((string)$o['area'],(string)$date['area'],4)!==0 || $o['free_since']!==null)
                throw new RuntimeException('Дата или идентичность объекта изменились: '.$id,409);
            $seen[$id]=true;Service::date($date['free_since']);
            if ($date['free_since']>'2026-10-31') throw new RuntimeException('Дата позже нулевой точки.',422);
            $data['tables']['rental_objects'][$i]['free_since']=$date['free_since'];
            $data['facts'][]=['namespace'=>'zero_point_data','source_key'=>'idle_date:'.$id,
                'fact_json'=>ZeroPoint::json($date+['workbook_sha256'=>$evidence['workbook_sha256']]),'operative'=>0];
        }
        $r=$evidence['replacement'];$contractId=(int)$r['contract_id'];$to=(int)$r['to'];$from=(int)$r['from'];
        if (($r['owner_confirmed'] ?? false)!==true) throw new RuntimeException('Нет подтверждения владельцем замены.',409);
        if (($data['tables']['rental_objects'][$objects[$to]]['name'] ?? '')!=='1.14'
            || ($data['tables']['rental_objects'][$objects[$from]]['name'] ?? '')!=='1.34'
            || !isset($seen[$from]) || $data['tables']['rental_objects'][$objects[$from]]['free_since']!=='2026-10-23')
            throw new RuntimeException('Не подтверждена замена места.',409);
        $ci=null;foreach($data['tables']['contracts'] as $i=>$c)if((int)$c['id']===$contractId)$ci=$i;
        $c=$data['tables']['contracts'][$ci ?? -1] ?? throw new RuntimeException('Нет договора замены.',409);
        if ($c['number']!=='33' || $c['document_date']!=='2024-02-01' || $c['status']!=='active' || $c['end_date']!=='2026-10-31')
            throw new RuntimeException('Договор замены изменился.',409);
        $raw=json_decode($c['source_json'],true,512,JSON_THROW_ON_ERROR);$future=null;
        foreach($data['facts'] as $f)if($f['namespace']==='zero_point_data' && $f['source_key']===$r['plan_key'])
            $future=json_decode($f['fact_json'],true,512,JSON_THROW_ON_ERROR)['source_plan']['condition'] ?? null;
        if (!$future || $future['contract_number']!=='33' || $future['contract_date']!==$c['document_date']
            || $future['party_id']!==($raw['sourceLegalMapping']['party1CRef_Key'] ?? '')
            || $future['rooms']!==['1.14'] || $future['start']!=='2026-11-01' || $future['end']!=='2027-10-01'
            || bccomp($future['monthly_gross'],'24000.00',2)!==0)
            throw new RuntimeException('Не подтверждены продолжение и тариф того же договора.',409);
        $relations=array_values(array_filter($data['tables']['contract_objects'],fn($x)=>(int)$x['contract_id']===$contractId));
        if (count($relations)!==1 || (int)$relations[0]['object_id']!==$to) throw new RuntimeException('Изменилась привязка договора.',409);
        $oldTerm=null;$prior=false;
        foreach($data['tables']['contract_terms'] as $t) {
            if ((int)$t['id']===$termId) throw new RuntimeException('ID условия уже занят.',409);
            if ((int)$t['contract_id']!==$contractId)continue;
            if ((int)$t['object_id']===$to && $t['start_date']==='2026-10-23' && $t['end_date']==='2026-10-31')$oldTerm=$t;
            if ((int)$t['object_id']===$from && $t['end_date']==='2026-10-22')$prior=true;
        }
        if (!$oldTerm || !$prior) throw new RuntimeException('Не совпала история замены.',409);
        $newTerm=$oldTerm;$newTerm['id']=$termId;$newTerm['effective_date']='2026-11-01';
        // Start is the uninterrupted occupancy boundary; effective_date controls the new tariff.
        $newTerm['start_date']='2026-10-23';$newTerm['end_date']=$future['end'];$newTerm['amount']=$future['monthly_gross'];
        $newTerm['source_json']=ZeroPoint::json(['sourceCondition'=>$future,'ownerConfirmedReplacement'=>true,
            'occupancyStart'=>'2026-10-23','amountEffectiveFrom'=>'2026-11-01','workbook_sha256'=>$evidence['workbook_sha256']]);
        $data['tables']['contract_terms'][]=$newTerm;
        $c['end_date']=$future['end'];$raw['end']=$future['end'];
        $raw['ownerConfirmedReplacement']=['from'=>$from,'to'=>$to,'date'=>'2026-10-23','planned_condition'=>$future['condition_id']];
        $c['source_json']=ZeroPoint::json($raw);$data['tables']['contracts'][$ci]=$c;
        $data['facts'][]=['namespace'=>'zero_point_data','source_key'=>'replacement:'.$contractId,
            'fact_json'=>ZeroPoint::json($r+['condition'=>$future,'workbook_sha256'=>$evidence['workbook_sha256']]),'operative'=>0];
        return $data;
    }

    public static function apply(array $plan): array
    {return ZeroPointRevision::apply('zero-point.correct',$plan);}

    public static function execute(PDO $db,array $plan): array
    {
        ZeroPointRevision::assertPending('zero-point.correct',$plan);
        $live=ZeroPoint::capture($db,true);
        if (!hash_equals($plan['current_sha256'],ZeroPoint::digest($live))) throw new RuntimeException('Данные изменились после проверки.',409);
        $corrected=self::correct($live,$plan['evidence'],(int)$plan['term_id']);$dir=ZeroPoint::directory();
        Service::available($db,[(int)$plan['evidence']['replacement']['to']],
            '2026-10-23','2027-10-01',(int)$plan['evidence']['replacement']['contract_id']);
        $backup=['schema'=>1,'kind'=>'COMMERCIAL_BEFORE_RESTORE','project_id'=>ZeroPoint::PROJECT,
            'as_of'=>date('Y-m-d'),'table_schema'=>ZeroPoint::schema($db),'data'=>$live];
        $backupSha=ZeroPoint::digest($backup);ZeroPoint::write($dir.'/'.$backupSha.'.json',$backup);
        foreach($plan['evidence']['dates'] as $d)
            $db->prepare('UPDATE rental_objects SET free_since=?,version=version+1 WHERE id=?')->execute([$d['free_since'],$d['id']]);
        $id=(int)$plan['evidence']['replacement']['contract_id'];
        $c=array_values(array_filter($corrected['tables']['contracts'],fn($x)=>(int)$x['id']===$id))[0];
        $db->prepare('UPDATE contracts SET end_date=?,source_json=?,legal_review=0,finance_review=0,version=version+1 WHERE id=?')->execute([$c['end_date'],$c['source_json'],$id]);
        $term=array_values(array_filter($corrected['tables']['contract_terms'],fn($x)=>(int)$x['id']===(int)$plan['term_id']))[0];
        $columns=array_keys($term);$db->prepare('INSERT INTO contract_terms (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($term));
        foreach(array_slice($corrected['facts'],count($live['facts'])) as $fact)
            $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,?)')->execute(array_values($fact));
        $db->prepare('UPDATE rental_runtime_context SET revision=revision+1,source_pair_sha256=? WHERE id=1')->execute([$plan['next_sha256']]);
        $q=$db->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active' FOR UPDATE");
        $runtime=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$runtime['baseline_sha256']=$plan['next_sha256'];
        $db->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='zero_point_runtime' AND source_key='active'")->execute([ZeroPoint::json($runtime)]);
        ZeroPoint::assertMoney($db);
        $result=['sha256'=>$plan['next_sha256'],'previous_sha256'=>$plan['expected_baseline'],'backup_sha256'=>$backupSha,'date_count'=>16,'contract_id'=>$id];
        Audit::record('zero-point.correct','zero_point',$plan['command_key'],null,$result);
        Service::event($db,ZeroPoint::requireAdmin(),'zero-point.correct','Исправлены даты простоя и замена 1.34 → 1.14 в договоре №33.',$id,$result);
        ZeroPoint::write($dir.'/revisions/'.$plan['command_key'].'.json',$result);
        return $result;
    }

    public static function recover(): array
    {return ZeroPointRevision::recover();}
}
