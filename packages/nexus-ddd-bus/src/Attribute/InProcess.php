<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an event-handler method as running inside the source aggregate's
 * transaction. The bus's `EventDrain` middleware delegates the actual
 * in-tx vs. write-then-relay semantics to the `Outbox` impl, but the
 * marker is what makes the choice visible at boot.
 *
 * `InProcessSameDbBootValidator` asserts at boot that the handler's
 * bound connection matches the source aggregate's bound connection —
 * an `#[InProcess]` listener may not span two databases. Adopters that
 * change connection bindings at runtime (e.g., env-var swap on deploy)
 * must restart workers so validation re-runs.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class InProcess {}
