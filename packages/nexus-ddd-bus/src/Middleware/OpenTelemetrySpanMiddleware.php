<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * No-op default. Activated when `open-telemetry/sdk` is detected — the
 * production adapter lives outside the framework so this package stays
 * dependency-free. The empty implementation preserves the canonical
 * pipeline slot without requiring an OTel install.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class OpenTelemetrySpanMiddleware implements Middleware
{
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        return $next($envelope);
    }
}
