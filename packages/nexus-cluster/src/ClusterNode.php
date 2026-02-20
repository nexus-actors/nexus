<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;

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

    public function __construct(
        private readonly int $workerId,
        private readonly ActorSystem $system,
        private readonly Transport $transport,
        private readonly ConsistentHashRing $ring,
        private readonly ClusterSerializer $serializer,
        private readonly ActorDirectory $directory,
    ) {
    }

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

        /** @var RemoteActorRef<T> $remoteRef */
        $remoteRef = new RemoteActorRef($path, $ownerWorker, $this->transport, $this->serializer, $this->directory);

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

        return new RemoteActorRef(
            ActorPath::fromString($path),
            $workerId,
            $this->transport,
            $this->serializer,
            $this->directory,
        );
    }

    /**
     * Start listening for incoming transport messages.
     *
     * Incoming envelopes are deserialized and delivered to the target local
     * actor's mailbox via enqueueEnvelope(), preserving the original sender path.
     */
    public function start(): void
    {
        $this->transport->listen(function (string $data): void {
            $envelope = $this->serializer->deserialize($data);
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
