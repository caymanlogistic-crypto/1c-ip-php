<?php
declare(strict_types=1);
// Explicit local read configuration, never enables the general external/send gates.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows' || $argc !== 2 || $argv[1] !== '--enable') {
    fwrite(STDERR, "Usage: APP_DB_CONFIG_FILE=<worktree .local/database.php> php bin/max-local-read-setup.php --enable\n");
    exit(2);
}
require __DIR__.'/local-preflight.php';
use Modules\Integrations\Config;
$db = App\DB::pdo();
$database = $db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', $database)
    || (int)$db->query('SELECT @@port')->fetchColumn() !== 43061) throw new RuntimeException('LOCAL_MAX_TASK_DATABASE_REQUIRED');
$connection = Config::get($db, 'max');
$secret = Config::secrets('max');
Config::assertSecretRevision($secret, (int)$connection['revision']);
$groups = $db->query("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 ORDER BY chat_id")->fetchAll(PDO::FETCH_COLUMN);
$approved = $secret['approved_chat_ids'] ?? [];
sort($approved, SORT_STRING);
if (!$connection['enabled'] || empty($secret['secret']) || !$groups || $groups !== $approved) throw new RuntimeException('LOCAL_MAX_EXISTING_APPROVED_PROFILE_REQUIRED');
foreach ($groups as $group) Modules\Integrations\Support::chatId($group);
$scope = ['operation'=>'max-token-and-membership-read','app_root'=>APP_ROOT,'database'=>$database,
    'revision'=>(int)$connection['revision'],'chat_ids'=>$groups,
    'vault_sha256'=>hash_file('sha256',APP_ROOT.'/private/integrations/max.enc')];
$path = APP_ROOT.'/.local/max-read-access.json';
$file = fopen($path, 'xb');
if (!$file) throw new RuntimeException('LOCAL_MAX_SCOPE_ALREADY_EXISTS');
try {
    $bytes = json_encode($scope, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (fwrite($file, $bytes) !== strlen($bytes)) throw new RuntimeException('LOCAL_MAX_SCOPE_WRITE_FAILED');
} finally { fclose($file); }
echo "Local MAX token/membership reads enabled for this worktree; sends and AutoMAX remain disabled.\n";
