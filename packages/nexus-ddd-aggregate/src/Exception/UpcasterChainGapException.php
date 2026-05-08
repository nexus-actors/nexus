<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

/**
 * @psalm-api
 *
 * Thrown at boot time when the `UpcasterPipeline` detects a missing
 * version-bridge upcaster: a stored event tagged `vN` exists in the
 * stream but no `Upcaster` is registered to migrate it to `vN+1` before
 * the latest schema version. Replay would silently fail mid-stream.
 *
 * Framework-wiring fault — caught at pipeline construction before any
 * replay starts. The fix is to register an `Upcaster` covering the
 * missing transition before deploying, not to catch this exception.
 */
final class UpcasterChainGapException extends NexusDddException
{
    /**
     * @param non-empty-string $eventName
     */
    public static function missingUpcaster(string $eventName, int $fromVersion, int $toVersion): self
    {
        return new self(sprintf(
            'No upcaster registered for %s v%d → v%d. The upcaster chain has a gap; replay would fail mid-stream. Register an Upcaster covering this transition before deploying.',
            $eventName,
            $fromVersion,
            $toVersion,
        ));
    }
}
