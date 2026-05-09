<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;
use Stringable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Identifies a single aggregate's event stream. The pair
 * (aggregateClass, aggregateId) is unique across the persistence
 * boundary; the store uses `toString()` as the lookup key.
 */
final readonly class AggregateStreamId implements Stringable
{
    /**
     * @param class-string $aggregateClass
     * @param non-empty-string $aggregateId
     */
    public function __construct(public string $aggregateClass, public string $aggregateId) {}

    /** @param class-string $aggregateClass */
    public static function for(string $aggregateClass, Identifier $id): self
    {
        /** @var non-empty-string $value */
        $value = $id->value();

        return new self($aggregateClass, $value);
    }

    public function toString(): string
    {
        return $this->aggregateClass . '/' . $this->aggregateId;
    }

    public function equals(self $other): bool
    {
        return $this->aggregateClass === $other->aggregateClass
            && $this->aggregateId === $other->aggregateId;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
