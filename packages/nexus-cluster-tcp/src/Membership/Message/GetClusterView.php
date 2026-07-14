<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 *
 * Query the actor's current membership snapshot. The actor replies with its
 * current {@see ClusterView} to `replyTo` and leaves its state unchanged.
 */
final readonly class GetClusterView
{
    /**
     * @param ActorRef<ClusterView> $replyTo
     */
    public function __construct(public ActorRef $replyTo) {}
}
