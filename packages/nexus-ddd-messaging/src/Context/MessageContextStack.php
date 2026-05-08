<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Stack of in-flight message contexts. Bus implementations push a new
 * context before invoking a handler and pop after; nested dispatches
 * walk back up the stack via current() to read parent metadata for
 * causation/correlation propagation.
 *
 * Composes a pluggable ContextStorage so concurrent runtimes (Swoole
 * coroutines, ReactPHP) replace per-process state with coroutine-keyed
 * isolation. The default factory wires StaticStackContextStorage; tests
 * and adapters inject their own.
 *
 * Instance-based; there is no global "current context" accessor. Every
 * collaborator that needs to read or push context receives a
 * MessageContextStack via constructor injection.
 */
final class MessageContextStack
{
    public function __construct(private readonly ContextStorage $storage) {}

    public static function default(): self
    {
        return new self(new StaticStackContextStorage());
    }

    public function storage(): ContextStorage
    {
        return $this->storage;
    }

    /** @return Option<MessageContext> */
    public function current(): Option
    {
        return $this->storage->current();
    }

    /** @internal Bus implementations call this when entering a handler. */
    public function push(MessageContext $ctx): void
    {
        $this->storage->push($ctx);
    }

    /** @internal Bus implementations call this when a handler returns. */
    public function pop(): void
    {
        $this->storage->pop();
    }

    /**
     * Application-boundary helper: run `$callback` with `$ctx` as the active
     * context, then restore. Exception-safe (try/finally).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function within(MessageContext $ctx, callable $callback): mixed
    {
        $this->push($ctx);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }
}
