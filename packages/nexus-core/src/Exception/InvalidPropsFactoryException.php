<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use function get_debug_type;
use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a Props factory violates its contract: the callable given to
 * Props::fromFactory()/fromStatefulFactory(), or the container behind
 * Props::fromContainer(), produced something other than the required handler
 * interface. Raised at actor start, independent of the zend.assertions
 * setting, and surfaced to the spawner wrapped in ActorInitializationException.
 */
final class InvalidPropsFactoryException extends NexusLogicException
{
    public function __construct(string $source, string $expectedInterface, mixed $actual)
    {
        parent::__construct(sprintf(
            '%s must produce an instance of %s, got %s',
            $source,
            $expectedInterface,
            get_debug_type($actual),
        ));
    }
}
