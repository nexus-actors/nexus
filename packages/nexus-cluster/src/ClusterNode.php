<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Router\MessageRouter;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;

/**
 * @psalm-api
 *
 * Per-worker cluster coordinator.
 *
 * Owns the ActorSystem, routes messages via the hash ring, and handles
 * incoming transport messages. Each worker process runs exactly one ClusterNode.
 */
final class ClusterNode
{
    /** @var array<string, LocalActorRef<object>> */
    private array $localRefs = [];

    /**
     * @param callable(ActorPath, int): ActorRef<object> $remoteRefFactory
     */
    public function __construct(
        private readonly int $workerId,
        private readonly ActorSystem $system,
        private readonly MessageRouter $router,
        private readonly ConsistentHashRing $ring,
        private readonly ActorDirectory $directory,
        private readonly mixed $remoteRefFactory,
    ) {}

    /**
     * Spawn an actor, routing to local or remote based on the hash ring.
     *
     * If the hash ring assigns the actor name to this worker, the actor is
     * spawned locally via the ActorSystem. Otherwise, a RemoteActorRef is
     * returned that routes messages via the transport.
     *
     * @template T of object
     * @param Props<T> $props
     * @return ActorRef<T>
     */
    public function spawn(Props $props, string $name): ActorRef
    {
        $ownerWorker = $this->ring->getWorker($name);
        $path = ActorPath::fromString('/user/' . $name);
        $pathStr = (string) $path;

        if ($ownerWorker === $this->workerId) {
            $ref = $this->system->spawn($props, $name);
            $this->directory->register($pathStr, $this->workerId);

            if ($ref instanceof LocalActorRef) {
                $this->localRefs[$pathStr] = $ref;
            }

            return $ref;
        }

        $this->directory->register($pathStr, $ownerWorker);

        /** @var ActorRef<T> $remoteRef */
        $remoteRef = ($this->remoteRefFactory)($path, $ownerWorker);

        return $remoteRef;
    }

    /**
     * Look up an actor by path, returning a local or remote ref.
     *
     * Returns null if the actor is not known to the directory.
     *
     * @return ActorRef<object>|null
     */
    public function actorFor(string $path): ?ActorRef
    {
        if (isset($this->localRefs[$path])) {
            return $this->localRefs[$path];
        }

        $workerId = $this->directory->lookup($path);

        if ($workerId === null) {
            return null;
        }

        return ($this->remoteRefFactory)(ActorPath::fromString($path), $workerId);
    }

    /**
     * Start listening for incoming transport messages.
     *
     * Incoming envelopes are deserialized and delivered to the target local
     * actor's mailbox via enqueueEnvelope(), preserving the original sender path.
     */
    public function start(): void
    {
        $this->router->startReceiving(function (Envelope $envelope): void {
            $targetPath = (string) $envelope->target;

            $ref = $this->localRefs[$targetPath] ?? null;

            if ($ref !== null) {
                $ref->enqueueEnvelope($envelope);
            }
        });
    }

    /**
     * Returns this node's worker ID.
     */
    public function workerId(): int
    {
        return $this->workerId;
    }

    /**
     * Returns the underlying ActorSystem.
     */
    public function system(): ActorSystem
    {
        return $this->system;
    }
}
