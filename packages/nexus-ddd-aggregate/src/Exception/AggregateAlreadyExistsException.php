<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Exception;

use Monadial\Nexus\Ddd\Core\Exception\DomainException;

/**
 * @psalm-api
 *
 * Thrown when `AggregateRepository::add()` (or `save()` of a v=0 aggregate)
 * collides with an aggregate of the same id already persisted. The collision
 * is a domain-level fact: someone else created this aggregate first.
 *
 * Terminal — middleware does NOT retry. The handler should surface the
 * conflict to the caller, who decides how to respond. Distinct from
 * `OptimisticLockException`, which signals a mid-air collision on an
 * existing aggregate (and IS retried by middleware).
 */
final class AggregateAlreadyExistsException extends DomainException
{
    /**
     * @param non-empty-string $aggregateClass
     * @param non-empty-string $aggregateId
     */
    public static function for(string $aggregateClass, string $aggregateId): self
    {
        return new self(sprintf(
            'Aggregate %s with id %s already exists. (add() or save(version=0) collided with a previously-persisted aggregate.)',
            $aggregateClass,
            $aggregateId,
        ));
    }
}
