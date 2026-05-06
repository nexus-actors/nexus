<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class ApplyMethodAmbiguousException extends NexusDddException
{
    /**
     * @param class-string $aggregateClass tightened to class-string<AggregateRoot> in Task 15
     * @param array<class-string> $eventClasses
     */
    public static function for(string $aggregateClass, string $shortName, array $eventClasses): self
    {
        return new self(
            sprintf(
                'Ambiguous applyXxx convention on %s: short name "%s" maps to multiple event classes [%s]. Rename one of the events.',
                $aggregateClass,
                $shortName,
                implode(', ', $eventClasses),
            ),
        );
    }
}
