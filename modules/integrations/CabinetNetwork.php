<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** CLI cabinet traffic stays on the host-resolved, scoped HTTPS routes. */
final class CabinetNetwork
{
    private const HOSTS = [
        'auth.kontur.ru'=>'diadoc', 'diadoc.kontur.ru'=>'diadoc',
        'lkkjr.mosenergosbyt.ru'=>'mosenergo', 'onewind.mosvodokanal.ru'=>'mosvodokanal',
        'resident-cabinet-back.pik-software.ru'=>'pik',
        'storage.pik-software.ru'=>'pik',
    ];

    public static function options(string $host): array
    {
        $host = strtolower($host);
        $provider = self::HOSTS[$host]??null;
        if ($provider===null || !isset(CabinetSyncAdmission::configured()[$provider])) return [];
        $path = Config::root().'/.local/cabinet-network.json';
        if (is_link($path)||!is_file($path)||filesize($path)>4096) throw new \RuntimeException('CABINET_NETWORK_UNAVAILABLE');
        $map = json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        $ip = $map[$host]??null;
        if (!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)) {
            throw new \RuntimeException('CABINET_NETWORK_UNAVAILABLE');
        }
        return [CURLOPT_RESOLVE=>[$host.':443:'.$ip]];
    }

    public static function mesTransport(): callable
    {
        return static function(string $method,string $path,?array $body,array $query,array $headers): array {
            $host='lkkjr.mosenergosbyt.ru';
            $pinned=self::options($host);
            if (!$pinned || !str_starts_with($path,'/api/') || !in_array($method,['GET','POST','PUT'],true)) {
                throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
            }
            $url='https://'.$host.$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
            $ch=curl_init($url);
            $raw='';
            $limit=str_ends_with($path,'/download')?16777216:8388608;
            $options=$pinned+[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function($ch,string $data)use(&$raw,$limit):int{
                    if(strlen($raw)+strlen($data)>$limit)return 0;
                    $raw.=$data;
                    return strlen($data);
                }];
            if($body!==null)$options[CURLOPT_POSTFIELDS]=$body===[]?'{}':Support::json($body);
            curl_setopt_array($ch,$options);
            if(curl_exec($ch)===false)throw new \RuntimeException('NETWORK_OR_TLS_FAILED');
            return ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'raw'=>$raw];
        };
    }
}
