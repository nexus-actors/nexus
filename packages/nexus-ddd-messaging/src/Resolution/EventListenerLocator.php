<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Resolution;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Handler\EventListener;

/**
 * @psalm-api
 *
 * Listeners are 0..N per event class — broadcast semantics. An empty
 * iterable is a valid response (no subscribers); not an error.
 */
interface EventListenerLocator
{
    /** @return iterable<int, EventListener> */
    public function locate(DomainEvent $event): iterable;
}
