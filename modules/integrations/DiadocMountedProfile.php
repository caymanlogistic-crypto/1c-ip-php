<?php
declare(strict_types=1);
namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** A single normal portal auth check for the explicitly supplied mounted profile. */
final class DiadocMountedProfile
{
    public const REFERENCE='working-mounted-diadoc-20261116-v1';
    public const REQUEST='74553669-6837-4482-a866-c8757240ee4f';
    public const ADMISSION='working-mounted-diadoc-once-20261116';

    public static function candidate(array $entry):array
    {
        if(count($entry)!==4||array_diff(array_keys($entry),['provider','reference','credentials','box_id'])
            ||($entry['provider']??null)!=='diadoc'||($entry['reference']??null)!=='main'
            ||!is_array($entry['credentials']??null)||count($entry['credentials'])!==2
            ||array_diff(array_keys($entry['credentials']),['username','password']))throw new RuntimeException('DIADOC_MOUNTED_PROFILE_INVALID');
        foreach(['username'=>320,'password'=>1024]as$key=>$limit){
            $value=$entry['credentials'][$key]??null;
            if(!is_string($value)||$value===''||strlen($value)>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('DIADOC_MOUNTED_PROFILE_INVALID');
        }
        if(!is_string($entry['box_id'])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$entry['box_id']))throw new RuntimeException('DIADOC_MOUNTED_PROFILE_INVALID');
        // No cookie, session or pending challenge is inherited from the previous identity.
        return $entry['credentials']+['box_id'=>$entry['box_id'],'_source_mounted_profile'=>$entry];
    }

    public static function assertCurrent(array $connection,array $source,int $revision,string $reference):void
    {
        $public=Support::decode($connection['config_json']??'{}');
        if(empty($connection['enabled'])||(int)($connection['revision']??0)!==$revision
            ||(int)($source['source_revision']??0)!==$revision||($source['selected_profile']??null)!==$reference
            ||($public['access_reference']??null)!==$reference)throw new RuntimeException('DIADOC_PROFILE_CAS_CONFLICT');
    }

    private static function authorized():void
    {
        if(PHP_SAPI!=='cli'||getenv('IPPHP_DIADOC_MOUNTED_ADMISSION')!==self::ADMISSION||!Config::liveAllowed())throw new RuntimeException('DIADOC_MOUNTED_NOT_AUTHORIZED');
    }

    public function __construct(private PDO $db,private PrivateStore $store){}

    private function source(bool $lock=false):array
    {
        return $this->db->query("SELECT * FROM integration_configuration_sources WHERE provider='diadoc'".($lock?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC)
            ?:throw new RuntimeException('SELECTED_CONFIG_REQUIRED');
    }

    public function activateAndCheck():array
    {
        self::authorized();
        if($this->db->inTransaction())throw new RuntimeException('DIADOC_NO_OPEN_TRANSACTION_REQUIRED');
        $lock='ipphp-provider:diadoc';$query=$this->db->prepare('SELECT GET_LOCK(?,0)');$query->execute([$lock]);
        if((int)$query->fetchColumn()!==1)throw new RuntimeException('PROVIDER_BUSY');
        try{
            $connection=Config::get($this->db,'diadoc');$source=$this->source();
            $reference=Support::decode($connection['config_json'])['access_reference']??'';
            self::assertCurrent($connection,$source,2,$reference);
            $file=Config::root().'/.local/integration-source/working-diadoc-current-server-20261116.profile.json';
            if(!is_file($file)||is_link($file)||filesize($file)>65536)throw new RuntimeException('DIADOC_MOUNTED_HANDOFF_REQUIRED');
            $candidate=self::candidate(Support::decode(file_get_contents($file)));
            $old=Config::profile('diadoc',$reference);
            if($old['username']===$candidate['username']&&$old['password']===$candidate['password']&&($old['box_id']??null)===$candidate['box_id'])throw new RuntimeException('DIADOC_PROFILE_NOT_CHANGED');
            unset($old);
            $all=Config::secrets('diadoc');
            if(isset($all['profiles'][self::REFERENCE])||file_exists(Config::root().'/private/integrations/state/diadoc/'.self::REFERENCE))throw new RuntimeException('DIADOC_CANDIDATE_ALREADY_EXISTS');
            unset($all);
            $proof=['provider'=>'diadoc','source_kind'=>'current-runtime-LoadCredential-mounted-diadoc-main',
                'source_authority_report_sha256'=>'2a2638fe75941845242c2a5228dd0244f5f5ad1d59740a521b92d3a07cc18054',
                'predecessor'=>$source,'sessions_copied'=>false,'credentials_hashed'=>false,'auth_raw_body_archive_forbidden'=>true];
            $path=$this->store->put('owner-config-provenance',Support::json($proof));
            $candidate['_source_receipt_sha256']=basename($path);Config::saveProfile('diadoc',self::REFERENCE,$candidate);unset($candidate);
            $this->db->beginTransaction();
            try{
                $current=Config::get($this->db,'diadoc');$currentSource=$this->source(true);
                self::assertCurrent($current,$currentSource,2,$reference);
                if($currentSource!==$source||$current['config_json']!==$connection['config_json'])throw new RuntimeException('DIADOC_PROFILE_CAS_CONFLICT');
                $public=Support::decode($current['config_json']);$public['access_reference']=self::REFERENCE;
                Config::save($this->db,'diadoc',$public,2,true);
                $this->db->prepare("UPDATE integration_configuration_sources SET variant='mounted-current-diadoc-v1',source_receipt_sha256=?,source_revision=3,selected_profile=?,enabled_in_source=1,metadata_json=?,activated_at=NOW(6) WHERE provider='diadoc'")
                    ->execute([basename($path),self::REFERENCE,Support::json($proof)]);
                $this->db->commit();
            }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
            // ReadOnlyCheck durably reserves REQUEST before any portal request. Pending or
            // failed runs are reused, and never cause another password/push operation.
            $result=(new ReadOnlyCheck($this->db,$this->store))->run('diadoc',self::REQUEST,false,3);
            return $result+['revision'=>3,'mode'=>'normal-web-cookie-first','sessions_inherited'=>false];
        }finally{$query=$this->db->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$lock]);}
    }
}
