<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class NoEventsRecordedException extends NexusDddException
{
    /**
     * @param class-string $aggregateClass tightened to class-string<AggregateRoot> in Task 15
     */
    public static function for(string $aggregateClass): self
    {
        return new self(
            sprintf('No events recorded by %s — pullRecordedEvents() returned empty.', $aggregateClass),
        );
    }
}
