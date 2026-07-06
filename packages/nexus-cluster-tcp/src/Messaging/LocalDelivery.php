<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Override;

/**
 * @psalm-api
 *
 * Default {@see InboundDelivery} for a single-`ActorSystem` node: resolves the target via
 * a {@see LocalActorRegistry} and enqueues the message through the envelope-preserving
 * delivery seam so an injected reply sender survives on the mailbox as the message sender.
 */
final readonly class LocalDelivery implements InboundDelivery
{
    public function __construct(private LocalActorRegistry $registry) {}

    /**
     * @param ActorRef<object>|null $replySender
     */
    #[Override]
    public function deliver(string $targetPath, object $message, ?ActorRef $replySender): DeliveryOutcome
    {
        $ref = $this->registry->resolve($targetPath);

        if ($ref === null) {
            return DeliveryOutcome::Unroutable;
        }

        $sender = $replySender?->path() ?? ActorPath::root();
        $envelope = Envelope::of($message, $sender, $ref->path());

        if ($replySender !== null) {
            $envelope = $envelope->withSenderRef($replySender);
        }

        return $ref->offerEnvelope($envelope) === EnqueueResult::Accepted
            ? DeliveryOutcome::Delivered
            : DeliveryOutcome::Unroutable;
    }
}
