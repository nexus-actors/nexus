<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

use Monadial\Nexus\Runtime\Exception\FutureException;

/**
 * @psalm-api
 *
 * Internal resolution mechanism for Future-based ask pattern.
 *
 * Each runtime provides its own implementation using runtime-specific
 * suspension primitives (Fiber::suspend, Swoole Channel, etc.).
 *
 * @template R of object
 */
interface FutureSlot
{
    /**
     * Resolve the slot with a value. Idempotent - second call is a no-op.
     * Wakes the awaiting fiber/coroutine if one is suspended.
     *
     * @param R $value
     */
    public function resolve(object $value): void;

    /**
     * Fail the slot with an exception. Idempotent - second call is a no-op.
     * Wakes the awaiting fiber/coroutine if one is suspended.
     *
     */
    public function fail(FutureException $e): void;

    /**
     * Whether the slot has been resolved or failed.
     */
    public function isResolved(): bool;

    /**
     * Block the current fiber/coroutine until the slot is resolved or failed.
     *
     * @return R The resolved value
     * @throws FutureException The failure exception if fail() was called
     */
    public function await(): object;
}
