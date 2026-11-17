<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Auxiliary\MarketSettingsStore;

if (PHP_SAPI !== 'cli') exit(2);
$options = getopt('', ['initialize', 'root:', 'channel:']);
if (!isset($options['initialize'], $options['root']) || !is_string($options['root'])) {
    fwrite(STDERR, "Explicit --initialize --root=<new-project-external-directory> required.\n");
    exit(2);
}
MarketSettingsStore::initialize($options['root'], $options['channel'] ?? 'msedge');
echo json_encode(['initialized' => true, 'collection_started' => false, 'network' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
