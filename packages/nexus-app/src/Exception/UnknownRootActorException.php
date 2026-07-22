<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Exception;

use RuntimeException;

use function implode;
use function sprintf;

/**
 * Thrown by {@see \Monadial\Nexus\App\StartedApp::ref()} when no root actor was
 * registered under the requested name.
 *
 * @psalm-api
 */
final class UnknownRootActorException extends RuntimeException
{
    /**
     * @param list<string> $known the names that were registered
     */
    public function __construct(string $name, array $known)
    {
        parent::__construct(sprintf(
            'No root actor named "%s" was registered. Known roots: %s.',
            $name,
            $known === []
                ? '(none)'
                : implode(', ', $known),
        ));
    }
}
