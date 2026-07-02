<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;

/**
 * @psalm-api
 *
 * Installs coroutine-aware OpenTelemetry context storage for the Swoole runtime,
 * so the active span is isolated per coroutine (no cross-coroutine bleed).
 * Call once per worker at startup; idempotent.
 */
final class SwooleContextRegistrar
{
    public static function install(): void
    {
        if (Context::storage() instanceof SwooleContextStorage) {
            return;
        }

        Context::setStorage(new SwooleContextStorage(Context::storage()));
    }
}
