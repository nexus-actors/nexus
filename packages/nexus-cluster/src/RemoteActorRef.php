<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Closure;
use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorPathContract;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Remote actor reference that delivers messages via transport to another worker.
 * Implements ActorRef for location transparency -- actor code never knows
 * whether it holds a LocalActorRef or RemoteActorRef.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class RemoteActorRef implements ActorRef
{
    /**
     * @param Closure(ActorPath, int, object, Duration): Future<object> $askHandler
     */
    public function __construct(
        private ActorPath $path,
        private int $targetWorker,
        private Transport $transport,
        private ClusterSerializer $serializer,
        private ActorDirectory $directory,
        private Closure $askHandler,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        $envelope = Envelope::of($message, ActorPath::root(), $this->path);
        $data = $this->serializer->serialize($envelope);
        $this->transport->send($this->targetWorker, $data);
    }

    #[Override]
    public function enqueueEnvelope(Envelope $envelope): void
    {
        $data = $this->serializer->serialize($envelope);
        $this->transport->send($this->targetWorker, $data);
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        /** @var Future<R> */
        return ($this->askHandler)($this->path, $this->targetWorker, $message, $timeout);
    }

    #[Override]
    public function path(): ActorPathContract
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return $this->directory->has((string) $this->path);
    }
}
