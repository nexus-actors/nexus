<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Override;

/**
 * @psalm-api
 *
 * Drains recorded events from the active aggregate(s) into the outbox.
 * Profile-aware semantics (per panel H7 — Sync + no in-process subs: no-op;
 * Sync + in-process subs: drain in-tx, #[InProcess] failure rolls back the
 * handler's TX boundary; Async/Actor: write-then-relay) are delegated to
 * the `Outbox::flush()` implementation downstream. From the bus side we
 * always call `flush()` after `$next` and let the outbox impl decide.
 *
 * Causation chain (per panel M7): emitted events get
 * `causationId = sourceCommand.messageId` and `depth+1` — handled by the
 * `Outbox::flush()` implementation downstream, not by this middleware.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class EventDrainMiddleware implements Middleware
{
    public function __construct(private readonly Outbox $outbox) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $result = $next($envelope);

        $this->outbox->flush();

        return $result;
    }
}
