<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence;

use InvalidArgumentException;
use Override;
use Stringable;

/**
 * Unique stable identity for a persistent actor.
 *
 * A `PersistenceId` combines an entity type (e.g. `"Order"`) with an entity
 * ID (e.g. `"order-42"`) and serialises to `"Order|order-42"`. The separator
 * is a pipe character (`|`) which is forbidden in the entity type to ensure
 * unambiguous round-trip parsing.
 *
 * All event-store and state-store operations use the persistence ID as the
 * primary key. Choose IDs that are stable across restarts — typically the
 * domain entity's UUID or slug.
 *
 * Example:
 * ```php
 * $id = PersistenceId::of('Order', 'order-42');
 * echo $id; // "Order|order-42"
 *
 * $same = PersistenceId::fromString('Order|order-42');
 * $id->equals($same); // true
 * ```
 *
 * @see EventSourcedBehavior for using a PersistenceId in event-sourced actors
 * @see DurableStateBehavior for using a PersistenceId in durable-state actors
 *
 * @psalm-api
 */
final readonly class PersistenceId implements Stringable
{
    private function __construct(public string $entityType, public string $entityId) {}

    /**
     * Create a persistence ID from separate entity type and entity ID strings.
     *
     * @throws InvalidArgumentException if either argument is empty or entity type contains `|`
     */
    public static function of(string $entityType, string $entityId): self
    {
        if ($entityType === '') {
            throw new InvalidArgumentException('Entity type must not be empty');
        }

        if ($entityId === '') {
            throw new InvalidArgumentException('Entity ID must not be empty');
        }

        if (str_contains($entityType, '|')) {
            throw new InvalidArgumentException('Entity type must not contain pipe character');
        }

        return new self($entityType, $entityId);
    }

    /**
     * Parse a persistence ID from its canonical `"EntityType|entityId"` string form.
     *
     * @throws InvalidArgumentException if the string does not contain a pipe separator
     */
    public static function fromString(string $value): self
    {
        $parts = explode('|', $value, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid persistence ID: {$value}");
        }

        return self::of($parts[0], $parts[1]);
    }

    /** Return the canonical `"EntityType|entityId"` string representation. */
    public function toString(): string
    {
        return "{$this->entityType}|{$this->entityId}";
    }

    /** Return true if both entity type and entity ID match. */
    public function equals(self $other): bool
    {
        return $this->entityType === $other->entityType
            && $this->entityId === $other->entityId;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
