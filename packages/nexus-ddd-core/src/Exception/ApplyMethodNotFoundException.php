<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class ApplyMethodNotFoundException extends NexusDddException
{
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

    private static function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        return end($parts);
    }
}
