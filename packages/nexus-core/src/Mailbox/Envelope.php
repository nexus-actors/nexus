<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable message wrapper carrying sender, target, and metadata.
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

    public function withRequestId(string $requestId): self
    {
        return clone($this, ['requestId' => $requestId]);
    }

    public function withCorrelationId(string $correlationId): self
    {
        return clone($this, ['correlationId' => $correlationId]);
    }

    public function withCausationId(string $causationId): self
    {
        return clone($this, ['causationId' => $causationId]);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
