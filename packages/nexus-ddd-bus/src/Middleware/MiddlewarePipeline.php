<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Composes a list of Middleware impls into a single Closure dispatching
 * an Envelope through the canonical pipeline. Built once at boot per
 * handler (cached in HandlerAttributeIndex).
 *
 * @template TIn of object
 * @template TOut
 */
final class MiddlewarePipeline
{
    /**
     * @param list<Middleware<TIn, TOut>> $middlewares  Outermost first; innermost last.
     * @param Closure(Envelope<TIn>): TOut $core
     */
    public function __construct(private readonly array $middlewares, private readonly Closure $core) {}

    /**
     * @param Envelope<TIn> $envelope
     * @return TOut
     *
     * @psalm-suppress InvalidArgument
     *   Closure parameter `$env` erases the `TIn` template; the list type
     *   of `$this->middlewares` guarantees the runtime narrowing.
     */
    public function dispatch(Envelope $envelope): mixed
    {
        $next = $this->core;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $current = $middleware;
            $previous = $next;
            $next = static fn(Envelope $env): mixed => $current->process($env, $previous);
        }

        return $next($envelope);
    }
}
