<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Immutable message wrapper that carries a user message through the mailbox system.
 *
 * Every message sent via `ActorRef::tell()` is wrapped in an `Envelope` before it
 * enters a mailbox. The envelope adds routing context — sender path, target path — and
 * distributed-tracing identifiers (`requestId`, `correlationId`, `causationId`) so
 * that message flows can be tracked across actor boundaries and serialized for
 * remote transport.
 *
 * Most user code never constructs envelopes directly; the actor runtime creates them
 * internally. The `Envelope` is exposed in serialization adapters and the worker-pool
 * transport layer where low-level routing is needed.
 *
 * Example (worker-pool transport usage):
 * ```php
 * $envelope = Envelope::of($message, $senderPath, $targetPath)
 *     ->withSenderRef($replyRef)
 *     ->withMetadata(['x-trace-id' => $traceId]);
 * $transport->send($targetWorkerId, $envelope);
 * ```
 *
 * @see ActorRef::tell()        for the user-facing send API that creates envelopes
 * @see ActorPath               for the routing addresses carried by the envelope
 * @see EnvelopeSerializer      for serializing envelopes across process boundaries
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class Envelope
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public object $message,
        public ActorPath $sender,
        public ActorPath $target,
        public string $requestId,
        public string $correlationId,
        public string $causationId,
        public ?ActorRef $senderRef = null,
        public array $metadata = [],
    ) {}

    /**
     * Creates an Envelope with empty metadata.
     */
    public static function of(object $message, ActorPath $sender, ActorPath $target): self
    {
        $requestId = self::newId();

        return new self(
            message: $message,
            sender: $sender,
            target: $target,
            requestId: $requestId,
            correlationId: $requestId,
            causationId: $requestId,
        );
    }

    /**
     * Returns a new Envelope with the given senderRef.
     */
    public function withSenderRef(ActorRef $senderRef): self
    {
        return clone($this, ['senderRef' => $senderRef]);
    }

    /**
     * Returns a new Envelope with updated metadata.
     *
     * @param array<string, string> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => $metadata]);
    }

    /**
     * Returns a new Envelope with updated sender.
     */
    public function withSender(ActorPath $sender): self
    {
        return clone($this, ['sender' => $sender]);
    }

    /**
     * Return a new Envelope with an updated request ID.
     *
     * The request ID uniquely identifies a single message send. Overriding it is
     * useful in serialization adapters that reconstruct envelopes from a wire format.
     */
    public function withRequestId(string $requestId): self
    {
        return clone($this, ['requestId' => $requestId]);
    }

    /**
     * Return a new Envelope with an updated correlation ID.
     *
     * The correlation ID ties together all messages that belong to the same logical
     * request chain (e.g. the original request and all downstream fan-out messages).
     */
    public function withCorrelationId(string $correlationId): self
    {
        return clone($this, ['correlationId' => $correlationId]);
    }

    /**
     * Return a new Envelope with an updated causation ID.
     *
     * The causation ID identifies the direct parent message that triggered this one,
     * enabling a causal DAG of message flows for distributed tracing.
     */
    public function withCausationId(string $causationId): self
    {
        return clone($this, ['causationId' => $causationId]);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
