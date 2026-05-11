<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use function array_last;
use function explode;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Outcome of `RoutingStrategy::resolve()`: the bus name to dispatch to and
 * the strategy class that produced the answer. The `displayName()` helper
 * renders the unqualified strategy name for CLI output.
 */
final readonly class RoutingResolution
{
    /** @param class-string<RoutingStrategy> $resolvedBy */
    public function __construct(public string $busName, public string $resolvedBy) {}

    public function displayName(): string
    {
        $parts = explode('\\', $this->resolvedBy);
        /** @var string $last — Psalm CallMap signature for array_last is mixed regardless of input element type */
        $last = array_last($parts);

        return $last;
    }
}
