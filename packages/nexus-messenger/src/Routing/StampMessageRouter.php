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
 * @psalm-api
 */
final readonly class StampMessageRouter implements MessageRouter
{
    /**
     * @param array<string, ActorRef<object>> $registry keyed by actor-path string
     */
    public function __construct(private array $registry)
    {
    }

    #[Override]
    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        $stamp = $envelope->last(TargetActorPathStamp::class);

        if (!$stamp instanceof TargetActorPathStamp) {
            return null;
        }

        return $this->registry[$stamp->path] ?? null;
    }
}
