<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Envelope;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template TMessage of object
 *
 * Wraps a message with its metadata + transport stamps. The envelope is
 * transport-shaped; domain code never instantiates one — bus and staging
 * implementations construct envelopes internally.
 */
final readonly class Envelope
{
    /**
     * @param TMessage $message
     * @param array<class-string<Stamp>, Stamp> $stamps keyed by stamp class
     */
    public function __construct(public object $message, public MessageMetadata $metadata, public array $stamps = []) {}

    /**
     * @return self<TMessage> with the stamp added (or replacing same-class).
     */
    #[NoDiscard('with() returns a new envelope; ignoring it loses the stamp')]
    public function with(Stamp $stamp): self
    {
        $next = $this->stamps;
        $next[$stamp::class] = $stamp;

        return new self($this->message, $this->metadata, $next);
    }

    /**
     * @template S of Stamp
     *
     * @param class-string<S> $stampClass
     * @return Option<S>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    public function get(string $stampClass): Option
    {
        return Option::fromNullable($this->stamps[$stampClass] ?? null);
    }
}
