<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\{Audit,DB};
use PDO;
use RuntimeException;

/** The owner's two explicit resolutions. No generic inference from all expired contracts. */
final class ZeroPointClarification
{
    public const BASELINE='6bea33fb852018531137cd3f7c1698d15ccb2c1e1e57f54f8f6c884cda36dc7f';
    public const WORKBOOK='328caf9a55923613b1845cce03fd65b0fe1e9f7d795a6c1d2f6193443f192fb2';
    public static function evidence(): array
    {
        return ['expected_baseline'=>self::BASELINE,'workbook_sha256'=>self::WORKBOOK,'sheet'=>'На31.10.2026','row'=>38,
            'owner_confirmed'=>true,'confirmed_on'=>'2026-11-18','expiry_accepted_as_release'=>['object_id'=>1097,'date'=>'2026-02-26','contract'=>'117-Ф','tenant'=>'ФЛ Алехин'],
            'legal_address_excluded'=>['object_id'=>1101,'contract'=>'132','physical_history'=>'UNKNOWN','last_tenant'=>null,'free_since'=>null]];
    }
    private static function access(): void
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Уточнение доступно только CLI.',403);
        ZeroPoint::requireAdmin();
    }
    public static function prepare(array $evidence): array
    {
        self::access();if(ZeroPoint::json($evidence)!==ZeroPoint::json(self::evidence()))throw new RuntimeException('Не совпало подтверждение владельца.',409);
        $point=ZeroPoint::load();if($point['sha256']!==self::BASELINE)throw new RuntimeException('Изменилась нулевая точка.',409);
        $p=DB::pdo();if($p->inTransaction())throw new RuntimeException('Подготовка ревизии требует отдельной транзакции.',409);
        $p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try{$live=ZeroPoint::capture($p);$p->rollBack();}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
        self::correct($live,$evidence);
        $next=$point;unset($next['sha256'],$next['target']);$next['data']=self::correct($point['data'],$evidence);
        $next['source_pins']['owner_clarifications']=$evidence;
        $next['revision']=['kind'=>'OWNER_CLARIFICATIONS','previous_sha256'=>$point['sha256']];
        $plan=['schema'=>1,'kind'=>'ZERO_POINT_CLARIFICATION','expected_baseline'=>$point['sha256'],'current_sha256'=>ZeroPoint::digest($live),
            'evidence'=>$evidence,'document'=>$next,'next_sha256'=>ZeroPoint::digest($next)];
        $plan['command_key']=substr(ZeroPoint::digest($plan),0,32);return $plan;
    }
    public static function correct(array $data,array $evidence): array
    {
        if(ZeroPoint::json($evidence)!==ZeroPoint::json(self::evidence()))throw new RuntimeException('Не совпало подтверждение владельца.',409);
        $objects=array_column($data['tables']['rental_objects'],null,'id');
        foreach([1097=>['182Н/17','70.3','17'],1101=>['264Н/22','73.1','22']] as $id=>[$name,$area,$floor]){
            $r=$objects[$id] ?? throw new RuntimeException('Не найден объект.',409);
            if($r['name']!==$name || bccomp($r['area'],$area,4)!==0 || (string)$r['floor']!==$floor || $r['free_since']!==null)
                throw new RuntimeException('Объект или дата изменились.',409);
            foreach(['contract_objects','contract_terms','document_terms'] as $table)foreach($data['tables'][$table] as $relation)
                if((int)$relation['object_id']===$id)throw new RuntimeException('Появилась история аренды: требуется сверка.',409);
        }
        $facts=[];foreach($data['facts'] as $f)if($f['namespace']==='zero_point_data')$facts[$f['source_key']]=json_decode($f['fact_json'],true);
        $last=$facts['last_occupant:1097'] ?? [];
        if(($last['tenant'] ?? '')!=='ФЛ Алехин' || ($last['contract_number'] ?? '')!=='117-Ф' || ($last['end_date'] ?? '')!=='2026-02-26'
            || isset($facts['last_occupant:1101']) || isset($facts['owner_clarification:1097']) || isset($facts['owner_clarification:1101']))throw new RuntimeException('Изменилась история последнего арендатора.',409);
        foreach($data['tables']['rental_objects'] as &$row){
            $id=(int)$row['id'];if(!in_array($id,[1097,1101],true))continue;
            $raw=json_decode($row['source_json'],true,512,JSON_THROW_ON_ERROR);
            if($id===1097){$row['free_since']='2026-02-26';$raw['releaseDateReview']=['basis'=>'OWNER_ACCEPTED_CONTRACT_EXPIRY','date'=>'2026-02-26','contract'=>'117-Ф','confirmed_on'=>'2026-11-18','workbook_sha256'=>self::WORKBOOK];}
            else $raw['physicalHistoryReview']=['status'=>'NO_PHYSICAL_LEASE_FOUND','owner_confirmed'=>true,'legal_address_excluded'=>true,'excluded_contract'=>'132','confirmed_on'=>'2026-11-18'];
            $row['source_json']=ZeroPoint::json($raw);
            $value=['object_id'=>$id,'as_of'=>'2026-10-31','owner_confirmed'=>true,'confirmed_on'=>'2026-11-18',
                'decision'=>$id===1097?$raw['releaseDateReview']:$raw['physicalHistoryReview']];
            $data['facts'][]=['namespace'=>'zero_point_data','source_key'=>'owner_clarification:'.$id,'fact_json'=>ZeroPoint::json($value),'operative'=>0];
        }unset($row);
        return $data;
    }
    public static function apply(array $plan): array {return ZeroPointRevision::apply('zero-point.clarify',$plan);}
    public static function execute(PDO $p,array $plan): array
    {
        ZeroPointRevision::assertPending('zero-point.clarify',$plan);$live=ZeroPoint::capture($p,true);
        if(!hash_equals($plan['current_sha256'],ZeroPoint::digest($live)))throw new RuntimeException('Данные изменились после проверки.',409);
        $next=self::correct($live,$plan['evidence']);
        // Do not write a past vacancy over current occupation, even where no contract relation exists.
        $read=new ObjectReadModel(date('Y-m-d'));
        foreach([1097,1101] as $id)if(empty($read->availability[$id]['available']))throw new RuntimeException('Объект уже занят или забронирован.',409);
        $backup=['schema'=>1,'kind'=>'COMMERCIAL_BEFORE_RESTORE','project_id'=>ZeroPoint::PROJECT,'as_of'=>date('Y-m-d'),'table_schema'=>ZeroPoint::schema($p),'data'=>$live];
        $backupSha=ZeroPoint::digest($backup);ZeroPoint::write(ZeroPoint::directory().'/'.$backupSha.'.json',$backup);
        foreach($next['tables']['rental_objects'] as $row)if(in_array((int)$row['id'],[1097,1101],true))
            $p->prepare('UPDATE rental_objects SET free_since=?,source_json=?,version=version+1 WHERE id=?')->execute([$row['free_since'],$row['source_json'],$row['id']]);
        foreach($next['facts'] as $f)if($f['namespace']==='zero_point_data' && in_array($f['source_key'],['owner_clarification:1097','owner_clarification:1101'],true))
            $p->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,?)')->execute(array_values($f));
        $p->prepare('UPDATE rental_runtime_context SET source_pair_sha256=? WHERE id=1')->execute([$plan['next_sha256']]);
        $runtime=json_decode($p->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active' FOR UPDATE")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$runtime['baseline_sha256']=$plan['next_sha256'];
        $p->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='zero_point_runtime' AND source_key='active'")->execute([ZeroPoint::json($runtime)]);
        ZeroPoint::assertMoney($p);$result=['sha256'=>$plan['next_sha256'],'previous_sha256'=>$plan['expected_baseline'],'backup_sha256'=>$backupSha,'objects'=>[1097,1101]];
        Audit::record('zero-point.clarify','zero_point',$plan['command_key'],null,$result);
        Service::event($p,ZeroPoint::requireAdmin(),'zero-point.clarify','Сохранены уточнения владельца по 182Н/17 и 264Н/22.',null,$result);
        ZeroPoint::write(ZeroPoint::directory().'/revisions/'.$plan['command_key'].'.json',$result);return $result;
    }
}
