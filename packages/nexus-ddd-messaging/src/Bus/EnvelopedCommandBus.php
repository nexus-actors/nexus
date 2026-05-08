<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/**
 * @psalm-api
 *
 * @internal Framework-facing — used by `Outbox` flush, DLQ replay,
 *           and transport recovery. Domain code uses `CommandBus` directly
 *           and never sees this interface.
 */
interface EnvelopedCommandBus extends CommandBus
{
    /**
     * Dispatch a command via an envelope that already exists — the
     * envelope's `MessageId`, metadata, and stamps are honored verbatim.
     *
     * @param Envelope<Command> $envelope
     */
    public function dispatchEnveloped(Envelope $envelope): void;
}
