<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

/**
 * @psalm-api
 *
 * Composes a chain of `Upcaster`s into a pipeline. Two read modes:
 *   - `upcast()` — drives to the highest registered version (used for
 *     aggregate replay, which must always reach current shape)
 *   - `upcastTo($targetVersion)` — pins the chain to a specific target
 *     (used for projection rebuilds that need to reproduce the historical
 *     payload shape they shipped against; per v6 spec §10.2)
 */
interface UpcasterPipeline
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(string $eventName, int $fromVersion, array $payload, PayloadContext $context): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcastTo(
        string $eventName,
        int $fromVersion,
        int $targetVersion,
        array $payload,
        PayloadContext $context,
    ): array;
}
