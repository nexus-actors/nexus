<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use ArrayObject;
use Swoole\Coroutine;

use function array_merge;
use function class_exists;
use function iterator_to_array;

/**
 * @psalm-api
 *
 * Mapped Diagnostic Context (à la SLF4J / Logback). A two-tier key/value
 * store that the Logger merges into every Record's context:
 *
 *   - **Static tier** — process / thread scope. In Swoole SWOOLE_THREAD
 *     mode each worker thread has its own PHP heap, so a static field is
 *     naturally per-thread isolated. Good for: host, pid, threadId,
 *     service name.
 *
 *   - **Coroutine tier** — optional, requires ext-swoole. When called
 *     inside a Swoole coroutine, put() stores on Coroutine::getContext()
 *     instead of the static tier, so the value lives only as long as the
 *     coroutine. Good for: requestId, traceId, userId — values that
 *     belong to one in-flight request.
 *
 * getAll() returns static ⊕ coroutine (coroutine wins on duplicate keys).
 *
 * Without ext-swoole loaded the coroutine tier transparently disables and
 * everything falls back to the static tier.
 */
/**
 * @psalm-suppress MixedAssignment — MDC values are intentionally mixed.
 */
final class Mdc
{
    /** @var array<string, mixed> */
    private static array $static = [];

    /**
     * Always writes to the thread/process-static tier, bypassing any
     * coroutine context. Use for boot-time process info (host, pid,
     * threadId, service) that should survive across all requests on this
     * thread.
     */
    public static function putStatic(string $key, mixed $value): void
    {
        self::$static[$key] = $value;
    }

    public static function put(string $key, mixed $value): void
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null) {
            $coCtx[$key] = $value;

            return;
        }

        self::$static[$key] = $value;
    }

    public static function get(string $key): mixed
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null && isset($coCtx[$key])) {
            return $coCtx[$key];
        }

        return self::$static[$key] ?? null;
    }

    public static function remove(string $key): void
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null && isset($coCtx[$key])) {
            unset($coCtx[$key]);

            return;
        }

        unset(self::$static[$key]);
    }

    /**
     * Merge of static + coroutine tiers. Coroutine values override static.
     *
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        $coCtx = self::coroutineContext();

        if ($coCtx === null) {
            return self::$static;
        }

        /** @var array<string, mixed> $coArray */
        $coArray = iterator_to_array($coCtx);

        return array_merge(self::$static, $coArray);
    }

    /**
     * Clear ONLY the static tier. Coroutine contexts are cleared
     * automatically when the coroutine ends.
     */
    public static function clearStatic(): void
    {
        self::$static = [];
    }

    /**
     * Scoped MDC override. Sets each key, runs $fn, restores previous
     * values (or removes them) in a finally block.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $fn
     * @return T
     */
    public static function scope(array $values, callable $fn): mixed
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = self::get($key);
            self::put($key, $value);
        }

        try {
            return $fn();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    self::remove($key);
                } else {
                    self::put($key, $value);
                }
            }
        }
    }

    private static function coroutineContext(): ?ArrayObject
    {
        if (!class_exists(Coroutine::class)) {
            return null;
        }

        if (Coroutine::getCid() <= 0) {
            return null;
        }

        /** @var ArrayObject<string, mixed> */
        return Coroutine::getContext();
    }
}
