<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Snapshot of all data `BusBuilder::build()` produces by reflection.
 * Written to disk via `BusBuilder::dumpCompiledTo()`; loaded via
 * `BusBuilder::loadCompiledFrom()`. The on-disk format is opcache-friendly
 * PHP — `return new CompiledBusBootSnapshot(...);`. Opcache parses + caches
 * the AST once; subsequent loads are near-zero-cost.
 */
final readonly class CompiledBusBootSnapshot
{
    /**
     * @param array<class-string, class-string> $handlerMap
     * @param array<class-string, ResolvedAttributesEntry> $entries
     */
    public function __construct(public string $sourceHash, public array $handlerMap, public array $entries) {}
}
