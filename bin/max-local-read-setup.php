<?php
declare(strict_types=1);
// Explicit local configuration. General external access and AutoMAX remain disabled.
$args = array_slice($argv, 1);
$allowed = ['--enable','--enable-manual-send','--refresh','--rebind-unchanged-profile'];
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows' || array_diff($args, $allowed)
    || count(array_intersect($args, ['--enable','--enable-manual-send'])) !== 1) {
    fwrite(STDERR, "Usage: php bin/max-local-read-setup.php --enable|--enable-manual-send [--refresh] [--rebind-unchanged-profile]\n");
    exit(2);
}
require __DIR__.'/local-preflight.php';
use Modules\Integrations\Config;
$db = App\DB::pdo();
if ((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn() !== 1) throw new RuntimeException('LOCAL_MAX_READ_BUSY');
try {
$database = $db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', $database)
    || (int)$db->query('SELECT @@port')->fetchColumn() !== 43061) throw new RuntimeException('LOCAL_MAX_TASK_DATABASE_REQUIRED');
$connection = Config::get($db, 'max');
$secret = Config::secrets('max');
$groups = $db->query("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 ORDER BY chat_id")->fetchAll(PDO::FETCH_COLUMN);
$approved = $secret['approved_chat_ids'] ?? [];
sort($approved, SORT_STRING);
if (!$connection['enabled'] || empty($secret['secret']) || !$groups || $groups !== $approved) throw new RuntimeException('LOCAL_MAX_EXISTING_APPROVED_PROFILE_REQUIRED');
foreach ($groups as $group) Modules\Integrations\Support::chatId($group);
$manual = in_array('--enable-manual-send', $args, true);
if ($manual) {
    $test = $db->query("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 AND name='ТЕСТОВАЯ'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($test) !== 1 || !in_array($test[0], $approved, true)) throw new RuntimeException('LOCAL_MAX_SINGLE_TEST_GROUP_REQUIRED');
}
$path = APP_ROOT.'/.local/max-read-access.json';
if (is_link($path) || (file_exists($path) && !in_array('--refresh', $args, true))) throw new RuntimeException('LOCAL_MAX_SCOPE_ALREADY_EXISTS');
if (($secret['_owner_bound_revision'] ?? null) !== (int)$connection['revision']) {
    // Explicit repair only when both credential and destination are still the original approved profile.
    $original = $secret['whole_working_profile'] ?? [];
    if (!in_array('--rebind-unchanged-profile', $args, true)
        || !is_string($original['token'] ?? null) || !hash_equals($original['token'], $secret['secret'])
        || $approved !== [(string)($original['selectedGroup']['chatId'] ?? '')]) throw new RuntimeException('CREDENTIAL_REVISION_CONFLICT');
    $backup = APP_ROOT.'/.local/max-vault-before-rebind-'.bin2hex(random_bytes(8)).'.enc';
    if (!copy(APP_ROOT.'/private/integrations/max.enc', $backup)) throw new RuntimeException('LOCAL_MAX_VAULT_BACKUP_FAILED');
    $secret['_owner_bound_revision'] = (int)$connection['revision'];
    Config::saveSecrets('max', $secret);
}
Config::assertSecretRevision($secret, (int)$connection['revision']);
$scope = ['operation'=>'max-token-and-membership-read','app_root'=>APP_ROOT,'database'=>$database,
    'revision'=>(int)$connection['revision'],'chat_ids'=>$groups,
    'vault_sha256'=>hash_file('sha256',APP_ROOT.'/private/integrations/max.enc')];
$scope['manual_send_chat_id'] = $manual ? $test[0] : null;
if (is_file($path) && !copy($path, $path.'.before-'.bin2hex(random_bytes(8)))) throw new RuntimeException('LOCAL_MAX_SCOPE_BACKUP_FAILED');
$temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
$file = fopen($temporary, 'xb');
if (!$file) throw new RuntimeException('LOCAL_MAX_SCOPE_ALREADY_EXISTS');
try {
    $bytes = json_encode($scope, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (fwrite($file, $bytes) !== strlen($bytes)) throw new RuntimeException('LOCAL_MAX_SCOPE_WRITE_FAILED');
} finally { fclose($file); }
if (!rename($temporary, $path)) throw new RuntimeException('LOCAL_MAX_SCOPE_WRITE_FAILED');
echo $manual ? "Local MAX reads and manual sends to the approved TEST group enabled; AutoMAX disabled.\n"
    : "Local MAX reads enabled; sends and AutoMAX disabled.\n";
} finally { $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')"); }
