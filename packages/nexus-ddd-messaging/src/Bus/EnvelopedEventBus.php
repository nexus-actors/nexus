<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * @internal Framework-facing — used by `Outbox` flush, DLQ replay,
 *           and transport recovery. Domain code uses `EventBus` directly.
 */
interface EnvelopedEventBus extends EventBus
{
    /** @param Envelope<DomainEvent> $envelope */
    public function publishEnveloped(Envelope $envelope): void;
}
