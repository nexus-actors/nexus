<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

use Monadial\Nexus\Core\Actor\ActorPath;

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
        public array $metadata = [],
    ) {
    }

    /**
     * Creates an Envelope with empty metadata.
     */
    public static function of(object $message, ActorPath $sender, ActorPath $target): self
    {
        return new self($message, $sender, $target);
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
}
