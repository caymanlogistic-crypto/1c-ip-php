<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Public read acquisition through PHP policy; caller supplies its verified own browser page. */
final class MarketBrowserAcquisition
{
    public static function load(MarketCdp $page,MarketHttpClient $http,string $source,string $url,string $kind,
        int $timeoutMs,int $pauseMs,int $limit,string $robotsPolicy='strict_stop'): array
    {
        if(!in_array($source,['cian','yandex'],true) || !in_array($kind,['office','parking'],true)
            || $timeoutMs<5000 || $timeoutMs>60000 || $pauseMs<1000 || $pauseMs>10000)throw new RuntimeException('Invalid native browser acquisition settings.');
        MarketSourceUrls::seed($source,$url);
        $page->events();
        $denied=[];$documentState=null;$requests=0;$bytes=0;
        $page->onEvent(static function(array$event)use($page,$http,$source,$robotsPolicy,&$denied,&$documentState,&$requests,&$bytes):void{
            if(($event['method']??'')!=='Fetch.requestPaused')return;
            $request=$event['params']['request']??null;$id=$event['params']['requestId']??null;$type=$event['params']['resourceType']??'Other';
            if(!is_array($request)||!is_string($id)||strlen($id)>200||!is_string($request['url']??null)||!is_string($request['method']??null)||!is_string($type))throw new RuntimeException('Invalid intercepted browser request.');
            try{
                if(++$requests>200)throw new RuntimeException('market_page_request_limit');
                $response=$http->resource($source,$request['url'],$request['method'],$type,$robotsPolicy);
                if($response['outcome']!=='fetched')throw new RuntimeException('market_resource_not_admitted');
                $bytes+=strlen($response['body']);
                if($bytes>12000000)throw new RuntimeException('market_page_byte_limit');
                $headers=[];
                foreach($response['headers']as$name=>$value){
                    if(!in_array($name,['content-type','content-security-policy','x-content-type-options','referrer-policy',
                        'access-control-allow-origin','access-control-allow-methods','cross-origin-resource-policy'],true))continue;
                    if(!is_string($value)||preg_match('/[\r\n\x00]/',$value))throw new RuntimeException('market_invalid_response_header');
                    $headers[]=['name'=>$name,'value'=>$value];
                }
                $page->notify('Fetch.fulfillRequest',['requestId'=>$id,'responseCode'=>200,'responseHeaders'=>$headers,'body'=>base64_encode($response['body'])]);
                if(strtolower($type)==='document')$documentState='fetched';
            }catch(RuntimeException$error){
                $reason=$error->getMessage();
                if(!preg_match('/^market_[a-z_]{1,70}$/D',$reason))$reason='market_transport_or_policy_failure';
                $denied[$reason]=($denied[$reason]??0)+1;
                $page->notify('Fetch.failRequest',['requestId'=>$id,'errorReason'=>'BlockedByClient']);
                if(strtolower($type)==='document')$documentState='blocked';
            }
        });
        try{
            $page->call('Network.enable');
            $page->call('Network.setBlockedURLs',['urls'=>['ws://*','wss://*']]);
            $page->call('Network.setBypassServiceWorker',['bypass'=>true]);
            $page->call('Page.enable');
            $page->call('Fetch.enable',['patterns'=>[['urlPattern'=>'*','requestStage'=>'Request']]]);
            $navigation=$page->call('Page.navigate',['url'=>$url]);
            if(!empty($navigation['errorText'])||$documentState==='blocked')return ['outcome'=>'blocked','format'=>'visible_primary_cards','cards'=>[],'network_denied'=>$denied];
            $wait='new Promise(resolve=>{const done=()=>setTimeout(()=>resolve(true),'.(int)$pauseMs.');if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",done,{once:true});else done();})';
            $ready=$page->call('Runtime.evaluate',['expression'=>$wait,'returnByValue'=>true,'awaitPromise'=>true,'timeout'=>$timeoutMs]);
            if(isset($ready['exceptionDetails'])||$documentState!=='fetched')throw new RuntimeException('Native browser document was not verified.');
            $final=$page->call('Runtime.evaluate',['expression'=>'location.href','returnByValue'=>true])['result']['value']??null;
            if(!is_string($final))throw new RuntimeException('Native browser location unavailable.');
            MarketSourceUrls::seed($source,$final);
            if(MarketSourceUrls::clean($source,$final)!==MarketSourceUrls::clean($source,$url))throw new RuntimeException('Native browser changed the requested source seed.');
            return MarketBrowserCards::read($page,$source,$url,$limit)+['http_status'=>200,'network_denied'=>$denied,'requests'=>$requests,'resource_bytes'=>$bytes];
        }finally{
            // Disable automatic continuation only after every new/paused browser URL is blocked.
            $page->onEvent(static function(array$event)use($page):void{
                if(($event['method']??'')==='Fetch.requestPaused' && is_string($event['params']['requestId']??null)) {
                    $page->notify('Fetch.failRequest',['requestId'=>$event['params']['requestId'],'errorReason'=>'BlockedByClient']);
                }
            });
            $page->call('Network.setBlockedURLs',['urls'=>['*']]);
            $page->call('Fetch.disable');
            $page->onEvent(null);
            $page->events();
        }
    }
}
