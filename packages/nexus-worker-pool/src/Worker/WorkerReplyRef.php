<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Worker;

use Closure;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskReply;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use NoDiscard;
use Override;
use RuntimeException;

/**
 * @psalm-api
 *
 * SenderRef bridge that routes ctx->reply() back to the requesting worker.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class WorkerReplyRef implements ActorRef
{
    /** @param Closure(object): bool $onReply */
    public function __construct(
        private string $requestId,
        private int $replyToWorker,
        private ActorPath $path,
        private WorkerTransport $transport,
        private Closure $onReply,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        if (!(($this->onReply)($message))) {
            return;
        }

        $envelope = Envelope::of(
            new WorkerAskReply($this->requestId, $message),
            ActorPath::root(),
            ActorPath::root(),
        );

        $this->transport->send($this->replyToWorker, $envelope);
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('Cannot ask() a WorkerReplyRef');
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }
}
