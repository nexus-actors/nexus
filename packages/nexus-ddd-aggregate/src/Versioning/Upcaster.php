<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

/**
 * @psalm-api
 *
 * Pure transformation `(payloadV_n, context) → payloadV_n+1` for ONE event
 * version transition. Implementations declare which event they handle
 * (eventName) and which version pair (fromVersion → toVersion).
 *
 * MUST be a pure function:
 *   - no clock reads, no RNG, no logger, no container access
 *   - no aggregate state access (the upcaster operates on raw payload arrays)
 *   - same restriction enforced by `ReplaySafeApplyRule` Psalm rule that
 *     governs `EventSourcedAggregateRoot::apply()`
 */
interface Upcaster
{
    public function eventName(): string;

    public function fromVersion(): int;

    public function toVersion(): int;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array;
}
