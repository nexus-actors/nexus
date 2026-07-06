<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;

/**
 * @psalm-api
 *
 * Location-transparent reference to an actor living on a cluster node.
 *
 * A send to the local node short-circuits straight to {@see InboundDelivery} with no frame
 * on the wire. A send to a remote node serializes the message into a {@see MessagePayload}
 * (trace context injected through the seam) and hands it to the {@see OutboundSink}.
 * {@see ask()} registers a correlation slot in the {@see TcpAskRegistry}, stamps a
 * `replyPath` derived from the sending node's address, and returns a {@see Future} that
 * resolves on the reply frame or fails with {@see AskTimeoutException} after one RTT.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class ClusterRef implements ActorRef
{
    /**
     * @param Closure(): bool $aliveChecker
     */
    public function __construct(
        private NodeAddress $self,
        private NodeAddress $target,
        private ActorPath $targetPath,
        private OutboundSink $sink,
        private InboundDelivery $localDelivery,
        private TcpAskRegistry $askRegistry,
        private ClusterMessageCodec $codec,
        private TraceContextInjector $trace,
        private Closure $aliveChecker,
    ) {}

    /**
     * @param T $message
     */
    #[Override]
    public function tell(object $message): void
    {
        if ($this->targetsSelf()) {
            $_ = $this->localDelivery->deliver((string) $this->targetPath, $message, null);

            return;
        }

        $encoded = $this->codec->encode($message);

        $this->sink->send($this->target, new MessagePayload(
            targetPath: (string) $this->targetPath,
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: null,
            replyPath: null,
            trace: $this->trace->inject(),
        ));
    }

    /**
     * @template R of object
     * @param T $message
     * @return Future<R>
     *
     * @throws AskTimeoutException When no reply arrives within `$timeout` (thrown on await).
     */
    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        $correlationId = bin2hex(random_bytes(16));
        $replyPath = $this->self->temporaryAskReplyPath($correlationId);

        $encoded = $this->codec->encode($message);

        /** @var Future<R> $future */
        $future = $this->askRegistry->register($correlationId, $timeout, $this->targetPath);

        $this->sink->send($this->target, new MessagePayload(
            targetPath: (string) $this->targetPath,
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: $correlationId,
            replyPath: (string) $replyPath,
            trace: $this->trace->inject(),
        ));

        return $future;
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->targetPath;
    }

    #[Override]
    public function isAlive(): bool
    {
        return ($this->aliveChecker)();
    }

    private function targetsSelf(): bool
    {
        return $this->target->toPathPrefix() === $this->self->toPathPrefix();
    }
}
