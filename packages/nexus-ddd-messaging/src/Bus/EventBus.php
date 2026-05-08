<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * Public event-publication contract. "Publish" verb (not "dispatch")
 * matches the broadcast semantics — the publisher does not know who
 * listens.
 */
interface EventBus
{
    public function publishEvent(DomainEvent $event): void;
}
