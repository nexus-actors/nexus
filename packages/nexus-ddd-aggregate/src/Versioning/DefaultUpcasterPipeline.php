<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use Override;

/**
 * @psalm-api
 *
 * Default in-memory pipeline. Holds a `eventName -> fromVersion -> Upcaster`
 * lookup; walks it iteratively for both `upcast()` (to-latest) and
 * `upcastTo($targetVersion)` (pin).
 */
final readonly class DefaultUpcasterPipeline implements UpcasterPipeline
{
    /**
     * @param array<string, array<int, Upcaster>> $byEventAndFrom eventName => fromVersion => Upcaster
     */
    public function __construct(private array $byEventAndFrom) {}

    #[Override]
    public function upcast(string $eventName, int $fromVersion, array $payload, PayloadContext $context): array
    {
        $current = $payload;
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
        array $payload,
        PayloadContext $context,
    ): array {
        if ($fromVersion >= $targetVersion) {
            return $payload;
        }

        $current = $payload;
        $version = $fromVersion;

        while ($version < $targetVersion && isset($this->byEventAndFrom[$eventName][$version])) {
            $upcaster = $this->byEventAndFrom[$eventName][$version];
            $current = $upcaster->upcast($current, $context);
            $version = $upcaster->toVersion();
        }

        return $current;
    }
}
