<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use NoDiscard;
use Throwable;

/**
 * @psalm-api
 *
 * Public command-dispatch contract. Domain code calls `dispatchCommand`
 * with the raw message; the bus internally constructs an `Envelope`,
 * generates a fresh `MessageId`, and reads the in-flight
 * `MessageContextStack` (DI-injected on the bus) for causation /
 * correlation propagation.
 */
interface CommandBus
{
    /**
     * Dispatch a command to its (single) handler.
     *
     * Returns void — the post-handler outcome flows out via events;
     * idempotency and retry are bus-impl concerns.
     */
    public function dispatchCommand(Command $command): void;

    /**
     * Lifts dispatch failures into Either::left instead of throwing.
     * Boot-time invariants (BusInvariantException) still propagate so
     * misconfiguration surfaces immediately rather than silently
     * disappearing into the error path.
     *
     * @return Either<Throwable, Accepted>
     */
    #[NoDiscard('tryDispatch returns Either; ignoring the result discards the error path')]
    public function tryDispatch(Command $command): Either;
}
