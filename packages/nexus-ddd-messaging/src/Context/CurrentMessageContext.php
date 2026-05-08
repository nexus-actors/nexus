<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Façade over `ContextStorage`. Domain code interacts only with `current()`
 * (read-only) and `within()` (boundary entry). Bus implementations
 * additionally use `push()` / `pop()` and may install a custom storage
 * via `setStorage()` for coroutine-aware runtimes.
 */
final class CurrentMessageContext
{
    private static ?ContextStorage $storage = null;

    public static function getStorage(): ContextStorage
    {
        return self::storage();
    }

    public static function setStorage(ContextStorage $storage): void
    {
        self::$storage = $storage;
    }

    public static function resetStorage(): void
    {
        self::$storage = null;
    }

    /** @return Option<MessageContext> */
    public static function current(): Option
    {
        return self::storage()->current();
    }

    /** @internal Bus implementations call this when entering a handler. */
    public static function push(MessageContext $ctx): void
    {
        self::storage()->push($ctx);
    }

    /** @internal Bus implementations call this when a handler returns. */
    public static function pop(): void
    {
        self::storage()->pop();
    }

    /**
     * Application-boundary helper: run `$callback` with `$ctx` as the active
     * context, then restore. Exception-safe (try/finally).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function within(MessageContext $ctx, callable $callback): mixed
    {
        self::push($ctx);

        try {
            return $callback();
        } finally {
            self::pop();
        }
    }

    private static function storage(): ContextStorage
    {
        return self::$storage ??= new StaticStackContextStorage();
    }
}
