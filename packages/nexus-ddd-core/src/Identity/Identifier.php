<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;

/**
 * @psalm-api
 *
 * Universal identity contract. Any value object that uniquely identifies something
 * (aggregate, message, scheduled task, …) implements this.
 */
interface Identifier
{
    /** Canonical string serialization for storage. */
    public function value(): string;

    /** Equality requires both runtime type AND value match. */
    public function equals(Identifier $other): bool;

    /**
     * Reconstruct an instance from its canonical string form.
     * Used by event store / outbox / snapshot rehydration.
     *
     * @throws InvalidIdentifierException on parse failure
     */
    public static function fromString(string $value): static;
}
