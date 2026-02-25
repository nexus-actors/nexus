<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Throwable;

/**
 * @psalm-api
 *
 * Internal resolution mechanism for Future-based ask pattern.
 *
 * Each runtime provides its own implementation using runtime-specific
 * suspension primitives (Fiber::suspend, Swoole Channel, etc.).
 */
interface FutureSlot
{
    /**
     * Resolve the slot with a value. Idempotent — second call is a no-op.
     * Wakes the awaiting fiber/coroutine if one is suspended.
     */
    public function resolve(object $value): void;

    /**
     * Fail the slot with an exception. Idempotent — second call is a no-op.
     * Wakes the awaiting fiber/coroutine if one is suspended.
     */
    public function fail(Throwable $e): void;

    /**
     * Whether the slot has been resolved or failed.
     */
    public function isResolved(): bool;

    /**
     * Block the current fiber/coroutine until the slot is resolved or failed.
     *
     * @return object The resolved value
     * @throws Throwable The failure exception if fail() was called
     */
    public function await(): object;
}
