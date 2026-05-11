<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * No-op default. Symmetric exit for `OpenTelemetrySpanMiddleware` — when
 * an adapter package (open-telemetry/sdk) is wired in, the real
 * implementation closes the span opened on entry. The empty default keeps
 * the canonical pipeline slot intact without requiring an OTel install.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class SpanCloseMiddleware implements Middleware
{
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        return $next($envelope);
    }
}
