<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Mailbox;

use Fp\Collections\HashMap;
use Monadial\Nexus\Core\Actor\ActorPath;

/**
 * Immutable message wrapper carrying sender, target, and metadata.
 */
final readonly class Envelope
{
    /**
     * @param HashMap<string, string> $metadata
     */
    public function __construct(
        public object $message,
        public ActorPath $sender,
        public ActorPath $target,
        public HashMap $metadata,
    ) {}

    /**
     * Creates an Envelope with empty metadata.
     */
    public static function of(object $message, ActorPath $sender, ActorPath $target): self
    {
        /** @var HashMap<string, string> $empty fp4php infers HashMap<never, never> for empty collections */
        $empty = HashMap::collect([]); // @phpstan-ignore varTag.type
        return new self($message, $sender, $target, $empty);
    }

    /**
     * Returns a new Envelope with updated metadata.
     *
     * @param HashMap<string, string> $metadata
     */
    public function withMetadata(HashMap $metadata): self
    {
        return new self($this->message, $this->sender, $this->target, $metadata);
    }

    /**
     * Returns a new Envelope with updated sender.
     */
    public function withSender(ActorPath $sender): self
    {
        return new self($this->message, $sender, $this->target, $this->metadata);
    }
}
