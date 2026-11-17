<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use DateTimeImmutable;
use RuntimeException;

/** Native bounded collection loop. Acquisition is an explicit adapter, never implicit HTTP. */
final class MarketCollectionWorker
{
    private static function page(string $source, string $url, string $kind, array $page, string $stamp): array
    {
        if (($page['format'] ?? null) === 'office_html' && in_array($source, ['morrow','fortex'], true)) {
            if (!is_string($page['body'] ?? null)) throw new RuntimeException('Invalid office capture.');
            return MarketOfficeHtml::extract($source, $url, $page['body'], $stamp);
        }
        if (($page['format'] ?? null) !== 'visible_primary_cards' || !in_array($source, ['cian','yandex'], true)
            || !is_array($page['cards'] ?? null) || !array_is_list($page['cards']) || count($page['cards']) > 500) throw new RuntimeException('Unsupported acquisition format.');
        if (($page['structure_supported'] ?? true) === false) return ['records'=>[],'state'=>'unsupported_structure'];
        $records = []; $bytes = 0;
        foreach ($page['cards'] as $card) {
            if (!is_array($card) || !is_string($card['url'] ?? null) || !is_string($card['text'] ?? null)) throw new RuntimeException('Invalid captured primary card.');
            $bytes += strlen($card['text']) + strlen($card['url']);
            if ($bytes > 6000000) throw new RuntimeException('Captured primary cards exceed source DOM bound.');
            if (MarketRobots::blocked($card['text'])) return ['records' => [], 'state' => 'challenge'];
            $row = MarketPrimaryCards::parse($source, $card['url'], $card['text'], $stamp);
            if ($row !== null) $records[] = $row;
        }
        return ['records' => $records, 'state' => $records === [] && $page['cards'] !== [] ? 'unsupported_structure' : 'parsed_primary_listing'];
    }

    private static function next(string $source, string $current, mixed $next): ?string
    {
        if ($next === null) return null;
        if (!is_string($next)) throw new RuntimeException('Invalid next page.');
        $next = MarketSourceUrls::seed($source, $next);
        if ($source !== 'cian' || MarketSourceUrls::nextCian($current, [$next]) !== $next) throw new RuntimeException('Unsupported or changed pagination scope.');
        return $next;
    }

    /**
     * Adapter: (source, seed, kind) -> outcome plus office_html/body or
     * visible_primary_cards/cards. A real adapter must own network admission.
     * @return array{attempts:array,records:array}
     */
    public static function collect(array $settings, callable $acquire, string $stamp): array
    {
        $settings = MarketSettings::validate($settings);
        if (!preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $stamp)) throw new RuntimeException('Collection observation needs a timezone.');
        new DateTimeImmutable($stamp);
        $attempts = []; $all = [];
        foreach ($settings['sources'] as $source => $config) {
            if (!$config['enabled']) continue;
            $sourceStopped = null;
            foreach ($settings['types'] as $kind) {
                $seeds = $config[$kind.'_urls'];
                $byScope = ['base' => [], 'nearby' => []];
                $identities = ['base' => [], 'nearby' => []];
                $events = []; $loaded = 0; $supported = false; $pageLimit = false;
                foreach ($seeds as $seed) {
                    if ($sourceStopped !== null) { $events[] = $sourceStopped + ['skipped_after_source_stop' => true]; break; }
                    $url = $seed; $visited = [];
                    for ($index = 0; $index < $settings['limits']['max_pages_per_seed']; $index++) {
                        if (isset($visited[$url])) break;
                        $visited[$url] = true;
                        try {
                            $page = $acquire($source, $url, $kind);
                            if (!is_array($page) || !in_array($page['outcome'] ?? null, ['fetched','blocked','failed'], true)) throw new RuntimeException('Invalid collection adapter result.');
                            $event = ['source_url' => $url, 'outcome' => $page['outcome'], 'stage' => 'page'];
                            if(isset($page['network_denied'])) {
                                if(!is_array($page['network_denied']) || count($page['network_denied'])>50)throw new RuntimeException('Invalid resource denial summary.');
                                foreach($page['network_denied'] as $reason=>$count)if(!is_string($reason)||!preg_match('/^market_[a-z_]{1,70}$/D',$reason)||!is_int($count)||$count<0||$count>10000)throw new RuntimeException('Invalid resource denial counter.');
                                $event['network_denied']=$page['network_denied'];
                            }
                            if (isset($page['http_status']) && is_int($page['http_status'])) $event['http_status'] = $page['http_status'];
                            if ($page['outcome'] !== 'fetched') {
                                $events[] = $event;
                                if ($page['outcome'] === 'blocked') $sourceStopped = $event;
                                break;
                            }
                            $loaded++;
                            // Captured text is evidence too: retain exact content hashes without copying it into status events.
                            $event['capture_sha256'] = hash('sha256', Market::canonical(array_intersect_key($page, array_flip(['format','body','cards']))));
                            $parsed = self::page($source, $url, $kind, $page, $stamp);
                            if ($parsed['state'] === 'challenge') {
                                $event['outcome'] = 'blocked'; $event['stage'] = 'parse';
                                $events[] = $event; $sourceStopped = $event; break;
                            }
                            $event['parse_state'] = $parsed['state'];
                            $events[] = $event;
                            $supported = $supported || $parsed['state'] !== 'unsupported_structure';
                            foreach ($parsed['records'] as $row) {
                                $scope = MarketPrimaryCards::scope($row['address'], $settings['geography']);
                                if ($scope === null || !MarketPrimaryCards::accepted($row, $kind, $settings['filters'][$kind])
                                    || count($byScope[$scope]) >= $settings['limits']['max_listings_per_scope']) continue;
                                $key = Market::canonical([$row['source_id'], $row['source_url'], $row['area_m2'], $row['floor']]);
                                if (isset($identities[$scope][$key])) continue;
                                $identities[$scope][$key] = true;
                                $row['scope'] = $scope;
                                $byScope[$scope][] = $row;
                            }
                            $next = self::next($source, $url, $page['next_url'] ?? null);
                            if ($next === null) break;
                            if ($index + 1 === $settings['limits']['max_pages_per_seed']) $pageLimit = true;
                            $url = $next;
                        } catch (\Throwable $error) {
                            $events[] = ['source_url' => $url, 'outcome' => 'failed', 'stage' => 'adapter_or_parse', 'error_type' => get_class($error)];
                            break;
                        }
                    }
                }
                $failures = array_values(array_filter($events, static fn(array $event): bool => in_array($event['outcome'], ['blocked','failed'], true)));
                $blocked = array_filter($failures, static fn(array $event): bool => $event['outcome'] === 'blocked') !== [];
                foreach ($byScope as $scope => $rows) {
                    $capped = count($rows) >= $settings['limits']['max_listings_per_scope'];
                    $attempts[] = [
                        'source' => $source, 'object_type' => $kind, 'scope' => $scope,
                        'outcome' => $rows !== [] ? 'extracted' : ($blocked ? 'blocked' : ($failures !== [] ? 'failed' : ($supported ? 'no_matching_listings' : 'unsupported'))),
                        'records' => count($rows), 'pages_loaded' => $loaded, 'coverage' => 'bounded_seed_sample',
                        'partial' => $failures !== [] || $pageLimit || $capped,
                        'stop_reason' => $failures !== [] ? 'source_error' : ($capped ? 'listing_limit' : ($pageLimit ? 'page_limit' : ($seeds === [] ? 'no_supported_section' : 'no_next_link'))),
                        'events' => $events,
                    ];
                    array_push($all, ...$rows);
                }
            }
        }
        return ['attempts' => $attempts, 'records' => $all];
    }

    public static function close(array $settings, callable $acquire, string $runId, string $stamp): array
    {
        $result = self::collect($settings, $acquire, $stamp);
        return MarketRunClosure::write($settings, $result['attempts'], $result['records'], $runId, $stamp);
    }
}
