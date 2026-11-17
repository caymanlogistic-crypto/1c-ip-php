<?php
declare(strict_types=1);
namespace Modules\Auxiliary;
use App\DB;
use Modules\Rental\Importer;
use PDO;
final class Market {
    public const SOURCES=['cian','yandex','morrow','fortex'];
    public static function canonical(mixed $v):string {if(is_array($v)){if(!array_is_list($v))ksort($v);foreach($v as &$x)if(is_array($x))$x=json_decode(self::canonical($x),true,512,JSON_THROW_ON_ERROR);}return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function validate(array $s):void {
        if(($s['schema_version']??null)!==1||!is_array($s['settings']??null)||!is_array($s['records']??null)||!is_array($s['attempts']??null))throw new \RuntimeException('Неподдерживаемая структура снимка рынка',422);
        $date=(string)($s['created_at']??'');if(!preg_match('/(?:Z|[+-]\d\d:\d\d)$/',$date))throw new \RuntimeException('Дата снимка требует часовой пояс',422);new \DateTimeImmutable($date);
        foreach(['sources','types','geography','filters','limits','browser']as $k)if(!is_array($s['settings'][$k]??null))throw new \RuntimeException('Повреждены параметры снимка: '.$k,422);
        if(count($s['records'])>100000||count($s['attempts'])>10000)throw new \RuntimeException('Снимок превышает ограничение',422);
        foreach($s['records']as $row){if(!is_array($row)||!in_array($row['source']??null,self::SOURCES,true)||!in_array($row['object_type']??null,['office','parking'],true)||!in_array($row['scope']??null,['base','nearby'],true)||!is_string($row['address']??null)||trim($row['address'])==='')throw new \RuntimeException('Повреждены типы или адрес объявления',422);}
        foreach($s['attempts']as $a)if(!is_array($a)||!is_array($a['events']??[]))throw new \RuntimeException('Повреждён результат источника',422);
    }
    public static function urlIdentity(array $r):?string {
        $u=parse_url((string)($r['source_url']??''));if(!$u||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port'])&&$u['port']!==443)return null;
        $rule=match($r['source']){'cian'=>[['cian.ru','www.cian.ru'],'~^/rent/commercial/\d+/?$~','www.cian.ru'],'yandex'=>[['realty.yandex.ru'],'~^/offer/\d+/?$~','realty.yandex.ru'],'fortex'=>[['fortexgroup.ru','www.fortexgroup.ru'],'~^/bc/[^/]+/arenda-ofisa/[^/]+-\d+/?$~','fortexgroup.ru'],default=>null};
        if(!$rule||!in_array(strtolower($u['host']??''),$rule[0],true)||!preg_match($rule[1],$u['path']??''))return null;return $r['source'].'|'.$r['object_type'].'|url:https://'.$rule[2].rtrim($u['path'],'/').'/';
    }
    public static function identity(array $r):?string {return isset($r['source_id'])&&(is_string($r['source_id'])||is_int($r['source_id']))&&trim((string)$r['source_id'])!==''?$r['source'].'|'.$r['object_type'].'|id:'.trim((string)$r['source_id']):self::urlIdentity($r);}
    private static function decimal(mixed $v):?string {if($v===null)return null;$s=(string)$v;if(!preg_match('/^\d{1,19}(?:\.\d{1,4})?$/D',$s))return null;return bcadd($s,'0',4);}
    public static function importDirectory(string $directory):array {
        $real=realpath($directory);if(!$real||!is_dir($real)||is_link($directory)||is_file($real.'/INCOMPLETE')||is_file($real.'/incomplete'))throw new \RuntimeException('Незавершённый или недопустимый снимок рынка');
        $bytes=[];foreach(['COMPLETE'=>4096,'snapshot.json'=>16000000,'records.csv'=>16000000,'sources.csv'=>1000000]as $name=>$limit){$path=$real.'/'.$name;if(is_link($path)||!is_file($path)||filesize($path)>$limit||realpath(dirname($path))!==$real)throw new \RuntimeException('Недопустимый файл снимка: '.$name);$bytes[$name]=file_get_contents($path);}
        $manifest=json_decode($bytes['COMPLETE'],true,512,JSON_THROW_ON_ERROR);$names=array_keys($manifest);sort($names);if($names!==['records.csv','snapshot.json','sources.csv'])throw new \RuntimeException('Некорректный COMPLETE manifest');foreach($manifest as $name=>$hash)if(!is_string($hash)||!hash_equals($hash,hash('sha256',$bytes[$name])))throw new \RuntimeException('SHA256 снимка не совпал: '.$name);
        $s=Importer::decodeExact($bytes['snapshot.json']);self::validate($s);$hash=hash('sha256',$bytes['snapshot.json']);$key=(string)($s['run_id']??$hash);if(!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$key))throw new \RuntimeException('Некорректный ID запуска');
        $archive=APP_ROOT.'/private/market/'.$hash;if(!is_dir($archive))mkdir($archive,0700,true);foreach($bytes as $name=>$raw){$file=$archive.'/'.$name;if(is_file($file)){if(!hash_equals(hash_file('sha256',$file),hash('sha256',$raw)))throw new \RuntimeException('Изменён immutable snapshot');}else{file_put_contents($file,$raw,LOCK_EX);@chmod($file,0400);}}
        return DB::transaction(function(PDO $p)use($s,$hash,$key,$archive){$q=$p->prepare('SELECT * FROM market_runs WHERE run_key=? FOR UPDATE');$q->execute([$key]);if($old=$q->fetch()){if($old['snapshot_hash']!==$hash)throw new \RuntimeException('Конфликт одного ID запуска с разными bytes',409);return ['alreadyImported'=>true,'id'=>$old['id'],'records'=>$old['records_count'],'sha256'=>$hash];}
            $date=(new \DateTimeImmutable($s['created_at']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s.u');$p->prepare('INSERT INTO market_runs(run_key,created_at,snapshot_hash,settings_hash,archive_path,records_count) VALUES(?,?,?,?,?,?)')->execute([$key,$date,$hash,hash('sha256',self::canonical($s['settings'])),'private/market/'.$hash,count($s['records'])]);$id=(int)$p->lastInsertId();
            $q=$p->prepare('INSERT INTO market_observations(run_id,position,source,object_type,scope,source_id,source_url,address,area,price_rub,price_period,annual_rate_rub_m2,currency,identity_key,raw_evidence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');foreach($s['records']as $i=>$r)$q->execute([$id,$i,$r['source'],$r['object_type'],$r['scope'],$r['source_id']??null,$r['source_url']??null,$r['address'],self::decimal($r['area_m2']??$r['area']??null),self::decimal($r['price_rub']??null),$r['price_period']??null,self::decimal($r['annual_rate_rub_m2']??null),$r['currency']??$r['price_currency']??'RUB',self::identity($r),json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $q=$p->prepare('INSERT INTO market_attempts(run_id,source,object_type,scope,outcome,partial,raw_evidence) VALUES(?,?,?,?,?,?,?)');foreach($s['attempts']as $a)$q->execute([$id,$a['source']??null,$a['object_type']??null,$a['scope']??null,$a['outcome']??null,(int)($a['partial']??false),json_encode($a,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);return ['alreadyImported'=>false,'id'=>$id,'records'=>count($s['records']),'sha256'=>$hash];
        });
    }
    public static function snapshot(int $id):array {$q=DB::pdo()->prepare('SELECT archive_path,snapshot_hash FROM market_runs WHERE id=?');$q->execute([$id]);$r=$q->fetch()?:throw new \RuntimeException('Запуск не найден',404);$path=APP_ROOT.'/'.$r['archive_path'].'/snapshot.json';if(!is_file($path)||!hash_equals($r['snapshot_hash'],hash_file('sha256',$path)))throw new \RuntimeException('Исходные bytes снимка повреждены');return Importer::decodeExact(file_get_contents($path));}
}
