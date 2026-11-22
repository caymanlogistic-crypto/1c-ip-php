<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class DadataLookup
{
    private const HOST='suggestions.dadata.ru';
    private const BASE='https://'.self::HOST.'/suggestions/api/4_1/rs/findById/';

    private function __construct(
        private readonly string $url,private readonly string $body,private readonly ?string $address
    ) {}

    public static function available(): bool
    {
        return self::apiKey()!==null && (getenv('APP_STAND')!=='ui' || self::pinnedAddress()!==null);
    }

    public static function read(string $kind,string $number,string $kpp): array
    {
        $key=self::apiKey();
        if ($key===null) throw new \RuntimeException('DADATA_NOT_CONFIGURED');
        $onSite=getenv('APP_STAND')==='ui';
        $address=$onSite?self::pinnedAddress():null;
        if ($onSite && $address===null) throw new \RuntimeException('DADATA_NETWORK_NOT_CONFIGURED');
        if ($kind==='bik' && preg_match('/^[0-9]{9}$/D',$number) && $kpp==='') {
            $method='bank';
            $payload=['query'=>$number];
        } elseif ($kind==='inn' && preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',$number)
            && ($kpp==='' || strlen($number)===10 && preg_match('/^[0-9]{4}[0-9A-Z]{2}[0-9]{3}$/D',$kpp))) {
            $method='party';
            $payload=['query'=>$number,'type'=>strlen($number)===10?'LEGAL':'INDIVIDUAL','count'=>2];
            if ($kpp!=='') $payload['kpp']=$kpp;
        } else {
            throw new \InvalidArgumentException('INVALID_DADATA_QUERY');
        }
        $url=self::BASE.$method;
        $body=json_encode($payload,JSON_THROW_ON_ERROR);
        $response=HttpTransport::request('POST',$url,[
            'Authorization: Token '.$key,'Accept: application/json','Content-Type: application/json',
        ],$body,524288,7,readCheck:new self($url,$body,$address));
        if ($response['status']!==200) throw new \RuntimeException('DADATA_UNAVAILABLE');
        return self::suggestions(json_decode($response['raw'],true,32,JSON_THROW_ON_ERROR));
    }

    public static function suggestions(mixed $result): array
    {
        if (!is_array($result['suggestions'] ?? null) || !array_is_list($result['suggestions'])) {
            throw new \RuntimeException('INVALID_DADATA_RESPONSE');
        }
        return $result['suggestions'];
    }

    public function assertHttpRequest(string $method,string $url,?string $body): void
    {
        if ($method!=='POST' || $url!==$this->url || $body!==$this->body) {
            throw new \RuntimeException('DADATA_READ_OPERATION_NOT_AUTHORIZED');
        }
    }

    public function resolve(): array
    {
        return $this->address===null?[]:[self::HOST.':443:'.$this->address];
    }

    private static function pinnedAddress(): ?string
    {
        $path=APP_ROOT.'/.local/dadata-network.json';
        $file=is_file($path) && !is_link($path)? @file_get_contents($path,false,null,0,512):false;
        $pin=is_string($file)?json_decode($file,true):null;
        $ip=$pin['ipv4'] ?? null;
        return ($pin['host'] ?? null)===self::HOST && is_string($ip)
            && filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)?$ip:null;
    }

    private static function apiKey(): ?string
    {
        $path=APP_ROOT.'/.local/dadata-party.json';
        $file=is_file($path) && !is_link($path)? @file_get_contents($path,false,null,0,4096):false;
        $key=is_string($file)? (json_decode($file,true)['api_key'] ?? null):null;
        return is_string($key) && preg_match('/^[a-zA-Z0-9]{20,128}$/D',$key)? $key:null;
    }
}
