<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\LocalActorRef;

/**
 * @psalm-api
 *
 * In-memory map of actor paths to the local refs exposed for cluster delivery.
 *
 * Only {@see LocalActorRef} instances may be exposed: remote refs cannot be delivered to
 * in-process, and only the local ref exposes the envelope-preserving delivery seam
 * ({@see LocalActorRef::offerEnvelope()}) that {@see LocalDelivery} relies on.
 */
final class LocalActorRegistry
{
    /** @var array<string, LocalActorRef<object>> */
    private array $refs = [];

    /**
     * @param ActorRef<object> $ref
     *
     * @throws InvalidArgumentException When the ref is not a {@see LocalActorRef}.
     */
    public function expose(ActorRef $ref): void
    {
        if (!$ref instanceof LocalActorRef) {
            throw new InvalidArgumentException(
                'LocalActorRegistry can only expose LocalActorRef instances, got ' . $ref::class,
            );
        }

        $this->refs[(string) $ref->path()] = $ref;
    }

    /**
     * @return LocalActorRef<object>|null
     */
    public function resolve(string $path): ?ActorRef
    {
        return $this->refs[$path] ?? null;
    }
}
