<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** One explicit CLI admission is consumed before any upstream request. */
final class FreshLoginAdmission
{
    public static function requireAuthorized(string $provider): void
    {
        if (PHP_SAPI !== 'cli'
            || getenv('IPPHP_ALLOW_FRESH_LOGIN') !== 'owner-authorized-single-fresh-login'
            || !in_array($provider, ['mosvodokanal', 'pik'], true)) {
            throw new \RuntimeException('FRESH_LOGIN_NOT_AUTHORIZED');
        }
    }

    public static function assertUnused(\PDO $db, string $provider, int $revision, array $source): void
    {
        self::requireAuthorized($provider);
        $query = $db->prepare("SELECT coverage_json FROM integration_runs WHERE provider=? AND operation='auth-metadata' AND revision=? AND actor_id=0");
        $query->execute([$provider, $revision]);
        while ($raw = $query->fetchColumn()) {
            $coverage = Support::decode($raw);
            if (($coverage['auth_request_mode'] ?? null) === 'fresh_login_once'
                && ($coverage['source_receipt_sha256'] ?? null) === $source['source_receipt_sha256']
                && ($coverage['private_profile_reference'] ?? null) === $source['selected_profile']) {
                // A pending or failed attempt is also consumed; uncertainty is never retried.
                throw new \RuntimeException('FRESH_LOGIN_ALREADY_ATTEMPTED');
            }
        }
    }
}
