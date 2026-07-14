<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Override;

/**
 * @psalm-api
 *
 * Default {@see InboundDelivery} for a single-`ActorSystem` node: resolves the target via
 * a {@see LocalActorRegistry} and enqueues the message through the envelope-preserving
 * delivery seam so an injected reply sender survives on the mailbox as the message sender.
 *
 * Unroutable delivers (registry miss or mailbox rejection) are counted via {@see drops()}.
 * This covers both inbound remote frames (routed via {@see InboxRouter}) and self-node tells
 * from {@see ClusterRef} that bypass the remote path.
 */
final class LocalDelivery implements InboundDelivery
{
    private int $drops = 0;

    public function __construct(
        private readonly LocalActorRegistry $registry,
        private readonly Observability $observability = new NoopObservability(),
    ) {}

    /**
     * @param ActorRef<object>|null $replySender
     */
    #[Override]
    public function deliver(string $targetPath, object $message, ?ActorRef $replySender): DeliveryOutcome
    {
        $ref = $this->registry->resolve($targetPath);

        if ($ref === null) {
            ++$this->drops;

            return DeliveryOutcome::Unroutable;
        }

        $sender = $replySender?->path() ?? ActorPath::root();
        $envelope = Envelope::of($message, $sender, $ref->path());

        if ($replySender !== null) {
            $envelope = $envelope->withSenderRef($replySender);
        }

        // Carry the active trace context (the cluster.receive span) onto the envelope so the
        // receiving actor's `process` span parents to it — this is what chains the distributed
        // trace across the network boundary into local actor processing.
        if ($this->observability->isEnabled()) {
            $carrier = [];
            $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);

            if ($carrier !== []) {
                $envelope = $envelope->withMetadata($carrier);
            }
        }

        $result = $ref->offerEnvelope($envelope) === EnqueueResult::Accepted
            ? DeliveryOutcome::Delivered
            : DeliveryOutcome::Unroutable;

        if ($result === DeliveryOutcome::Unroutable) {
            ++$this->drops;
        }

        return $result;
    }

    /**
     * Number of unroutable delivers (registry miss or mailbox rejection).
     */
    public function drops(): int
    {
        return $this->drops;
    }
}
