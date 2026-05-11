<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Forward-shipped in Phase 10b → fleshed by Phase 12a. Re-use, do not
 * recreate, in Phase 12a; the constructor + `all()` method exist now
 * so the validation and authorization middleware can consume the
 * cached lookup.
 *
 * Pre-computed cache built once at boot by `BusBuilder`. Lookups are
 * O(1) by message class.
 */
final class HandlerAttributeIndex
{
    /**
     * @param array<class-string, ResolvedAttributesEntry> $entries
     *
     * @psalm-suppress PossiblyUnusedMethod — wired by BusBuilder in Phase 12a.
     */
    public function __construct(
        /** @var array<class-string, ResolvedAttributesEntry> */
        private readonly array $entries,
    ) {}

    /**
     * @param class-string $messageClass
     * @return Option<ResolvedAttributesEntry>
     */
    public function lookup(string $messageClass): Option
    {
        return Option::fromNullable($this->entries[$messageClass] ?? null);
    }

    /**
     * @return iterable<class-string, ResolvedAttributesEntry>
     *
     * @psalm-suppress PossiblyUnusedMethod — consumed by RoutesShowCommand in Phase 14.
     */
    public function all(): iterable
    {
        return $this->entries;
    }
}
