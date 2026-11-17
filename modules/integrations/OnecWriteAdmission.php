<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Operator approval for the existing prepared CREATE journal, never a global network gate. */
final class OnecWriteAdmission
{
    public static function settings(): ?array
    {
        if(getenv('APP_STAND')!=='ui')return null;
        $file=Config::root().'/.local/onec-write-admission.json';
        if(!file_exists($file))return null;
        if(is_link($file)||!is_readable($file)||filesize($file)>4096)throw new \RuntimeException('ONEC_WRITE_ADMISSION_INVALID');
        $v=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        $keys=is_array($v)?array_keys($v):[];sort($keys);
        if($keys!==['connection_revision','enabled','profile_reference','project_id','scope','source_instance','source_revision','stand']
            ||$v['enabled']!==true||$v['stand']!=='ui'||$v['project_id']!==CaptureReader::PROJECT
            ||$v['scope']!=='prepared-counterparty-customer-invoice-create'
            ||!is_string($v['source_instance'])||!preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D',$v['source_instance'])
            ||!is_int($v['connection_revision'])||$v['connection_revision']<1
            ||!is_int($v['source_revision'])||$v['source_revision']<1
            ||!is_string($v['profile_reference'])||Config::reference($v['profile_reference'])!==$v['profile_reference'])throw new \RuntimeException('ONEC_WRITE_ADMISSION_INVALID');
        return $v;
    }

    public static function available(\PDO $db): bool
    {
        if(self::settings()===null)return false;
        try{$source=(new OnecBindings($db,Config::store()))->source();}
        catch(OnecSetupGap){return false;}
        return self::allows($db,$source,$source['profile_ref'],'POST',OnecMapping::COLLECTIONS['counterparty']);
    }

    public static function allows(\PDO $db,array $source,string $reference,string $method,string $collection): bool
    {
        if(!in_array($method,['GET','POST'],true)||!in_array($collection,$method==='POST'?array_values(OnecMapping::COLLECTIONS):[...array_values(OnecMapping::REFS),...array_values(OnecMapping::COLLECTIONS)],true))return false;
        $v=self::settings();if($v===null)return false;
        if(($source['source_instance']??null)!==$v['source_instance']||$reference!==$v['profile_reference']
            ||($source['project_id']??null)!==CaptureReader::PROJECT||($source['profile_ref']??null)!==$reference
            ||(int)($source['revision']??0)!==$v['source_revision'])return false;
        $q=$db->prepare('SELECT enabled,revision,profile_ref,project_id FROM integration_native_sources WHERE source_instance=?');$q->execute([$v['source_instance']]);$current=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$current||!(bool)$current['enabled']||(int)$current['revision']!==$v['source_revision']||$current['profile_ref']!==$reference||$current['project_id']!==CaptureReader::PROJECT)return false;
        $c=Config::get($db,'onec');$public=Support::decode($c['config_json']);
        if(!$c['enabled']||(int)$c['revision']!==$v['connection_revision']||($public['access_reference']??null)!==$reference)return false;
        $q=$db->query("SELECT selected_profile,source_receipt_sha256 FROM integration_configuration_sources WHERE provider='onec'");$selected=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$selected||$selected['selected_profile']!==$reference||($source['config_receipt_sha256']??null)!==$selected['source_receipt_sha256'])return false;
        $secret=Config::profile('onec',$reference);$policy=$secret['write_policy']??[];
        return is_array($policy)&&($policy['enabled']??false)===true&&($policy['project_id']??null)===CaptureReader::PROJECT
            &&($policy['source_instance']??null)===$v['source_instance']
            &&($policy['organization_key']??null)===OnecMapping::ORGANIZATION&&($source['organization_key']??null)===OnecMapping::ORGANIZATION
            &&($policy['organization_inn']??null)==='390613939405'&&($source['organization_inn']??null)==='390613939405';
    }

    public static function createAllowed(\PDO $db): bool
    {
        return (Config::liveAllowed()&&getenv('IPPHP_ALLOW_ONEC_WRITE')==='owner-authorized-prepared-create')||self::available($db);
    }
}
