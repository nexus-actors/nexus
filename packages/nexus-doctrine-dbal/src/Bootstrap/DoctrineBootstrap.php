<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Bootstrap;

use Swoole\Runtime as SwooleRuntime;

/**
 * Enables Swoole coroutine hooks for Doctrine DBAL drivers. Idempotent.
 * No-op when Swoole is not loaded (Fiber dev runtime).
 *
 * @psalm-api
 */
final class DoctrineBootstrap
{
    private static bool $enabled = false;

    public static function enable(): void
    {
        if (self::$enabled) {
            return;
        }

        if (!extension_loaded('swoole')) {
            return;
        }

        SwooleRuntime::enableCoroutine(SWOOLE_HOOK_ALL);
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
