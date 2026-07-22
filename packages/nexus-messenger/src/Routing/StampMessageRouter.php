<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Override;
use Symfony\Component\Messenger\Envelope;

/**
 * Cluster-seam router: resolves the TargetActorPathStamp on the envelope
 * against a path → ActorRef registry. Messages without the stamp, or with a
 * path not present in the registry, are unroutable.
 *
 * When a TargetAuthorizer is supplied, producer → target routing is authorized
 * per envelope: a resolved target is only returned if the authorizer permits the
 * envelope's producer to reach it, so a producer with publish rights cannot invoke
 * every registered target (SEC-012). A denied envelope is unroutable — the
 * ReceiverActor rejects or dead-letters it per its policy — so an unauthorized
 * producer never reaches the target actor or consumes its capacity.
 *
 * @psalm-api
 */
final readonly class StampMessageRouter implements MessageRouter
{
    /**
     * @param array<string, ActorRef<object>> $registry keyed by actor-path string
     * @param TargetAuthorizer|null $authorizer when set, authorizes producer → target routing per envelope
     */
    public function __construct(private array $registry, private ?TargetAuthorizer $authorizer = null) {}

    #[Override]
    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        $stamp = $envelope->last(TargetActorPathStamp::class);

        if (!$stamp instanceof TargetActorPathStamp) {
            return null;
        }

        $target = $this->registry[$stamp->path] ?? null;

        if ($target === null) {
            return null;
        }

        if ($this->authorizer !== null && !$this->authorizer->authorize($stamp->path, $envelope)) {
            return null;
        }

        return $target;
    }
}
