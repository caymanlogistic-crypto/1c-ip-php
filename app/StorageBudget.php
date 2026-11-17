<?php
declare(strict_types=1);
namespace App;

final class StorageBudget
{
    public static function artifactRoot(?string $declaredRoot = null): ?string
    {
        if ($declaredRoot !== null) {
            $path = str_replace('\\', '/', $declaredRoot);
            if (!preg_match('~^(?:[A-Za-z]:/|/)~', $path) || str_starts_with($path, '//')
                || str_contains($path, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
                throw new \RuntimeException('ARTIFACT_ROOT_INVALID');
            }
        }
        $value = $declaredRoot ?? getenv('APP_ARTIFACTS_ROOT');
        if (!$value) return null;
        $real = realpath($value);
        if (!$real || !is_dir($real) || is_link($value)) throw new \RuntimeException('ARTIFACT_ROOT_INVALID');
        return str_replace('\\', '/', $real);
    }

    /** A scoped declared root does not replace the process-wide configuration. */
    public static function target(string $target, int $bytes, ?string $declaredRoot = null): void
    {
        if ($bytes < 0 || str_contains($target, "\0") || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $target)) {
            throw new \RuntimeException('ARTIFACT_TARGET_INVALID');
        }
        $absolute = str_replace('\\', '/', $target);
        if (!preg_match('~^(?:[A-Za-z]:/|/)~', $absolute) || str_starts_with($absolute, '//')) {
            throw new \RuntimeException('ARTIFACT_ABSOLUTE_TARGET_REQUIRED');
        }
        $existing = $target;
        while (!file_exists($existing) && dirname($existing) !== $existing) $existing = dirname($existing);
        $real = realpath($existing);
        if (!$real || is_link($existing)) throw new \RuntimeException('ARTIFACT_TARGET_INVALID');
        $normalize = static fn(string $value) => strtolower(rtrim(str_replace('\\', '/', $value), '/'));
        $actual = $normalize($real).substr($absolute, strlen(str_replace('\\', '/', $existing)));
        $own = $normalize(realpath(APP_ROOT) ?: APP_ROOT);
        $external = self::artifactRoot($declaredRoot);
        $inside = static fn(string $path, string $root) => $path === $root || str_starts_with($path, $root.'/');
        if (!$inside($actual, $own) && !($external && $inside($actual, $normalize($external)))) {
            throw new \RuntimeException('ARTIFACT_TARGET_OUTSIDE_DECLARED_ROOT');
        }
        if ($bytes > 104857600 && (!$external || !$inside($actual, $normalize($external)))) {
            throw new \RuntimeException('LARGE_ARTIFACT_REQUIRES_DECLARED_EXTERNAL_ROOT');
        }
        $reserve = 536870912;
        // Windows PHP expects a directory; probing an existing artifact file returns false.
        $free = disk_free_space(is_dir($real)?$real:dirname($real));
        if ($free === false || $free < $bytes + $reserve) throw new \RuntimeException('ARTIFACT_DESTINATION_SPACE_REQUIRED');
        $appFree = disk_free_space(APP_ROOT);
        if ($appFree === false || $appFree < $reserve) throw new \RuntimeException('APPLICATION_VOLUME_RESERVE_REQUIRED');
    }
}
