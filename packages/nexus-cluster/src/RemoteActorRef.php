<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;
use Override;
use RuntimeException;

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
    public function __construct(
        private ActorPath $path,
        private int $targetWorker,
        private Transport $transport,
        private ClusterSerializer $serializer,
        private ActorDirectory $directory,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        $envelope = Envelope::of($message, ActorPath::root(), $this->path);
        $data = $this->serializer->serialize($envelope);
        $this->transport->send($this->targetWorker, $data);
    }

    /**
     * @throws RuntimeException Always -- ask() is not supported for remote actors in v1
     */
    #[Override]
    #[NoDiscard]
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        throw new RuntimeException('ask() is not supported for remote actors');
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return $this->directory->has((string) $this->path);
    }
}
