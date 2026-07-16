<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool;

use Closure;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\Directory\WorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * Cross-worker actor reference within a local worker pool.
 * Delivers messages via WorkerTransport — no serializer involved;
 * the transport (ThreadQueueTransport) handles object passing.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class WorkerActorRef implements ActorRef
{
    /**
     * @param Closure(ActorPath, int, object, Duration): Future<object> $askHandler
     */
    public function __construct(
        private ActorPath $path,
        private int $targetWorker,
        private WorkerTransport $transport,
        private WorkerDirectory $directory,
        private Closure $askHandler,
    ) {}

    /** @param T|SystemMessage $message */
    #[Override]
    public function tell(object $message): void
    {
        $envelope = Envelope::of($message, ActorPath::root(), $this->path);
        $this->transport->send($this->targetWorker, $envelope);
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
