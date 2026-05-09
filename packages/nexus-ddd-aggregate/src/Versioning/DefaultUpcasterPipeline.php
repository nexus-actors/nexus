<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Override;

/**
 * @psalm-api
 *
 * Default in-memory pipeline. Holds an `eventName -> fromVersion -> Upcaster`
 * lookup; walks it iteratively for both `upcast()` (to-latest) and
 * `upcastTo($targetVersion)` (pin). Each step transforms a typed
 * `DomainEvent` to the next-version typed `DomainEvent`.
 */
final readonly class DefaultUpcasterPipeline implements UpcasterPipeline
{
    /**
     * @param array<string, array<int, Upcaster>> $byEventAndFrom eventName => fromVersion => Upcaster
     */
    public function __construct(private array $byEventAndFrom) {}

    #[Override]
    public function upcast(string $eventName, int $fromVersion, DomainEvent $event, UpcastContext $context): DomainEvent
    {
        $current = $event;
        $version = $fromVersion;

        while (isset($this->byEventAndFrom[$eventName][$version])) {
            $upcaster = $this->byEventAndFrom[$eventName][$version];
            $current = $upcaster->upcast($current, $context);
            $version = $upcaster->toVersion();
        }

        return $current;
    }

    #[Override]
    public function upcastTo(
        string $eventName,
        int $fromVersion,
        int $targetVersion,
        DomainEvent $event,
        UpcastContext $context,
    ): DomainEvent {
        if ($fromVersion >= $targetVersion) {
            return $event;
        }

        $current = $event;
        $version = $fromVersion;

        while ($version < $targetVersion && isset($this->byEventAndFrom[$eventName][$version])) {
            $upcaster = $this->byEventAndFrom[$eventName][$version];
            $current = $upcaster->upcast($current, $context);
            $version = $upcaster->toVersion();
        }

        return $current;
    }
}
