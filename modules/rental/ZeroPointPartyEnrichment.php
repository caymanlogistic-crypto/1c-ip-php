<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\{Audit,BusinessIds,DB};
use Modules\Integrations\OnecPartySupplement;
use PDO;
use RuntimeException;

/** The approved one-off directory supplement, preserved in the existing immutable point. */
final class ZeroPointPartyEnrichment
{
    public const BASELINE='d99fa48dffe0363362441872ead1f9d5173de4619c0a129cd30cf596dbe0f6f0';
    private static function access(): void
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only',403);ZeroPoint::requireAdmin();
    }
    private static function sourceCurrent(PDO $p,array $capture,bool $lock=false): void
    {
        $q=$p->prepare('SELECT revision,profile_ref FROM integration_native_sources WHERE source_instance=? AND project_id=? AND enabled=1'.($lock?' FOR UPDATE':''));$q->execute([$capture['source_instance'],ZeroPoint::PROJECT]);$source=$q->fetch();
        $current=$p->query("SELECT enabled,revision,config_json FROM integration_connections WHERE provider='onec'".($lock?' FOR UPDATE':''))->fetch();
        if(!$source || !$current || !(int)$current['enabled'] || (int)$source['revision']!==$capture['source_revision'] || $source['profile_ref']!==$capture['profile_reference']
            || (int)$current['revision']!==$capture['connection_revision'] || (json_decode($current['config_json'],true)['access_reference']??'')!==$source['profile_ref'])throw new RuntimeException('OneC source changed.',409);
    }
    public static function prepare(array $evidence): array
    {
        self::access();if(array_keys($evidence)!==['capture_sha256'])throw new RuntimeException('Invalid supplement evidence.',409);
        $capture=OnecPartySupplement::load($evidence['capture_sha256']);$at=strtotime($capture['captured_at']);
        if(!$at || $at>time()+30 || time()-$at>7200)throw new RuntimeException('Supplement is stale. Read OneC again.',409);
        $p=DB::pdo();if($p->inTransaction())throw new RuntimeException('Separate read transaction required.',409);
        self::sourceCurrent($p,$capture);
        $point=ZeroPoint::load();if($point['sha256']!==self::BASELINE)throw new RuntimeException('Baseline changed.',409);
        $p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try{$live=ZeroPoint::capture($p);$nextId=max(BusinessIds::next($p,'party_bank_accounts'),max(array_column($point['data']['tables']['party_bank_accounts'],'id'))+1);$p->rollBack();}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
        $current=PartySupplementMapping::correct($live,$capture,$nextId);$corrected=PartySupplementMapping::correct($point['data'],$capture,$nextId);
        $next=$point;unset($next['sha256'],$next['target']);$next['data']=$corrected['data'];$next['source_pins']['party_enrichment']=['capture_sha256'=>$evidence['capture_sha256'],'captured_at'=>$capture['captured_at'],'source_instance'=>$capture['source_instance']];
        $next['revision']=['kind'=>'ONEC_PARTY_ENRICHMENT','previous_sha256'=>$point['sha256']];$next['summary']['counts']=array_map('count',$next['data']['tables']);
        if($corrected['report']['banks_selected']>0){$next['limitations']=array_values(array_filter($next['limitations'],static fn($s)=>!str_starts_with($s,'Рабочие банковские счета не подтверждены:')));$next['limitations'][]='Контакты и основные счета дополнены текущим чтением 1С от '.date('d.m.Y',$at).'; отсутствие сведений не заменялось догадкой. Историческое состояние реквизитов на 31.10 отдельно не удостоверяется.';}
        $plan=['schema'=>1,'kind'=>'ZERO_POINT_PARTY_ENRICHMENT','expected_baseline'=>$point['sha256'],'current_sha256'=>ZeroPoint::digest($live),'evidence'=>$evidence,'next_bank_id'=>$nextId,
            'document'=>$next,'next_sha256'=>ZeroPoint::digest($next),'report'=>$current['report']];$plan['command_key']=substr(ZeroPoint::digest($plan),0,32);return $plan;
    }
    public static function apply(array $plan): array {return ZeroPointRevision::apply('zero-point.parties',$plan);}
    public static function execute(PDO $p,array $plan): array
    {
        ZeroPointRevision::assertPending('zero-point.parties',$plan);$live=ZeroPoint::capture($p,true);
        if(!hash_equals($plan['current_sha256'],ZeroPoint::digest($live)))throw new RuntimeException('Data changed after preview.',409);
        $capture=OnecPartySupplement::load($plan['evidence']['capture_sha256']);self::sourceCurrent($p,$capture,true);$next=PartySupplementMapping::correct($live,$capture,(int)$plan['next_bank_id'])['data'];
        $backup=['schema'=>1,'kind'=>'COMMERCIAL_BEFORE_RESTORE','project_id'=>ZeroPoint::PROJECT,'as_of'=>date('Y-m-d'),'table_schema'=>ZeroPoint::schema($p),'data'=>$live];$backupSha=ZeroPoint::digest($backup);ZeroPoint::write(ZeroPoint::directory().'/'.$backupSha.'.json',$backup);
        foreach(['parties','party_bank_accounts'] as $table){
            $old=array_column($live['tables'][$table],null,'id');
            foreach($next['tables'][$table] as $row){
                $before=$old[$row['id']]??null;if($before && ZeroPoint::json($before)===ZeroPoint::json($row))continue;
                if(!$before){$cols=array_keys($row);$p->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));continue;}
                $fields=[];foreach($row as $key=>$value)if($key!=='id' && $key!=='version' && (string)$value!==(string)$before[$key])$fields[$key]=$value;
                if(!$fields)continue;$set=implode(',',array_map(static fn($k)=>'`'.$k.'`=?',array_keys($fields)));if($table==='parties')$set.=',version=version+1';
                $p->prepare('UPDATE '.$table.' SET '.$set.' WHERE id=?')->execute([...array_values($fields),$row['id']]);
            }
        }
        $p->prepare('UPDATE rental_runtime_context SET source_pair_sha256=? WHERE id=1')->execute([$plan['next_sha256']]);
        $runtime=json_decode($p->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active' FOR UPDATE")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$runtime['baseline_sha256']=$plan['next_sha256'];
        $p->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='zero_point_runtime' AND source_key='active'")->execute([ZeroPoint::json($runtime)]);
        ZeroPoint::assertMoney($p);$result=['sha256'=>$plan['next_sha256'],'previous_sha256'=>$plan['expected_baseline'],'backup_sha256'=>$backupSha,'report'=>$plan['report']];
        Audit::record('zero-point.parties','zero_point',$plan['command_key'],null,$result);Service::event($p,ZeroPoint::requireAdmin(),'zero-point.parties','Разово дополнены реквизиты контрагентов из текущей 1С.',null,$result);
        ZeroPoint::write(ZeroPoint::directory().'/revisions/'.$plan['command_key'].'.json',$result);return $result;
    }
}
