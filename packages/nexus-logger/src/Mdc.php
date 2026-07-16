<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use ArrayObject;
use Stringable;
use Swoole\Coroutine;

use function array_merge;
use function class_exists;

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
 *     inside a Swoole coroutine, put() stores under a dedicated key on
 *     Coroutine::getContext() instead of the static tier, so the value
 *     lives only as long as the coroutine. Good for: requestId, traceId,
 *     userId — values that belong to one in-flight request.
 *
 * getAll() returns static ⊕ coroutine (coroutine wins on duplicate keys).
 *
 * Values are log-safe primitives: scalars, Stringable objects, or null —
 * anything the formatters can interpolate or JSON-encode losslessly.
 *
 * Without ext-swoole loaded the coroutine tier transparently disables and
 * everything falls back to the static tier.
 */
final class Mdc
{
    /**
     * Key under which the MDC map lives inside Coroutine::getContext().
     * The coroutine context is a shared per-coroutine store; owning a
     * single namespaced slot keeps the MDC map fully typed.
     */
    private const string CONTEXT_KEY = 'nexus.mdc';

    /** @var array<string, scalar|Stringable|null> */
    private static array $static = [];

    /**
     * Always writes to the thread/process-static tier, bypassing any
     * coroutine context. Use for boot-time process info (host, pid,
     * threadId, service) that should survive across all requests on this
     * thread.
     */
    public static function putStatic(string $key, string|int|float|bool|Stringable|null $value): void
    {
        self::$static[$key] = $value;
    }

    public static function put(string $key, string|int|float|bool|Stringable|null $value): void
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null) {
            $tier = self::coroutineTier($coCtx);
            $tier[$key] = $value;
            $coCtx[self::CONTEXT_KEY] = $tier;

            return;
        }

        self::$static[$key] = $value;
    }

    public static function get(string $key): string|int|float|bool|Stringable|null
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null) {
            $tier = self::coroutineTier($coCtx);

            if (isset($tier[$key])) {
                return $tier[$key];
            }
        }

        return self::$static[$key] ?? null;
    }

    public static function remove(string $key): void
    {
        $coCtx = self::coroutineContext();

        if ($coCtx !== null) {
            $tier = self::coroutineTier($coCtx);

            if (isset($tier[$key])) {
                unset($tier[$key]);
                $coCtx[self::CONTEXT_KEY] = $tier;

                return;
            }
        }

        unset(self::$static[$key]);
    }

    /**
     * Merge of static + coroutine tiers. Coroutine values override static.
     *
     * @return array<string, scalar|Stringable|null>
     */
    public static function getAll(): array
    {
        $coCtx = self::coroutineContext();

        if ($coCtx === null) {
            return self::$static;
        }

        return array_merge(self::$static, self::coroutineTier($coCtx));
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
     * @param array<string, scalar|Stringable|null> $values
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

    /** @return ArrayObject<string, mixed>|null */
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

    /**
     * @param ArrayObject<string, mixed> $coCtx
     * @return array<string, scalar|Stringable|null>
     */
    private static function coroutineTier(ArrayObject $coCtx): array
    {
        /** @var array<string, scalar|Stringable|null> $tier — CONTEXT_KEY is written exclusively by Mdc::put()/remove(), which only accept these value types. */
        $tier = $coCtx[self::CONTEXT_KEY] ?? [];

        return $tier;
    }
}
