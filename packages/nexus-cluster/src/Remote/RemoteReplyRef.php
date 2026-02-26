<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Remote;

use Closure;
use Monadial\Nexus\Cluster\Protocol\RemoteAskReply;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
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
final readonly class RemoteReplyRef implements ActorRef
{
    /** @param Closure(object): void $onReply */
    public function __construct(
        private string $requestId,
        private int $replyToWorker,
        private ActorPath $path,
        private Transport $transport,
        private ClusterSerializer $serializer,
        private Closure $onReply,
    ) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        ($this->onReply)($message);

        $envelope = Envelope::of(
            new RemoteAskReply($this->requestId, $message),
            ActorPath::root(),
            ActorPath::root(),
        );

        $this->transport->send($this->replyToWorker, $this->serializer->serialize($envelope));
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('Cannot ask() a RemoteReplyRef');
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
