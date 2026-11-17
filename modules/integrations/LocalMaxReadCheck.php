<?php
declare(strict_types=1);
namespace Modules\Integrations;

use PDO;
use RuntimeException;

/** Worktree-only MAX scope. Manual sends require a separately approved test destination. */
final class LocalMaxReadCheck
{
    private ?array $scope = null;
    private int $next = 0;
    private ?string $pending = null;
    private string $operation = 'check';
    private ?string $sendBody = null;

    public function __construct(private PDO $db) {}

    public static function configured(): bool
    {
        return is_file(Config::root().'/.local/max-read-access.json');
    }

    private function context(): array
    {
        $root = Config::root();
        $path = $root.'/.local/max-read-access.json';
        if (PHP_OS_FAMILY !== 'Windows' || !in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            || Config::liveAllowed() || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')
            || !is_file($path) || is_link($path) || $this->db->inTransaction()) {
            throw new RuntimeException('LOCAL_MAX_READ_NOT_AUTHORIZED');
        }
        $scope = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $settings = json_decode(file_get_contents($root.'/.local/runtime-settings.json'), true, 32, JSON_THROW_ON_ERROR);
        $verification = json_decode(file_get_contents($root.'/.local/copy-verification.json'), true, 32, JSON_THROW_ON_ERROR);
        $normalize = static fn(string $p): string => strtolower(str_replace('\\', '/', $p));
        if (($scope['operation'] ?? null) !== 'max-token-and-membership-read'
            || $normalize((string)($scope['app_root'] ?? '')) !== $normalize($root)
            || !preg_match('/^ipphp_task_[a-z0-9_]{1,20}$/D', (string)($scope['database'] ?? ''))
            || ($settings['database'] ?? null) !== $scope['database']
            || ($settings['max_enabled'] ?? null) !== false
            || ($verification['database'] ?? null) !== $scope['database']
            || ($verification['isolated_test_data'] ?? null) !== true
            || ($verification['exact_copy_before_login'] ?? null) !== true
            || $this->db->query('SELECT DATABASE()')->fetchColumn() !== $scope['database']
            || (int)$this->db->query('SELECT @@port')->fetchColumn() !== 43061
            || $this->db->query('SELECT CURRENT_USER()')->fetchColumn() !== $scope['database'].'@127.0.0.1') {
            throw new RuntimeException('LOCAL_MAX_READ_CONTEXT_CHANGED');
        }
        $connection = Config::get($this->db, 'max');
        $secret = Config::secrets('max');
        Config::assertSecretRevision($secret, (int)$connection['revision']);
        if (!$connection['enabled'] || ($scope['revision'] ?? null) !== (int)$connection['revision']
            || !hash_equals((string)($scope['vault_sha256'] ?? ''), hash_file('sha256', $root.'/private/integrations/max.enc'))) {
            throw new RuntimeException('LOCAL_MAX_READ_CONTEXT_CHANGED');
        }
        $groups = $this->db->query("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 ORDER BY chat_id")->fetchAll(PDO::FETCH_COLUMN);
        if (!$groups || count($groups) > 100 || ($scope['chat_ids'] ?? null) !== $groups) {
            throw new RuntimeException('LOCAL_MAX_READ_GROUP_CHANGED');
        }
        foreach ($groups as $group) Support::chatId($group);
        return $scope;
    }

    private function current(): array
    {
        if ($this->scope === null || $this->context() !== $this->scope) throw new RuntimeException('LOCAL_MAX_READ_NOT_AUTHORIZED');
        return $this->scope;
    }

    public static function assertPath(int $next, string $method, string $path, ?string $body, array $groups): void
    {
        $paths = ['/me'];
        foreach ($groups as $group) $paths[] = '/chats/'.Support::chatId($group).'/members/me';
        if ($method !== 'GET' || $body !== null || !isset($paths[$next]) || $paths[$next] !== $path) {
            throw new RuntimeException('LOCAL_MAX_READ_PATH_NOT_AUTHORIZED');
        }
    }

    public static function assertSendPath(int $next, string $method, string $path, ?string $body, string $chat, string $expectedBody): void
    {
        Support::chatId($chat);
        if ($next !== 0 || $method !== 'POST' || $path !== '/messages?chat_id='.rawurlencode($chat)
            || $body !== $expectedBody || $expectedBody === '') throw new RuntimeException('LOCAL_MAX_SEND_PATH_NOT_AUTHORIZED');
    }

    private function checkPath(string $method, string $path, ?string $body): void
    {
        $scope = $this->current();
        if ($this->operation === 'send') {
            self::assertSendPath($this->next, $method, $path, $body, $scope['manual_send_chat_id'], $this->sendBody ?? '');
        } else self::assertPath($this->next, $method, $path, $body, $scope['chat_ids']);
    }

    public function assertOperation(string $provider, string $operation, string $target, string $raw, array $groups): void
    {
        $scope = $this->current();
        sort($groups, SORT_STRING);
        if ($provider !== 'max' || $operation !== $this->operation || $groups !== $scope['chat_ids']) {
            throw new RuntimeException('LOCAL_MAX_READ_NOT_AUTHORIZED');
        }
        if ($operation === 'check') {
            if ($target !== '@connection' || $raw !== '') throw new RuntimeException('LOCAL_MAX_READ_NOT_AUTHORIZED');
        } else {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if ($target !== ($scope['manual_send_chat_id'] ?? null) || !is_array($body) || array_keys($body) !== ['text']
                || !is_string($body['text']) || trim($body['text']) === '') throw new RuntimeException('LOCAL_MAX_SEND_NOT_AUTHORIZED');
            $this->sendBody = $raw;
        }
    }

    public function beforeRequest(string $method, string $path, ?string $body): void
    {
        $this->checkPath($method, $path, $body);
        $this->pending = $path;
    }

    public function assertHttpRequest(string $method, string $url, ?string $body): void
    {
        $scope = $this->current();
        $prefix = 'https://platform-api2.max.ru';
        if (!str_starts_with($url, $prefix.'/')) throw new RuntimeException('LOCAL_MAX_READ_PATH_NOT_AUTHORIZED');
        $path = substr($url, strlen($prefix));
        $this->checkPath($method, $path, $body);
        if ($this->pending !== $path) throw new RuntimeException('LOCAL_MAX_READ_PATH_NOT_AUTHORIZED');
        $this->pending = null;
        $this->next++;
    }

    public function dispatch(int $actor, string $key): array
    {
        return $this->command($actor, $key, 'check', [], '');
    }

    public function dispatchManual(int $actor, string $key, array $targets, string $text): array
    {
        return $this->command($actor, $key, 'send', $targets, $text);
    }

    private function command(int $actor, string $key, string $operation, array $targets, string $text): array
    {
        $context = $this->context();
        if ($operation === 'send') {
            if (count($targets) !== 1 || !is_string($context['manual_send_chat_id'] ?? null)) throw new RuntimeException('LOCAL_MAX_SEND_NOT_AUTHORIZED');
            $q = $this->db->prepare("SELECT chat_id FROM messaging_groups WHERE provider='max' AND enabled=1 AND name='ТЕСТОВАЯ' AND id=?");
            $q->execute([reset($targets)]);
            if ($q->fetchColumn() !== $context['manual_send_chat_id']) throw new RuntimeException('LOCAL_MAX_SEND_NOT_AUTHORIZED');
        }
        $q = $this->db->prepare("SELECT u.id,u.active,u.role role_id,r.grants_configured,CASE WHEN u.role='admin' THEN 'admin' ELSE COALESCE(rp.kind,'viewer') END role FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?");
        $q->execute([$actor]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        $permissions = $this->db->prepare("SELECT permission_key FROM role_permissions WHERE role_id=?");
        $permissions->execute([$user['role_id'] ?? '']);
        $grants = $permissions->fetchAll(PDO::FETCH_COLUMN);
        if (!$user || !$user['active']
            || (!in_array('*', $grants, true) && array_diff(['messaging.read','messaging.send'], $grants))
            || \App\PagePolicy::levelForRole($user['role_id'], $user['role'], (bool)$user['grants_configured'], 'connections.max') < 2) {
            throw new RuntimeException('LOCAL_MAX_READ_ACTOR_NOT_AUTHORIZED');
        }
        if ((int)$this->db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn() !== 1) throw new RuntimeException('LOCAL_MAX_READ_BUSY');
        try {
            $this->scope = $this->context();
            $this->operation = $operation;
            $this->sendBody = null;
            $this->next = 0;
            $this->pending = null;
            $pending = $this->db->prepare("SELECT COUNT(*) FROM messaging_attempts WHERE provider='max' AND status='pending' AND NOT(actor_id=? AND request_key=?)");
            $pending->execute([$actor, $key]);
            if ((int)$pending->fetchColumn() > 0) throw new RuntimeException('LOCAL_MAX_READ_PENDING_REQUIRES_RECONCILIATION');
            $transport = function($p,$o,$c,$s,$t,$r,$id,$g): array {
                sort($g, SORT_STRING);
                return (new MessageTransport(null,null,null,$this))->execute($p,$o,$c,$s,$t,$r,$id,$g);
            };
            return (new Messaging($this->db, Config::store(), $transport))->dispatch('max', $actor, $key, $operation, $targets, $text);
        } finally {
            $this->scope = null;
            $this->pending = null;
            $this->sendBody = null;
            $this->db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
        }
    }
}
