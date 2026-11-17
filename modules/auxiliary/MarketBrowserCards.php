<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Exact source-S visible primary-list DOM selectors, read through native PHP CDP. */
final class MarketBrowserCards
{
    private static function absolute(string $base,string $href): string
    {
        if (strlen($href)>4096 || preg_match('/[\x00-\x20\\\\]/',$href)) throw new RuntimeException('Unsafe browser card href.');
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i',$href)) return $href;
        $parts=parse_url($base);
        $origin='https://'.$parts['host'];
        if (str_starts_with($href,'//')) return 'https:'.$href;
        if (str_starts_with($href,'/')) return $origin.$href;
        if (str_starts_with($href,'?')) return $origin.$parts['path'].$href;
        return $origin.rtrim(dirname($parts['path']),'/').'/'.$href;
    }

    public static function read(MarketCdp $page,string $source,string $url,int $limit): array
    {
        if (!in_array($source,['cian','yandex'],true) || $limit<1 || $limit>500) throw new RuntimeException('Unsupported browser card source/limit.');
        MarketSourceUrls::seed($source,$url);
        $arguments=json_encode([$source,$limit],JSON_THROW_ON_ERROR);
        $script=<<<'JS'
((source,limit)=>{
    const yandex=source==='yandex';
    const list=yandex?document.querySelector('ol.OffersSerp__list'):(document.querySelector('[data-name="Offers"]')||document);
    const elements=list?[...list.querySelectorAll(yandex?'li.OffersSerpItem':'[data-name="CommercialOfferCard"], [data-name="CardComponent"]')]:[];
    const cards=elements.slice(0,limit).map(element=>({
        href:element.querySelector(yandex?'a[href*="/offer/"]':'a[href*="/rent/commercial/"]')?.getAttribute('href')||null,
        text:element.innerText
    }));
    const pagination=[...document.querySelectorAll(yandex?'a[rel="next"]':'[data-name="Pagination"] a[href]')].slice(0,100).map(a=>a.getAttribute('href'));
    return {supported:yandex?!!list:elements.length>0,cards,pagination,html_prefix:document.documentElement.outerHTML.slice(0,30000)};
})(...ARGUMENTS)
JS;
        $result=$page->call('Runtime.evaluate',['expression'=>str_replace('ARGUMENTS',$arguments,$script),'returnByValue'=>true,'awaitPromise'=>false]);
        if (isset($result['exceptionDetails']) || !is_array($result['result']['value'] ?? null)) throw new RuntimeException('Browser primary DOM extraction failed.');
        $value=$result['result']['value'];
        if (!is_bool($value['supported'] ?? null) || !is_array($value['cards'] ?? null) || count($value['cards'])>$limit
            || !is_array($value['pagination'] ?? null) || count($value['pagination'])>100 || !is_string($value['html_prefix'] ?? null)) throw new RuntimeException('Invalid browser primary DOM result.');
        if (MarketRobots::blocked($value['html_prefix'])) return ['outcome'=>'blocked','format'=>'visible_primary_cards','cards'=>[]];
        $cards=[]; $bytes=0;
        foreach ($value['cards'] as $card) {
            if (!is_array($card) || !is_string($card['href'] ?? null) || !is_string($card['text'] ?? null)) continue;
            $bytes+=strlen($card['text']);
            if ($bytes>3000000) throw new RuntimeException('Browser primary-card text exceeds bound.');
            try { $link=MarketSourceUrls::clean($source,self::absolute($url,$card['href'])); }
            catch (RuntimeException) { continue; }
            $path=parse_url($link,PHP_URL_PATH);
            if (!preg_match($source==='cian'?'~^/rent/commercial/[0-9]+/?$~D':'~^/offer/[0-9]+/?$~D',$path)) continue;
            $cards[]=['url'=>$link,'text'=>$card['text']];
        }
        $next=null;
        foreach ($value['pagination'] as $href) {
            if (!is_string($href)) continue;
            try { $candidate=MarketSourceUrls::clean($source,self::absolute($url,$href)); }
            catch (RuntimeException) { continue; }
            if ($source==='cian') { $next=MarketSourceUrls::nextCian($url,[$candidate]); if ($next!==null) break; }
            else { $next=$candidate; break; }
        }
        return ['outcome'=>'fetched','format'=>'visible_primary_cards','cards'=>$cards,
            'structure_supported'=>$value['supported'],'next_url'=>$next];
    }
}
