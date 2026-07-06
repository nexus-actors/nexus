<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use BadMethodCallException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;

/**
 * @psalm-api
 *
 * Reply-only actor reference injected as the sender of an inbound cluster ask. Calling
 * {@see tell()} serializes the reply, wraps it in a {@see MessagePayload} addressed to the
 * originating node's temporary ask-reply path (with the correlation ID echoed), and sends
 * it back through the {@see OutboundSink}. {@see ask()} is unsupported.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class ClusterReplyRef implements ActorRef
{
    public function __construct(
        private NodeAddress $origin,
        private string $replyPath,
        private string $correlationId,
        private OutboundSink $sink,
        private ClusterMessageCodec $codec,
        private TraceContextInjector $trace,
    ) {}

    /**
     * @param T $message
     */
    #[Override]
    public function tell(object $message): void
    {
        $encoded = $this->codec->encode($message);

        $this->sink->send($this->origin, new MessagePayload(
            targetPath: $this->replyPath,
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: $this->correlationId,
            replyPath: null,
            trace: $this->trace->inject(),
        ));
    }

    /**
     * @template R of object
     * @param T $message
     * @return Future<R>
     */
    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new BadMethodCallException('ClusterReplyRef is reply-only and does not support ask().');
    }

    #[Override]
    public function path(): ActorPath
    {
        return ActorPath::fromString($this->replyPath);
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }
}
