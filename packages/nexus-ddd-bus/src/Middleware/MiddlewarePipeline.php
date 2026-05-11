<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

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
final class MiddlewarePipeline implements EnvelopePipeline
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
     * @psalm-suppress MoreSpecificImplementedParamType
     *   `EnvelopePipeline::dispatch` takes `Envelope<object>`; this impl
     *   narrows to `Envelope<TIn>` per its templated `TIn`. The narrower
     *   parameter is sound because callers carry the same template.
     */
    #[Override]
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
