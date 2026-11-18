<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\DB;
use Modules\Rental\ZeroPoint;
use RuntimeException;

/** A one-off operator read of the current bound parties. Never writes to OneC. */
final class OnecPartySupplement
{
    public static function directory(): string {return Config::root().'/private/party-enrichment';}
    public static function capture(): array
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only',403);ZeroPoint::requireAdmin();$db=DB::pdo();
        if(OnecDevSshTransport::settings()!==null)throw new RuntimeException('Party supplement requires the approved direct transport.');
        $lock=$db->prepare('SELECT GET_LOCK(?,0)');$lock->execute(['ipphp-provider:onec']);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('OneC provider is busy.',409);
        try{
            $connection=Config::get($db,'onec');$public=Support::decode($connection['config_json']);$profile=Config::profile('onec',$public['access_reference']??'');
            if(!OnecReadAdmission::allows($db,'onec','records',$profile))throw new RuntimeException('Scoped OneC read is unavailable.',403);
            $bindings=(new OnecBindings($db,Config::store()))->source();$scope=[];$baselineIds=array_column(ZeroPoint::load()['data']['tables']['parties'],'id');
            foreach($db->query('SELECT id,source_json FROM parties ORDER BY id')->fetchAll() as $p){$s=Support::decode($p['source_json']);if(in_array($p['id'],$baselineIds,true)&&!empty($s['source_party_guid']))$scope[(string)$p['id']]=$s['source_party_guid'];}
            if(!$scope || count($scope)>100 || count($scope)!==count(array_unique($scope)))throw new RuntimeException('Invalid bound party scope.');
            $reader=OnecReaderFactory::create($profile,60,'connection');$data=$reader->partySupplement(array_values($scope));
            if((int)Config::get($db,'onec')['revision']!==(int)$connection['revision'])throw new RuntimeException('OneC profile changed.',409);
            $capture=['schema'=>1,'kind'=>'ONEC_PARTY_SUPPLEMENT','captured_at'=>date(DATE_ATOM),'project_id'=>ZeroPoint::PROJECT,
                'source_instance'=>$bindings['source_instance'],'source_revision'=>(int)$bindings['revision'],'profile_reference'=>$bindings['profile_ref'],
                'connection_revision'=>(int)$connection['revision'],'scope'=>$scope,'data'=>$data,'requests'=>$reader->requests];
            $sha=ZeroPoint::digest($capture);ZeroPoint::write(self::directory().'/'.$sha.'.json',$capture);
            return ['sha256'=>$sha,'parties'=>count($scope),'contacts'=>count($data['contacts']),'primary_banks'=>count($data['bank_accounts']),'requests'=>count($reader->requests),'external_writes'=>0];
        }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute(['ipphp-provider:onec']);}
    }
    public static function load(string $sha): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new RuntimeException('Invalid supplement SHA.',409);
        $path=self::directory().'/'.$sha.'.json';
        if(!is_file($path)||is_link($path)||!hash_equals($sha,hash_file('sha256',$path)))throw new RuntimeException('Private supplement integrity check failed.',409);
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($data['kind']??'')!=='ONEC_PARTY_SUPPLEMENT'||($data['project_id']??'')!==ZeroPoint::PROJECT||($data['schema']??null)!==1)throw new RuntimeException('Invalid supplement identity.',409);
        return $data;
    }
}
