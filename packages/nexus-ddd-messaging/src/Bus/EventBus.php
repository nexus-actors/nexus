<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use NoDiscard;
use Throwable;

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

    /**
     * Lifts publication failures into Either::left instead of throwing.
     * Boot-time invariants (BusInvariantException) still propagate.
     *
     * @return Either<Throwable, Accepted>
     */
    #[NoDiscard('tryPublish returns Either; ignoring the result discards the error path')]
    public function tryPublish(DomainEvent $event): Either;
}
