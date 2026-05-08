<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Messaging\Message\Command;

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
}
