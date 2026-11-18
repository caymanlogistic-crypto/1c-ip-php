<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,BusinessIds,DB};
use PDO;
use RuntimeException;

/** Restores confirmed dated sources without changing cash, files or unsigned-document evidence. */
final class ZeroPointContinuity
{
    private static function access(): void
    {
        if (PHP_SAPI!=='cli') throw new RuntimeException('Коррекция источника доступна только CLI.',403);
        ZeroPoint::requireAdmin();
    }

    public static function prepare(array $evidence): array
    {
        self::access();$point=ZeroPoint::load();$db=DB::pdo();
        if (($evidence['expected_baseline'] ?? '')!==$point['sha256'] || count($evidence['imports'] ?? [])!==3
            || count($evidence['plans'] ?? [])!==2 || count($evidence['dates'] ?? [])!==2
            || !preg_match('/^[a-f0-9]{64}$/D',$evidence['archive_index_sha256'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D',$evidence['reference_sha256'] ?? ''))
            throw new RuntimeException('Не совпал проверенный источник.',409);
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {$live=ZeroPoint::capture($db);$termId=max(BusinessIds::next($db,'contract_terms'),max(array_column($point['data']['tables']['contract_terms'],'id'))+1);$db->rollBack();}
        catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $next=$point;unset($next['sha256'],$next['target']);
        $next['data']=self::correct($point['data'],$evidence,$termId);self::correct($live,$evidence,$termId);
        $next['source_pins']['continuity_correction']=$evidence;
        $next['revision']=['previous_sha256'=>$point['sha256'],'archive_index_sha256'=>$evidence['archive_index_sha256'],'kind'=>'DATED_SOURCE_CONTINUITY'];
        $next['summary']['counts']=array_map('count',$next['data']['tables']);
        $next['summary']['last_occupants']=count(array_filter($next['data']['facts'],fn($f)=>$f['namespace']==='zero_point_data' && str_starts_with($f['source_key'],'last_occupant:')));
        $next['limitations'][]='Подтверждённые последующие договоры и условия сохранены с датами; при восстановлении применяются на текущую дату.';
        $plan=['schema'=>1,'kind'=>'ZERO_POINT_CONTINUITY','expected_baseline'=>$point['sha256'],'current_sha256'=>ZeroPoint::digest($live),
            'evidence'=>$evidence,'term_id'=>$termId,'document'=>$next,'next_sha256'=>ZeroPoint::digest($next)];
        $plan['command_key']=substr(ZeroPoint::digest($plan),0,32);return $plan;
    }

    private static function fact(array &$data,string $key,array $value): void
    {
        $data['facts']=array_values(array_filter($data['facts'],fn($f)=>$f['namespace']!=='zero_point_data' || $f['source_key']!==$key));
        $data['facts'][]=['namespace'=>'zero_point_data','source_key'=>$key,'fact_json'=>ZeroPoint::json($value),'operative'=>0];
    }

    public static function correct(array $data,array $evidence,int $termId): array
    {
        $objects=array_column($data['tables']['rental_objects'],null,'id');$parties=array_column($data['tables']['parties'],null,'id');
        $contracts=array_column($data['tables']['contracts'],null,'id');$cadastre=array_column($data['tables']['cadastre'],null,'id');
        $facts=[];foreach($data['facts'] as $f)if($f['namespace']==='zero_point_data')$facts[$f['source_key']]=json_decode($f['fact_json'],true);
        foreach($data['tables']['contract_terms'] as $t)if((int)$t['id']>=$termId)throw new RuntimeException('Изменился диапазон ID условий.',409);
        $schedule=[];$numbers=[];
        foreach($evidence['imports'] as $import) {
            $c=$import['contract'];$id=(int)$c['id'];$number=(string)$c['number'];$source=null;
            foreach($facts as $f)if(is_array($f) && ($f['reason'] ?? '')==='AFTER_CUTOFF' && ($f['source']['values']['number'] ?? '')===$number)$source=$f['source'];
            if (!$source || isset($contracts[$id]) || isset($numbers[$number]) || !isset($parties[$c['party_id']]))
                throw new RuntimeException('Изменились ID или состав будущего договора.',409);
            $numbers[$number]=true;$values=$source['values'];$author=$values['source_json']['authorContract'];
            foreach(['number','document_date','start_date','end_date','amount'] as $key)
                if((string)$c[$key]!== (string)$values[$key])throw new RuntimeException('Договор отличается от сохранённого источника.',409);
            if ($id!==(int)$author['id'] || (int)$c['party_id']!==(int)$author['party'] || !in_array($number,['156','157','158'],true)
                || $c['start_date']<='2026-10-31')throw new RuntimeException('Не подтверждена идентичность будущего договора.',409);
            $old=$contracts[$import['predecessor_id']] ?? throw new RuntimeException('Нет прежнего договора.',409);
            if((int)$old['party_id']!==(int)$c['party_id'] || !in_array($old['number'],['124','125','126'],true)
                || (new \DateTimeImmutable($old['end_date']))->modify('+1 day')->format('Y-m-d')!==$c['start_date'])
                throw new RuntimeException('Не подтверждена последовательность перезаключения.',409);
            $relationIds=array_map(fn($r)=>(int)$r['object_id'],$import['relations']);$authorIds=array_map('intval',$author['objects']);sort($relationIds);sort($authorIds);
            if($relationIds!==$authorIds || count($relationIds)!==count(array_unique($relationIds)))throw new RuntimeException('Не совпали объекты договора.',409);
            $oldIds=array_map(fn($r)=>(int)$r['object_id'],array_values(array_filter($data['tables']['contract_objects'],fn($r)=>(int)$r['contract_id']===(int)$old['id'])));sort($oldIds);
            if($oldIds!==$relationIds)throw new RuntimeException('Изменились объекты прежнего договора.',409);
            $refs=array_column($values['source_json']['sourceLegalMapping']['accountingContractRefs'],'ref');
            foreach($import['bindings'] as $binding) {
                if($binding['entity_type']!=='contract' || (int)$binding['entity_id']!==$id || !in_array($binding['source_id'],$refs,true))
                    throw new RuntimeException('GUID не принадлежит исходному договору.',409);
                foreach($data['tables']['rental_source_bindings'] as $prior)if($prior['namespace']===$binding['namespace'] && $prior['collection']===$binding['collection'] && $prior['source_id']===$binding['source_id'] && $prior['entity_type']===$binding['entity_type'] && $prior['relation_kind']===$binding['relation_kind'])
                    throw new RuntimeException('Источник уже привязан. Требуется сверка.',409);
                $data['tables']['rental_source_bindings'][]=$binding;
            }
            if(count($import['bindings'])!==2)throw new RuntimeException('Не подтверждены GUID аренды и обеспечения.',409);
            $author['imported']=true;$author['originalSourceStatus']=$author['sourceStatus'];$author['zeroPointScheduled']=true;
            $author['zeroPointAsOf']='2026-10-31';$author['sourceLegalMapping']=$values['source_json']['sourceLegalMapping'];
            $author['history']=[];$author['versions']=[];$author['signed']=false;$author['financialReview']=false;
            $c['source_json']=ZeroPoint::json($author);$c['status']='approved';$c['signed']=0;$c['finance_review']=0;$c['legal_review']=0;$c['version']=1;
            foreach(['finish_date','finish_reason','return_confirmed','return_to','servicing_document_id','servicing_effective_date'] as $key)if(array_key_exists($key,$c))$c[$key]=null;
            $data['tables']['contracts'][]=$c;$contracts[$id]=$c;
            $sum='0.00';$termObjects=[];
            foreach($import['terms'] as $t) {
                if((int)$t['contract_id']!==$id || !in_array((int)$t['object_id'],$relationIds,true) || isset($termObjects[$t['object_id']])
                    || $t['start_date']!==$c['start_date'] || $t['end_date']!==$c['end_date'])throw new RuntimeException('Не совпали датированные условия.',409);
                $termObjects[$t['object_id']]=true;$sum=bcadd($sum,(string)$t['amount'],2);$t['id']=$termId++;$t['effective_date']=$t['start_date'];
                $t['source_json']=ZeroPoint::json(['sourceTerm'=>json_decode($t['source_json'],true),'sourceContract'=>$number,'reference_sha256'=>$evidence['reference_sha256']]);
                $data['tables']['contract_terms'][]=$t;
            }
            if(count($termObjects)!==count($relationIds) || bccomp($sum,$c['amount'],2)!==0)throw new RuntimeException('Неверна сумма условий будущего договора.',409);
            foreach($import['relations'] as $relation){if((int)$relation['contract_id']!==$id || !isset($objects[$relation['object_id']]))throw new RuntimeException('Неверная связь помещения.',409);$data['tables']['contract_objects'][]=$relation;}
            $schedule[]=['predecessor'=>(int)$old['id'],'successor'=>$id,'start'=>$c['start_date']];
        }
        if(count($numbers)!==3)throw new RuntimeException('Неполный набор перезаключений.',409);
        $continuationId=null;$planObjects=[];
        foreach($evidence['plans'] as $p) {
            $condition=$facts[$p['key']]['source_plan']['condition'] ?? null;$oid=(int)$p['object_id'];$o=$objects[$oid] ?? [];
            if(!$condition || $condition['contract_number']!=='24' || $condition['rooms']!==[$o['name'] ?? ''] || !in_array($oid,[1009,1015],true) || isset($planObjects[$oid]))
                throw new RuntimeException('Не подтверждено продолжение №24.',409);
            $planObjects[$oid]=true;$ci=null;foreach($data['tables']['contracts'] as $i=>$c)if($c['number']==='24' && $c['document_date']===$condition['contract_date'])$ci=$i;
            if($ci===null)throw new RuntimeException('Нет договора продолжения.',409);
            $c=$data['tables']['contracts'][$ci];$raw=json_decode($c['source_json'],true);$continuationId=(int)$c['id'];
            if(!in_array($c['status'],['active'],true) || ($raw['sourceLegalMapping']['party1CRef_Key'] ?? '')!==$condition['party_id']
                || $condition['end']!=='2027-10-10' || ($cadastre[$o['cadastre_id']]['number'] ?? '')!==$condition['cadastre'])
                throw new RuntimeException('Изменились условия или помещение №24.',409);
            $prior=null;foreach($data['tables']['contract_terms'] as $t)if((int)$t['contract_id']===$continuationId && (int)$t['object_id']===$oid && ($prior===null || (int)$t['id']>(int)$prior['id']))$prior=$t;
            if($oid===1009 && (!$prior || $condition['start']!=='2026-11-11' || $prior['end_date']!=='2026-11-10'))throw new RuntimeException('Разрыв продолжения 2.3.',409);
            if($oid===1015 && ($prior || $condition['start']!=='2026-11-05'))throw new RuntimeException('Изменилось добавление 2.12.',409);
            if(!$prior)$data['tables']['contract_objects'][]=['contract_id'=>$continuationId,'object_id'=>$oid];
            $data['tables']['contract_terms'][]=['id'=>$termId++,'contract_id'=>$continuationId,'object_id'=>$oid,
                'start_date'=>$prior['start_date'] ?? $condition['start'],'end_date'=>$condition['end'],'effective_date'=>$condition['start'],
                'amount'=>$condition['monthly_gross'],'superseded_on'=>null,'source_json'=>ZeroPoint::json(['sourceCondition'=>$condition,'sourcePlanKey'=>$p['key'],'sourceContinuationConfirmed'=>true])];
            $c['end_date']=$condition['end'];$raw['end']=$condition['end'];$raw['sourceContinuationConfirmed']=true;$c['source_json']=ZeroPoint::json($raw);$data['tables']['contracts'][$ci]=$c;
        }
        foreach($evidence['dates'] as $d) {
            $o=$objects[$d['id']] ?? throw new RuntimeException('Нет объекта даты.',409);
            if(!in_array($o['name'],['26Н/26','405'],true) || $o['name']!==$d['name'] || $o['free_since']!==null || (string)$o['floor']!==(string)$d['floor']
                || bccomp($o['area'],$d['area'],4)!==0 || $cadastre[$o['cadastre_id']]['number']!==$d['cadastre'])throw new RuntimeException('Не совпал объект подтверждённой даты.',409);
            Service::date($d['date']);if($d['date']>'2026-10-31')throw new RuntimeException('Дата позже точки.',409);
            foreach($data['tables']['rental_objects'] as &$row)if((int)$row['id']===(int)$o['id'])$row['free_since']=$d['date'];unset($row);
            self::fact($data,'idle_date:'.$o['id'],$d);
            self::fact($data,'last_occupant:'.$o['id'],['as_of'=>'2026-10-31','tenant'=>$d['tenant'],'party_1c'=>$d['party_1c'],
                'end_date'=>$d['date'],'contract_number'=>$d['contract_number'],'evidence_ids'=>[$d['evidence_id']],'source_row'=>$d['source_row'],'source_sha256'=>$d['source_sha256']]);
        }
        self::fact($data,'continuity:schedule',['schema'=>1,'transitions'=>$schedule,'continuation_contract'=>$continuationId,'archive_sha256'=>$evidence['archive_index_sha256']]);
        self::fact($data,'continuity:archive_review',['findings'=>$evidence['unresolved'],'archive_sha256'=>$evidence['archive_index_sha256']]);
        return $data;
    }

    /** Applies only admitted source transitions, not a general expiration/closure policy. */
    public static function project(array $data,string $day): array
    {
        Service::date($day);$schedule=null;
        foreach($data['facts'] as $f)if($f['namespace']==='zero_point_data' && $f['source_key']==='continuity:schedule')$schedule=json_decode($f['fact_json'],true,512,JSON_THROW_ON_ERROR);
        if(!$schedule)return $data;
        foreach($schedule['transitions'] as $change)if($change['start']<=$day) {
            foreach($data['tables']['contracts'] as &$c) {
                $raw=json_decode($c['source_json'],true);
                if((int)$c['id']===(int)$change['successor'] && $c['status']==='approved' && !empty($raw['zeroPointScheduled'])) {
                    $c['status']='active';$raw['sourceStatus']='Действует';$raw['status']='active';$c['source_json']=ZeroPoint::json($raw);
                } elseif((int)$c['id']===(int)$change['predecessor'] && $c['status']==='active') {
                    $c['status']='ended';$raw['sourceStatus']='Прекращено';$raw['status']='ended';$raw['sourceReplacedBy']=$change['successor'];$c['source_json']=ZeroPoint::json($raw);
                }
            }unset($c);
        }
        $latest=[];foreach($data['tables']['contract_terms'] as $t)if((int)$t['contract_id']===(int)$schedule['continuation_contract'] && ($t['effective_date'] ?? $t['start_date'])<=$day) {
            $key=$t['object_id'];if(!isset($latest[$key]) || (int)$t['id']>(int)$latest[$key]['id'])$latest[$key]=$t;
        }
        $sum='0.00';$count=0;foreach($latest as $t)if($t['start_date']<=$day && $t['end_date']>=$day){$sum=bcadd($sum,$t['amount'],2);$count++;}
        if($count)foreach($data['tables']['contracts'] as &$c)if((int)$c['id']===(int)$schedule['continuation_contract'])$c['amount']=$sum;unset($c);
        return $data;
    }

    public static function afterRestore(PDO $db,string $day): void
    {
        $before=ZeroPoint::capture($db);$after=self::project($before,$day);self::updateContracts($db,$before,$after);
    }

    private static function updateContracts(PDO $db,array $before,array $after): void
    {
        $prior=array_column($before['tables']['contracts'],null,'id');
        foreach($after['tables']['contracts'] as $c)if(isset($prior[$c['id']])) {
            $fields=[];foreach(['status','amount','end_date','source_json'] as $key)if((string)$prior[$c['id']][$key] !== (string)$c[$key])$fields[$key]=$c[$key];
            if(!$fields)continue;$set=implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($fields)));
            $db->prepare('UPDATE contracts SET '.$set.',version=version+1 WHERE id=?')->execute([...array_values($fields),$c['id']]);
        }
    }

    public static function apply(array $plan): array {return ZeroPointRevision::apply('zero-point.continuity',$plan);}

    public static function execute(PDO $db,array $plan): array
    {
        ZeroPointRevision::assertPending('zero-point.continuity',$plan);$live=ZeroPoint::capture($db,true);
        if(!hash_equals($plan['current_sha256'],ZeroPoint::digest($live)))throw new RuntimeException('Данные изменились после проверки.',409);
        $next=self::project(self::correct($live,$plan['evidence'],(int)$plan['term_id']),date('Y-m-d'));
        foreach($plan['evidence']['imports'] as $import)foreach($import['relations'] as $r)
            Service::available($db,[(int)$r['object_id']],$import['contract']['start_date'],$import['contract']['end_date'],(int)$import['contract']['id']);
        foreach($plan['evidence']['plans'] as $p) {
            $facts=array_column($next['facts'],null,'source_key');$c=json_decode($facts[$p['key']]['fact_json'],true)['source_plan']['condition'];
            $id=array_values(array_filter($next['tables']['contracts'],fn($r)=>$r['number']==='24'))[0]['id'];
            Service::available($db,[(int)$p['object_id']],$c['start'],$c['end'],(int)$id);
        }
        $backup=['schema'=>1,'kind'=>'COMMERCIAL_BEFORE_RESTORE','project_id'=>ZeroPoint::PROJECT,'as_of'=>date('Y-m-d'),'table_schema'=>ZeroPoint::schema($db),'data'=>$live];
        $backupSha=ZeroPoint::digest($backup);ZeroPoint::write(ZeroPoint::directory().'/'.$backupSha.'.json',$backup);
        foreach($plan['evidence']['dates'] as $d)$db->prepare('UPDATE rental_objects SET free_since=?,version=version+1 WHERE id=?')->execute([$d['date'],$d['id']]);
        foreach(['contracts','contract_objects','contract_terms','rental_source_bindings'] as $table) {
            $old=$live['tables'][$table];
            foreach($next['tables'][$table] as $row) {
                $exists=$table==='contracts'||$table==='contract_terms'?in_array($row['id'],array_column($old,'id'),true):in_array(ZeroPoint::json($row),array_map(ZeroPoint::json(...),$old),true);
                if($exists)continue;$cols=array_keys($row);$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));
            }
        }
        self::updateContracts($db,$live,$next);
        $prior=[];foreach($live['facts'] as $f)$prior[$f['namespace'].':'.$f['source_key']]=ZeroPoint::json($f);
        foreach($next['facts'] as $f)if(($prior[$f['namespace'].':'.$f['source_key']] ?? '')!==ZeroPoint::json($f))
            $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json),operative=VALUES(operative)')->execute(array_values($f));
        $db->prepare('UPDATE rental_runtime_context SET revision=revision+1,source_pair_sha256=? WHERE id=1')->execute([$plan['next_sha256']]);
        $q=$db->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active' FOR UPDATE");$runtime=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$runtime['baseline_sha256']=$plan['next_sha256'];
        $db->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='zero_point_runtime' AND source_key='active'")->execute([ZeroPoint::json($runtime)]);
        ZeroPoint::assertMoney($db);$result=['sha256'=>$plan['next_sha256'],'previous_sha256'=>$plan['expected_baseline'],'backup_sha256'=>$backupSha,'successors'=>3,'conditions'=>2,'dates'=>2];
        Audit::record('zero-point.continuity','zero_point',$plan['command_key'],null,$result);
        Service::event($db,ZeroPoint::requireAdmin(),'zero-point.continuity','Восстановлены подтверждённые продолжения аренды и даты освобождения.',null,$result);
        ZeroPoint::write(ZeroPoint::directory().'/revisions/'.$plan['command_key'].'.json',$result);return $result;
    }
}
