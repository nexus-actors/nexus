<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @psalm-api
 *
 * Publishes MembershipEvents to the ActorSystem's PSR-14 EventDispatcher.
 * Kept thin: the event object is dispatched as-is. C1.7 will add metrics and
 * trace annotations on a separate layer; this class stays a pure dispatch forwarder.
 */
final readonly class EventDispatcherMembershipEventPublisher implements MembershipEventPublisher
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[Override]
    public function publish(MembershipEvent $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
