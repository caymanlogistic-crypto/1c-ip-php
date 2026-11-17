<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Auxiliary\{Market,MarketCollectionWorker,MarketHttpClient,MarketJobs,MarketSettingsStore,MarketSourceUrls};

if (PHP_SAPI !== 'cli') exit(2);
$options = getopt('', ['root:','initialize-queue','once','capture:','capture-sha256:','allow-public-http']);
if (!is_string($options['root'] ?? null)) throw new RuntimeException('Explicit project-local external --root required.');
$settings = new MarketSettingsStore($options['root']);
if (isset($options['initialize-queue'])) {
    if (isset($options['once']) || isset($options['capture']) || isset($options['allow-public-http'])) throw new RuntimeException('Queue initialization is separate from execution.');
    MarketJobs::initialize($settings);
    echo "{\"initialized\":true,\"network\":0}\n";
    exit;
}
if (!isset($options['once'])) throw new RuntimeException('Explicit --once required; no background service is installed.');
if (isset($options['capture'])) {
    if (isset($options['allow-public-http']) || !is_string($options['capture']) || !is_string($options['capture-sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D',$options['capture-sha256'])) throw new RuntimeException('Captured mode requires an exact SHA and no network flag.');
    $path = $options['capture'];
    if (is_link($path) || !is_file($path) || filesize($path)>16000000) throw new RuntimeException('Captured acquisition input is invalid.');
    $bytes = file_get_contents($path);
    if (!hash_equals($options['capture-sha256'],hash('sha256',$bytes))) throw new RuntimeException('Captured acquisition input changed.');
    $capture = json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    if (($capture['schema'] ?? null) !== 1 || !is_array($capture['pages'] ?? null) || !array_is_list($capture['pages']) || count($capture['pages'])>120) throw new RuntimeException('Invalid captured acquisition envelope.');
    $pages = [];
    foreach ($capture['pages'] as $page) {
        if (!is_array($page) || !is_string($page['source'] ?? null) || !is_string($page['url'] ?? null)
            || !in_array($page['kind'] ?? null,['office','parking'],true) || !is_array($page['result'] ?? null)) throw new RuntimeException('Invalid captured acquisition page.');
        MarketSourceUrls::seed($page['source'],$page['url']);
        $key = Market::canonical([$page['source'],$page['url'],$page['kind']]);
        if (isset($pages[$key])) throw new RuntimeException('Duplicate captured acquisition page.');
        $pages[$key] = $page['result'];
    }
    $acquire = static function(string $source,string $url,string $kind) use($pages): array {
        $key = Market::canonical([$source,$url,$kind]);
        return $pages[$key] ?? throw new RuntimeException('Captured page unavailable.');
    };
} else {
    if (!isset($options['allow-public-http'])) throw new RuntimeException('No acquisition mode admitted; queued jobs were not claimed.');
    $acquire = null;
}
$jobs = new MarketJobs($settings);
$admit = isset($options['capture']) ? null : static function(array $snapshot): void {
    foreach (['cian','yandex'] as $source) if ($snapshot['sources'][$source]['enabled']) throw new RuntimeException('Native browser acquisition is not configured; job not claimed. Configure an office-only source selection.');
};
$status = $jobs->runNext(static function(array $snapshot,string $id,string $stamp) use($acquire): array {
    if ($acquire === null) {
        $http = new MarketHttpClient(true,$snapshot['browser']['timeout_ms'],$snapshot['browser']['pause_ms']);
        $acquire = static function(string $source,string $url,string $kind) use($http,$snapshot): array {
            if (!in_array($source,['morrow','fortex'],true) || $kind !== 'office') throw new RuntimeException('Native browser acquisition required.');
            $page = $http->load($source,$url,$snapshot['browser']['robots_unavailable_policy']);
            return ['outcome'=>$page['outcome'],'http_status'=>$page['http_status'] ?? null,'format'=>'office_html','body'=>$page['html']];
        };
    }
    return MarketCollectionWorker::close($snapshot,$acquire,$id,$stamp);
},$admit);
echo json_encode(['status'=>$status,'acquisition'=>isset($options['capture'])?'captured':'public-http','sql_imported'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
