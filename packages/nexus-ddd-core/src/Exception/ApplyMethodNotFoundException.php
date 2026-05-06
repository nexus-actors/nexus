<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class ApplyMethodNotFoundException extends NexusDddException
{
    /**
     * @param class-string $aggregateClass tightened to class-string<AggregateRoot> in Task 15
     * @param class-string $eventClass tightened to class-string<DomainEvent> when DomainEvent exists
     */
    public static function for(string $aggregateClass, string $eventClass): self
    {
        return new self(
            sprintf(
                'No applyXxx() method found on %s for event %s. Expected method: apply%s.',
                $aggregateClass,
                $eventClass,
                self::shortName($eventClass),
            ),
        );
    }

    /** @param class-string $fqn */
    private static function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);

        return end($parts);
    }
}
