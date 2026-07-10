<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Override;

/**
 * @psalm-api
 *
 * Decorates a {@see MembershipEventPublisher}: when a node is declared {@see NodeDown}, fails every
 * in-flight ask targeting it before forwarding the event. The inbound-link onClose path already
 * fails asks on a socket close, but a phi-accrual Down (a black-holed/half-open peer that never
 * sends EOF) produces no socket close — so without this the reply can never arrive yet the awaiting
 * caller parks until its per-ask timeout. Failing on the authoritative Down decision closes that gap.
 */
final readonly class AskFailingMembershipEventPublisher implements MembershipEventPublisher
{
    public function __construct(private MembershipEventPublisher $inner, private TcpAskRegistry $askRegistry) {}

    #[Override]
    public function publish(MembershipEvent $event): void
    {
        if ($event instanceof NodeDown) {
            $this->askRegistry->failAllForNode($event->node);
        }

        $this->inner->publish($event);
    }
}
