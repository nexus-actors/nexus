<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use Monadial\Nexus\Ddd\Aggregate\Exception\UpcasterChainGapException;

/**
 * @psalm-api
 *
 * Boot-time builder for `UpcasterPipeline`. Reads a list of Upcaster
 * class-strings, instantiates each (assumes no-arg constructor — a
 * documented framework convention), validates that each event's chain
 * has no gaps from version 1 to its highest registered version, and
 * returns a `DefaultUpcasterPipeline`.
 *
 * Chain gap = an event has upcasters covering versions {1, 3} but no
 * (2 → 3) upcaster. Replay would fail mid-stream; caught at boot.
 */
final class UpcasterRegistry
{
    /**
     * @param iterable<class-string<Upcaster>> $classes
     *
     * @throws UpcasterChainGapException
     */
    public static function scan(iterable $classes): UpcasterPipeline
    {
        /** @var array<string, array<int, Upcaster>> $registered */
        $registered = [];
        /** @var array<string, int> $maxVersionByEvent */
        $maxVersionByEvent = [];

        foreach ($classes as $class) {
            /** @psalm-suppress UnsafeInstantiation Upcasters are framework contracts; no-arg ctor is the documented convention. */
            $upcaster = new $class();
            $event = $upcaster->eventName();
            $from = $upcaster->fromVersion();
            $to = $upcaster->toVersion();
            $registered[$event][$from] = $upcaster;
            $maxVersionByEvent[$event] = max($maxVersionByEvent[$event] ?? 1, $to);
        }

        foreach ($registered as $event => $byFrom) {
            $max = $maxVersionByEvent[$event];

            for ($v = 1; $v < $max; $v++) {
                if (! isset($byFrom[$v])) {
                    /** @var non-empty-string $event */
                    throw UpcasterChainGapException::missingUpcaster($event, $v, $v + 1);
                }
            }
        }

        return new DefaultUpcasterPipeline($registered);
    }
}
