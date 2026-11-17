<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Source-S browser_runtime host/method policy, before a resource can reach PHP HTTP. */
final class MarketBrowserPolicy
{
    private const DOCUMENTS=[
        'cian'=>['cian.ru','www.cian.ru'], 'yandex'=>['realty.yandex.ru'],
        'morrow'=>['morrowgroup.ru','www.morrowgroup.ru'], 'fortex'=>['fortexgroup.ru','www.fortexgroup.ru'],
    ];
    private const RESOURCES=[
        'cian'=>['cdn-cian.ru','cdn-cian.site','cian.ru'],
        'yandex'=>['yastatic.net','realty.yandex.ru','avatars.mds.yandex.net'],
        'morrow'=>['morrowgroup.ru'], 'fortex'=>['fortexgroup.ru'],
    ];

    public static function check(string $source,string $url,string $method,string $type): string
    {
        if (!isset(self::DOCUMENTS[$source]) || !in_array($method,['GET','HEAD'],true)) throw new RuntimeException('market_non_read_method_or_source');
        if (in_array(strtolower($type),['image','media','font','websocket'],true)) throw new RuntimeException('market_unneeded_resource');
        $parts=parse_url($url);
        if ($parts===false || ($parts['scheme']??'')!=='https' || !is_string($parts['host']??null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['port']) && $parts['port']!==443 || strlen($url)>4096 || preg_match('/[\x00-\x20\\\\]/',$url)) throw new RuntimeException('market_unsafe_resource_url');
        $host=strtolower($parts['host']);
        $document=strtolower($type)==='document';
        $allowed=in_array($host,self::DOCUMENTS[$source],true);
        if (!$document) foreach(self::RESOURCES[$source] as $suffix) $allowed=$allowed || $host===$suffix || str_ends_with($host,'.'.$suffix);
        if (!$allowed) throw new RuntimeException('market_resource_host_not_allowed');
        $path=$parts['path']??'/';
        if (preg_match('~(?:^|/)\.\.(?:/|$)|%2e|%2f|%5c|%(?![0-9a-f]{2})~i',$path)) throw new RuntimeException('market_unsafe_resource_path');
        if ($document) {
            if (preg_match('/captcha|challenge/i',$path)) throw new RuntimeException('market_challenge_navigation');
            MarketSourceUrls::seed($source,$url);
        }
        return 'https://'.$host;
    }

    public static function contentType(string $type,string $header): bool
    {
        $mime=strtolower(trim(explode(';',$header,2)[0]));
        return match(strtolower($type)) {
            'document'=>in_array($mime,['text/html','application/xhtml+xml'],true),
            'script'=>in_array($mime,['text/javascript','application/javascript','application/x-javascript','text/ecmascript','application/ecmascript'],true),
            'stylesheet'=>$mime==='text/css',
            default=>in_array($mime,['application/json','text/json','text/plain','text/html','application/javascript','text/javascript','text/css'],true)
                || (bool)preg_match('~^application/[a-z0-9.+-]+\+json$~D',$mime),
        };
    }
}
