<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Stream;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Logical stream name. A stream is a partition of the event store from
 * which a consumer can subscribe to events. Concrete physical storage
 * (table layout) is determined by the StreamStrategy + DBAL/Doctrine impl;
 * domain code never sees physical names.
 */
final readonly class StreamName
{
    /** @param non-empty-string $value */
    public function __construct(public string $value) {}

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
