<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Onion-style middleware. Implementations may transform the envelope
 * before calling $next($envelope), short-circuit (skip the handler),
 * inspect the return value, or wrap exceptions.
 *
 * @template TIn of object
 * @template TOut
 */
interface Middleware
{
    /**
     * @param Envelope<TIn> $envelope
     * @param Closure(Envelope<TIn>): TOut $next
     * @return TOut
     */
    public function process(Envelope $envelope, Closure $next): mixed;
}
