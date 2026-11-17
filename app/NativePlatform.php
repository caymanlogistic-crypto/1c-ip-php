<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Local prerequisite only. It neither reads a profile nor performs a provider action. */
final class NativePlatform
{
    public static function requireOwnerChecks(string $family = PHP_OS_FAMILY, ?callable $available = null): void
    {
        if ($family === 'Windows') return;
        if ($family !== 'Linux') throw new RuntimeException('Native provider runtime supports Windows or Linux only.',503);
        $available ??= function_exists(...);
        if (!$available('posix_geteuid')) throw new RuntimeException(
            'Linux native provider runtime requires the POSIX extension and enabled posix_geteuid for private-file owner checks.',503);
    }
}
