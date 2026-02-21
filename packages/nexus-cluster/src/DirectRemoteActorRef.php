<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Transport\EnvelopeTransport;
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
 * Remote actor reference that delivers envelopes directly via EnvelopeTransport,
 * bypassing serialization. Used for same-process transports (e.g. Swoole threads)
 * where messages can be passed as objects without serialization overhead.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class DirectRemoteActorRef implements ActorRef
{
    public function __construct(
        private ActorPath $path,
        private int $targetWorker,
        private EnvelopeTransport $transport,
        private ActorDirectory $directory,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        $envelope = Envelope::of($message, ActorPath::root(), $this->path);
        $this->transport->send($this->targetWorker, $envelope);
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
