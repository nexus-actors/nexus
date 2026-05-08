<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Context;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Pure value object — metadata + stamps for the in-flight message.
 * The bus pushes a MessageContext onto its injected `MessageContextStack`
 * before invoking a handler; the bus reads
 * `MessageContextStack::current()->metadata` when stamping nested
 * dispatches.
 */
final readonly class MessageContext
{
    /**
     * @param array<class-string<Stamp>, Stamp> $stamps
     */
    public function __construct(
        public MessageMetadata $metadata,
        public array $stamps = [],
    ) {}

    /**
     * @template S of Stamp
     *
     * @param class-string<S> $stampClass
     * @return Option<S>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    public function stamp(string $stampClass): Option
    {
        return Option::fromNullable($this->stamps[$stampClass] ?? null);
    }
}
