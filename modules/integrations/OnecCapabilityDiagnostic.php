<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Explicit fixed-route diagnostics, not an authentication fallback or import. */
final class OnecCapabilityDiagnostic
{
    public static function inspectSourceOrganization(\NativeOnec\Reader $reader): array
    {
        $present = array_key_exists('IPPHP_NATIVE_AUTH_EPHEMERAL',$GLOBALS);
        $previous = $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] ?? null;
        $GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = true;
        $fact = ['alias'=>'organizations_source_query','schema_valid'=>false];
        try {
            // The existing source reader owns this fixed route and query. No ROOT prerequisite.
            $result = $reader->rows('organizations',1,0);
            foreach($result['rows'] as $row) {
                if(!is_string($row['Ref_Key'] ?? null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$row['Ref_Key'])
                    ||!is_string($row['DataVersion'] ?? null)||$row['DataVersion']===''||strlen($row['DataVersion'])>128)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
            }
            $fact['schema_valid']=true;
            $fact['count']=$result['count'];
            unset($result,$row);
        } catch(\Throwable $error) {
            $fact['code']=\NativeOnec\Reader::failureCode($error);
        } finally {
            if($present)$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']=$previous;else unset($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL']);
        }
        $requests=[];
        foreach(array_slice($reader->diagnostics,0,1)as$diagnostic){
            $safe=['alias'=>'organizations_source_query'];
            foreach(['http_code'=>599,'curl_errno'=>9999,'ssl_verify_result'=>65535,'total_ms'=>900000,'bytes'=>33554432]as$key=>$limit){
                $value=$diagnostic[$key]??null;$safe[$key]=is_int($value)&&$value>=0&&$value<=$limit?$value:null;
            }
            $requests[]=$safe;
        }
        return ['capabilities'=>[$fact],'requests'=>$requests,'raw_body_archives'=>0,'service_root_rechecked'=>false,
            'business_imports'=>0,'provider_writes'=>0,'query_contract'=>['top'=>1,'skip'=>0,'format'=>'json','orderby'=>'Ref_Key','select'=>false],
            'entity_bindings_admitted'=>0];
    }

    public static function assertUnused(\PDO $db,int $revision,array $source): void
    {
        $query=$db->prepare("SELECT coverage_json FROM integration_runs WHERE provider='onec' AND operation='capability-probe' AND revision=?");
        $query->execute([$revision]);
        while($raw=$query->fetchColumn()){
            $old=Support::decode($raw);
            if(($old['source_receipt_sha256']??null)===$source['source_receipt_sha256']&&($old['profile_reference']??null)===$source['selected_profile'])throw new \RuntimeException('CAPABILITY_DIAGNOSTIC_ALREADY_ATTEMPTED');
        }
    }
    public static function inspect(\NativeOnec\Reader $reader):array
    {
        $facts=[];
        foreach(['organization_metadata','invoice_metadata']as$alias){
            try{
                $count=$reader->capabilityMetadata($alias);
                $facts[]=['alias'=>$alias,'schema_valid'=>true,'count'=>$count];
            }catch(\Throwable$error){
                $facts[]=['alias'=>$alias,'schema_valid'=>false,'code'=>\NativeOnec\Reader::failureCode($error)];
                break; // No retry or next route after any denial, challenge, unknown or schema failure.
            }
        }
        $requests=[];
        foreach(array_slice($reader->diagnostics,0,2)as$index=>$diagnostic){
            $safe=['alias'=>$facts[$index]['alias']??'unknown'];
            foreach(['http_code'=>599,'curl_errno'=>9999,'ssl_verify_result'=>65535,'total_ms'=>900000,'bytes'=>33554432]as$key=>$limit){
                $value=$diagnostic[$key]??null;$safe[$key]=is_int($value)&&$value>=0&&$value<=$limit?$value:null;
            }
            $requests[]=$safe;
        }
        return ['capabilities'=>$facts,'requests'=>$requests,'raw_body_archives'=>0,
            'service_root_rechecked'=>false,'business_imports'=>0,'provider_writes'=>0];
    }

    public static function run(\PDO $db,PrivateStore $store,int $expectedRevision,bool $sourceOrganization=false):array
    {
        $scope=$sourceOrganization?'owner-authorized-once-source-organization-read':'owner-authorized-once-fixed-entity-metadata';
        if(PHP_SAPI!=='cli'||$expectedRevision<1||!Config::liveAllowed()||getenv('IPPHP_ALLOW_ONEC_CAPABILITY')!==$scope)throw new \RuntimeException('CAPABILITY_DIAGNOSTIC_NOT_AUTHORIZED');
        if(!$sourceOrganization&&$expectedRevision!==2)throw new \RuntimeException('CAPABILITY_DIAGNOSTIC_NOT_AUTHORIZED');
        require_once __DIR__.'/native/php/onec/ReadOnly.php';
        $lock='ipphp-provider:onec';$query=$db->prepare('SELECT GET_LOCK(?,0)');$query->execute([$lock]);
        if((int)$query->fetchColumn()!==1)throw new \RuntimeException('PROVIDER_BUSY');
        try{
            $connection=Config::get($db,'onec');$public=Support::decode($connection['config_json']);
            $query=$db->query("SELECT * FROM integration_configuration_sources WHERE provider='onec'");$source=$query->fetch(\PDO::FETCH_ASSOC);
            if(!$source||!$connection['enabled']||(int)$connection['revision']!==$expectedRevision||(int)$connection['revision']!==(int)$source['source_revision']
                ||($public['access_reference']??null)!==$source['selected_profile'])throw new \RuntimeException('SELECTED_CONFIG_CHANGED');
            if($sourceOrganization&&($expectedRevision!==4||$source['variant']!=='mounted-current-onec-v1'||$source['selected_profile']!=='working-mounted-main-20261116-v1'))throw new \RuntimeException('SELECTED_CONFIG_CHANGED');
            self::assertUnused($db,$expectedRevision,$source);
            $id=Support::uuid();$key=Support::uuid();
            $coverage=['mode'=>$sourceOrganization?'once_source_equivalent_organization_read':'once_fixed_entity_metadata','operative_revision'=>(int)$connection['revision'],
                'source_receipt_sha256'=>$source['source_receipt_sha256'],'profile_reference'=>$source['selected_profile'],'raw_body_archives'=>0];
            $db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,coverage_json) VALUES(?,'onec','capability-probe',?,0,?,?,'pending',?)")
                ->execute([$id,$key,$connection['revision'],hash('sha256',Support::canonical($coverage)),Support::json($coverage)]);
            $secret=Config::profile('onec',$source['selected_profile']);
            $reader=OnecReaderFactory::create($secret,60,'connection',Support::caFile());
            if(OnecDevSshTransport::settings()!==null)$coverage['transport']=OnecDevSshTransport::identity();
            $coverage+=$sourceOrganization?self::inspectSourceOrganization($reader):self::inspect($reader);
            $success=count($coverage['capabilities'])===($sourceOrganization?1:2)&&!array_filter($coverage['capabilities'],static fn($fact)=>!$fact['schema_valid']);
            $status=$success?'success':'failed';$code=$sourceOrganization?($success?'ORGANIZATION_SOURCE_QUERY_CHECKED':'ORGANIZATION_SOURCE_QUERY_NOT_CONFIRMED'):($success?'ENTITY_METADATA_CHECKED':'ENTITY_METADATA_NOT_CONFIRMED');
            $current=Config::get($db,'onec');$query=$db->query("SELECT * FROM integration_configuration_sources WHERE provider='onec'");$currentSource=$query->fetch(\PDO::FETCH_ASSOC);
            $coverage['operative_scope_current']=$current['enabled']&&(int)$current['revision']===(int)$connection['revision']&&$current['config_json']===$connection['config_json']
                &&$currentSource&&$currentSource['selected_profile']===$source['selected_profile']&&$currentSource['source_receipt_sha256']===$source['source_receipt_sha256']
                &&(int)$currentSource['source_revision']===(int)$source['source_revision'];
            if(!$coverage['operative_scope_current']){$status='failed';$code='REVISION_CONFLICT';}
            $receipt=$store->spool(['run_id'=>$id,'provider'=>'onec','operation'=>'capability-probe','revision'=>(int)$connection['revision']],Support::json(['status'=>$status,'code'=>$code,'coverage'=>$coverage]));
            $coverage['receipt_path']=$receipt;
            $db->prepare("UPDATE integration_runs SET status=?,result_code=?,coverage_json=?,finished_at=NOW(6) WHERE id=? AND status='pending'")
                ->execute([$status,$code,Support::json($coverage),$id]);
            return ['id'=>$id,'status'=>$status,'code'=>$code,'revision'=>(int)$connection['revision'],'capabilities'=>$coverage['capabilities'],'requests'=>$coverage['requests'],'operative_scope_current'=>$coverage['operative_scope_current'],'receipt_path'=>$receipt,'raw_body_archives'=>0];
        }finally{$query=$db->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$lock]);}
    }
}
